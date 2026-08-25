# Architecture

Coffee Time uses a front controller in `public/index.php`, application classes under `src/`, and an additive SQLite/MySQL schema managed by `Db`. Every API mutation is server-authoritative and database writes use immediate transactions with retry handling. This document records the design decisions and invariants the code relies on.

## Authentication

WebAuthn registrations require discoverable credentials and user verification. Login uses an empty allow-list, allowing the authenticator to choose the account. Ceremony challenges are single-use and sessions store only SHA-256 hashes of opaque tokens.

Registration writes the user row and its first passkey in **one** transaction (`Users::createWithCredential()`). Split across two, a failure in between would leave a user whose `name_hash` reserves the name while no credential can sign in as it — only an administrator could clear that out, and only since [deleting an account](#deleting-an-account) exists — and for the very first user, the `is_admin` flag would be stranded on an unusable account with the setup wizard already closed.

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

Where that address comes from decides whether any of it works. With `trustProxy` on, `X-Forwarded-For` is read from the **right**, `trustedProxyHops` entries in — never from the left. A proxy only ever *appends* the peer it saw, so anything already in the header arrived with the request and is the caller's to choose. Keying on the left-most entry, as this did, meant one changed header value per request bought a fresh counter: the invite code, the login and the admin password were throttled in name only. Only `PASSWORD_ACCOUNT_MAX` was unaffected, because it keys on the account's `name_hash` rather than on an address. A header shorter than the configured hop count means fewer proxies than configured, so `REMOTE_ADDR` — the one value no caller can forge — is used instead.

## Request and response hardening

The application shell is served with a `Content-Security-Policy` that allows only same-origin script and style (it has no inline script or style of its own) and denies framing outright, plus `X-Frame-Options: DENY`. Framing matters here because every state-changing control — "Take a coffee", "Record payment" — lives on that one document, and the session cookie is `SameSite=Lax`. For the same reason `/?book=1` only books on sight in the **installed app**; in a browser tab it shows a confirmation instead (see [The booking shortcut](#the-booking-shortcut)). Request bodies are bounded (`Http::MAX_BODY_BYTES`) and answered with `413` rather than being buffered until `memory_limit` turns them into a `500`.

## Invitation links

`/?invite=CODE` prefills the invite field and strips the parameter from the URL via `replaceState` before anything else runs, so the code does not linger in the address bar, history or a bookmark. It carries the same shared invite the admin settings show — a convenience, not a second credential, and not single-use; a link is exactly as private as wherever it was pasted, and rotating the invite code invalidates every link carrying the old one. A code outside the 4–64 character bounds the server enforces is dropped rather than prefilled.

The strip preserves any other query parameters, because `checkPendingBook()` runs afterwards and still has to see a `?book=1` that arrived alongside it. `checkInviteLink()` must therefore stay ordered before it: `checkPendingBook()` rewrites the URL with no query string at all.

## Writing NFC tags

The admin view shows both tag links in full — `/?book=1` for the sticker on the machine, `/?invite=CODE` for the one that hands out accounts — and, where Web NFC exists (Chrome on Android, secure context, from a user gesture), writes either of them to a blank tag as a single NDEF `url` record. `NDEFReader` is feature-detected and the buttons stay hidden without it, so the card degrades to two URLs anyone can copy into a tag-writing app or a QR code. Each write is bounded by an `AbortController` (`NFC_WRITE_TIMEOUT_MS`) so a tag that never arrives releases the buttons again, and the `DOMException` names Web NFC reports are mapped individually: whether NFC is switched off, the tag is locked or too small, or it simply moved away mid-write are different things to do next, not one generic failure.

The registration link is built from the invite code the server last confirmed, never from the settings input above it, so an unsaved edit cannot end up on a tag the server would reject; the code is cleared on sign-out, since a kitchen device is shared. A tag is only a link and carries no credential of its own: the booking tag books for whoever is signed in on the phone that taps it, and it only books automatically because a tag opens the app with no referrer (see above). The registration tag is exactly as secret as the invite code printed on it, and rotating that code invalidates every tag carrying the old one.

## The booking shortcut

`/?book=1` is what the app shortcut launches and what an NFC tag on the machine carries. It books without asking **only when the app is running installed** (`display-mode: standalone`/`minimal-ui`/`fullscreen`, or `navigator.standalone` on iOS, which learned the media query late). A browser tab gets a confirmation card instead.

This used to key off `document.referrer` being empty, reasoning that a tag or a shortcut opens with none. That is backwards: the referrer belongs to whoever navigates, and `referrerpolicy="no-referrer"` empties it for free. Since the session cookie is `SameSite=Lax` it rides along on a top-level navigation, so any page on the internet could charge a coffee to whoever happened to be signed in — no click required on our side, and one more per navigation. The empty referrer was being read as proof of a trusted launch when it was only proof that someone asked for it to be empty.

Display mode is a property of *how the app was launched*, not something the caller transmits, so a remote page cannot assert it. The shortcut always runs installed and keeps its one-action promise; a tag that lands in a browser tab costs one tap, which is a thing no remote attacker can supply. The residual case is narrow and worth naming: a victim who has the app installed *and* has enabled OS link handling can have an attacker's link open the installed app, where it would book. Making the confirmation unconditional closes that too, at the cost of the shortcut — the guard lives in one function (`checkPendingBook()`) if that trade looks different to you later.

## First-run setup token

`POST /api/setup/init` cannot require a session: it runs before any account exists. It also decides the two things an installation can never take back — the RSA public key every name is sealed to, and the invite code. Together that meant whoever reached a freshly uploaded instance first *owned* it: one request installs their key, the account they register next is flagged admin by the [first-user rule](#first-user-admin), and every colleague who signs up afterwards has their name encrypted to a key the operator does not hold. The operator would see only `already_initialized` — easy to read as "I must have done this earlier".

`SetupToken` closes the window without breaking the wizard's whole reason for existing, which is that no file has to be edited by hand. The server generates the token itself on the first `setup/status` or `setup/init`, writes it to `setup-token.txt` next to the database, and logs it once; the wizard asks for it. Being able to *read* it stands in for authentication — that requires filesystem access to the deployment, which the operator has and a remote caller does not. It is the shape Jupyter and GitLab use for their own first-run secrets.

Three details carry weight:

- **The token is never served over HTTP**, and neither is its path. Serving either would defeat the point, and an absolute path hands an unauthenticated caller the server's directory layout for nothing. The log line written on generation carries the exact path for the operator.
- **It is checked before the payload is validated**, so a caller without it cannot use the endpoint to probe which keys or invite codes would be accepted. Wrong tokens have their own tight counter (`RateLimit::SETUP_TOKEN_MAX`, 5 per window).
- **It is created with `fopen($path, 'x')`**, not a plain write: two concurrent first requests must not each generate their own, or the token the operator reads is not the one the next request compares against.

If the file can be neither read nor created the endpoint answers `500 setup_token_unavailable` and logs the path, rather than waving setup through. A completed setup deletes it — from then on `needsSetup()` closes the wizard and the file would just be a secret lying in a web-served tree. `setupToken` in `config.php` overrides the file for scripted deployments, and is read from `config.php` only: taking it from the settings table would let the wizard authorize the very request that first writes those settings.

## Name privacy

Names are normalized only for a keyed HMAC used to prevent duplicates. The original JSON name is encrypted using the configured RSA public key. The server has no decryption function or private key. Administrators may decrypt API ciphertext in their browser using a selected PKCS#8 key, or use the offline CLI.

Normalization also strips control characters, which keeps a maximum-length name from JSON-encoding into six-byte `\uXXXX` escapes that would exceed the RSA-OAEP plaintext capacity; an over-long payload is refused as a `400`, never as a failed encryption.

**Where the pepper lives matters.** `users.name_hash` is `HMAC-SHA256(namePepper, normalizedName)` — a deterministic fingerprint, not a one-way function of an unknown input. Names come from a small, guessable population (an office roster), so anyone holding both the hash and the pepper can recover every registered name by computing candidates. The two must therefore not travel together:

- `namePepper` in `config.php` (or another out-of-band store) is the stronger setup: a database dump then contains fingerprints whose key is not in the dump.
- The setup wizard, which exists so no file has to be edited by hand, has nowhere else to put it and stores it in the `settings` table. That is convenient but means **a database backup carries the key to its own name fingerprints**. RSA-sealed `name_encrypted` stays safe either way (the private key is never on the server); only duplicate-detection fingerprints are exposed.

A missing or placeholder pepper is refused outright at construction rather than silently producing fingerprints anyone can recompute.

**The admin public key is rebuilt, not merely checked.** `openssl_pkey_get_public()` does not only parse PEM text: given a `file://…` argument it reads that path off the server. The key is user-supplied — the setup wizard takes a pasted or uploaded one — so `Crypto` parses the PEM and assembles a fresh block from a fixed label literal and the re-encoded body instead of forwarding the input. What OpenSSL sees is then provably a BEGIN line, base64, and an END line: a shape no path can take, which is a stronger guarantee than a prefix check that has to anticipate every hostile spelling. Trailing junk, a non-base64 body, mismatched BEGIN/END labels and non-key PEM types fall out of the same parse.

## Key rotation

There is deliberately no rotation path for `adminPublicKey` or `namePepper`. Re-keying either would strand existing data: a new RSA key cannot decrypt names sealed with the old one, and a new pepper invalidates every stored `name_hash`, breaking duplicate detection. The application therefore refuses to change them after initial setup. Rotating in practice means exporting the decrypted roster with the old private key (`tools/decrypt-users.php`), starting a fresh database, and re-registering — treat the admin private key as unrotatable and back it up accordingly.

## Frontend and service worker

The PWA service worker caches only static shell resources. API calls always use the network. Dynamic text is assigned with `textContent`; no user data is inserted as HTML.

The shell markup itself is a plain HTML template, `src/shell.html`, not string literals in PHP. `Frontend::shell()` reads it and substitutes the one dynamic value, the `{{BUILD}}` placeholder for the footer badge. It lives under `src/` — beside `src/build.json`, which set the precedent for non-PHP files there — because that directory is shipped as a whole by both the release bundle and the Docker image and is denied over HTTP by its own `.htaccess`, so the template only ever reaches the browser through the front controller.

The frontend is authored in TypeScript (`frontend/app.ts`, `frontend/sw.ts`) and compiled to the plain JavaScript actually served (`public/app.js`, `public/sw.js`), since production hosts have no Node runtime. Compiled output is committed like any other static asset; CI rebuilds it and diffs against the commit (`git diff --exit-code`) so a stale build fails the pipeline rather than reaching production.

## Payment reminders

Payment reminders are local notifications, not Web Push — there is no push server, no VAPID keys, and no subscription stored anywhere. The server only answers `GET /api/reminders` for the signed-in session: a month-end entry (due on the last day of a month, caught up for at most 7 days into the next one, and only while `balanceCents > 0`) and an admin-requested entry (`POST /api/admin/remind` stamps `users.remind_requested_at`). The service worker checks on a page poke at every app start and via periodic background sync where available, shows the notifications, and then confirms via `POST /api/reminders/ack` exactly what it showed: the month lands in `users.reminded_month`, and the admin request is cleared only if its timestamp still matches (a newer request queued between read and ack survives). Reading is never consuming, so a device that fails to display consumes nothing, and the server-side markers make each reminder appear at most once across all of a user's devices.

The app-icon badge is tied to those notifications and to nothing else: the worker sets it when it actually shows a reminder (and skips it when a window of the app is already visible, since a reminder that arrives on screen has been seen), and the page clears it — along with any notification still sitting in the notification centre — whenever the app comes to the front. It is a "there is something for you" flag, never a running total. It used to badge the outstanding balance instead, which on iOS meant a count that by definition never cleared itself and that the user had no way to get rid of.

## Database layer

MySQL/MariaDB migrations additionally take a `GET_LOCK` advisory lock, since its DDL cannot run inside a transaction and concurrent cold starts would otherwise race each other through the steps. Index creation is best-effort and only logged on failure (legacy data can violate a uniqueness constraint), so `migrate()` re-checks the uniqueness-critical indexes on every request and retries the safety net if one is missing — otherwise a single failed attempt would leave name or credential uniqueness silently unenforced forever.

`Db` supports two drivers, SQLite (default) and MySQL/MariaDB, selected by the `db` config block. Migration steps are listed per driver (`sqliteSteps()`/`mysqlSteps()`) since DDL syntax diverges; each step is additive and idempotent (`CREATE TABLE IF NOT EXISTS`, `ensureColumn`), so a crash mid-migration is recovered by the next request. Applied schema version is tracked as `PRAGMA user_version` on SQLite and a one-row `schema_meta` table on MySQL, since MySQL has no equivalent pragma. Callers never write dialect-specific SQL directly; portable helpers like `Db::dayExpr()` (UTC day boundary for history/streaks) hide the difference (`date(col, 'unixepoch')` vs `DATE_FORMAT(FROM_UNIXTIME(col), '%Y-%m-%d')`). `Db::transaction()` retries on driver-specific transient errors: SQLite "locked"/"busy", MySQL deadlock (1213) or lock-wait timeout (1205/40001), up to 12 attempts with a randomized backoff.

## Accepted trade-offs

One behavior looks like a bug but is deliberate, and is called out here so it is not "fixed" by accident:

- **`name_taken` reveals that a name is registered.** Preventing duplicate registrations requires answering "is this name already taken", which necessarily confirms membership to whoever holds an invite code. The exposure is bounded by rate limiting rather than removed, since the alternative — accepting silent duplicates — is worse for a shared tab.

## Undo window

`POST /api/coffee/undo` takes back the most recent booking, but only while it is younger than `Config::undoWindowSeconds()` (default 300, configurable in `config.php`, clamped to 30 .. 86400). Undo exists for the mis-tap and the double tap; without a bound it is also a way for anyone to walk their own counter back to zero one press at a time, which is what it was being used for. Repeated undo inside the window is fine and intended — three quick taps are three quick undos.

The window is decided by the same transaction that removes the event, so concurrent presses from two devices can never take back more than the bookings that are there. `Users::undoableSeconds()` reports the remaining time (relative, not an absolute timestamp: a phone with a skewed clock would misread the latter), which rides along on `/api/me` and both coffee endpoints. The app uses it to show the undo button at all and to take it away again on a timer; a request that arrives late is answered `409 undo_expired`. An account at zero coffees keeps the older contract and answers `200` with unchanged state — a stale client is not an error. A pre-schema-v5 row with no event carries no date, cannot be told apart from last month's coffee, and therefore is not undoable at all.

## Admin pages

An administrator's surface is three pages inside `view-app` — the counter, the user list, and settings — with `#admin-nav` switching between them. Everyone else has the counter and no navigation at all. The same nav markup is a tab row by default and a left sidebar from 900px up, driven by `body.has-admin-nav` and `#view-app.has-nav`; a drawer was rejected deliberately, since it would add an overlay, a focus trap and one more tap per switch for no gain on a kitchen phone. The pages share a grid cell and are toggled with `hidden`, so only one is ever laid out.

Which page is open lives in the hash (`#/users`, `#/settings`; the counter keeps a bare URL), so a reload stays put and the back button walks the pages. The requested page is held in a variable rather than re-read from the URL at every step: boot renders the sign-in view before `/api/me` has answered, and at that moment nobody is an administrator yet — reading the hash back there would discard the page the URL asked for a moment before we know whether it is allowed. `syncPage()` only rewrites the URL once `view-app` is on screen, and coerces a page the account may not open back to the counter with `replaceState`, since a redirect is not a place to go back to. None of this is access control: the hash is a display preference, and what protects the data behind those pages is the server refusing the requests.

## Build identification

The footer names the build so it is possible to tell what is deployed without opening a terminal. The deployed tree is an FTP mirror with no `.git`, so `scripts/build-release.sh` writes `src/build.json` (short commit + build timestamp) into the release bundle; `src/` is denied over HTTP by its own `.htaccess`, and the value reaches the browser through the public `GET /api/version`. Running from a checkout there is no bundle file and `Version` reads `.git/HEAD` directly instead — including the packed-refs case, and without shelling out to `git`, which shared hosting often forbids. The build script refuses to produce a bundle outside a checkout rather than shipping an unidentifiable release.

Two builds are in play and the distinction matters. `Frontend::shell()` bakes in the build the shell was served as, which after a deploy can still be the previous one: the service worker answers a navigation from its cache and only then fetches a fresh copy. `/api/version` is never cached (nothing under `/api/` is), so it reports what the server actually runs. The badge shows the server's value and marks itself when the two disagree — which is exactly when the double tap is worth using: it deletes every cache, calls `registration.update()`, and reloads. `location.reload(true)` has not forced anything for years; dropping the caches is what makes it hard.

## Paying the tab

The optional "Pay with PayPal" button is a plain `<a>` to `https://www.paypal.com/paypalme/<handle>/<amount>EUR` with `target="_blank"` — no PayPal SDK, no script from paypal.com, and therefore nothing to loosen in the CSP; the new tab also keeps an installed PWA from navigating out of its own scope. The handle is a runtime setting (`paypalHandle`, admin settings screen, `settings` table) and rides along on `/api/me`, since everyone with a tab needs it. `Config::normalizePaypalHandle()` reduces whatever was pasted — bare handle, `paypal.me/name`, either full URL spelling — to the bare handle and rejects anything that is not 1–20 ASCII alphanumerics, which is also what makes it safe to interpolate into a URL. An empty value hides the button, as does a balance of zero.

The button deliberately moves no money in the app's own books: pressing it settles nothing, and the balance changes only when an admin records the payment. The server cannot see whether PayPal was actually paid, and a control that lets a user zero their own tab is the same hole the undo window above closes.

## Price freezing

Each coffee booking reads the current price once and freezes it twice: into `coffee_events.price_cents` for that event, and added into the user's running `users.tab_cents`. A later price change only affects bookings made after it; undo reverses the specific event's frozen price. `balanceCents` is `tab_cents - paid_cents`, both driven by frozen per-event prices and admin payments, never recomputed from the live price.

## Runtime settings

A `settings` table holds runtime-configurable values (`priceCents`, `invite`, `paypalHandle`, `adminPublicKey`, `namePepper`) as name/value string pairs, written by `Settings::setMany()` via a portable select-then-update-or-insert upsert (no `ON CONFLICT`/`ON DUPLICATE KEY`, to stay driver-neutral). `Config` reads each of these through `Settings::get()` first, falling back to the `config.php` array only when no row exists — DB values take precedence once set, and only price/invite can be changed after initial setup (changing `adminPublicKey`/`namePepper` post-hoc would strand existing ciphertext/HMACs). `Settings` caches all rows per request and treats a missing `settings` table (mid-migration on a fresh install) as "nothing set" rather than failing.

## Device linking and recovery codes

Link codes (`link_codes`) exist for two flows: a signed-in device linking a new device to itself (15 min TTL), and an admin issuing a recovery code for a user who lost every device (60 min TTL). Only `sha256(code)` is ever stored, never the plaintext. The WebAuthn ceremony around consumption is split into `peek()` (read-only, used when building registration options — an aborted attempt must not burn the code) and `consume()` (called only after attestation verifies and the credential ID is confirmed free), so a failed or retried attempt leaves the code usable. A second code issued for the same user invalidates the first.

## Deleting an account

`Users::delete()` removes the row together with its credentials, sessions, coffee events and link codes in one transaction — a hard delete, not a tombstone. A tombstone would defeat the point: it would keep the RSA-sealed name on the server, and it would keep the `name_hash` occupying the unique index, which is the constraint that made this feature necessary. Until it existed, registering reserved a name **forever**; the name of a colleague who had left could never be used again, by them or by a namesake who joined later. Releasing that reservation is what deleting buys, and it is also the honest answer to "can you remove my data" for an app whose whole name handling is built around not holding more than it must.

The bookings leave with the row, so the installation-wide totals in `stats()` fall by that user's share. Holding them steady would mean keeping an anonymous remainder row, which is a user under another name — the totals are a leaderboard, not an accounting ledger, and the frozen prices that *are* a ledger live on the events that go away with their owner.

Two deliberate asymmetries in what the endpoint refuses:

- **An open balance does not block it.** The moment an admin most needs to remove an account is when its owner left owing for a month of coffee, and a delete that refuses exactly then is a delete that does not work. The balance is instead spelled out in the confirmation dialog, since `POST /api/admin/user/delete` will not put it in front of anyone by itself.
- **Deleting your own account is refused** (`cannot_delete_self`, 400). `is_admin` is only ever handed to the very first user of a fresh database and there is no path to promote a second one, so an installation whose sole administrator deletes themselves is locked out with no way back. The admin list disables the button on the signed-in row rather than offering one that only answers with an error.

## First-user admin

The first user ever created in a fresh database is flagged `users.is_admin = 1` inside the same transaction as the `INSERT`, using a `COUNT(*)` read before the insert — this is how an installation gets an administrator once the setup wizard removes `admins` from `config.php`. `requireAdmin()` checks both the static `config.php` admins list and this per-row flag.

On SQLite the enclosing `BEGIN IMMEDIATE` already serializes writers. MySQL/MariaDB runs in `REPEATABLE READ`, where a plain `COUNT(*)` is a non-locking snapshot read — two simultaneous first registrations would both see zero and both claim admin — so the count is taken `FOR UPDATE` there.

## Offline booking queue

The offline booking queue (frontend, `localStorage`) assigns a client-generated event ID to each booking before sending it, and retries with the same ID until it succeeds. The server keys `coffee_events.client_event_id` with a unique index and treats a repeat of a known ID as a no-op (returns current state, no re-increment) — this is what makes retries under flaky connectivity safe.

The key is unique **per user**, not globally (`idx_coffee_events_user_client`, schema v10). The ID comes from the client, so a globally unique key would let one account's ID collide with another's and silently swallow that second booking behind a success response. Because the existence check and the insert are not one atomic step, a duplicate that slips through to the unique index is caught and reported as the idempotent success it represents.

Queue entries also record the account they were made under, and the queue is cleared on sign-out. This app is built for a shared kitchen tablet: without both, a coffee booked offline by one person and flushed after someone else signed in would be charged to that next account. Entries stamped with a different account are dropped rather than misattributed.

## Day boundaries

Streaks, the history chart, "today" and the month-end reminder all derive a calendar day from a Unix timestamp. That boundary is configurable as a fixed offset (`dayOffsetMinutes`, minutes east of UTC, default 0 = the historical UTC behavior) so a late-evening coffee counts for the day it was actually had. `Db::dayExpr()` applies the same shift inside SQL that `Users::dayOf()` applies in PHP — the two are compared as strings and must stay in step. A fixed offset deliberately does not follow daylight saving time; that would require per-row timezone conversion that SQLite cannot do portably.
