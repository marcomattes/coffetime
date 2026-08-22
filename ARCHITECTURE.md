# Architecture

Coffee Time uses a front controller in `public/index.php`, application classes under `src/`, and an additive SQLite/MySQL schema managed by `Db`. Every API mutation is server-authoritative and database writes use immediate transactions with retry handling. This document records the design decisions and invariants the code relies on.

## Authentication

WebAuthn registrations require discoverable credentials and user verification. Login uses an empty allow-list, allowing the authenticator to choose the account. Ceremony challenges are single-use and sessions store only SHA-256 hashes of opaque tokens.

## Name privacy

Names are normalized only for a keyed HMAC used to prevent duplicates. The original JSON name is encrypted using the configured RSA public key. The server has no decryption function or private key. Administrators may decrypt API ciphertext in their browser using a selected PKCS#8 key, or use the offline CLI.

## Frontend and service worker

The PWA service worker caches only static shell resources. API calls always use the network. Dynamic text is assigned with `textContent`; no user data is inserted as HTML.

The frontend is authored in TypeScript (`frontend/app.ts`, `frontend/sw.ts`) and compiled to the plain JavaScript actually served (`public/app.js`, `public/sw.js`), since production hosts have no Node runtime. Compiled output is committed like any other static asset; CI rebuilds it and diffs against the commit (`git diff --exit-code`) so a stale build fails the pipeline rather than reaching production.

## Payment reminders

Payment reminders are local notifications, not Web Push — there is no push server, no VAPID keys, and no subscription stored anywhere. The server only answers `GET /api/reminders` for the signed-in session: a month-end entry (due on the last day of a month, caught up for at most 7 days into the next one, and only while `balanceCents > 0`) and an admin-requested entry (`POST /api/admin/remind` stamps `users.remind_requested_at`). The service worker checks on a page poke at every app start and via periodic background sync where available, shows the notifications, and then confirms via `POST /api/reminders/ack` exactly what it showed: the month lands in `users.reminded_month`, and the admin request is cleared only if its timestamp still matches (a newer request queued between read and ack survives). Reading is never consuming, so a device that fails to display consumes nothing, and the server-side markers make each reminder appear at most once across all of a user's devices.

## Database layer

`Db` supports two drivers, SQLite (default) and MySQL/MariaDB, selected by the `db` config block. Migration steps are listed per driver (`sqliteSteps()`/`mysqlSteps()`) since DDL syntax diverges; each step is additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `ensureColumn`), so a crash mid-migration is recovered by the next request. Applied schema version is tracked as `PRAGMA user_version` on SQLite and a one-row `schema_meta` table on MySQL, since MySQL has no equivalent pragma. Callers never write dialect-specific SQL directly; portable helpers like `Db::dayExpr()` (UTC day boundary for history/streaks) hide the difference (`date(col, 'unixepoch')` vs `DATE_FORMAT(FROM_UNIXTIME(col), '%Y-%m-%d')`). `Db::transaction()` retries on driver-specific transient errors: SQLite "locked"/"busy", MySQL deadlock (1213) or lock-wait timeout (1205/40001), up to 12 attempts with a randomized backoff.

## Price freezing

Each coffee booking reads the current price once and freezes it twice: into `coffee_events.price_cents` for that event, and added into the user's running `users.tab_cents`. A later price change only affects bookings made after it; undo reverses the specific event's frozen price (falling back to the current price only for pre-price-tracking legacy rows with no event). `balanceCents` is `tab_cents - paid_cents`, both driven by frozen per-event prices and admin payments, never recomputed from the live price.

## Runtime settings

A `settings` table holds runtime-configurable values (`priceCents`, `invite`, `adminPublicKey`, `namePepper`) as name/value string pairs, written by `Settings::setMany()` via a portable select-then-update-or-insert upsert (no `ON CONFLICT`/`ON DUPLICATE KEY`, to stay driver-neutral). `Config` reads each of these through `Settings::get()` first, falling back to the `config.php` array only when no row exists — DB values take precedence once set, and only price/invite can be changed after initial setup (changing `adminPublicKey`/`namePepper` post-hoc would strand existing ciphertext/HMACs). `Settings` caches all rows per request and treats a missing `settings` table (mid-migration on a fresh install) as "nothing set" rather than failing.

## Device linking and recovery codes

Link codes (`link_codes`) exist for two flows: a signed-in device linking a new device to itself (15 min TTL), and an admin issuing a recovery code for a user who lost every device (60 min TTL). Only `sha256(code)` is ever stored, never the plaintext. The WebAuthn ceremony around consumption is split into `peek()` (read-only, used when building registration options — an aborted attempt must not burn the code) and `consume()` (called only after attestation verifies and the credential ID is confirmed free), so a failed or retried attempt leaves the code usable. A second code issued for the same user invalidates the first.

## First-user admin

The first user ever created in a fresh database is flagged `users.is_admin = 1` inside the same transaction as the `INSERT`, using a `COUNT(*)` read before the insert — this is how an installation gets an administrator once the setup wizard removes `admins` from `config.php`. `requireAdmin()` checks both the static `config.php` admins list and this per-row flag.

## Offline booking queue

The offline booking queue (frontend, `localStorage`) assigns a client-generated event ID to each booking before sending it, and retries with the same ID until it succeeds. The server keys `coffee_events.client_event_id` with a unique index and treats a repeat of a known ID as a no-op (returns current state, no re-increment) — this is what makes retries under flaky connectivity safe.
