# Contributing

Thank you for contributing to Coffee Time. This document covers how to set up
a development environment, run the checks CI runs, and what to include in a
pull request.

## Development environment

- PHP 8.2 or newer, with the `openssl` and `pdo_sqlite` extensions.
- [Composer](https://getcomposer.org/).
- Node.js (Node 22 is used in CI) for the frontend build, if you touch
  anything under `frontend/`.

Set up the repository:

```bash
composer install
cp config.example.php config.php
php tools/decrypt-users.php --genkey
# Copy admin-public.pem into adminPublicKey in config.php.
# Keep admin-private.pem out of the repository.
```

Run the app locally:

```bash
PHP_CLI_SERVER_WORKERS=4 php -S 127.0.0.1:8123 -t public
```

Then open <http://localhost:8123>. See the README quick start for the setup
wizard flow if you would rather generate the admin keypair in the browser
instead of via `config.php`.

## Backend checks

Run the PHP test suite and lint before submitting a pull request:

```bash
php tests/run.php
find public src tests tools -name '*.php' -print0 | xargs -0 -n1 php -l
```

`tests/run.php` runs every standalone `tests/*Test.php` script (unit
coverage, an HTTP integration run against the PHP built-in server, and the
encryption tests). Each script is also runnable on its own, e.g.
`php tests/UnitTest.php`. By default every test runs against its own
throwaway SQLite file; see the README for running the suite against
MySQL/MariaDB via `COFFEE_TEST_DB`.

If your change affects Composer dependencies, also run
`composer validate --strict --no-check-publish`, as CI does.

## Frontend build

The PWA frontend is TypeScript under `frontend/` (`app.ts`, `sw.ts`),
compiled to `public/app.js` and `public/sw.js`. **These two compiled files
are committed to the repository** — there is no Node runtime in production,
so the server ships the checked-in build output directly.

If you change anything under `frontend/`, you must regenerate and commit the
build:

```bash
npm ci
npm run build
```

`npm run check` runs the TypeScript type-check without writing files, useful
while iterating. CI rebuilds the frontend and fails with a diff if
`public/app.js` or `public/sw.js` do not match what your commit built —
always run `npm run build` and include the resulting changes in your pull
request.

## End-to-end tests

The Playwright suite exercises the app through a browser:

```bash
npm ci
npx playwright install --with-deps chromium
npm run test:e2e
```

## Pull requests

- Open an issue before starting large or behavior-changing work.
- Keep pull requests focused on a single change; avoid bundling unrelated
  refactors.
- Write commit messages in English, imperative mood (e.g. "Add", not
  "Added" or "Adds"), and explain *why* for any change that isn't
  self-evident from the diff.
- Describe the behavior change and any security or privacy implications in
  the pull request description (this project handles WebAuthn credentials
  and encrypted personal data, so this matters even for small changes).
- Make sure `php tests/run.php`, the PHP lint, and — if `frontend/` changed —
  `npm run build` all pass and are reflected in your commit before opening
  the pull request.
- Never commit private keys (e.g. `admin-private.pem`), `config.php`,
  database files, or any other local secrets.

By participating, you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).
