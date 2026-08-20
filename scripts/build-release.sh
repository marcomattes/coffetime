#!/usr/bin/env bash

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OUTPUT="${ROOT}/deploy"

cd "${ROOT}"

# Resolve exactly the versions in composer.lock and omit development packages.
composer install \
  --no-dev \
  --no-interaction \
  --no-progress \
  --prefer-dist \
  --optimize-autoloader

rm -rf "${OUTPUT}"
mkdir -p "${OUTPUT}"

# Keep the same directory layout used by both supported Apache configurations.
cp -R public src tools vendor "${OUTPUT}/"
cp .htaccess composer.json composer.lock config.example.php LICENSE "${OUTPUT}/"

# Fail before deployment if required runtime files or rewrite rules are missing.
required=(
  .htaccess
  public/.htaccess
  public/index.php
  src/Bootstrap.php
  vendor/autoload.php
  config.example.php
)
for file in "${required[@]}"; do
  if [[ ! -f "${OUTPUT}/${file}" ]]; then
    printf 'Release is missing required file: %s\n' "${file}" >&2
    exit 1
  fi
done

printf 'Production release assembled in %s\n' "${OUTPUT}"
