<?php

declare(strict_types=1);

/**
 * Kaffeeliste – Offline-Werkzeug für den Administrator.
 *
 * Liest die SQLite-Datei nur lesend, öffnet die versiegelten Namen mit dem
 * privaten Schlüssel und gibt je Benutzer genau eine Zeile aus:
 *
 *     id|firstName|lastName|coffees|balanceCents
 *
 * Aufruf:
 *     php tools/decrypt-users.php --db <pfad> --key <pfad-zur-schlüsseldatei>
 *
 * Optional:
 *     --price <cent>   Preis je Kaffee (sonst aus ../config.php)
 *     --genkey         erzeugt ein neues Schlüsselpaar (Hex) und beendet
 *
 * Abhängigkeiten: ausschliesslich sodium und pdo_sqlite. Kein Composer, kein
 * Autoloader, keine Anwendungsklasse.
 */

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;

function fail(string $message, int $code = EXIT_ERROR): never
{
    fwrite(STDERR, 'Fehler: ' . $message . PHP_EOL);
    exit($code);
}

function usage(): never
{
    fwrite(
        STDERR,
        'Aufruf: php tools/decrypt-users.php --db <pfad> --key <pfad> [--price <cent>]' . PHP_EOL
    );
    exit(EXIT_USAGE);
}

/**
 * @param list<string> $argv
 * @return array<string, string|bool>
 */
function parseArguments(array $argv): array
{
    $options = [];
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        $argument = $argv[$i];
        if (!str_starts_with($argument, '--')) {
            usage();
        }
        $name = substr($argument, 2);
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $options[$name] = $value;
            continue;
        }
        if ($name === 'genkey' || $name === 'help') {
            $options[$name] = true;
            continue;
        }
        if ($i + 1 >= $count) {
            usage();
        }
        $options[$name] = $argv[++$i];
    }

    return $options;
}

function generateKeyPair(): never
{
    $keyPair = sodium_crypto_box_keypair();
    echo 'private: ' . sodium_bin2hex(sodium_crypto_box_secretkey($keyPair)) . PHP_EOL;
    echo 'public:  ' . sodium_bin2hex(sodium_crypto_box_publickey($keyPair)) . PHP_EOL;
    exit(EXIT_OK);
}

function loadSecretKey(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        fail('Schlüsseldatei nicht lesbar: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail('Schlüsseldatei nicht lesbar: ' . $path);
    }
    $hex = trim($raw);
    if (preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
        fail('Der private Schlüssel muss 64 Hex-Zeichen (32 Byte) enthalten.');
    }
    $binary = hex2bin(strtolower($hex));
    if ($binary === false || strlen($binary) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
        fail('Der private Schlüssel ist kein gültiger X25519-Schlüssel.');
    }

    return $binary;
}

function resolvePriceCents(array $options): int
{
    if (isset($options['price'])) {
        $value = (string) $options['price'];
        if (preg_match('/^-?[0-9]+$/', $value) !== 1) {
            fail('--price erwartet eine ganze Zahl in Cent.');
        }

        return (int) $value;
    }

    $configPath = dirname(__DIR__) . '/config.php';
    if (is_file($configPath) && is_readable($configPath)) {
        /** @psalm-suppress UnresolvableInclude */
        $config = require $configPath;
        if (is_array($config) && isset($config['priceCents']) && is_numeric($config['priceCents'])) {
            return (int) $config['priceCents'];
        }
    }

    fwrite(STDERR, 'Hinweis: kein Preis gefunden (--price oder config.php), es wird mit 0 gerechnet.' . PHP_EOL);

    return 0;
}

function openDatabase(string $path): PDO
{
    if (!is_file($path) || !is_readable($path)) {
        fail('Datenbank nicht lesbar: ' . $path);
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    try {
        // Nur lesender Zugriff, damit das Werkzeug nichts verändern kann.
        return new PDO('sqlite:file:' . $path . '?mode=ro', null, null, $options);
    } catch (Throwable) {
        try {
            return new PDO('sqlite:' . $path, null, null, $options);
        } catch (Throwable $e) {
            fail('Datenbank kann nicht geöffnet werden: ' . $e->getMessage());
        }
    }
}

/** @return array{0: string, 1: string} */
function openSealedName(string $ciphertextBase64, string $keyPair, int|string $id): array
{
    $ciphertext = base64_decode($ciphertextBase64, true);
    if ($ciphertext === false) {
        fail('Chiffrat von Benutzer ' . $id . ' ist kein gültiges Base64.');
    }
    try {
        $plain = sodium_crypto_box_seal_open($ciphertext, $keyPair);
    } catch (Throwable $e) {
        fail('Benutzer ' . $id . ' konnte nicht entschlüsselt werden: ' . $e->getMessage());
    }
    if ($plain === false) {
        fail('Benutzer ' . $id . ' konnte nicht entschlüsselt werden – passt der private Schlüssel?');
    }
    $data = json_decode($plain, true);
    if (!is_array($data) || !isset($data['firstName'], $data['lastName'])) {
        fail('Entschlüsselter Datensatz von Benutzer ' . $id . ' hat ein unbekanntes Format.');
    }

    return [(string) $data['firstName'], (string) $data['lastName']];
}

/** Zerlegt einen Altbestands-Klartextnamen in Vor- und Nachname. */
function splitLegacyName(string $name): array
{
    $name = trim($name);
    if ($name === '') {
        return ['', ''];
    }
    $position = strpos($name, ' ');
    if ($position === false) {
        return [$name, ''];
    }

    return [substr($name, 0, $position), trim(substr($name, $position + 1))];
}

function sanitize(string $value): string
{
    // Zeilenumbrüche und Trennzeichen würden das Ausgabeformat zerstören.
    return str_replace(["\r", "\n", '|'], ' ', $value);
}

// --------------------------------------------------------------------- Ablauf

if (!extension_loaded('sodium')) {
    fail('Die Erweiterung sodium fehlt.');
}
if (!extension_loaded('pdo_sqlite')) {
    fail('Die Erweiterung pdo_sqlite fehlt.');
}

$options = parseArguments($argv);
if (isset($options['help'])) {
    usage();
}
if (isset($options['genkey'])) {
    generateKeyPair();
}
if (!isset($options['db']) || !isset($options['key']) || !is_string($options['db']) || !is_string($options['key'])) {
    usage();
}

$secretKey = loadSecretKey($options['key']);
$publicKey = sodium_crypto_box_publickey_from_secretkey($secretKey);
$keyPair = sodium_crypto_box_keypair_from_secretkey_and_publickey($secretKey, $publicKey);

$priceCents = resolvePriceCents($options);
$pdo = openDatabase($options['db']);

try {
    $statement = $pdo->query(
        'SELECT id, name, name_encrypted, coffees, paid_cents FROM users ORDER BY id ASC'
    );
    $rows = $statement === false ? [] : $statement->fetchAll();
} catch (Throwable $e) {
    fail('Die Tabelle users kann nicht gelesen werden: ' . $e->getMessage());
}

// Erst alles entschlüsseln, dann ausgeben: bei einem falschen Schlüssel darf
// keine einzige Datenzeile auf stdout landen.
$lines = [];
foreach ($rows as $row) {
    $id = $row['id'] ?? '';
    $coffees = isset($row['coffees']) && is_numeric($row['coffees']) ? (int) $row['coffees'] : 0;
    $paid = isset($row['paid_cents']) && is_numeric($row['paid_cents']) ? (int) $row['paid_cents'] : 0;

    $encrypted = $row['name_encrypted'] ?? null;
    if (is_string($encrypted) && $encrypted !== '') {
        [$firstName, $lastName] = openSealedName($encrypted, $keyPair, $id);
    } else {
        // Altbestand: der Name lag schon im Klartext in der Datenbank.
        [$firstName, $lastName] = splitLegacyName((string) ($row['name'] ?? ''));
    }

    $lines[] = implode('|', [
        (string) $id,
        sanitize($firstName),
        sanitize($lastName),
        (string) $coffees,
        (string) ($coffees * $priceCents - $paid),
    ]);
}

foreach ($lines as $line) {
    echo $line, PHP_EOL;
}

exit(EXIT_OK);
