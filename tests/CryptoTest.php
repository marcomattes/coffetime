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

// openssl_pkey_get_public() reads a "file://…" argument off disk instead of
// parsing it. The key is user-supplied (pasted or uploaded in the setup
// wizard), so anything that is not an inline PEM block has to be refused
// before OpenSSL ever sees it.
$keyRejected = static function (string $candidate): bool {
    try {
        Crypto::assertValidPublicKey($candidate);
    } catch (InvalidArgumentException) {
        return true;
    } catch (Throwable) {
        return false;
    }

    return false;
};

$publicKeyFile = tempnam(sys_get_temp_dir(), 'coffee-key-');
file_put_contents($publicKeyFile, $publicPem);
try {
    check('a file:// reference is not read from disk', $keyRejected('file://' . $publicKeyFile));
    check('a bare path is not read from disk', $keyRejected($publicKeyFile));
} finally {
    unlink($publicKeyFile);
}
check('/etc/passwd is not read from disk', $keyRejected('file:///etc/passwd'));

// The PEM is re-assembled from its decoded body, so everything outside the
// block is gone by construction rather than merely tolerated.
check('a key with trailing junk is rejected', $keyRejected($publicPem . "file:///etc/passwd\n"));
check('a key with leading junk is rejected', $keyRejected("file:///etc/passwd\n" . $publicPem));
check('an unknown PEM label is rejected', $keyRejected(
    str_replace('PUBLIC KEY', 'CERTIFICATE', $publicPem)
));
check('a PEM with a non-base64 body is rejected', $keyRejected(
    "-----BEGIN PUBLIC KEY-----\nnot base64 at all!\n-----END PUBLIC KEY-----\n"
));
check('a PEM with an empty body is rejected', $keyRejected(
    "-----BEGIN PUBLIC KEY-----\n-----END PUBLIC KEY-----\n"
));
check('mismatched BEGIN/END labels are rejected', $keyRejected(
    str_replace('-----END PUBLIC KEY-----', '-----END RSA PUBLIC KEY-----', $publicPem)
));

// Re-assembly must not turn a valid key into an invalid one: whatever line
// wrapping, surrounding whitespace or line endings the key arrives with, it
// still has to load.
check('a key wrapped in extra whitespace is still accepted', !$keyRejected("\n\n  " . $publicPem . "  \n\n"));
check('a key with CRLF line endings is still accepted', !$keyRejected(
    str_replace("\n", "\r\n", $publicPem)
));
check('an unwrapped single-line body is still accepted', !$keyRejected(
    '-----BEGIN PUBLIC KEY-----' . "\n"
    . str_replace("\n", '', trim(str_replace(['-----BEGIN PUBLIC KEY-----', '-----END PUBLIC KEY-----'], '', $publicPem)))
    . "\n" . '-----END PUBLIC KEY-----' . "\n"
));
// A rebuilt key still has to encrypt to the same private key.
check('a rebuilt key still seals to the original private key', (function () use ($publicPem, $privatePem): bool {
    $rebuilt = new Crypto("\n" . trim($publicPem) . "\n\n", 'a-secret-test-pepper');
    $sealed = $rebuilt->sealName('Ada', 'Lovelace');
    $cipher = base64_decode(substr($sealed, strlen(Crypto::CIPHER_PREFIX)), true);
    $opened = '';
    if ($cipher === false || !openssl_private_decrypt($cipher, $opened, $privatePem, OPENSSL_PKCS1_OAEP_PADDING)) {
        return false;
    }
    $data = json_decode($opened, true);

    return ($data['firstName'] ?? null) === 'Ada' && ($data['lastName'] ?? null) === 'Lovelace';
})());

printf("%d failure(s)\n", $failures);
exit($failures === 0 ? 0 : 1);
