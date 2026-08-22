# Playwright end-to-end test plan

This document is the authoritative plan for the Playwright suite under `e2e/`.
Every spec file below lists its test cases with the expected, server-authoritative
behavior. The suite runs against the real PHP application (built-in server), real
SQLite databases, and real WebAuthn ceremonies via Chromium's virtual
authenticator (CDP) — nothing in the app is mocked.

## Infrastructure

- **Runner**: `@playwright/test`, Chromium only (the WebAuthn virtual authenticator
  is CDP-based). `npx playwright test` from the repo root; config in
  `playwright.config.ts`. `workers: 1`, no full parallelism — all tests share one
  server + database and reset state via the test API.
- **Servers**: `global-setup.ts` spawns two `php -S` instances (and
  `global-teardown.ts` stops them), each with its own generated config selected
  via `COFFEE_CONFIG_PATH` and its own SQLite file under `e2e/.runtime/`
  (gitignored):
  - **main** (`http://localhost:8231`, override `E2E_PORT_MAIN`): configured
    `adminPublicKey` (RSA-4096 keypair generated in global setup, PEMs stored in
    `e2e/.runtime/`), `namePepper`, `invite: TEST-INVITE`, `priceCents: 150`,
    `testMode: true` + token, `rpId: localhost`, `origin` matching the port.
    Used by every spec except the setup wizard.
  - **setup** (`http://localhost:8232`, override `E2E_PORT_SETUP`): empty
    `adminPublicKey`/`namePepper`, so the instance boots into the first-run
    wizard. The wizard writes settings to the DB, and `/api/test/reset` does
    **not** clear the `settings` table — so the wizard spec resets by deleting
    this instance's SQLite file (migrations recreate it on the next request).
- **State control**: helper `TestApi` wraps `/api/test/*` (guarded by
  `X-Test-Token`): `reset()`, `seed(users)`, `state()`, `clock(offsetSeconds)`,
  `loginAs(page, userId)`. `loginAs` posts `/api/test/login` through
  `page.request` so the session cookie lands in the browser context.
  `reset()` also resets the clock offset to 0 — a leaked offset must never leak
  into the next test.
- **WebAuthn**: helper creates a CDP virtual authenticator per page
  (`protocol: ctap2`, `transport: internal`, `hasResidentKey: true`,
  `hasUserVerification: true`, `isUserVerified: true`,
  `automaticPresenceSimulation: true`) — this satisfies the app's discoverable
  credential + user-verification requirements. `http://localhost` is a secure
  context, so no HTTPS is needed.
- **Selectors**: the shell and the frontend already carry `data-testid`
  attributes everywhere; tests use `page.getByTestId(...)` exclusively (plus
  role/text queries where a testid does not exist, e.g. leaderboard rows).
- **CI**: a `test-e2e` job in `.github/workflows/ci.yml` (composer install,
  npm ci, install Chromium, run the suite, upload the HTML report on failure).

## Spec files and test cases

### 1. `setup-wizard.spec.ts` (setup instance, serial)

1. A fresh instance shows the setup view; auth and app views stay hidden.
2. "Generate key & download" downloads `admin-private.pem` (a PKCS#8
   `PRIVATE KEY` PEM) and enables "Finish setup".
3. Client-side validation: invalid price shows `invalid_price`; a too-short
   invite shows `invalid_invite` — no request leaves the page in these cases.
   Finishing without a generated key is impossible through the UI: the button
   ships `disabled` and only a successful key generation enables it, so the
   suite asserts exactly that guarantee (button stays disabled, zero
   `/api/setup/init` requests). The "Generate the key first." branch in
   `initSetup()` is unreachable defensive code.
4. Finish setup (price 2.00 €, invite `WIZARD-INVITE`) switches to the auth
   view; `/api/setup/status` now reports `needsSetup: false`.
5. A second `/api/setup/init` returns 409 `already_initialized`.
6. The first account registered after setup becomes administrator (admin view
   visible; `/api/test/state` reports `admin: true`); price shown is the wizard
   price. A second registered account is not an administrator.

### 2. `registration.spec.ts` (main instance)

1. Happy path: valid names + invite → passkey created via virtual
   authenticator → app view with counter 0, price 1.50 €, "1 passkey".
2. Wrong invite → `invalid_invite` in the auth error; no user created.
3. Empty first or last name → `invalid_name`.
4. Duplicate name (same normalized name seeded first) → `name_taken`.
5. The session survives a reload (still in the app view).
6. Sign out returns to the auth view; `/api/me` afterwards is 401.

### 3. `login.spec.ts` (main instance)

1. Register → sign out → "Sign in with passkey" signs back into the same
   account (coffee count preserved through the round trip).
2. Sign-in with an authenticator that has no matching credential fails and
   shows an error; the user stays on the auth view.

### 4. `booking.spec.ts` (main instance, seeded user + test login)

1. "Take a coffee" increments the counter to 1, outstanding becomes 1.50 €,
   "Today" and "All coffees" show 1; `/api/test/state` confirms one coffee.
2. Three bookings aggregate (counter 3, balance 4.50 €).
3. Undo reverts counter and balance; server state confirms.
4. Undo at zero is a no-op: counter stays 0, balance stays 0.00 €, no error.
5. Price freeze: book at 150, admin changes the price to 200 (via settings
   API), book again → balance 3.50 €; undo removes the *last* event → balance
   1.50 € (the frozen 200 is refunded, not the current price twice).
6. A seeded starting balance (`paidCents`) is reflected: coffees 4 × 150 with
   300 paid → outstanding 3.00 €.

### 5. `stats.spec.ts` (main instance)

1. Leaderboard: three seeded users (7/4/1 coffees) → ranks 1–3 with correct
   totals, the signed-in user's row is marked "(me)", "All coffees" is 12,
   "My rank" matches.
2. Anonymity: no leaderboard entry contains a name — only "Rank N" and counts.
3. History: after two bookings today, the chart renders 14 day columns and
   today's column reports 2 coffees (aria-label), "Today" stat shows 2.
4. Streak: a booking yesterday (clock offset −1 day) plus one today → the
   streak line shows "2 days in a row". A single-day streak stays hidden.

### 6. `offline-queue.spec.ts` (main instance)

1. Offline booking queues: `context.setOffline(true)` → tap → hint "1 booking
   waiting for connection"; server still reports 0 coffees.
2. Back online (plus a dispatched `online` event) flushes the queue: counter 1,
   hint hidden, server reports exactly 1 coffee (no duplicates from retries).
3. Multiple offline taps queue and flush completely (3 offline → 3 booked).
4. The queue survives a reload while offline (service worker serves the shell;
   the hint still shows the pending count) and flushes after going online.

### 7. `device-link.spec.ts` (main instance, two browser contexts)

1. "Link another device" shows a code on device A; device B enters it and ends
   up signed into the same account (same counter); the passkey count reaches 2.
2. An invalid code on device B shows `invalid_code`.
3. A used code cannot be used again (second attempt fails).
4. An expired code (clock offset +16 min) fails with `invalid_code`.

### 8. `admin.spec.ts` (main instance)

1. A non-admin user does not see the admin view.
2. The admin (config `admins` entry via test seed order — first registered user
   is flagged) sees the settings card, the user rows with `rsa-oaep-sha1:`
   ciphertexts, and a correct totals line.
3. Record payment (2.00 € against a user with a 3.00 € tab) reduces the row's
   outstanding to 1.00 €; server state shows `paidCents` 200. An invalid amount
   (0, negative) shows `invalid_amount` without a request.
4. Settings: saving price 2.50 € + invite `NEW-INVITE` shows the saved status;
   the app price stat updates; registration with the old invite now fails and
   with the new invite succeeds. (The spec restores the defaults afterwards.)
5. Recovery code: the admin generates a code for another user; a fresh context
   redeems it under "Link this device" and is signed into *that* user's account.
6. Local decryption: selecting the matching `admin-private.pem` decrypts the
   seeded names in the rows ("Names decrypted locally…" status); a non-matching
   key file shows the failure status and leaves ciphertexts visible.
7. CSV export downloads `coffee-time.csv`; with the key loaded it contains the
   decrypted names and per-user balances.
8. XSS safety: a seeded name containing `<img src=x onerror=…>` renders as
   text after decryption — no `img` element appears in the admin rows.

### 9. `security.spec.ts` (API-level, `request` fixture)

1. Every session-protected endpoint (`/api/me`, `/api/coffee`,
   `/api/coffee/undo`, `/api/stats`, `/api/history`, `/api/logout`,
   `/api/link/code`, all `/api/admin/*`) returns 401 without a session.
2. Admin endpoints return 403 for a signed-in non-admin.
3. `/api/test/*` without or with a wrong `X-Test-Token` returns 404.
4. Wrong method on an existing route returns 405; unknown `/api/...` paths 404.
5. App routes `/`, `/app`, `/admin`, `/login` serve the shell (200, HTML);
   other paths (e.g. `/config.php`, `/../src/Db.php`) return 404.
6. Registration options with a wrong invite fail 403 before any name
   validation; `amountCents` as string/float/0 is rejected with
   `invalid_amount`; settings updates with out-of-range price/invite are
   rejected with `invalid_settings`.

### 10. `pwa.spec.ts` (main instance)

1. `/manifest.webmanifest` is served and points to existing icons; the shell
   links it.
2. The service worker registers and activates on load
   (`navigator.serviceWorker.ready` resolves).
3. `/?book=1` while signed in books exactly one coffee immediately and cleans
   the URL (no re-booking on reload).
4. `/?book=1` while signed out shows the NFC hint; after signing in, the
   pending coffee is booked automatically (counter 1).

### 11. `reminders.spec.ts` (main instance)

1. Without the notification permission, the reminders card offers the enable
   button with its explanatory hint.
2. With the permission granted, the card reports reminders as on (headless
   Chromium has no periodic background sync, so the on-open wording applies).
3. The admin "Remind" button queues a reminder; the user's session then sees
   it on `/api/reminders` (with `requestedAt` and the open balance), and
   reading does not consume it.
4. Mid-month (fixed server clock), no month-end reminder is due.
5. On the last day of a month (fixed server clock), opening the app as a user
   with an open balance and a queued admin reminder shows both local
   notifications via the service worker (asserted through
   `registration.getNotifications()` tags) and acknowledges them, after which
   `/api/reminders` reports nothing due. Date-dependent tests pin the server
   clock via `TestApi.clock()` and create sessions only after the jump
   (sessions idle out after 30 days). This file pins `channel: 'chromium'`:
   the default headless browser (chrome-headless-shell) has no Notifications
   API, so `Notification.permission` reads 'denied' there even after
   `grantPermissions()`.

## Conventions for implementers

- Each test (or `beforeEach`) starts from `TestApi.reset()` on the main
  instance; the wizard spec deletes its own SQLite file instead.
- Assert server truth via `TestApi.state()` in addition to UI state where the
  test's point is bookkeeping correctness.
- No `page.waitForTimeout` — rely on web-first assertions (`expect(...)
  .toHaveText`, `toBeVisible`, `toBeHidden`) and `page.waitForEvent('download')`.
- Money assertions use the exact rendered format, e.g. `1.50 €` (the app uses
  a normal space before the euro sign).
- If a test uncovers an application bug, fix the application (and note the fix
  in the PR), do not bend the test to the buggy behavior.
