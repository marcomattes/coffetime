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

# data/ is gitignored, so its deny rule exists nowhere in the source tree and
# would never reach a server. The directory holds the SQLite database and the
# first-run setup token; ship the guard with the bundle rather than relying on
# Db::pdo() to write one at runtime, which it only does if it finds the
# directory missing.
mkdir -p "${OUTPUT}/data"
cat > "${OUTPUT}/data/.htaccess" <<'HTACCESS'
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
HTACCESS

# Fail before deployment if required runtime files or rewrite rules are missing.
required=(
  .htaccess
  public/.htaccess
  public/index.php
  src/Bootstrap.php
  vendor/autoload.php
  config.example.php
  data/.htaccess
)
for file in "${required[@]}"; do
  if [[ ! -f "${OUTPUT}/${file}" ]]; then
    printf 'Release is missing required file: %s\n' "${file}" >&2
    exit 1
  fi
done

# The deployed tree is an FTP mirror with no .git, so the commit it was built
# from is written into the bundle. src/ is denied over HTTP by its own
# .htaccess; the value reaches the browser through GET /api/version.
BUILD_COMMIT="$(git -C "${ROOT}" rev-parse --short=7 HEAD 2>/dev/null || echo '')"
BUILD_AT="$(date -u +%s)"
if [[ -z "${BUILD_COMMIT}" ]]; then
  printf 'Refusing to build: not a git checkout, so the release would carry no build id.\n' >&2
  exit 1
fi
if [[ -n "$(git -C "${ROOT}" status --porcelain 2>/dev/null)" ]]; then
  printf 'Warning: the working tree is dirty; %s does not describe it exactly.\n' "${BUILD_COMMIT}" >&2
fi
printf '{"version":"%s","builtAt":%s}\n' "${BUILD_COMMIT}" "${BUILD_AT}" > "${OUTPUT}/src/build.json"

printf 'Production release assembled in %s (build %s)\n' "${OUTPUT}" "${BUILD_COMMIT}"
