<?php

declare(strict_types=1);

/** Framework-free tests for name encryption and hashing. */
require_once __DIR__ . '/../src/Crypto.php';
use Coffee\Crypto;

$failures = 0;
function check(string $label, bool $condition): void {
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
}

$key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($key, $privatePem);
$publicPem = openssl_pkey_get_details($key)['key'];
$crypto = new Crypto($publicPem, 'a-secret-test-pepper');

$first = 'Bärbel';
$last = "O'Brien <script>alert(1)</script>";
$a = $crypto->sealName($first, $last);
$b = $crypto->sealName($first, $last);
check('encryption is randomized', $a !== $b);
check('ciphertext carries its format marker', str_starts_with($a, Crypto::CIPHER_PREFIX));
$cipher = base64_decode(substr($a, strlen(Crypto::CIPHER_PREFIX)), true);
check('ciphertext is valid Base64', $cipher !== false);
$opened = '';
check('matching private key decrypts', openssl_private_decrypt($cipher, $opened, $privatePem, OPENSSL_PKCS1_OAEP_PADDING));
$data = json_decode($opened, true);
check('Unicode first name survives', ($data['firstName'] ?? null) === $first);
check('special characters survive', ($data['lastName'] ?? null) === $last);
check('same normalized name has same HMAC', $crypto->nameHash('  Ada ', 'Lovelace') === $crypto->nameHash('Ada', 'Lovelace'));
check('different names have different HMACs', $crypto->nameHash('Ada', 'Lovelace') !== $crypto->nameHash('Grace', 'Hopper'));
check('invalid public key is rejected', (function (): bool { try { new Crypto('invalid', 'x'); } catch (InvalidArgumentException) { return true; } return false; })());

/** True when constructing Crypto with this pepper is refused. */
$pepperRejected = static function (string $pepper) use ($publicPem): bool {
    try {
        new Crypto($publicPem, $pepper);
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
};

// name_hash is a keyed fingerprint: a missing or publicly known pepper makes
// every registered name recomputable from a copy of the database, so it must
// fail loudly instead of silently producing weak hashes.
check('an empty pepper is rejected', $pepperRejected(''));
check('a too-short pepper is rejected', $pepperRejected('short'));
check('the config.example.php placeholder pepper is rejected', $pepperRejected('change-me-to-a-long-random-value'));
check('a real random pepper is accepted', !$pepperRejected(bin2hex(random_bytes(32))));

// The public key can be validated on its own, which is what the setup wizard
// does before any pepper exists.
check('assertValidPublicKey accepts a real 4096-bit key', (function () use ($publicPem): bool {
    try {
        Crypto::assertValidPublicKey($publicPem);
    } catch (Throwable) {
        return false;
    }

    return true;
})());
check('assertValidPublicKey rejects garbage', (function (): bool {
    try {
        Crypto::assertValidPublicKey('not-a-key');
    } catch (InvalidArgumentException) {
        return true;
    }

    return false;
})());

// Control characters JSON-encode to six-byte \uXXXX escapes. Left in, a name
// of the maximum length could outgrow the RSA-OAEP plaintext capacity and
// turn a validation problem into a failed encryption.
check(
    'normalizeNamePart strips control characters',
    Crypto::normalizeNamePart("A\x00d\x07a") === 'Ada'
);
check(
    'normalizeNamePart still collapses whitespace to single spaces',
    Crypto::normalizeNamePart("  Ada \n\t Lovelace  ") === 'Ada Lovelace'
);
check(
    'a maximum-length control-character name no longer breaks encryption',
    (function (): bool {
        $raw = str_repeat("\x01", Crypto::NAME_MAX_LENGTH);
        $normalized = Crypto::normalizeNamePart($raw);
        // Everything is stripped, so this is caught as an empty name upstream
        // rather than reaching the cipher at all.
        return $normalized === '';
    })()
);
check(
    'a name too long to seal is refused with InvalidArgumentException, not a cipher error',
    (function () use ($crypto): bool {
        try {
            // Far beyond the 470-byte OAEP capacity.
            $crypto->sealName(str_repeat('ä', 400), str_repeat('ö', 400));
        } catch (InvalidArgumentException) {
            return true;
        } catch (Throwable) {
            return false;
        }

        return false;
    })()
);

printf("%d failure(s)\n", $failures);
exit($failures === 0 ? 0 : 1);
