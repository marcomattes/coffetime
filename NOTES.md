# Technical notes

Coffee Time uses a front controller in `public/index.php`, application classes under `src/`, and an additive SQLite schema managed by `Db`. Every API mutation is server-authoritative and database writes use immediate transactions with retry handling.

WebAuthn registrations require discoverable credentials and user verification. Login uses an empty allow-list, allowing the authenticator to choose the account. Ceremony challenges are single-use and sessions store only SHA-256 hashes of opaque tokens.

Names are normalized only for a keyed HMAC used to prevent duplicates. The original JSON name is encrypted using the configured RSA public key. The server has no decryption function or private key. Administrators may decrypt API ciphertext in their browser using a selected PKCS#8 key, or use the offline CLI.

The PWA service worker caches only static shell resources. API calls always use the network. Dynamic text is assigned with `textContent`; no user data is inserted as HTML.
