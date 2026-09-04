#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
readonly REPOSITORY_ROOT
readonly COMPOSE_FILE="${SCRIPT_DIR}/compose.yaml"
readonly PROJECT_NAME="kimai-stripe-connector-e2e-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-$$}"
readonly PLUGIN_NAME="KimaiStripeConnectorBundle"
readonly PLUGIN_PATH="/opt/kimai/var/plugins/${PLUGIN_NAME}"

compose() {
  docker compose --project-name "${PROJECT_NAME}" --file "${COMPOSE_FILE}" "$@"
}

diagnostics() {
  local kimai_container

  echo "E2E environment failed; preserving diagnostics before teardown." >&2
  compose ps >&2 || true

  kimai_container="$(compose ps --quiet kimai 2>/dev/null || true)"
  if [[ -n "${kimai_container}" ]]; then
    echo "Kimai container health state:" >&2
    docker inspect --format '{{json .State.Health}}' "${kimai_container}" >&2 || true
  fi

  echo "Recent Kimai and database logs:" >&2
  compose logs --no-color --tail 200 kimai sqldb >&2 || true
}

cleanup() {
  compose down --volumes --remove-orphans >/dev/null 2>&1 || true
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

if [[ ! -f "${REPOSITORY_ROOT}/vendor/autoload.php" ]]; then
  echo "error: plugin-local Composer dependencies are missing; run composer install first" >&2
  exit 1
fi

cleanup

compose up --detach --wait --wait-timeout 240

# Install the repository exactly where Kimai expects a normal plugin.  The named
# plugin volume belongs only to this Compose project and is destroyed by cleanup.
compose exec --user root --no-TTY kimai mkdir -p "${PLUGIN_PATH}"
compose cp "${REPOSITORY_ROOT}/." "kimai:${PLUGIN_PATH}"
compose exec --no-TTY kimai bin/console kimai:reload --env=prod

compose exec --no-TTY kimai php -r '
require "/opt/kimai/vendor/autoload.php";
exit(class_exists("KimaiPlugin\\KimaiStripeConnectorBundle\\KimaiStripeConnectorBundle") ? 0 : 1);
'

compose exec --no-TTY kimai bin/console kimai:version

# Verify that the plugin can be removed cleanly and that a fresh Kimai CLI process
# no longer resolves the bundle afterwards.
compose exec --user root --no-TTY kimai rm -rf "${PLUGIN_PATH}"
compose exec --no-TTY kimai bin/console kimai:reload --env=prod

compose exec --no-TTY kimai php -r '
require "/opt/kimai/vendor/autoload.php";
exit(class_exists("KimaiPlugin\\KimaiStripeConnectorBundle\\KimaiStripeConnectorBundle") ? 1 : 0);
'

# cleanup runs through the EXIT trap and removes containers, networks, and all
# named volumes, including the test database and installed-plugin volume.
