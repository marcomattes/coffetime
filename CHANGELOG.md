# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.10.0] - 2026-08-23

Mostly a hardening release. Three of the fixes below are ones an operator
cannot work around from the outside — the setup token could be read empty on a
healthy install, a pasted admin key could be turned into a file read, and the
service worker acted on messages without checking where they came from. The
visible change is that the image is now on Docker Hub as well.

### Added

- The release image is now published to Docker Hub as
  `marcomattes/coffetime` alongside `ghcr.io/marcomattes/coffetime`. It is the
  same build pushed to both registries in one step, so the tags and the digest
  are identical and neither can drift behind the other. The Docker Hub half
  reads the `DOCKERHUB_USERNAME` and `DOCKERHUB_TOKEN` repository secrets and
  is skipped rather than failed when they are absent, which keeps forks
  publishing to `ghcr.io` without any credentials of their own.
- `docs/docker-hub.md` is the overview shown on the Docker Hub page, pushed
  there by the publish workflow. The README is not used for it: it is written
  for GitHub, sits a few hundred bytes under the Hub's 25,000-byte ceiling
  where it would start being truncated, and its relative links do not resolve
  off GitHub.

### Security

- The setup token is now published atomically. It was created empty with
  `fopen($path, 'x')` and filled a moment later, so anything reading in that
  window saw an empty file: a second concurrent first request would fall
  through, fail to create the file that already existed, read it back empty and
  report that it cannot read the token — on a perfectly healthy install — and an
  operator running `cat` at the wrong moment saw nothing. The token is now
  written in full to a private temp file and published with `link()`, which is
  atomic and, like the `fopen('x')` it replaces, still fails if the destination
  exists, so two concurrent first requests cannot each install their own token.
- A pasted admin public key can no longer turn into a file read.
  `openssl_pkey_get_public()` also accepts `file://` paths, and the setup wizard
  hands it whatever was pasted in, so the key is now required to be an inline
  PEM block before OpenSSL ever sees it.
- The service worker verifies the origin of incoming `message` events before
  acting on them, instead of trusting any message that carries the right type.
- Every third-party GitHub Action is pinned to a full commit SHA rather than a
  mutable tag, `npm ci` runs with `--ignore-scripts` in CI, and the Playwright
  browser install goes through a package.json script instead of `npx`, which can
  resolve and execute a package on demand.

### Changed

- A static-analysis sweep over the whole codebase: long functions split into
  named helpers (`Frontend::markup()`, `Db`'s schema checks, `Api`'s settings
  update, the icon and export scripts), repeated literals lifted into named
  constants, dedicated exception types instead of bare `RuntimeException`,
  identifier whitelists in front of the SQL that cannot use bound parameters,
  and current JavaScript APIs (`replaceAll`, `Number.isFinite`, `dataset`,
  optional chaining) in the frontend. Behaviour is unchanged throughout: the
  emitted HTML, the generated database schema and the tools' output were each
  verified byte-for-byte against the previous implementation.
- The offline booking queue no longer falls back to `Math.random()` for its
  client event id when `crypto.getRandomValues` is missing; the id only has to
  be unique per client, so it now uses a counter instead of a weak PRNG.
- A second static-analysis pass over the frontend: the base64url padding
  calculation is an `if`/`else if` rather than a nested ternary, the badge-clear
  call routes a synchronous throw and a rejected promise through one `.catch()`
  instead of wrapping a `.catch()` in a `try`, the notification lookup returns a
  promise from both of its branches, `csvField()` also routes functions through
  `JSON.stringify()` rather than stringifying their source text, and the reflow
  read in `bump()` is marked `void` to say the discarded value is deliberate.
- The forged `X-Forwarded-For` prefixes in the unit tests are drawn from the
  RFC 5737 documentation ranges, like the caller addresses beside them, instead
  of live addresses such as a public resolver.

### Fixed

- `setup-wizard` e2e tests no longer read the token file before the server has
  written it, which made them fail intermittently with "Enter the setup token
  shown in the server log" despite a filled-in field.

## [0.9.0] - 2026-08-23

First tagged release, and the point the project became installable without
cloning it: the work below prepared it for its open-source release, and a
container image is published from this tag onwards.

### Security

- The first-run setup wizard now requires a setup token. `POST /api/setup/init`
  cannot require a session — it runs before any account exists — and it fixes
  the RSA key every name is sealed to, so whoever reached a freshly uploaded
  instance first could claim it: install their own key, become administrator
  via the first-user rule, and have every colleague's name encrypted to a key
  the operator does not hold. The server generates the token on first contact,
  writes it next to the database and logs it once; being able to read it stands
  in for authentication. It is never served over HTTP, is checked before the
  payload is validated, and is deleted once setup completes.
- `/?book=1` no longer books unasked in a browser tab. It keyed off an empty
  `document.referrer` as proof of an NFC tag or app shortcut, but a foreign page
  controls its own referrer (`referrerpolicy="no-referrer"`) and the session
  cookie is `SameSite=Lax`, so any page could charge a coffee to whoever was
  signed in — repeatable, and with no interaction on the victim's side. Only the
  installed app books on sight now; a browser tab asks first. The app shortcut
  keeps its one-action behaviour.
- `X-Forwarded-For` is read from the right (`trustedProxyHops`, default 1)
  instead of the left. A proxy only appends, so the left-most entry is the
  caller's own — with `trustProxy` on, one changed header value per request
  bought a fresh rate-limit counter and the invite code, the login and the
  admin password were throttled in name only.
- `data/.htaccess` ships with the release bundle, and `Db` writes one whenever
  it is missing rather than only when it creates the directory. The directory
  is gitignored, so the deny rule existed in no source tree and reached no
  server; it now holds the setup token as well as the database.

- The offline booking queue is now bound to the account that created it and is
  cleared on sign-out. On a shared device, queued coffees could previously be
  flushed under whoever signed in next and charged to their tab.
- `namePepper` is validated: an empty or example-placeholder value is refused
  instead of silently producing name fingerprints anyone could recompute.
  Documented that the wizard stores the pepper in the database, so a backup
  carries the key to its own fingerprints — set it in `config.php` to avoid that.
- Rate limiting on registration, login, device linking and setup
  (`RateLimit`, new `rate_limits` table), so the invite code and the
  `name_taken` response are no longer an unthrottled guessing oracle.
- The app shell is served with a `Content-Security-Policy` and
  `X-Frame-Options: DENY`; `/?book=1` no longer books automatically when the
  app was opened from another site.
- Sessions gained an absolute 180-day cap on top of the sliding idle window,
  and completing an admin-issued recovery code now ends that account's other
  sessions.
- Request bodies are bounded and answered with `413` instead of being buffered
  until `memory_limit` produced a `500`.
- `--genkey` writes the private key as `0600` and refuses to overwrite an
  existing one.

### Fixed

- A container deployment reported its build as `dev`. `Version::current()`
  reads `src/build.json`, which only `scripts/build-release.sh` wrote, and
  falls back to `.git`, which `.dockerignore` excludes — so the footer build
  indicator worked for the FTP deploy and was blank in Docker. The image now
  writes the same file from a build argument.
- PHP's error log now goes to stderr in the image. The first-run setup token is
  logged on generation and the documented way to read it is `docker compose
  logs`, which only works if the log leaves the container.
- The README described a `config.php` baked into the image that pinned `origin`
  to `http://localhost:8123`. No such file was ever written; the origin is
  derived from the request, which is what lets one image serve any host.
- The installed app could not be scrolled on Android. `overscroll-behavior-y:
  contain` was set on `body` as well as `html`, and `body { overflow-x: hidden }`
  had already turned the body into a scroll container of its own — a scroll
  container that cannot scroll and refuses to pass the gesture on. Both
  properties now live on the root element only.
- Pull to refresh drew its spinner on top of the first card instead of in the
  gap the pull opens: the indicator and the content were translated by the same
  amount, so the spinner travelled along with what it was supposed to sit above.
- Pull to refresh no longer claims a gesture before it is clearly a downward
  pull from the very top. A scroll that started with a pixel or two in the wrong
  direction used to be cancelled for its whole duration.
- The iOS app-icon badge could not be cleared. It showed the outstanding
  balance, so it stayed for as long as anything was owed and looked like an
  unread count nobody could dismiss. The badge is now set by the service worker
  when it shows a payment reminder, and the app clears it — together with any
  notification still in the notification centre — whenever it comes to the
  front.
- Registration writes the user and its first passkey in one transaction. A
  failure in between left a user who could never sign in while permanently
  reserving the name — and, for the first user, the admin flag.
- `tools/decrypt-users.php` computed balances from the *current* price,
  contradicting the app after any price change; it now uses the stored
  `tab_cents` with each booking's frozen price.
- The offline-queue idempotency key is unique per user rather than globally, so
  one account's client-generated id can no longer swallow another's booking.
- MySQL/MariaDB: the first-user-becomes-admin check is a locking read, so two
  concurrent first registrations cannot both become admin; migrations take an
  advisory lock and no longer fail a request on a lost `CREATE INDEX` race.
- Uniqueness-critical indexes are re-checked and retried; a single failed
  creation no longer left uniqueness silently unenforced forever.
- An admin payment reminder is withheld while nothing is outstanding, instead
  of telling a settled user to pay "0.00 €".
- Names containing control characters no longer produce a `500` at
  registration; they are normalized away and an over-long name is a `400`.
- The admin screen no longer hides every name behind a "wrong key" message when
  a single ciphertext fails to decrypt, and its totals no longer net
  overpayments against other people's real debt.
- The app icons were drawn off-centre and too small for their canvas, so the
  installed app showed a lopsided cup floating in brown. They are regenerated
  centred, and the apple-touch icon is now full-bleed and fully opaque, which
  is what iOS expects before applying its own squircle mask.
- Installed on iOS, the first card sat underneath the notch: the status bar
  style is no longer `black-translucent`, and every edge of the layout now
  respects `env(safe-area-inset-*)` — including the home indicator and, in
  landscape, the rounded corners.
- iOS in a browser tab has no Notification API at all, so the reminders card
  reported notifications as unsupported on a device that supports them
  perfectly well once installed. It now says so and links to the steps.

### Added

- A published container image at `ghcr.io/marcomattes/coffetime`, built for
  `linux/amd64` and `linux/arm64` and pushed only after the full CI suite goes
  green. A `v*` git tag publishes the semver tags and moves `latest`; commits
  on `main` move `edge`; every build also gets an immutable `sha-` tag and a
  signed build provenance attestation. Running the app no longer requires
  cloning the repository.
- Administrators can delete an account (`POST /api/admin/user/delete`, Delete
  button in the user list). It is a hard delete — the row, its passkeys,
  sessions, bookings and any pending link code go together in one transaction.
  That releases the `name_hash`, which until now reserved a name forever: the
  name of someone who had left the company could never be registered again, by
  them or by a namesake. An open balance does not block the delete (the account
  you most need to remove is the one whose owner left owing money), but the
  confirmation names the amount being written off. Deleting the account you are
  signed in with is refused, since an installation whose only administrator
  removes themselves has no way back in.
- Community health files for external contributors: `CODE_OF_CONDUCT.md`,
  `SECURITY.md`, this `CHANGELOG.md`.
- `trustProxy` config option so a TLS-terminating reverse proxy's
  `X-Forwarded-Proto` is honored — without it a derived origin fell back to
  `http://`, breaking WebAuthn and dropping the cookie's `Secure` flag.
- `dayOffsetMinutes` config option to move the day boundary for streaks,
  history and month-end reminders off UTC.
- An "Add to Home Screen" card: the browser's own install prompt where one is
  offered, and the Share-sheet steps on iOS, which has no install API. It is
  dismissible and never appears in the installed app.
- Pull to refresh. The installed app has no browser chrome and therefore no
  reload button; the gesture is handled only there, so a browser tab keeps its
  own.
- `scripts/make-icons.mjs` renders every file in `public/icons/` from one
  vector description, with the margins each purpose needs.
- Optional password sign-in for **administrators only**, for managed
  workstations where passkeys are blocked (schema v11, `users.password_hash`).
  An admin sets a password on their own account in the admin view and signs in
  with name plus password; it is opt-in, removable, and never replaces the
  passkey path. Regular accounts stay passkey-only, and a password on a row
  without admin rights does not sign in. Hashed with `password_hash()` over a
  SHA-256 pre-hash (so bcrypt's 72-byte truncation cannot silently drop input),
  and throttled both per caller and per account so guessing from a pool of
  addresses is no cheaper than from one. Names stay sealed either way — the RSA
  private key is never on the server.
- Invitation links: `/?invite=CODE` prefills the invite code and strips the
  parameter from the URL again. Same shared code as before, one less thing to
  retype.
- Writing NFC tags from the admin area. The booking link (`/?book=1`) and the
  registration link (`/?invite=CODE`) are shown as full URLs, and on Chrome for
  Android a button writes either one to a blank sticker as an NDEF `url` record
  via Web NFC — no separate tag-writing app. Browsers without Web NFC keep the
  URLs to copy. The registration tag is exactly as secret as the invite code it
  carries; the booking tag is not a credential and books for whoever is signed
  in on the phone that taps it.
- Manifest screenshots, so Chromium shows the full install dialog with a
  preview instead of the narrow mini-infobar. Regenerated reproducibly by
  `scripts/make-screenshots.mjs`, which boots a throwaway instance, fills it
  through the same `/api/test/*` surface the e2e suite uses, and photographs
  it at both form factors.
- A footer crediting the author, linking to mattes.dev. Opened in a new tab:
  an in-page navigation would leave the manifest scope and replace the
  installed app with a browser view.

- **Admin navigation**: settings and the user list are pages of their own
  instead of cards stacked under the counter. The same nav is a left sidebar
  from 900px and a tab row below it — no drawer, so no overlay, no focus trap
  and no extra tap. The open page lives in the hash (`#/users`, `#/settings`),
  so a reload stays put and the back button walks the pages; the counter keeps
  a bare URL and non-admins get no navigation at all.
- The footer names the **deployed build** (short commit hash). The release
  bundle carries `src/build.json` written by `scripts/build-release.sh`, since
  the FTP-mirrored tree has no `.git`; a checkout falls back to reading
  `.git/HEAD`. The badge reports what `GET /api/version` says — never the
  cached shell — and marks itself when the two disagree. **A double tap on it
  deletes every cache and reloads**, which is the only reliable hard refresh in
  an installed PWA.
- Optional **"Pay with PayPal" button**: a plain PayPal.me link carrying the
  outstanding amount, shown to every user once an admin has stored a handle
  (`paypalHandle`, admin settings or `config.php`; a pasted paypal.me link is
  reduced to the handle). No PayPal SDK and no CSP change — and pressing it
  settles nothing on its own, since only an admin can see the money arrive.

### Changed

- **Undo is now bounded in time.** `POST /api/coffee/undo` only takes back a
  booking younger than `undoWindowSeconds` (new `config.php` key, default 300,
  clamped to 30 .. 86400); an older one is answered `409 undo_expired`. Undo
  without a bound let anyone walk their own counter back to zero one press at a
  time, reload included. `/api/me` and both coffee endpoints report the
  remaining `undoableSeconds`, and the app shows the undo button only while it
  is real, hiding it again on a timer. A pre-schema-v5 row with no event carries
  no date and is no longer undoable at all. This reverses a documented
  "accepted trade-off" in `ARCHITECTURE.md`.
- Translated code comments and identifiers to English across the backend
  (`Api`, `Users`), frontend, end-to-end tests, and support classes.
- Rewrote `README.md` and restructured `ARCHITECTURE.md` for an external
  audience; expanded contributor documentation.
- Hardened CI ahead of the public release.

### Baseline feature set

Everything this first release ships, as a feature inventory rather than a
point-in-time diff. Development history before this tag is not itemized; see
the git log for how each feature was built up.

#### Authentication & accounts

- Passwordless, username-less WebAuthn login with discoverable passkeys —
  no emails or passwords stored.
- Device linking: a signed-in device generates a short-lived code to add a
  passkey on a new device.
- Admin-issued, single-use recovery codes (only their hash stored) for users
  who lost every device.
- The first account ever registered becomes administrator automatically.

#### Bookings & balances

- Server-authoritative coffee counter with undo, balances, and streaks.
- Personal 28-day booking history with chart.
- Per-booking price freezing: later price changes never reprice existing
  coffees.
- Admin payment bookings to settle tabs.
- Offline CLI for accounting, with optional XLSX export.

#### Privacy & encryption

- Names encrypted with the administrator's RSA public key (RSA-OAEP); the
  server holds no private key and no decryption code.
- Admin screen decrypts names locally with Web Crypto from a selected
  private-key file, never uploaded or persisted, including CSV export.
- Anonymous rankings: each user sees only their own totals and history.

#### PWA & offline

- Installable app with shortcuts, NFC links, and app badges.
- Offline booking queue with idempotent event IDs so retried bookings are
  never double-counted.
- TypeScript frontend (strict, ES2022) compiled to the plain JavaScript the
  PHP server ships as static files.

#### Reminders

- Local, opt-in month-end payment reminder shown while the tab is open — no
  push server, VAPID keys, or subscriptions.
- Admin "Remind" button.

#### Admin

- First-run setup wizard: admin RSA keypair generated in the browser, the
  private key offered for download only, price and invite code stored
  without touching `config.php`.
- In-app admin settings for price and invite code at runtime.

#### Database

- SQLite by default; optional MySQL/MariaDB adapter with driver dialects.
- Automatic, additive schema migrations on both drivers.
