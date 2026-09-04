#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=""
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

REPOSITORY_ROOT=""
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
readonly REPOSITORY_ROOT

readonly COMPOSE_FILE="${SCRIPT_DIR}/compose.yaml"
readonly STRIPE_COMPOSE_FILE="${SCRIPT_DIR}/compose.stripe.yaml"
readonly PROJECT_NAME="kimai-stripe-connector-hosted-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-$$}"
readonly PLUGIN_NAME="KimaiStripeConnectorBundle"
readonly PLUGIN_PATH="/opt/kimai/var/plugins/${PLUGIN_NAME}"
readonly KIMAI_CONSOLE="/opt/kimai/bin/console"
readonly EVIDENCE_HELPER="/tmp/stripe-hosted-evidence.php"
readonly API_TOKEN="phase7b-e2e-api-token"
readonly ADMIN_EMAIL="e2e@example.invalid"
readonly INVOICE_TEMPLATE="phase7b-hosted"
readonly EXPECTED_MINOR_UNITS="1234"
readonly EXPECTED_CURRENCY="usd"

readonly DB_USER="kimaiuser"
readonly DB_PASSWORD="e2e-kimai-password"
readonly DB_HOST="sqldb"
readonly DB_NAME="kimai"
readonly DB_CHARSET="utf8mb4"
readonly DB_SERVER_VERSION="8.3.0"

E2E_DATABASE_URL="mysql"
E2E_DATABASE_URL+="://${DB_USER}:${DB_PASSWORD}@${DB_HOST}/${DB_NAME}"
E2E_DATABASE_URL+="?charset=${DB_CHARSET}&serverVersion=${DB_SERVER_VERSION}"
export E2E_DATABASE_URL

PACKAGE_ROOT=""
STRIPE_HELPER_READY=false

compose() {
  docker compose \
    --project-name "${PROJECT_NAME}" \
    --file "${COMPOSE_FILE}" \
    --file "${STRIPE_COMPOSE_FILE}" \
    "$@"
}

mysql_query() {
  local sql="$1"

  compose exec --no-TTY sqldb \
    mysql \
    --batch \
    --skip-column-names \
    "-u${DB_USER}" \
    "-p${DB_PASSWORD}" \
    "${DB_NAME}" \
    --execute "${sql}"
}

kimai_api() {
  local method="$1"
  local path="$2"
  local body="${3:-}"
  local args=(
    --fail-with-body
    --silent
    --show-error
    --request "${method}"
    --header "Authorization: Bearer ${API_TOKEN}"
    --header "Content-Type: application/json"
  )

  if [[ -n "${body}" ]]; then
    args+=(--data "${body}")
  fi

  curl "${args[@]}" "${KIMAI_URL}${path}"
}

diagnostics() {
  local kimai_container

  echo "Hosted-payment E2E failed; preserving diagnostics before teardown." >&2
  compose ps >&2 || true

  kimai_container="$(compose ps --quiet kimai 2> /dev/null || true)"
  if [[ -n "${kimai_container}" ]]; then
    echo "Kimai container health state:" >&2
    docker inspect --format '{{json .State.Health}}' "${kimai_container}" >&2 || true
  fi

  echo "Recent Kimai and database logs:" >&2
  compose logs --no-color --tail 200 kimai sqldb >&2 || true
}

cleanup_local() {
  if [[ -n "${PACKAGE_ROOT}" && -d "${PACKAGE_ROOT}" ]]; then
    rm -rf "${PACKAGE_ROOT}"
  fi

  compose down --volumes --remove-orphans > /dev/null 2>&1 || true
}

cleanup_stripe() {
  if [[ "${STRIPE_HELPER_READY}" != true ]]; then
    return 0
  fi

  compose exec --no-TTY kimai php "${EVIDENCE_HELPER}" cleanup
}

on_exit() {
  local status=$?
  local stripe_cleanup_status=0

  trap - EXIT INT TERM

  if cleanup_stripe; then
    stripe_cleanup_status=0
  else
    stripe_cleanup_status=$?
    echo "error: run-owned Stripe Payment Link cleanup failed" >&2
  fi

  if ((status != 0 || stripe_cleanup_status != 0)); then
    diagnostics
  fi

  cleanup_local

  if ((status == 0 && stripe_cleanup_status != 0)); then
    status="${stripe_cleanup_status}"
  fi

  exit "${status}"
}

trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

for command in composer curl docker jq unzip; do
  if ! command -v "${command}" > /dev/null 2>&1; then
    echo "error: ${command} is required for hosted-payment E2E" >&2
    exit 1
  fi
done

if ! docker compose version > /dev/null 2>&1; then
  echo "error: Docker Compose plugin is required for hosted-payment E2E" >&2
  exit 1
fi

if [[ -z "${STRIPE_SANDBOX_RESTRICTED_KEY:-}" ]]; then
  echo "error: STRIPE_SANDBOX_RESTRICTED_KEY is required for hosted-payment E2E" >&2
  exit 1
fi

if [[ ! -r /proc/sys/kernel/random/uuid ]]; then
  echo "error: a Linux kernel UUID source is required for disposable instance identity" >&2
  exit 1
fi

KIMAI_STRIPE_CONNECTOR_INSTANCE_ID="$(cat /proc/sys/kernel/random/uuid)"
readonly KIMAI_STRIPE_CONNECTOR_INSTANCE_ID
export KIMAI_STRIPE_CONNECTOR_INSTANCE_ID

cleanup_local

PACKAGE_ROOT="$(mktemp -d)"
readonly PACKAGE_ROOT
readonly PACKAGE_OUTPUT="${PACKAGE_ROOT}/artifact"
readonly PACKAGE_EXTRACT="${PACKAGE_ROOT}/extracted"
mkdir -p "${PACKAGE_OUTPUT}" "${PACKAGE_EXTRACT}"

VERSION=e2e-hosted OUTPUT_DIR="${PACKAGE_OUTPUT}" \
  bash "${REPOSITORY_ROOT}/tools/package.sh"

readonly PACKAGE_ARCHIVE="${PACKAGE_OUTPUT}/${PLUGIN_NAME}-e2e-hosted.zip"
unzip -q "${PACKAGE_ARCHIVE}" -d "${PACKAGE_EXTRACT}"
readonly PLUGIN_STAGE="${PACKAGE_EXTRACT}/${PLUGIN_NAME}"

if [[ ! -f "${PLUGIN_STAGE}/vendor/autoload.php" ]]; then
  echo "error: release artifact is missing plugin runtime dependencies" >&2
  exit 1
fi

compose up --detach --wait --wait-timeout 240

compose exec --user root --no-TTY kimai mkdir -p "${PLUGIN_PATH}"
compose cp "${PLUGIN_STAGE}/." "kimai:${PLUGIN_PATH}"
compose exec --no-TTY kimai "${KIMAI_CONSOLE}" kimai:reload --env=prod
compose cp "${SCRIPT_DIR}/stripe-hosted-evidence.php" "kimai:${EVIDENCE_HELPER}"
STRIPE_HELPER_READY=true

compose exec --no-TTY kimai php -r '
require "/opt/kimai/vendor/autoload.php";
exit(class_exists("KimaiPlugin\\KimaiStripeConnectorBundle\\KimaiStripeConnectorBundle") ? 0 : 1);
'

admin_count="$(mysql_query "SELECT COUNT(*) FROM kimai2_users WHERE email = '${ADMIN_EMAIL}';")"
if [[ "${admin_count}" != "1" ]]; then
  echo "error: disposable Kimai admin account could not be resolved uniquely" >&2
  exit 1
fi

mysql_query "
INSERT INTO kimai2_access_token (user_id, token, name, last_usage, expires_at)
SELECT id, '${API_TOKEN}', 'Phase 7b E2E', NULL, NULL
FROM kimai2_users
WHERE email = '${ADMIN_EMAIL}';
" > /dev/null

KIMAI_URL="http://$(compose port kimai 8001)"
readonly KIMAI_URL

customer_json="$(kimai_api POST /api/customers '{"name":"Phase 7b E2E","country":"US","currency":"USD","timezone":"UTC","language":"en","visible":true,"billable":true}')"
customer_id="$(jq -er '.id | numbers' <<< "${customer_json}")"
readonly customer_id

project_json="$(kimai_api POST /api/projects "{\"name\":\"Phase 7b E2E\",\"customer\":${customer_id},\"globalActivities\":false,\"visible\":true,\"billable\":true}")"
project_id="$(jq -er '.id | numbers' <<< "${project_json}")"
readonly project_id

activity_json="$(kimai_api POST /api/activities "{\"name\":\"Phase 7b E2E\",\"project\":${project_id},\"visible\":true,\"billable\":true}")"
activity_id="$(jq -er '.id | numbers' <<< "${activity_json}")"
readonly activity_id

fixture_date="$(date -u -d 'yesterday' '+%Y-%m-%d')"
readonly fixture_date

timesheet_json="$(kimai_api POST /api/timesheets "{\"activity\":${activity_id},\"project\":${project_id},\"begin\":\"${fixture_date} 09:00:00\",\"end\":\"${fixture_date} 10:00:00\",\"description\":\"Phase 7b hosted Stripe payment\",\"fixedRate\":12.34,\"hourlyRate\":12.34,\"billable\":true}")"
timesheet_rate="$(jq -er '.rate | numbers' <<< "${timesheet_json}")"
if [[ "${timesheet_rate}" != "12.34" ]]; then
  echo "error: disposable Kimai timesheet did not preserve the exact 12.34 fixed rate" >&2
  exit 1
fi

mysql_query "
INSERT INTO kimai2_invoice_templates
    (name, title, due_days, vat, calculator, number_generator, renderer, language, customer_id)
VALUES
    ('${INVOICE_TEMPLATE}', 'Phase 7b Hosted Payment', 30, 0, 'default', 'default', 'invoice', 'en', ${customer_id});
" > /dev/null

invoice_create_output="$(
  compose exec --no-TTY kimai \
    "${KIMAI_CONSOLE}" kimai:invoice:create \
    --user="${ADMIN_EMAIL}" \
    --project="${project_id}" \
    --start="${fixture_date}" \
    --end="${fixture_date}" \
    --template="${INVOICE_TEMPLATE}" \
    --env=prod
)"

if [[ "${invoice_create_output}" != *"12.34 USD"* ]]; then
  echo "error: Kimai invoice creation did not report the expected 12.34 USD total" >&2
  printf '%s\n' "${invoice_create_output}" >&2
  exit 1
fi

invoices_json="$(kimai_api GET "/api/invoices?customers%5B%5D=${customer_id}&size=10")"
if [[ "$(jq 'length' <<< "${invoices_json}")" != "1" ]]; then
  echo "error: expected exactly one disposable Kimai invoice" >&2
  exit 1
fi

invoice_id="$(jq -er '.[0].id | numbers' <<< "${invoices_json}")"
invoice_number="$(jq -er '.[0].invoiceNumber | strings' <<< "${invoices_json}")"
invoice_total="$(jq -er '.[0].total | numbers' <<< "${invoices_json}")"
invoice_currency="$(jq -er '.[0].currency | strings' <<< "${invoices_json}")"
invoice_status="$(jq -er '.[0].status | strings' <<< "${invoices_json}")"
readonly invoice_id invoice_number

if [[ "${invoice_total}" != "12.34" || "${invoice_currency^^}" != "USD" || "${invoice_status}" == "paid" ]]; then
  echo "error: disposable invoice identity, money, or initial paid state is incorrect" >&2
  exit 1
fi

link_json="$(
  compose exec --no-TTY kimai php "${EVIDENCE_HELPER}" \
    locate "${invoice_number}" "${EXPECTED_MINOR_UNITS}" "${EXPECTED_CURRENCY}"
)"
payment_link_url="$(jq -er '.url | strings' <<< "${link_json}")"

cat << EOF

======================================================================
ACTION REQUIRED: complete one Stripe-hosted TEST payment now.

${payment_link_url}

Use Stripe's successful test card:
  Card number: 4242 4242 4242 4242
  Expiration: any future date
  CVC: any three digits
  Postal code: any valid value if requested

This is Stripe test mode. No live charge can be created by this E2E harness.
The job will wait for the real Checkout Session / PaymentIntent / Charge chain.
======================================================================

EOF

compose exec --no-TTY kimai php "${EVIDENCE_HELPER}" \
  wait "${invoice_number}" "${EXPECTED_MINOR_UNITS}" "${EXPECTED_CURRENCY}"

invoice_before_reconcile="$(kimai_api GET "/api/invoices/${invoice_id}")"
if [[ "$(jq -er '.status | strings' <<< "${invoice_before_reconcile}")" == "paid" ]]; then
  echo "error: Kimai became paid before connector reconciliation ran" >&2
  exit 1
fi

reconciliation_dry_run="$(
  compose exec --no-TTY kimai \
    "${KIMAI_CONSOLE}" kimai:stripe:reconcile --dry-run --env=prod
)"
if [[ "${reconciliation_dry_run}" != *"[SUCCESS] invoice=${invoice_number} would_change=yes changed=no"* ]]; then
  echo "error: reconciliation dry-run did not prove the expected paid transition" >&2
  printf '%s\n' "${reconciliation_dry_run}" >&2
  exit 1
fi

reconciliation_output="$(
  compose exec --no-TTY kimai \
    "${KIMAI_CONSOLE}" kimai:stripe:reconcile --env=prod
)"
if [[ "${reconciliation_output}" != *"[SUCCESS] invoice=${invoice_number} would_change=yes changed=yes"* ]]; then
  echo "error: reconciliation did not apply the exact Kimai paid transition" >&2
  printf '%s\n' "${reconciliation_output}" >&2
  exit 1
fi

invoice_after_reconcile="$(kimai_api GET "/api/invoices/${invoice_id}")"
if [[ "$(jq -er '.status | strings' <<< "${invoice_after_reconcile}")" != "paid" ]]; then
  echo "error: reconciled Kimai invoice is not paid" >&2
  exit 1
fi
if [[ "$(jq -r '.paymentDate // empty' <<< "${invoice_after_reconcile}")" == "" ]]; then
  echo "error: reconciled Kimai invoice has no Stripe-derived payment date" >&2
  exit 1
fi

reconciliation_second_run="$(
  compose exec --no-TTY kimai \
    "${KIMAI_CONSOLE}" kimai:stripe:reconcile --env=prod
)"
if [[ "${reconciliation_second_run}" != *"[NO_CHANGE] invoice=${invoice_number} would_change=no changed=no"* ]]; then
  echo "error: reconciliation rerun was not idempotent" >&2
  printf '%s\n' "${reconciliation_second_run}" >&2
  exit 1
fi

printf 'Hosted Stripe payment E2E passed for Kimai invoice %s.\n' "${invoice_number}"
