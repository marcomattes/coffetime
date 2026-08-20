<?php

declare(strict_types=1);

/** Framework-free tests for name encryption and hashing. */
require __DIR__ . '/../src/Crypto.php';
use Coffee\Crypto;

$failures = 0;
function check(string $label, bool $condition): void {
    global $failures;
    echo ($condition ? 'ok   ' : 'FAIL ') . $label . PHP_EOL;
    if (!$condition) $failures++;
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

printf("%d failure(s)\n", $failures);
exit($failures === 0 ? 0 : 1);
