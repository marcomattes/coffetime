<?php

/**
 * Coffee Time example configuration. Copy this file to `config.php` and edit
 * it. The application reloads it on every request.
 *
 * Manually editing this file is optional: a fresh deployment with no
 * adminPublicKey and no users offers the in-app setup wizard instead, which
 * generates the admin keypair in the browser (the private key is only
 * offered for download, never uploaded) and stores adminPublicKey,
 * priceCents, and invite itself. This file remains the supported path for
 * scripted or production deployments that want everything fixed ahead of
 * time.
 */
return [
    // Price of one coffee in cents. Once an admin changes the price from the
    // in-app settings screen (or the setup wizard has run), the stored value
    // overrides this one — see 'invite' below and the README's "Settings
    // precedence" section.
    'priceCents' => 150,

    // Invite code required during registration. Like priceCents, an in-app
    // change (setup wizard or admin settings) overrides this file's value on
    // every later request; edit this only to set the initial/fallback value.
    'invite' => 'BEANS-2026',

    // How long a freshly booked coffee can still be taken back, in seconds.
    // Undo is there for the mis-tap, not for editing the tab down: past this
    // window the booking stands and the app hides the undo button. Clamped to
    // 30 .. 86400; anything else falls back to the default of 300.
    'undoWindowSeconds' => 300,

    // PayPal.me handle the "Pay with PayPal" button links to, with the
    // outstanding amount filled in. Leave empty to hide the button. Like
    // priceCents and invite, an in-app change (admin settings) overrides this
    // file's value. A full paypal.me link is accepted and reduced to the
    // handle. Pressing the button settles nothing by itself -- an admin still
    // records the payment once the money has arrived.
    'paypalHandle' => '',

    // User IDs (as strings) with administrator privileges.
    'admins' => [],

    // WebAuthn relying-party ID (host only, without scheme or port).
    // If omitted, it is derived from the request's Host header — set it
    // explicitly in production, especially behind a reverse proxy.
    'rpId' => 'localhost',

    // Complete origin, including scheme and port. Same fallback rule as
    // rpId: derived from the request when omitted, pin it in production.
    'origin' => 'http://localhost:8123',

    // Set this only when a reverse proxy in front of the app sets the
    // X-Forwarded-* headers itself (and strips any the client sent). It lets
    // a derived origin take its scheme from X-Forwarded-Proto, which is what
    // a TLS-terminating proxy needs so the session cookie keeps its Secure
    // flag and WebAuthn origins match. Pinning 'origin' above is still the
    // more robust option.
    'trustProxy' => false,

    // Day boundary for streaks, the history chart and the month-end
    // reminder, in minutes east of UTC. 0 means days end at UTC midnight.
    // Set it to your office's standard offset (Berlin winter = 60) so a
    // late-evening coffee counts for the day people actually had it. A fixed
    // offset does not follow daylight saving time.
    'dayOffsetMinutes' => 0,

    // SQLite path. Keep it outside public/. Ignored when 'db' below selects
    // the mysql driver.
    'dbPath' => __DIR__ . '/data/coffee.sqlite',

    // Optional: use MySQL/MariaDB instead of SQLite. Uncomment and fill in
    // your own values; an invalid or incomplete block falls back to SQLite.
    // 'db' => [
    //     'driver' => 'mysql',
    //     'host' => '127.0.0.1',
    //     'port' => 3306,
    //     'database' => 'coffee',
    //     'user' => 'coffee',
    //     'password' => 'change-me',
    //     'charset' => 'utf8mb4',
    // ],

    // Test endpoints (/api/test/*). Always false in production.
    'testMode' => false,
    'testToken' => '',

    // First-run setup token. POST /api/setup/init is unauthenticated by
    // necessity -- it runs before any account exists -- and it fixes the RSA
    // key every name is sealed to, which cannot be re-keyed afterwards.
    // Whoever reached a freshly uploaded instance first would otherwise own
    // it. Leave this empty and the server generates a token on first request,
    // writes it to setup-token.txt next to the database and prints it to the
    // error log; enter it in the wizard. Set it here instead to pin your own
    // for a scripted deployment.
    'setupToken' => '',

    // Number of proxies between the client and the app. X-Forwarded-For is
    // read this many entries from the RIGHT, because a proxy only ever
    // appends -- anything already in the header came from the caller. Only
    // consulted when trustProxy is true. Setting this higher than the real
    // chain would select a caller-supplied entry again and un-throttle every
    // per-caller rate limit, so leave it at the number of hops you actually
    // have.
    'trustedProxyHops' => 1,

    // RSA public key generated by: php tools/decrypt-users.php --genkey
    // Never put the corresponding private key on the server. Leave this
    // empty to use the in-app setup wizard instead: it generates the keypair
    // in the browser and stores the public half via the settings table
    // (once set that way, it takes precedence over this file — see the
    // "Settings precedence" section in README.md). Unlike priceCents/invite,
    // it cannot be changed again from the app afterward: doing so would
    // strand every name already encrypted with the old key.
    'adminPublicKey' => <<<'PEM'
-----BEGIN PUBLIC KEY-----
REPLACE_WITH_YOUR_PUBLIC_KEY
-----END PUBLIC KEY-----
PEM,

    // Secret used to detect duplicate names without storing plaintext. If
    // left unset here, the setup wizard generates a random one and stores it
    // via the settings table on first run; like adminPublicKey, it is never
    // rewritten afterward, since that would invalidate every existing
    // duplicate-name check.
    //
    // Generate one with: php -r 'echo bin2hex(random_bytes(32)), "\n";'
    // The value below is refused at runtime precisely because it is public —
    // name_hash is a keyed fingerprint, so a known key would let anyone with
    // a copy of the database recover names by guessing them.
    //
    // Setting it HERE rather than letting the wizard store it in the database
    // is the stronger option: a database backup then no longer contains the
    // key to its own name fingerprints. See "Name privacy" in ARCHITECTURE.md.
    //
    // If you are editing this file at all, set it. The wizard's fallback is to
    // generate one into the settings table, which puts the fingerprints and
    // the key that unlocks them in the same backup -- and names come from a
    // small, guessable population, so that key is the whole roster.
    'namePepper' => 'change-me-to-a-long-random-value',
];
