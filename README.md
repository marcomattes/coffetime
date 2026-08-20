# Coffee Time

Coffee Time is a small, installable PWA for a shared office coffee tab. People sign in with a passkey and track their own coffees. Names and individual totals remain private; only anonymous aggregate statistics are shared.

Composer resolves dependencies against PHP 8.2, matching the minimum runtime
and CI version. This prevents lock-file updates on newer development machines
from silently selecting packages that production cannot install.

## Features

- Passwordless, username-less WebAuthn authentication with discoverable passkeys.
- Server-authoritative coffee counter, undo, balances, anonymous ranking, streaks, PWA shortcuts, NFC links, and app badges.
- Names encrypted with the administrator's RSA public key; the server never receives the private key.
- An administrator screen that accepts a private-key file and decrypts names **locally with Web Crypto**. The file is neither uploaded nor persisted.
- An offline CLI and optional XLSX export for accounting.
- Plain PHP 8.2, SQLite, vanilla JavaScript, no frontend build pipeline.

## Quick start

```bash
composer install
cp config.example.php config.php
php tools/decrypt-users.php --genkey
# Copy admin-public.pem into adminPublicKey in config.php.
# Store admin-private.pem safely and never upload it to the server.
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 -t public
```

Open <http://localhost:8123>. Set `rpId` to the host without a port and `origin` to the complete origin. Production WebAuthn deployments require HTTPS.

## Configuration

`config.php` returns an array. Important settings are `priceCents`, `invite`, `admins` (user IDs as strings), `rpId`, `origin`, `dbPath`, `adminPublicKey`, and the secret `namePepper`. Keep the SQLite database and all private keys outside `public/`.

The public key must be a PEM-encoded RSA key of at least 4096 bits. New ciphertexts use RSA-OAEP (the SHA-1 OAEP profile supported by PHP OpenSSL and Web Crypto) and carry the `rsa-oaep-sha1:` prefix. Existing X25519 ciphertexts need to be exported with the earlier CLI before upgrading.

## Local administration

Add the account ID to `admins`, sign in, and select `admin-private.pem` in the Administration section. JavaScript imports it as a non-extractable Web Crypto key and decrypts API ciphertexts in memory. No request containing the key or plaintext names is made.

For a fully offline database export:

```bash
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin-private.pem --price 150
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin-private.pem --price 150 --xlsx ./coffee-time.xlsx
```

## Deployment

Point the web root at `public/`, run `composer install --no-dev`, make the database directory writable by PHP, configure HTTPS, and keep `testMode` disabled. Apache rewrite and deny rules are included. Migrations run automatically and are additive.

`./scripts/build-release.sh` assembles a production-only `deploy/` directory
with optimized Composer dependencies. GitHub Actions runs the same build after
the test suite and deploys pushes to `main` over FTPS. Configure the
`FTP_SERVER`, `FTP_USERNAME`, and `FTP_PASSWORD` repository secrets. The
optional `FTP_SERVER_DIR`, `FTP_PROTOCOL`, and `FTP_PORT` variables control the
destination. The workflow explicitly preserves the server-side `config.php`
and `data/` directory.

## Tests

```bash
php tests/CryptoTest.php
find public src tests tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

## Security and privacy

The database contains IDs, encrypted names, keyed name fingerprints, counters, balances, and passkey material—no email addresses or passwords. Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

## Contributing and license

Contributions are welcome; see [CONTRIBUTING.md](CONTRIBUTING.md). Coffee Time is released under the [MIT License](LICENSE).
