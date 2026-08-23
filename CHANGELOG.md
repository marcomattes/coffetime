# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work preparing the project for its open-source release.

### Security

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

### Changed

- Translated code comments and identifiers to English across the backend
  (`Api`, `Users`), frontend, end-to-end tests, and support classes.
- Rewrote `README.md` and restructured `ARCHITECTURE.md` for an external
  audience; expanded contributor documentation.
- Hardened CI ahead of the public release.

## [1.0.0] - TBD

Nothing has been tagged or released yet. This entry describes the complete
feature set assembled for the first release, not a point-in-time diff.
Pre-1.0 development history is not itemized here; see the git log for how
each feature was built up.

### Added

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
