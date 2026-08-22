# ☕ Coffee Time

[![CI](https://github.com/marcomattes/coffetime/actions/workflows/ci.yml/badge.svg)](https://github.com/marcomattes/coffetime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777BB4.svg)](composer.json)

**A privacy-first, installable PWA for the shared office coffee tab.** Everyone signs in with a passkey and tracks their own coffees; names and individual totals stay private, and only anonymous aggregate statistics are ever shared. The server stores names exclusively as ciphertext it cannot decrypt — the admin's private key never leaves the admin's browser.

Built to run anywhere PHP runs: no framework, no Node runtime in production, SQLite by default. `composer install`, point a web root at `public/`, done.

## Highlights

**Authentication & accounts**
- Passwordless, username-less WebAuthn login with discoverable passkeys — no emails, no passwords stored.
- Device linking: a signed-in device generates a short-lived code to add a passkey on a new device.
- Admin-issued recovery codes for users who lost every device. Codes are single-use; only their hash is stored.
- The first account ever registered becomes administrator automatically.

**Privacy by construction**
- Names are encrypted in the browser-facing API with the administrator's RSA public key; the server holds no private key and no decryption code.
- The admin screen decrypts names **locally with Web Crypto** from a selected private-key file (never uploaded, never persisted), including CSV export.
- Rankings are anonymous; each user sees only their own totals and history.

**Bookkeeping that holds up**
- Server-authoritative counter with undo, balances, streaks, and a personal 28-day history with chart.
- Every booking freezes the price at booking time — later price changes never reprice existing coffees.
- Admin payments settle tabs; an offline CLI (with optional XLSX export) covers accounting.

**Offline-capable PWA**
- Installable app with shortcuts, NFC links, and app badges.
- Offline booking queue: coffees booked without a connection are queued on the device and retried with an idempotent event ID — never double-counted.
- Local payment reminders without a push server: an opt-in month-end notification while the tab is open, plus an admin "Remind" button. No VAPID keys, no subscriptions, no third party.

**Operations**
- First-run setup wizard: the admin keypair is generated in the browser, the private key is only offered for download, and price/invite code are stored without touching `config.php`.
- In-app admin settings for price and invite code at runtime.
- SQLite by default, optional MySQL/MariaDB; automatic, additive migrations on both.
- TypeScript frontend compiled to plain JavaScript that the PHP server ships as static files.

## Quick start

```bash
composer install
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 -t public
```

Open <http://localhost:8123> and follow the setup wizard: it generates the admin RSA keypair in your browser, has you download the private key, and asks for a price and invite code. The first account you register afterwards becomes administrator. No manual `config.php` editing is required to get started.

Once you deploy beyond localhost, set `rpId` to the host without a port and `origin` to the complete origin. Production WebAuthn deployments require HTTPS.

### Manual configuration (advanced / production)

The wizard is optional. For a scripted or production deployment, copy `config.example.php` to `config.php` and fill it in ahead of time, still using the offline keygen:

```bash
cp config.example.php config.php
php tools/decrypt-users.php --genkey
# Copy admin-public.pem into adminPublicKey in config.php.
# Store admin-private.pem safely and never upload it to the server.
```

If `config.php` already sets `adminPublicKey`, the setup wizard is skipped. Once any of `priceCents`, `invite`, `adminPublicKey`, or `namePepper` has been set through the app (wizard or settings screen), the stored value takes precedence over `config.php` on every later request — see [Settings precedence](#settings-precedence).

## Configuration

`config.php` returns an array. Important settings are `priceCents`, `invite`, `admins` (user IDs as strings), `rpId`, `origin`, `dbPath` (or `db`, see [Database](#database)), `adminPublicKey`, and the secret `namePepper`. Keep the SQLite database and all private keys outside `public/`.

The public key must be a PEM-encoded RSA key of at least 4096 bits. New ciphertexts use RSA-OAEP (the SHA-1 OAEP profile supported by PHP OpenSSL and Web Crypto) and carry the `rsa-oaep-sha1:` prefix. Existing X25519 ciphertexts need to be exported with the earlier CLI before upgrading.

### Settings precedence

`priceCents`, `invite`, `adminPublicKey`, and `namePepper` can each be set two ways: in `config.php`, or at runtime through the app (the setup wizard, or the admin settings screen for price/invite). Whichever a database `settings` row exists for wins over `config.php`; if no row exists, the `config.php` value (or built-in default) applies. Connection and bootstrap values — `rpId`, `origin`, `dbPath`/`db`, `admins`, `testMode`, `testToken` — are read from `config.php` only.

### Database

SQLite is the default: set `dbPath` to a writable file outside `public/`. To use MySQL or MariaDB instead, add a `db` block:

```php
'db' => [
    'driver' => 'mysql',
    'host' => '127.0.0.1',
    'port' => 3306,
    'database' => 'coffee',
    'user' => 'coffee',
    'password' => 'change-me',
    'charset' => 'utf8mb4',
],
```

An invalid or incomplete `db` block falls back to SQLite. Schema migrations run automatically on both drivers. The offline CLI export (`tools/decrypt-users.php`) reads the SQLite file directly and does not support MySQL; the in-app admin export (decrypt-and-download in the browser) works with either driver, since it goes through the API.

## Administration

Add the account ID to `admins` (or rely on the first-registered-user rule), sign in, and select `admin-private.pem` in the Administration section. JavaScript imports it as a non-extractable Web Crypto key and decrypts API ciphertexts in memory. No request containing the key or plaintext names is ever made. From the same screen an administrator can book a payment against a user's tab and export the decrypted roster as CSV, both without the key leaving the browser.

For a fully offline database export (SQLite only):

```bash
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin-private.pem --price 150
php tools/decrypt-users.php --db ./coffee.sqlite --key ./admin-private.pem --price 150 --xlsx ./coffee-time.xlsx
```

### Devices and recovery

A signed-in device can generate a link code (valid 15 minutes) to add a passkey on a new device to the same account. An administrator can generate a longer-lived recovery code (60 minutes) for a user who lost every device. Codes are single-use; only their hash is stored, never the plaintext.

## Offline use

The service worker caches the app shell for offline start. A coffee booked while offline is queued on the device and retried automatically once the connection returns; the server deduplicates by the booking's event ID, so a retried booking is never counted twice.

## Deployment

Point the web root at `public/`, run `composer install --no-dev`, make the database directory writable by PHP, configure HTTPS, and keep `testMode` disabled. Apache rewrite and deny rules are included. Migrations run automatically and are additive.

`./scripts/build-release.sh` assembles a production-only `deploy/` directory with optimized Composer dependencies. GitHub Actions runs the same build after the test suite and deploys pushes to `main` over FTPS. Configure the `FTP_SERVER`, `FTP_USERNAME`, and `FTP_PASSWORD` repository secrets. The optional `FTP_SERVER_DIR`, `FTP_PROTOCOL`, and `FTP_PORT` variables control the destination. The workflow explicitly preserves the server-side `config.php` and `data/` directory.

## Development

### Tests

```bash
php tests/run.php
find public src tests tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

`tests/run.php` runs every standalone `tests/*Test.php` script (unit coverage with no web server, an HTTP integration run against the PHP built-in server, and the encryption tests) and aggregates the results. Each script is also runnable on its own, e.g. `php tests/UnitTest.php`.

By default every test runs against its own throwaway SQLite file. Set `COFFEE_TEST_DB` to a JSON object to run the same suite against MySQL/MariaDB instead:

```bash
COFFEE_TEST_DB='{"driver":"mysql","host":"127.0.0.1","port":3306,"database":"coffee_test","user":"coffee","password":"coffee-pass"}' php tests/run.php
```

The configured database is dropped and recreated before each test phase, so the user in `COFFEE_TEST_DB` needs `DROP`/`CREATE` privileges on it.

End-to-end tests run with Playwright: `npm run test:e2e` (see [e2e/README.md](e2e/README.md)).

### Frontend build

The PWA frontend is written in TypeScript under `frontend/` (`app.ts`, `sw.ts`) and compiled to the plain scripts the server actually ships, `public/app.js` and `public/sw.js`. There is no Node runtime on the production host, so the compiled output is committed to the repository like any other static asset.

```bash
npm ci
npm run build
```

regenerates `public/app.js` and `public/sw.js` from `frontend/`. CI runs the same build and fails if it differs from what is committed, so a stale build never reaches production. Run `npm run check` for a type-check without writing files.

### Dependency policy

Composer resolves dependencies against PHP 8.2 (a `platform` pin in `composer.json`), matching the minimum runtime and CI version. This prevents lock-file updates on newer development machines from silently selecting packages that production cannot install.

### Architecture

Design decisions and invariants — price freezing, the migration model, reminder semantics, link-code lifecycle, the offline queue — are documented in [ARCHITECTURE.md](ARCHITECTURE.md).

## Security and privacy

The database contains IDs, encrypted names, keyed name fingerprints, counters, balances, and passkey material — no email addresses and no passwords. Please report vulnerabilities privately as described in [SECURITY.md](SECURITY.md).

### Backups

Back up the database (the SQLite file, or regular dumps for MySQL/MariaDB) and the admin private key. They protect different things: the database backup restores balances, counters, and history; only the private key can ever decrypt the encrypted names again. **A lost private key makes existing encrypted names permanently unrecoverable** — balances and counters are unaffected, but names are gone for good. Keep `testMode` disabled in production; it exposes reset/seed/clock endpoints guarded only by a shared token.

## Contributing and license

Contributions are welcome — see [CONTRIBUTING.md](CONTRIBUTING.md). Coffee Time is released under the [MIT License](LICENSE).
