#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR=""
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

REPOSITORY_ROOT=""
REPOSITORY_ROOT="$(cd -- "${SCRIPT_DIR}/.." && pwd)"
readonly REPOSITORY_ROOT

readonly PLUGIN_NAME="KimaiStripeConnectorBundle"
readonly VERSION="${VERSION:-dev}"

OUTPUT_DIR="${OUTPUT_DIR:-${REPOSITORY_ROOT}/dist}"
mkdir -p "${OUTPUT_DIR}"
OUTPUT_DIR="$(cd -- "${OUTPUT_DIR}" && pwd)"
readonly OUTPUT_DIR

case "${VERSION}" in
  '' | *[!A-Za-z0-9._-]*)
    echo "error: VERSION must contain only letters, digits, dot, underscore, or hyphen" >&2
    exit 1
    ;;
esac

for command_name in composer zip; do
  if ! command -v "${command_name}" > /dev/null 2>&1; then
    echo "error: ${command_name} is required to build the plugin package" >&2
    exit 1
  fi
done

BUILD_ROOT=""
BUILD_ROOT="$(mktemp -d)"
readonly BUILD_ROOT
readonly PLUGIN_ROOT="${BUILD_ROOT}/${PLUGIN_NAME}"
readonly ARCHIVE_NAME="${PLUGIN_NAME}-${VERSION}.zip"
readonly ARCHIVE_PATH="${OUTPUT_DIR}/${ARCHIVE_NAME}"

cleanup() {
  rm -rf "${BUILD_ROOT}"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

mkdir -p "${PLUGIN_ROOT}"

runtime_directories=(
  Cleanup
  Common
  DependencyInjection
  PaymentLink
  Reconciliation
  Resources
)

runtime_files=(
  KimaiStripeConnectorBundle.php
  composer.json
  composer.lock
  LICENSE
  README.md
)

for path in "${runtime_directories[@]}"; do
  cp -R -- "${REPOSITORY_ROOT}/${path}" "${PLUGIN_ROOT}/${path}"
done

for path in "${runtime_files[@]}"; do
  cp -- "${REPOSITORY_ROOT}/${path}" "${PLUGIN_ROOT}/${path}"
done

mkdir -p "${PLUGIN_ROOT}/doc"
cp -- "${REPOSITORY_ROOT}/doc/INSTALL.md" "${PLUGIN_ROOT}/doc/INSTALL.md"

composer install \
  --working-dir="${PLUGIN_ROOT}" \
  --no-dev \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --optimize-autoloader

if [[ ! -f "${PLUGIN_ROOT}/vendor/autoload.php" ]]; then
  echo "error: production dependency installation did not create vendor/autoload.php" >&2
  exit 1
fi

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
    echo "error: package contains development-only path: ${forbidden_path}" >&2
    exit 1
  fi
done

# Normalize permissions and timestamps before archiving so two builds from the
# same source/dependency graph have the same ZIP metadata as well as file content.
find "${PLUGIN_ROOT}" -type d -exec chmod 0755 {} +
find "${PLUGIN_ROOT}" -type f -exec chmod 0644 {} +
find "${PLUGIN_ROOT}" -exec touch -h -t 198001010000.00 {} +

rm -f "${ARCHIVE_PATH}" "${ARCHIVE_PATH}.sha256"

(
  cd -- "${BUILD_ROOT}"
  export LC_ALL=C
  export TZ=UTC
  find "${PLUGIN_NAME}" \( -type f -o -type l \) -print \
    | LC_ALL=C sort \
    | zip -X -q -y "${ARCHIVE_PATH}" -@
)

(
  cd -- "${OUTPUT_DIR}"
  sha256sum "${ARCHIVE_NAME}" > "${ARCHIVE_NAME}.sha256"
)

printf 'Built %s\n' "${ARCHIVE_PATH}"
printf 'Checksum %s.sha256\n' "${ARCHIVE_PATH}"
