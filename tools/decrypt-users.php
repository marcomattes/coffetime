<?php

declare(strict_types=1);

/**
 * Coffee Time offline administration tool.
 *
 * Reads the SQLite file read-only, opens the sealed names with the private
 * key, and prints exactly one line per user:
 *
 *     id|firstName|lastName|coffees|balanceCents
 *
 * Usage:
 *     php tools/decrypt-users.php --db <path> --key <path-to-key-file>
 *
 * Optional:
 *     --price <cents>  price per coffee. Only used for pre-v5 databases that
 *                      have no tab_cents column; newer ones carry each
 *                      booking's own frozen price and ignore this.
 *     --xlsx <path>    additionally writes an Excel spreadsheet (.xlsx)
 *                      with the same data — name, coffees, outstanding
 *                      amount. Replaces online payment booking: who owes
 *                      whom what is settled outside the app.
 *     --genkey         generates a new key pair (hex) and exits
 *
 * Dependencies: OpenSSL, PDO SQLite, and ZipArchive only for --xlsx. The tool
 * does not use Composer or application classes.
 */

const EXIT_OK = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;

// Every OOXML part in the xlsx we write starts with this exact declaration.
const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';

function fail(string $message, int $code = EXIT_ERROR): never
{
    fwrite(STDERR, 'Error: ' . $message . PHP_EOL);
    exit($code);
}

function usage(): never
{
    fwrite(
        STDERR,
        'Usage: php tools/decrypt-users.php --db <path> --key <path> [--price <cents>] [--xlsx <path>]' . PHP_EOL
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
    $i = 1;
    while ($i < $count) {
        $argument = $argv[$i];
        if (!str_starts_with($argument, '--')) {
            usage();
        }
        $name = substr($argument, 2);
        $i++;
        if (str_contains($name, '=')) {
            [$name, $value] = explode('=', $name, 2);
            $options[$name] = $value;
            continue;
        }
        if ($name === 'genkey' || $name === 'help') {
            $options[$name] = true;
            continue;
        }
        if ($i >= $count) {
            usage();
        }
        $options[$name] = $argv[$i];
        $i++;
    }

    return $options;
}

function generateKeyPair(): never
{
    // Overwriting an existing private key is unrecoverable: every name sealed
    // with the old one becomes permanently unreadable. Refuse instead.
    foreach (['admin-private.pem', 'admin-public.pem'] as $existing) {
        if (file_exists($existing)) {
            fail(
                $existing . ' already exists. Move it away first — overwriting a private key'
                . ' makes every name encrypted with it unrecoverable.'
            );
        }
    }

    $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    if ($key === false || !openssl_pkey_export($key, $privateKey)) {
        fail('Could not generate an RSA key pair.');
    }
    $publicKey = openssl_pkey_get_details($key)['key'] ?? '';

    // Create the private key readable only by its owner, and do so BEFORE
    // writing to it: a default umask would otherwise leave a window in which
    // the key is world-readable on a shared machine.
    $handle = fopen('admin-private.pem', 'w');
    if ($handle === false) {
        fail('Could not create admin-private.pem');
    }
    @chmod('admin-private.pem', 0600);
    fwrite($handle, $privateKey);
    fclose($handle);

    file_put_contents('admin-public.pem', $publicKey);
    echo "Created admin-private.pem (mode 0600) and admin-public.pem\n";
    echo "Keep admin-private.pem offline and copy the public PEM into config.php.\n";
    exit(EXIT_OK);
}

function loadSecretKey(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        fail('Key file is not readable: ' . $path);
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        fail('Key file is not readable: ' . $path);
    }
    if (openssl_pkey_get_private($raw) === false) {
        fail('The file does not contain a valid, unencrypted PEM private key.');
    }
    return $raw;
}

/** @param array<string, mixed> $options */
function resolvePriceCents(array $options): int
{
    if (isset($options['price'])) {
        $value = (string) $options['price'];
        if (preg_match('/^-?\d+$/', $value) !== 1) {
            fail('--price expects an integer number of cents.');
        }

        return (int) $value;
    }

    $configPath = dirname(__DIR__) . '/config.php';
    if (is_file($configPath) && is_readable($configPath)) {
        /** @psalm-suppress UnresolvableInclude */
        $config = require_once $configPath;
        if (is_array($config) && isset($config['priceCents']) && is_numeric($config['priceCents'])) {
            return (int) $config['priceCents'];
        }
    }

    // Only reached for a pre-v5 database; from v5 on the balance comes from
    // tab_cents and this value is never consulted.
    fwrite(STDERR, 'Warning: no price found (--price or config.php); using 0.' . PHP_EOL);

    return 0;
}

function openDatabase(string $path): PDO
{
    if (!is_file($path) || !is_readable($path)) {
        fail('Database is not readable: ' . $path);
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
    try {
        // Read-only access, so the tool cannot alter anything.
        return new PDO('sqlite:file:' . $path . '?mode=ro', null, null, $options);
    } catch (Throwable) {
        try {
            return new PDO('sqlite:' . $path, null, null, $options);
        } catch (Throwable $e) {
            fail('Could not open database: ' . $e->getMessage());
        }
    }
}

/** @return array{0: string, 1: string} */
function openSealedName(string $ciphertextBase64, string $privateKey, int|string $id): array
{
    $prefix = 'rsa-oaep-sha1:';
    if (!str_starts_with($ciphertextBase64, $prefix)) {
        fail('User ' . $id . ' uses an unsupported legacy encryption format.');
    }
    $ciphertextBase64 = substr($ciphertextBase64, strlen($prefix));
    $ciphertext = base64_decode($ciphertextBase64, true);
    if ($ciphertext === false) {
        fail('Ciphertext for user ' . $id . ' is not valid Base64.');
    }
    try {
        $ok = openssl_private_decrypt($ciphertext, $plain, $privateKey, OPENSSL_PKCS1_OAEP_PADDING);
    } catch (Throwable $e) {
        fail('Could not decrypt user ' . $id . ': ' . $e->getMessage());
    }
    if (!$ok) {
        fail('Could not decrypt user ' . $id . '; does the private key match?');
    }
    $data = json_decode($plain, true);
    if (!is_array($data) || !isset($data['firstName'], $data['lastName'])) {
        fail('Decrypted record for user ' . $id . ' has an unknown format.');
    }

    return [(string) $data['firstName'], (string) $data['lastName']];
}

/**
 * Splits a legacy plaintext name into first and last name.
 *
 * @return array{string, string}
 */
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
    // Line breaks and the delimiter would corrupt the output format.
    return str_replace(["\r", "\n", '|'], ' ', $value);
}

function xmlEscape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Converts a zero-based column index to its spreadsheet letter (0 => A, 25 => Z, 26 => AA, ...). */
function xlsxColumnLetter(int $index): string
{
    $letters = '';
    $index++;
    while ($index > 0) {
        $remainder = ($index - 1) % 26;
        $letters = chr(65 + $remainder) . $letters;
        $index = intdiv($index - 1, 26);
    }

    return $letters;
}

/** @param int|float|string $value */
function xlsxCellXml($value, string $ref): string
{
    if (is_int($value) || is_float($value)) {
        $number = is_float($value) ? rtrim(rtrim(sprintf('%.4F', $value), '0'), '.') : (string) $value;

        return '<c r="' . $ref . '" t="n"><v>' . $number . '</v></c>';
    }

    return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">'
        . xmlEscape((string) $value) . '</t></is></c>';
}

/** @param list<int|float|string> $cells */
function xlsxRowXml(int $rowNumber, array $cells): string
{
    $xml = '<row r="' . $rowNumber . '">';
    foreach (array_values($cells) as $colIndex => $value) {
        $xml .= xlsxCellXml($value, xlsxColumnLetter($colIndex) . $rowNumber);
    }

    return $xml . '</row>';
}

/**
 * @param list<string> $headers
 * @param list<list<int|float|string>> $rows
 */
function xlsxSheetDataXml(array $headers, array $rows): string
{
    $xml = '';
    foreach ([$headers, ...$rows] as $rowIndex => $cells) {
        $xml .= xlsxRowXml($rowIndex + 1, $cells);
    }

    return $xml;
}

/**
 * Assembles the fixed OOXML package parts around the given sheet data and
 * writes them into the xlsx zip archive at $path.
 */
function writeXlsxPackage(string $path, string $sheetDataXml): void
{
    $contentTypes = XML_DECLARATION
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>';

    $rootRels = XML_DECLARATION
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>';

    $workbook = XML_DECLARATION
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Coffee Time" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>';

    $workbookRels = XML_DECLARATION
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>';

    $sheet = XML_DECLARATION
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<sheetData>' . $sheetDataXml . '</sheetData>'
        . '</worksheet>';

    if (is_file($path) && !@unlink($path)) {
        fail('Could not replace existing file: ' . $path);
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fail('Could not create xlsx file: ' . $path);
    }
    $zip->addFromString('[Content_Types].xml', $contentTypes);
    $zip->addFromString('_rels/.rels', $rootRels);
    $zip->addFromString('xl/workbook.xml', $workbook);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();
}

/**
 * Writes a minimal but valid .xlsx file with a single worksheet, using
 * only ZipArchive, without a Composer dependency.
 *
 * @param list<string> $headers
 * @param list<list<int|float|string>> $rows
 */
function writeXlsx(string $path, array $headers, array $rows): void
{
    if (!extension_loaded('zip')) {
        fail('The zip extension is required for --xlsx.');
    }

    writeXlsxPackage($path, xlsxSheetDataXml($headers, $rows));
}

// ---------------------------------------------------------------------- Flow

if (!extension_loaded('openssl')) {
    fail('The OpenSSL extension is missing.');
}
if (!extension_loaded('pdo_sqlite')) {
    fail('The pdo_sqlite extension is missing.');
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
if (isset($options['xlsx']) && !is_string($options['xlsx'])) {
    usage();
}
$xlsxPath = isset($options['xlsx']) ? (string) $options['xlsx'] : null;

$privateKey = loadSecretKey($options['key']);

$priceCents = resolvePriceCents($options);
$pdo = openDatabase($options['db']);

// Schema v5 and later keep the authoritative running total in tab_cents:
// every booking freezes the price that applied at the time, so a later price
// change must not re-value coffees that were already booked. Only a pre-v5
// database (no such column) still needs coffees * price.
$hasTabCents = false;
try {
    $columns = $pdo->query('PRAGMA table_info(users)');
    foreach ($columns === false ? [] : $columns->fetchAll() as $column) {
        if (($column['name'] ?? null) === 'tab_cents') {
            $hasTabCents = true;
        }
    }
} catch (Throwable $e) {
    fail('Could not inspect the users table: ' . $e->getMessage());
}

try {
    $statement = $pdo->query(
        'SELECT id, name, name_encrypted, coffees, paid_cents'
        . ($hasTabCents ? ', tab_cents' : '')
        . ' FROM users ORDER BY id ASC'
    );
    $rows = $statement === false ? [] : $statement->fetchAll();
} catch (Throwable $e) {
    fail('Could not read the users table: ' . $e->getMessage());
}

// Decrypt everything first, then print: with the wrong key, not a single
// data line may reach stdout.
$lines = [];
$xlsxRows = [];
foreach ($rows as $row) {
    $id = $row['id'] ?? '';
    $coffees = isset($row['coffees']) && is_numeric($row['coffees']) ? (int) $row['coffees'] : 0;
    $paid = isset($row['paid_cents']) && is_numeric($row['paid_cents']) ? (int) $row['paid_cents'] : 0;

    $encrypted = $row['name_encrypted'] ?? null;
    if (is_string($encrypted) && $encrypted !== '') {
        [$firstName, $lastName] = openSealedName($encrypted, $privateKey, $id);
    } else {
        // Legacy record: the name was already stored as plaintext in the database.
        [$firstName, $lastName] = splitLegacyName((string) ($row['name'] ?? ''));
    }

    // tab_cents already sums each booking at its own frozen price; falling
    // back to the current price would silently re-value old coffees and make
    // this export disagree with what the app shows.
    $tabCents = $hasTabCents && isset($row['tab_cents']) && is_numeric($row['tab_cents'])
        ? (int) $row['tab_cents']
        : $coffees * $priceCents;
    $balanceCents = $tabCents - $paid;

    $lines[] = implode('|', [
        (string) $id,
        sanitize($firstName),
        sanitize($lastName),
        (string) $coffees,
        (string) $balanceCents,
    ]);
    $xlsxRows[] = [
        (int) $id,
        sanitize($firstName),
        sanitize($lastName),
        $coffees,
        round($balanceCents / 100, 2),
    ];
}

foreach ($lines as $line) {
    echo $line, PHP_EOL;
}

if ($xlsxPath !== null) {
    writeXlsx(
        $xlsxPath,
        ['ID', 'First name', 'Last name', 'Coffees', 'Outstanding (EUR)'],
        $xlsxRows
    );
    fwrite(STDERR, 'Wrote xlsx: ' . $xlsxPath . PHP_EOL);
}

exit(EXIT_OK);
