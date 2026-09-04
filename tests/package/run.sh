#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=""
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

REPOSITORY_ROOT=""
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
readonly REPOSITORY_ROOT

readonly PLUGIN_NAME="KimaiStripeConnectorBundle"
readonly TEST_VERSION="package-test"

for command_name in composer php unzip sha256sum; do
  if ! command -v "${command_name}" > /dev/null 2>&1; then
    echo "error: ${command_name} is required to verify the plugin package" >&2
    exit 1
  fi
done

WORK_ROOT=""
WORK_ROOT="$(mktemp -d)"
readonly WORK_ROOT

cleanup() {
  rm -rf "${WORK_ROOT}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

readonly FIRST_OUTPUT="${WORK_ROOT}/first"
readonly SECOND_OUTPUT="${WORK_ROOT}/second"
mkdir -p "${FIRST_OUTPUT}" "${SECOND_OUTPUT}"

VERSION="${TEST_VERSION}" OUTPUT_DIR="${FIRST_OUTPUT}" \
  bash "${REPOSITORY_ROOT}/tools/package.sh"
VERSION="${TEST_VERSION}" OUTPUT_DIR="${SECOND_OUTPUT}" \
  bash "${REPOSITORY_ROOT}/tools/package.sh"

readonly ARCHIVE_NAME="${PLUGIN_NAME}-${TEST_VERSION}.zip"
readonly FIRST_ARCHIVE="${FIRST_OUTPUT}/${ARCHIVE_NAME}"
readonly SECOND_ARCHIVE="${SECOND_OUTPUT}/${ARCHIVE_NAME}"

cmp "${FIRST_ARCHIVE}" "${SECOND_ARCHIVE}"

(
  cd -- "${FIRST_OUTPUT}"
  sha256sum --check "${ARCHIVE_NAME}.sha256"
)

readonly EXTRACT_ROOT="${WORK_ROOT}/extracted"
mkdir -p "${EXTRACT_ROOT}"
unzip -q "${FIRST_ARCHIVE}" -d "${EXTRACT_ROOT}"

readonly PLUGIN_ROOT="${EXTRACT_ROOT}/${PLUGIN_NAME}"

required_paths=(
  KimaiStripeConnectorBundle.php
  composer.json
  composer.lock
  README.md
  LICENSE
  doc/INSTALL.md
  Resources/config/services.yaml
  vendor/autoload.php
)

for path in "${required_paths[@]}"; do
  if [[ ! -e "${PLUGIN_ROOT}/${path}" ]]; then
    echo "error: package is missing required path: ${path}" >&2
    exit 1
  fi
done

for forbidden_path in \
  tests \
  .github \
  tools \
  Makefile \
  Doxyfile \
  phpcs.xml \
  phpstan.neon.dist \
  phpunit.xml.dist \
  vendor/phpunit \
  vendor/phpstan \
  vendor/friendsofphp \
  vendor/squizlabs; do
  if [[ -e "${PLUGIN_ROOT}/${forbidden_path}" ]]; then
    echo "error: package contains forbidden development path: ${forbidden_path}" >&2
    exit 1
  fi
done

composer validate --strict --working-dir="${PLUGIN_ROOT}"

php -r \
  'require $argv[1]; exit(class_exists("Stripe\\StripeClient") ? 0 : 1);' \
  "${PLUGIN_ROOT}/vendor/autoload.php"

printf 'Package contract verified: %s\n' "${ARCHIVE_NAME}"
