<?php

/**
 * Kaffeeliste – Beispielkonfiguration.
 *
 * Diese Datei nach `config.php` kopieren (gleiche Ebene wie `public/`) und
 * anpassen. Die Anwendung liest `config.php` bei *jedem* Request neu, damit
 * Änderungen sofort greifen.
 */
return [
    // Preis eines Kaffees in Cent.
    'priceCents' => 150,

    // Einladungscode für die Registrierung.
    'invite' => 'BEANS-2026',

    // Benutzer-IDs (als Strings!), die Administratorrechte haben.
    'admins' => [],

    // WebAuthn Relying Party ID (nur der Host, ohne Schema und Port).
    'rpId' => 'localhost',

    // Vollständige Origin inklusive Schema und Port.
    'origin' => 'http://localhost:8123',

    // Absoluter Pfad zur SQLite-Datei. Muss ausserhalb von public/ liegen.
    'dbPath' => __DIR__ . '/data/coffee.sqlite',

    // Teststeuerung (/api/test/*). In Produktion immer false.
    'testMode' => false,
    'testToken' => '',

    // Öffentlicher X25519-Schlüssel (64 Hex-Zeichen) des Administrators.
    // Namen werden damit versiegelt; der private Schlüssel gehört NICHT auf den Server.
    // Schlüsselpaar erzeugen: php tools/decrypt-users.php --genkey
    'adminPublicKey' => '0000000000000000000000000000000000000000000000000000000000000000',

    // Geheimer Schlüssel für den HMAC der Namens-Eindeutigkeit.
    'namePepper' => 'bitte-aendern',
];
