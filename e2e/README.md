# Playwright E2E suite

Run the whole suite from the repo root:

    npm run test:e2e

`playwright.config.ts` runs two Chromium projects against two independently
configured `php -S` instances, both started by `global-setup.ts` and stopped
by `global-teardown.ts`:

- **main** (`http://localhost:8231`, project `main`) has an admin key and
  invite already configured; every spec except the setup wizard runs here.
- **setup** (`http://localhost:8232`, project `setup`) boots with no admin
  key, i.e. into the first-run wizard; only `specs/setup-wizard.spec.ts` runs
  here (`playwright.config.ts` routes it there by filename).

Both instances get their own SQLite file and `config.php` under the
gitignored `e2e/.runtime/`, wiped fresh on every run. State between tests is
reset via `helpers/test-api.ts` (`TestApi.reset()`), not by restarting the
server. `helpers/env.ts` centralizes ports, the test token, and every
generated path; override ports with `E2E_PORT_MAIN` / `E2E_PORT_SETUP`.

See `TESTPLAN.md` in this directory for the authoritative spec-by-spec plan,
conventions (no `waitForTimeout`, exact money formatting, etc.), and the
WebAuthn virtual-authenticator setup used by `helpers/webauthn.ts`.
