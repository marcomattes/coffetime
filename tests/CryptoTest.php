<?php

declare(strict_types=1);

/**
 * Kaffeeliste – Tests für das Versiegeln und Hashen von Namen.
 *
 * Ohne Framework, ohne Composer: `php tests/CryptoTest.php`. Der Prozess endet
 * mit einem Rückgabewert ungleich null, sobald eine Zusicherung scheitert.
 */

require __DIR__ . '/../src/Crypto.php';

use Coffee\Crypto;

final class TestRunner
{
    private int $passed = 0;

    /** @var list<string> */
    private array $failures = [];

    public function assertTrue(string $label, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            $this->passed++;
            echo "  ok   {$label}\n";

            return;
        }
        $message = $label . ($detail !== '' ? ' :: ' . $detail : '');
        $this->failures[] = $message;
        echo "  FAIL {$message}\n";
    }

    public function assertSame(string $label, mixed $expected, mixed $actual): void
    {
        $this->assertTrue(
            $label,
            $expected === $actual,
            'erwartet ' . var_export($expected, true) . ', erhalten ' . var_export($actual, true)
        );
    }

    public function assertNotSame(string $label, mixed $unexpected, mixed $actual): void
    {
        $this->assertTrue($label, $unexpected !== $actual, 'beide Werte sind ' . var_export($actual, true));
    }

    public function assertThrows(string $label, callable $callback): void
    {
        try {
            $callback();
        } catch (Throwable) {
            $this->assertTrue($label, true);

            return;
        }
        $this->assertTrue($label, false, 'es wurde keine Ausnahme ausgelöst');
    }

    public function summary(): int
    {
        $failed = count($this->failures);
        printf("\n%d Zusicherungen erfüllt, %d gescheitert\n", $this->passed, $failed);

        return $failed === 0 ? 0 : 1;
    }
}

if (!extension_loaded('sodium')) {
    fwrite(STDERR, "Die Erweiterung sodium fehlt.\n");
    exit(1);
}

$test = new TestRunner();

$keyPair = sodium_crypto_box_keypair();
$secretKey = sodium_crypto_box_secretkey($keyPair);
$publicKeyHex = sodium_bin2hex(sodium_crypto_box_publickey($keyPair));
$pepper = 'pfeffer-fuer-den-test';

$crypto = new Crypto($publicKeyHex, $pepper);

/** Öffnet ein Chiffrat wie das Offline-Werkzeug des Administrators. */
$open = static function (string $base64) use ($keyPair): array {
    $ciphertext = base64_decode($base64, true);
    if ($ciphertext === false) {
        return [];
    }
    $plain = sodium_crypto_box_seal_open($ciphertext, $keyPair);
    if ($plain === false) {
        return [];
    }
    $data = json_decode($plain, true);

    return is_array($data) ? $data : [];
};

echo "\n== Versiegeln ==\n";
$first = 'Ada';
$last = 'Lovelace';
$sealedA = $crypto->sealName($first, $last);
$sealedB = $crypto->sealName($first, $last);

$test->assertNotSame('zweimal derselbe Name ergibt verschiedene Chiffrate', $sealedA, $sealedB);
$test->assertTrue('Chiffrat ist gültiges Base64', base64_decode($sealedA, true) !== false);
$test->assertTrue(
    'Chiffrat enthält den Klartext nicht',
    !str_contains($sealedA, $first) && !str_contains($sealedA, $last)
        && !str_contains((string) base64_decode($sealedA, true), $first),
    $sealedA
);
$test->assertTrue(
    'Chiffrat hat die Länge eines sealed box',
    strlen((string) base64_decode($sealedA, true)) > SODIUM_CRYPTO_BOX_SEALBYTES
);

echo "\n== Entschlüsseln mit dem passenden privaten Schlüssel ==\n";
foreach (['erstes' => $sealedA, 'zweites' => $sealedB] as $label => $sealed) {
    $opened = $open($sealed);
    $test->assertSame("{$label} Chiffrat: Vorname", $first, $opened['firstName'] ?? null);
    $test->assertSame("{$label} Chiffrat: Nachname", $last, $opened['lastName'] ?? null);
}

$unicode = $crypto->sealName('Bärbel', "O'Brien <script>alert(1)</script>");
$opened = $open($unicode);
$test->assertSame('Umlaute bleiben erhalten', 'Bärbel', $opened['firstName'] ?? null);
$test->assertSame(
    'Sonderzeichen bleiben erhalten',
    "O'Brien <script>alert(1)</script>",
    $opened['lastName'] ?? null
);

echo "\n== Falscher privater Schlüssel ==\n";
$otherPair = sodium_crypto_box_keypair();
$test->assertSame(
    'fremder Schlüssel öffnet das Chiffrat nicht',
    false,
    sodium_crypto_box_seal_open((string) base64_decode($sealedA, true), $otherPair)
);

echo "\n== Namens-HMAC ==\n";
$hash = $crypto->nameHash($first, $last);
$test->assertSame('gleiche Namen ergeben denselben Hash', $hash, $crypto->nameHash($first, $last));
$test->assertNotSame('anderer Vorname ergibt anderen Hash', $hash, $crypto->nameHash('Alan', $last));
$test->assertNotSame('anderer Nachname ergibt anderen Hash', $hash, $crypto->nameHash($first, 'Turing'));
$test->assertNotSame(
    'vertauschte Namensteile ergeben anderen Hash',
    $hash,
    $crypto->nameHash($last, $first)
);
$test->assertNotSame(
    'verschobene Grenze zwischen den Teilen ergibt anderen Hash',
    $crypto->nameHash('AdaLove', 'lace'),
    $hash
);
$test->assertSame('umgebender Whitespace wird normalisiert', $hash, $crypto->nameHash('  Ada ', " Lovelace\t"));
$test->assertSame('Hash ist Hex mit 64 Zeichen', 1, preg_match('/^[0-9a-f]{64}$/', $hash));

$otherPepper = new Crypto($publicKeyHex, 'anderer-pfeffer');
$test->assertNotSame('anderer Pepper ergibt anderen Hash', $hash, $otherPepper->nameHash($first, $last));
$test->assertTrue('der Hash enthält den Namen nicht', !str_contains($hash, strtolower($first)));

echo "\n== Ungültiger öffentlicher Schlüssel ==\n";
$test->assertThrows('zu kurzer Schlüssel wird abgewiesen', static fn () => new Crypto('abcd', 'pfeffer'));
$test->assertThrows(
    'nicht-hexadezimaler Schlüssel wird abgewiesen',
    static fn () => new Crypto(str_repeat('z', 64), 'pfeffer')
);
$test->assertThrows('leerer Schlüssel wird abgewiesen', static fn () => new Crypto('', 'pfeffer'));

exit($test->summary());
