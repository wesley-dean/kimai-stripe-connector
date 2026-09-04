#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=""
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

REPOSITORY_ROOT=""
REPOSITORY_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly REPOSITORY_ROOT

readonly COMPOSE_FILE="${SCRIPT_DIR}/compose.yaml"
readonly PROJECT_NAME="kimai-stripe-connector-e2e-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-$$}"
readonly PLUGIN_NAME="KimaiStripeConnectorBundle"
readonly PLUGIN_PATH="/opt/kimai/var/plugins/${PLUGIN_NAME}"
readonly KIMAI_CONSOLE="/opt/kimai/bin/console"

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

PLUGIN_STAGE=""

compose() {
  docker compose --project-name "${PROJECT_NAME}" --file "${COMPOSE_FILE}" "$@"
}

diagnostics() {
  local kimai_container

  echo "E2E environment failed; preserving diagnostics before teardown." >&2
  compose ps >&2 || true

  kimai_container="$(compose ps --quiet kimai 2> /dev/null || true)"
  if [[ -n "${kimai_container}" ]]; then
    echo "Kimai container health state:" >&2
    docker inspect --format '{{json .State.Health}}' "${kimai_container}" >&2 || true
  fi

  echo "Recent Kimai and database logs:" >&2
  compose logs --no-color --tail 200 kimai sqldb >&2 || true
}

cleanup() {
  if [[ -n "${PLUGIN_STAGE}" && -d "${PLUGIN_STAGE}" ]]; then
    rm -rf "${PLUGIN_STAGE}"
  fi

  compose down --volumes --remove-orphans > /dev/null 2>&1 || true
}

on_exit() {
  local status=$?

  trap - EXIT INT TERM
  if ((status != 0)); then
    diagnostics
  fi
  cleanup
  exit "${status}"
}

trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

if ! command -v composer > /dev/null 2>&1; then
  echo "error: composer is required to stage plugin runtime dependencies" >&2
  exit 1
fi

cleanup

PLUGIN_STAGE="$(mktemp -d)"
readonly PLUGIN_STAGE

# Build an isolated plugin tree with the production dependency graph from the
# committed lockfile. The developer checkout may contain PHPUnit, PHPStan,
# Symfony, or other require-dev packages under vendor/; copying that tree into
# Kimai would test a development workspace rather than the self-contained runtime
# artifact required by ADR-012 and can collide with Kimai's own dependencies.
# Test source/stubs are also excluded so the staged tree models the production
# plugin rather than merely relying on --no-dev to keep them out of the autoloader.
tar \
  -C "${REPOSITORY_ROOT}" \
  --exclude='./.git' \
  --exclude='./vendor' \
  --exclude='./tests' \
  --exclude='./build' \
  --exclude='./coverage' \
  --exclude='./dist' \
  --exclude='./doc/reference' \
  -cf - . | tar -C "${PLUGIN_STAGE}" -xf -

if [[ -e "${PLUGIN_STAGE}/tests" ]]; then
  echo "error: production-shaped plugin stage contains test artifacts" >&2
  exit 1
fi

composer install \
  --working-dir="${PLUGIN_STAGE}" \
  --no-dev \
  --no-interaction \
  --prefer-dist \
  --optimize-autoloader

if [[ ! -f "${PLUGIN_STAGE}/vendor/autoload.php" ]]; then
  echo "error: staged plugin runtime dependencies are missing" >&2
  exit 1
fi

compose up --detach --wait --wait-timeout 240

# Install the staged plugin exactly where Kimai expects a normal plugin. The named
# plugin volume belongs only to this Compose project and is destroyed by cleanup.
compose exec --user root --no-TTY kimai mkdir -p "${PLUGIN_PATH}"
compose cp "${PLUGIN_STAGE}/." "kimai:${PLUGIN_PATH}"
compose exec --no-TTY kimai "${KIMAI_CONSOLE}" kimai:reload --env=prod

compose exec --no-TTY kimai php -r '
require "/opt/kimai/vendor/autoload.php";
exit(class_exists("KimaiPlugin\\KimaiStripeConnectorBundle\\KimaiStripeConnectorBundle") ? 0 : 1);
'

# Run the real Phase 4 command through Kimai's production service container while
# the connector is disabled by default. This proves command registration and
# autowiring of the Kimai repository/service dependencies without requiring a
# Stripe credential or performing any Stripe access or financial mutation.
reconciliation_output="$(
  compose exec --no-TTY kimai \
    "${KIMAI_CONSOLE}" kimai:stripe:reconcile --dry-run --env=prod
)"
if [[ "${reconciliation_output}" != *"[NO_CHANGE]"* ]]; then
  echo "error: disabled reconciliation command did not report NO_CHANGE" >&2
  printf '%s\n' "${reconciliation_output}" >&2
  exit 1
fi

compose exec --no-TTY kimai "${KIMAI_CONSOLE}" kimai:version

# Verify that the plugin can be removed cleanly and that a fresh Kimai CLI process
# no longer resolves the bundle afterwards.
compose exec --user root --no-TTY kimai rm -rf "${PLUGIN_PATH}"
compose exec --no-TTY kimai "${KIMAI_CONSOLE}" kimai:reload --env=prod

compose exec --no-TTY kimai php -r '
require "/opt/kimai/vendor/autoload.php";
exit(class_exists("KimaiPlugin\\KimaiStripeConnectorBundle\\KimaiStripeConnectorBundle") ? 1 : 0);
'

# cleanup runs through the EXIT trap and removes containers, networks, the staging
# directory, and all named volumes, including the test database and plugin volume.
