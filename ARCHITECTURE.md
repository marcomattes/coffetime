# Architecture

Coffee Time uses a front controller in `public/index.php`, application classes under `src/`, and an additive SQLite/MySQL schema managed by `Db`. Every API mutation is server-authoritative and database writes use immediate transactions with retry handling. This document records the design decisions and invariants the code relies on.

## Authentication

WebAuthn registrations require discoverable credentials and user verification. Login uses an empty allow-list, allowing the authenticator to choose the account. Ceremony challenges are single-use and sessions store only SHA-256 hashes of opaque tokens.

Registration writes the user row and its first passkey in **one** transaction (`Users::createWithCredential()`). Split across two, a failure in between would leave a user whose `name_hash` reserves the name forever while no credential can sign in as it — and for the very first user, the `is_admin` flag would be stranded on an unusable account with the setup wizard already closed.

Sessions renew on use but also carry a hard ceiling (`Sessions::ABSOLUTE_LIFETIME`, 180 days) on top of the 30-day idle window, so a stolen token cannot stay valid indefinitely by being used. Completing an **admin-issued** recovery link revokes that account's other sessions (`Sessions::destroyForUser()`), which is what makes lost-device recovery actually end the lost device's access; a self-issued link (adding a second device of one's own) deliberately leaves them alone.

### Administrator password login

WebAuthn is the only credential for regular accounts. Administrators may additionally set a password on **their own** account (`POST /api/admin/password`) and sign in with it (`POST /api/login/password`). This exists for one case WebAuthn cannot cover: a managed workstation whose policy blocks authenticators outright, which would otherwise leave the person responsible for the tab unable to sign in anywhere.

The constraints that keep this from widening the attack surface:

- **Admin-only, checked on both sides.** The setting endpoint requires `requireAdmin()`, and the login endpoint re-checks admin status after verifying the hash — a row that somehow kept a hash without the flag stops being a way in rather than quietly remaining one.
- **Own account only.** An admin session cannot set a password on somebody else's account. This is not a new escalation either way: that session already grants `POST /api/admin/link-code` for any account, which is the stronger persistence primitive of the two.
- **No account oracle.** There is no username in the schema, so the account is found by `Crypto::nameHash()` — the same keyed HMAC duplicate detection uses. An unknown name, an account without a password and a wrong password all answer a bare `401`, and `Passwords::verify()` runs a full comparison against a dummy hash in the first two cases so they do not answer measurably faster.
- **Two counters.** `RateLimit::PASSWORD_MAX` per caller, `PASSWORD_ACCOUNT_MAX` per account across all callers. Without the second, every address an attacker controls would get its own budget against the same account. The cost is that anyone can burn the account counter and block password sign-in for the rest of the window — a nuisance, not a lockout, since the passkey path has its own counter.
- **Passwords are pre-hashed with SHA-256 before `password_hash()`.** `PASSWORD_DEFAULT` is still bcrypt on the shared hosts this app targets, and bcrypt truncates at 72 bytes and stops at the first NUL. Pre-hashing turns any passphrase into 44 printable characters, so every byte counts. It is applied on both sides, so stored hashes survive a future change of `PASSWORD_DEFAULT`.

A leaked admin password does **not** leak the roster: names are RSA-sealed and the private key is never on the server (see [Name privacy](#name-privacy)). What it grants is the admin API surface — balances, payments, settings, link codes.

`users.password_hash` is NULL for every account that has not opted in, which is every account until an administrator deliberately sets one.

### Rate limiting

The unauthenticated endpoints — registration, login, device linking and setup — are rate limited per caller and endpoint group (`RateLimit`, fixed window in the `rate_limits` table). The invite code can be as short as four characters, and `name_taken` reveals whether a given real name is registered; both are only safe behind a throttle. Counters are keyed by a hash of the endpoint group and client address, so no bare IP is stored, and the limiter fails open if the database is briefly unavailable.

## Request and response hardening

The application shell is served with a `Content-Security-Policy` that allows only same-origin script and style (it has no inline script or style of its own) and denies framing outright, plus `X-Frame-Options: DENY`. Framing matters here because every state-changing control — "Take a coffee", "Record payment" — lives on that one document, and the session cookie is `SameSite=Lax`. For the same reason `/?book=1` only books automatically when the app was opened without a foreign referrer (an NFC tag or the app shortcut open with none); arriving from another site just opens the app. Request bodies are bounded (`Http::MAX_BODY_BYTES`) and answered with `413` rather than being buffered until `memory_limit` turns them into a `500`.

## Invitation links

`/?invite=CODE` prefills the invite field and strips the parameter from the URL via `replaceState` before anything else runs, so the code does not linger in the address bar, history or a bookmark. It carries the same shared invite the admin settings show — a convenience, not a second credential, and not single-use; a link is exactly as private as wherever it was pasted, and rotating the invite code invalidates every link carrying the old one. A code outside the 4–64 character bounds the server enforces is dropped rather than prefilled.

The strip preserves any other query parameters, because `checkPendingBook()` runs afterwards and still has to see a `?book=1` that arrived alongside it. `checkInviteLink()` must therefore stay ordered before it: `checkPendingBook()` rewrites the URL with no query string at all.

## Writing NFC tags

The admin view shows both tag links in full — `/?book=1` for the sticker on the machine, `/?invite=CODE` for the one that hands out accounts — and, where Web NFC exists (Chrome on Android, secure context, from a user gesture), writes either of them to a blank tag as a single NDEF `url` record. `NDEFReader` is feature-detected and the buttons stay hidden without it, so the card degrades to two URLs anyone can copy into a tag-writing app or a QR code. Each write is bounded by an `AbortController` (`NFC_WRITE_TIMEOUT_MS`) so a tag that never arrives releases the buttons again, and the `DOMException` names Web NFC reports are mapped individually: whether NFC is switched off, the tag is locked or too small, or it simply moved away mid-write are different things to do next, not one generic failure.

The registration link is built from the invite code the server last confirmed, never from the settings input above it, so an unsaved edit cannot end up on a tag the server would reject; the code is cleared on sign-out, since a kitchen device is shared. A tag is only a link and carries no credential of its own: the booking tag books for whoever is signed in on the phone that taps it, and it only books automatically because a tag opens the app with no referrer (see above). The registration tag is exactly as secret as the invite code printed on it, and rotating that code invalidates every tag carrying the old one.

## Name privacy

Names are normalized only for a keyed HMAC used to prevent duplicates. The original JSON name is encrypted using the configured RSA public key. The server has no decryption function or private key. Administrators may decrypt API ciphertext in their browser using a selected PKCS#8 key, or use the offline CLI.

Normalization also strips control characters, which keeps a maximum-length name from JSON-encoding into six-byte `\uXXXX` escapes that would exceed the RSA-OAEP plaintext capacity; an over-long payload is refused as a `400`, never as a failed encryption.

**Where the pepper lives matters.** `users.name_hash` is `HMAC-SHA256(namePepper, normalizedName)` — a deterministic fingerprint, not a one-way function of an unknown input. Names come from a small, guessable population (an office roster), so anyone holding both the hash and the pepper can recover every registered name by computing candidates. The two must therefore not travel together:

- `namePepper` in `config.php` (or another out-of-band store) is the stronger setup: a database dump then contains fingerprints whose key is not in the dump.
- The setup wizard, which exists so no file has to be edited by hand, has nowhere else to put it and stores it in the `settings` table. That is convenient but means **a database backup carries the key to its own name fingerprints**. RSA-sealed `name_encrypted` stays safe either way (the private key is never on the server); only duplicate-detection fingerprints are exposed.

A missing or placeholder pepper is refused outright at construction rather than silently producing fingerprints anyone can recompute.

## Key rotation

There is deliberately no rotation path for `adminPublicKey` or `namePepper`. Re-keying either would strand existing data: a new RSA key cannot decrypt names sealed with the old one, and a new pepper invalidates every stored `name_hash`, breaking duplicate detection. The application therefore refuses to change them after initial setup. Rotating in practice means exporting the decrypted roster with the old private key (`tools/decrypt-users.php`), starting a fresh database, and re-registering — treat the admin private key as unrotatable and back it up accordingly.

## Frontend and service worker

The PWA service worker caches only static shell resources. API calls always use the network. Dynamic text is assigned with `textContent`; no user data is inserted as HTML.

The frontend is authored in TypeScript (`frontend/app.ts`, `frontend/sw.ts`) and compiled to the plain JavaScript actually served (`public/app.js`, `public/sw.js`), since production hosts have no Node runtime. Compiled output is committed like any other static asset; CI rebuilds it and diffs against the commit (`git diff --exit-code`) so a stale build fails the pipeline rather than reaching production.

## Payment reminders

Payment reminders are local notifications, not Web Push — there is no push server, no VAPID keys, and no subscription stored anywhere. The server only answers `GET /api/reminders` for the signed-in session: a month-end entry (due on the last day of a month, caught up for at most 7 days into the next one, and only while `balanceCents > 0`) and an admin-requested entry (`POST /api/admin/remind` stamps `users.remind_requested_at`). The service worker checks on a page poke at every app start and via periodic background sync where available, shows the notifications, and then confirms via `POST /api/reminders/ack` exactly what it showed: the month lands in `users.reminded_month`, and the admin request is cleared only if its timestamp still matches (a newer request queued between read and ack survives). Reading is never consuming, so a device that fails to display consumes nothing, and the server-side markers make each reminder appear at most once across all of a user's devices.

## Database layer

MySQL/MariaDB migrations additionally take a `GET_LOCK` advisory lock, since its DDL cannot run inside a transaction and concurrent cold starts would otherwise race each other through the steps. Index creation is best-effort and only logged on failure (legacy data can violate a uniqueness constraint), so `migrate()` re-checks the uniqueness-critical indexes on every request and retries the safety net if one is missing — otherwise a single failed attempt would leave name or credential uniqueness silently unenforced forever.

`Db` supports two drivers, SQLite (default) and MySQL/MariaDB, selected by the `db` config block. Migration steps are listed per driver (`sqliteSteps()`/`mysqlSteps()`) since DDL syntax diverges; each step is additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `ensureColumn`), so a crash mid-migration is recovered by the next request. Applied schema version is tracked as `PRAGMA user_version` on SQLite and a one-row `schema_meta` table on MySQL, since MySQL has no equivalent pragma. Callers never write dialect-specific SQL directly; portable helpers like `Db::dayExpr()` (UTC day boundary for history/streaks) hide the difference (`date(col, 'unixepoch')` vs `DATE_FORMAT(FROM_UNIXTIME(col), '%Y-%m-%d')`). `Db::transaction()` retries on driver-specific transient errors: SQLite "locked"/"busy", MySQL deadlock (1213) or lock-wait timeout (1205/40001), up to 12 attempts with a randomized backoff.

## Accepted trade-offs

Two behaviors look like bugs but are deliberate, and are called out here so they are not "fixed" by accident:

- **Undo is unbounded in time and count.** `POST /api/coffee/undo` removes the most recent booking whenever it is called, however old. This is an honor-system tab among colleagues, not an audit ledger, and a correction hours later is a legitimate use. Anyone can only ever undo their *own* bookings, and the counter floors at zero.
- **`name_taken` reveals that a name is registered.** Preventing duplicate registrations requires answering "is this name already taken", which necessarily confirms membership to whoever holds an invite code. The exposure is bounded by rate limiting rather than removed, since the alternative — accepting silent duplicates — is worse for a shared tab.

## Price freezing

Each coffee booking reads the current price once and freezes it twice: into `coffee_events.price_cents` for that event, and added into the user's running `users.tab_cents`. A later price change only affects bookings made after it; undo reverses the specific event's frozen price (falling back to the current price only for pre-price-tracking legacy rows with no event). `balanceCents` is `tab_cents - paid_cents`, both driven by frozen per-event prices and admin payments, never recomputed from the live price.

## Runtime settings

A `settings` table holds runtime-configurable values (`priceCents`, `invite`, `adminPublicKey`, `namePepper`) as name/value string pairs, written by `Settings::setMany()` via a portable select-then-update-or-insert upsert (no `ON CONFLICT`/`ON DUPLICATE KEY`, to stay driver-neutral). `Config` reads each of these through `Settings::get()` first, falling back to the `config.php` array only when no row exists — DB values take precedence once set, and only price/invite can be changed after initial setup (changing `adminPublicKey`/`namePepper` post-hoc would strand existing ciphertext/HMACs). `Settings` caches all rows per request and treats a missing `settings` table (mid-migration on a fresh install) as "nothing set" rather than failing.

## Device linking and recovery codes

Link codes (`link_codes`) exist for two flows: a signed-in device linking a new device to itself (15 min TTL), and an admin issuing a recovery code for a user who lost every device (60 min TTL). Only `sha256(code)` is ever stored, never the plaintext. The WebAuthn ceremony around consumption is split into `peek()` (read-only, used when building registration options — an aborted attempt must not burn the code) and `consume()` (called only after attestation verifies and the credential ID is confirmed free), so a failed or retried attempt leaves the code usable. A second code issued for the same user invalidates the first.

## First-user admin

The first user ever created in a fresh database is flagged `users.is_admin = 1` inside the same transaction as the `INSERT`, using a `COUNT(*)` read before the insert — this is how an installation gets an administrator once the setup wizard removes `admins` from `config.php`. `requireAdmin()` checks both the static `config.php` admins list and this per-row flag.

On SQLite the enclosing `BEGIN IMMEDIATE` already serializes writers. MySQL/MariaDB runs in `REPEATABLE READ`, where a plain `COUNT(*)` is a non-locking snapshot read — two simultaneous first registrations would both see zero and both claim admin — so the count is taken `FOR UPDATE` there.

## Offline booking queue

The offline booking queue (frontend, `localStorage`) assigns a client-generated event ID to each booking before sending it, and retries with the same ID until it succeeds. The server keys `coffee_events.client_event_id` with a unique index and treats a repeat of a known ID as a no-op (returns current state, no re-increment) — this is what makes retries under flaky connectivity safe.

The key is unique **per user**, not globally (`idx_coffee_events_user_client`, schema v10). The ID comes from the client, so a globally unique key would let one account's ID collide with another's and silently swallow that second booking behind a success response. Because the existence check and the insert are not one atomic step, a duplicate that slips through to the unique index is caught and reported as the idempotent success it represents.

Queue entries also record the account they were made under, and the queue is cleared on sign-out. This app is built for a shared kitchen tablet: without both, a coffee booked offline by one person and flushed after someone else signed in would be charged to that next account. Entries stamped with a different account are dropped rather than misattributed.

## Day boundaries

Streaks, the history chart, "today" and the month-end reminder all derive a calendar day from a Unix timestamp. That boundary is configurable as a fixed offset (`dayOffsetMinutes`, minutes east of UTC, default 0 = the historical UTC behavior) so a late-evening coffee counts for the day it was actually had. `Db::dayExpr()` applies the same shift inside SQL that `Users::dayOf()` applies in PHP — the two are compared as strings and must stay in step. A fixed offset deliberately does not follow daylight saving time; that would require per-row timezone conversion that SQLite cannot do portably.
