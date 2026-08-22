# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

Work preparing the project for its open-source release.

### Added

- Community health files for external contributors: `CODE_OF_CONDUCT.md`,
  `SECURITY.md`, this `CHANGELOG.md`.

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
