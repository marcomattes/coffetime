<?php

declare(strict_types=1);

/**
 * HTTP integration coverage: boots the real PHP built-in server against a
 * temp config/database and drives it exactly like a browser would, over
 * curl. The server process is always terminated on exit, success or not.
 */

require __DIR__ . '/helpers.php';

$projectRoot = dirname(__DIR__);
$workspace = make_temp_workspace('coffee-api');

$keys = generate_rsa_keypair();
$testToken = 'test-token-' . bin2hex(random_bytes(8));

$configPath = write_test_config($workspace, [
    'priceCents' => 150,
    'invite' => 'TEST-INVITE',
    'admins' => ['1'],
    'dbPath' => $workspace . '/data/coffee.sqlite',
    'testMode' => true,
    'testToken' => $testToken,
    'adminPublicKey' => $keys['public'],
    'namePepper' => 'test-pepper',
]);

/** @var resource|null $serverProcess */
$serverProcess = null;
register_shutdown_function(static function () use (&$serverProcess): void {
    if ($serverProcess !== null) {
        stop_php_server($serverProcess);
    }
});

[$serverProcess, $port] = start_php_server($projectRoot . '/public', $configPath);
$client = new HttpClient('http://127.0.0.1:' . $port);
$testHeaders = ['X-Test-Token' => $testToken];

// ------------------------------------------------------- unauthenticated ---

$r = $client->get('/api/me');
check('GET /api/me without a session is 401', $r['status'] === 401);
check('GET /api/me without a session reports "unauthorized"', ($r['json']['error'] ?? null) === 'unauthorized');

$r = $client->get('/nonsense');
check('an unknown path is 404', $r['status'] === 404);

$r = $client->post('/api/me');
check(
    'POST /api/me without a session is still 401 (session check precedes method check)',
    $r['status'] === 401
);

$r = $client->get('/api/logout');
check('GET /api/logout without a session is 401', $r['status'] === 401);

// ---------------------------------------------------- registration guards ---

$r = $client->post('/api/register/options', ['invite' => 'WRONG-INVITE', 'firstName' => 'A', 'lastName' => 'B']);
check('register/options with the wrong invite is 403', $r['status'] === 403);
check('register/options with the wrong invite reports invalid_invite', ($r['json']['error'] ?? null) === 'invalid_invite');

$r = $client->post('/api/register/options', ['invite' => 'TEST-INVITE', 'firstName' => '', 'lastName' => '']);
check('register/options with a valid invite but empty names is 400', $r['status'] === 400);
check('register/options with empty names reports invalid_name', ($r['json']['error'] ?? null) === 'invalid_name');

// ----------------------------------------------------------- test control ---

$r = $client->post('/api/test/reset');
check('test endpoints are hidden (404) without the test token', $r['status'] === 404);

$r = $client->post('/api/test/reset', null, $testHeaders);
check('test/reset with the correct token succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);

$seedBody = [
    'users' => [
        ['firstName' => 'Admin', 'lastName' => 'Boss', 'coffees' => 5, 'paidCents' => 200],
        ['firstName' => 'Normal', 'lastName' => 'Person', 'coffees' => 2, 'paidCents' => 0],
        ['firstName' => 'Zero', 'lastName' => 'Case', 'coffees' => 0, 'paidCents' => 0],
    ],
];
$r = $client->post('/api/test/seed', $seedBody, $testHeaders);
check('test/seed succeeds', $r['status'] === 200);
$seeded = $r['json']['users'] ?? [];
check('test/seed returns one id per seeded user', is_array($seeded) && count($seeded) === 3);
$adminId = $seeded[0]['id'] ?? null;
$normalId = $seeded[1]['id'] ?? null;
$zeroId = $seeded[2]['id'] ?? null;
check('the first seeded user becomes id "1" (configured as admin)', $adminId === '1');

$r = $client->get('/api/test/state', $testHeaders);
check('test/state succeeds', $r['status'] === 200);
check('test/state reflects the configured priceCents', ($r['json']['config']['priceCents'] ?? null) === 150);
$stateUsers = $r['json']['users'] ?? [];
$adminStateRow = null;
foreach ($stateUsers as $row) {
    if (($row['id'] ?? null) === $adminId) {
        $adminStateRow = $row;
    }
}
check('test/state marks the configured admin id as admin', ($adminStateRow['admin'] ?? null) === true);

// ------------------------------------------------------------- test login ---

$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('test/login as the seeded non-admin user succeeds', $r['status'] === 200);
check('test/login sets a session cookie', $client->cookie('coffee_session') !== null);

// ------------------------------------------------------------------ /me ---

$r = $client->get('/api/me');
check('GET /api/me with a session is 200', $r['status'] === 200);
$me = $r['json'];
check('me.coffees matches the seeded value', ($me['coffees'] ?? null) === 2);
check('me.balanceCents = coffees * priceCents - paidCents', ($me['balanceCents'] ?? null) === 2 * 150);
check('me.priceCents matches the configured price', ($me['priceCents'] ?? null) === 150);
check('me.admin is false for the non-admin user', ($me['admin'] ?? null) === false);

// -------------------------------------------------------------- version ---

// Public on purpose: the footer names the build on the sign-in screen too.
$anonymous = new HttpClient('http://127.0.0.1:' . $port);
$r = $anonymous->get('/api/version');
check('GET /api/version without a session is 200', $r['status'] === 200);
check('version reports a non-empty build id', is_string($r['json']['version'] ?? null) && ($r['json']['version'] ?? '') !== '');
check('version reports a builtAt timestamp', is_int($r['json']['builtAt'] ?? null));
$r = $anonymous->post('/api/version');
check('POST /api/version is 405 – it is a read', $r['status'] === 405);

// The shell carries the same build, so the badge has something to show
// before the request above has even come back.
$r = $anonymous->get('/');
check('the shell embeds the build id for the footer badge', str_contains((string) $r['raw'], 'data-testid="build-badge"'));

// --------------------------------------------------------------- coffee ---

$r = $client->post('/api/coffee');
check('POST /api/coffee increments the counter', ($r['json']['coffees'] ?? null) === 3);
check('POST /api/coffee updates balanceCents accordingly', ($r['json']['balanceCents'] ?? null) === 3 * 150);

$r = $client->post('/api/coffee/undo');
check('POST /api/coffee/undo decrements the counter back', ($r['json']['coffees'] ?? null) === 2);

// ----------------------------------------------------------- undo window ---

// Undo only takes back a mis-tap, so the endpoint reports how long it is
// still willing to, and refuses once that time has passed.
$r = $client->post('/api/coffee');
check('a fresh booking reports a positive undo window', ($r['json']['undoableSeconds'] ?? 0) > 0);
$r = $client->get('/api/me');
check('/api/me carries the same undo window', ($r['json']['undoableSeconds'] ?? 0) > 0);
// Mirrors Config::UNDO_WINDOW_DEFAULT -- this suite talks to a running
// server over HTTP and does not load the application's classes.
$undoWindow = 300;
check(
    'the undo window never exceeds the configured grace period',
    ($r['json']['undoableSeconds'] ?? 0) <= $undoWindow
);

// Jump past the window rather than waiting it out. The session survives: it
// idles out after 30 days, not minutes.
$r = $client->post(
    '/api/test/clock',
    ['offsetSeconds' => $undoWindow + 60],
    $testHeaders
);
check('test/clock jumps past the undo window', $r['status'] === 200);
$r = $client->get('/api/me');
check('past the window /api/me reports nothing to undo', ($r['json']['undoableSeconds'] ?? null) === 0);
$r = $client->post('/api/coffee/undo');
check('undo past the window is refused with 409', $r['status'] === 409);
check('undo past the window reports undo_expired', ($r['json']['error'] ?? null) === 'undo_expired');
$r = $client->get('/api/me');
check('the refused undo left the counter alone', ($r['json']['coffees'] ?? null) === 3);
check('the refused undo left the balance alone', ($r['json']['balanceCents'] ?? null) === 3 * 150);

$client->post('/api/test/clock', ['offsetSeconds' => 0], $testHeaders);
$r = $client->post('/api/coffee/undo');
check('with the clock restored the same booking is undoable again', ($r['json']['coffees'] ?? null) === 2);

// Book one coffee for the history check below.
$r = $client->post('/api/coffee');
check('booking again before the history check increments to 3', ($r['json']['coffees'] ?? null) === 3);

// --------------------------------------------------------------- stats ---

$r = $client->get('/api/stats');
check('GET /api/stats is 200', $r['status'] === 200);
$stats = $r['json'];
check('stats.total sums every seeded user\'s coffees', ($stats['total'] ?? null) === 5 + 3 + 0);
check('stats.users counts all three seeded users', ($stats['users'] ?? null) === 3);
check('stats.rank places the caller between the leader and the trailing user', ($stats['rank'] ?? null) === 2);

// --------------------------------------------------------------- history ---

$r = $client->get('/api/history');
check('GET /api/history is 200', $r['status'] === 200);
$history = $r['json'];
check('history has 28 days', is_array($history['days'] ?? null) && count($history['days']) === 28);
check('history.today reflects the coffee booked just now', ($history['today'] ?? null) === 1);

// ------------------------------------------------------- undo floors at 0 ---

$r = $client->post('/api/test/login', ['userId' => $zeroId], $testHeaders);
check('test/login as the zero-coffee user succeeds', $r['status'] === 200);
$r = $client->post('/api/coffee/undo');
check('undo below zero stays at zero', ($r['json']['coffees'] ?? null) === 0);
$r = $client->post('/api/coffee/undo');
check('a second undo at zero still stays at zero', ($r['json']['coffees'] ?? null) === 0);

// ---------------------------------------------------- device linking codes ---

// linkCode without a session is 401 (a protected endpoint).
$client->clearCookies();
$r = $client->post('/api/link/code');
check('POST /api/link/code without a session is 401', $r['status'] === 401);

$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('test/login as the non-admin user for the linking tests succeeds', $r['status'] === 200);

$r = $client->post('/api/link/code');
check('POST /api/link/code with a session is 200', $r['status'] === 200);
$selfCode = $r['json']['code'] ?? '';
check(
    'the self-service code matches the XXXX-XXXX unambiguous-alphabet format',
    is_string($selfCode) && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $selfCode) === 1
);
check('the self-service code carries an expiresAt in the future', (($r['json']['expiresAt'] ?? 0) > time()));

// /api/admin/link-code as a non-admin is 403.
$r = $client->post('/api/admin/link-code', ['userId' => $normalId]);
check('POST /api/admin/link-code as a non-admin is 403', $r['status'] === 403);

// As admin: unknown userId is 404, a real one succeeds.
$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('test/login as the admin user for the linking tests succeeds', $r['status'] === 200);

$r = $client->post('/api/admin/link-code', ['userId' => '999999']);
check('POST /api/admin/link-code for an unknown user is 404', $r['status'] === 404);
check('POST /api/admin/link-code for an unknown user reports unknown_user', ($r['json']['error'] ?? null) === 'unknown_user');

$r = $client->post('/api/admin/link-code', ['userId' => $zeroId]);
check('POST /api/admin/link-code for a real user succeeds', $r['status'] === 200);
$adminIssuedCode = $r['json']['code'] ?? '';
check(
    'the admin-issued code matches the same XXXX-XXXX format',
    is_string($adminIssuedCode) && preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $adminIssuedCode) === 1
);

// /api/link/options is public: no session needed, and garbage codes are rejected.
$client->clearCookies();
$r = $client->post('/api/link/options', ['code' => 'NOTAREAL-CODE']);
check('POST /api/link/options with a garbage code is 400', $r['status'] === 400);
check('POST /api/link/options with a garbage code reports invalid_code', ($r['json']['error'] ?? null) === 'invalid_code');

$r = $client->post('/api/link/options', []);
check('POST /api/link/options without a code field is 400', $r['status'] === 400);
check('POST /api/link/options without a code field reports invalid_code', ($r['json']['error'] ?? null) === 'invalid_code');

// A valid (admin-issued) code produces real creation options.
$r = $client->post('/api/link/options', ['code' => $adminIssuedCode]);
check('POST /api/link/options with a valid code is 200', $r['status'] === 200);
$linkOptions = $r['json'];
check('link/options returns a challenge', is_string($linkOptions['challenge'] ?? null) && $linkOptions['challenge'] !== '');
check('link/options returns rp information', is_array($linkOptions['rp'] ?? null));
check(
    'link/options user.name uses the same opaque coffee- label scheme as registration',
    str_starts_with((string) ($linkOptions['user']['name'] ?? ''), 'coffee-')
);
// Assert the field is really there first: a bare "does not contain a space"
// check would also pass when user.name is missing entirely.
$linkUserName = $linkOptions['user']['name'] ?? null;
check('link/options returns a user.name at all', is_string($linkUserName) && $linkUserName !== '');
check(
    'link/options carries no plaintext name anywhere in user.name',
    is_string($linkUserName) && $linkUserName !== '' && !str_contains($linkUserName, ' ')
);

// peek() semantics: calling options twice with the same code must both succeed
// (the code is not consumed just by building options for it).
$r = $client->post('/api/link/options', ['code' => $adminIssuedCode]);
check('a second call to link/options with the same still-unused code also succeeds', $r['status'] === 200);
$secondLinkOptions = $r['json'];
check(
    'the second call gets the same user handle as the first (same account, same handle)',
    ($secondLinkOptions['user']['id'] ?? null) === ($linkOptions['user']['id'] ?? null)
);
check(
    'the second call gets a fresh challenge (a new WebAuthn ceremony each time)',
    ($secondLinkOptions['challenge'] ?? null) !== ($linkOptions['challenge'] ?? null)
);

// /api/link/verify with a garbage credential must be rejected before ever
// touching the link code, and must not burn it.
$r = $client->post('/api/link/verify', ['code' => $adminIssuedCode, 'credential' => ['not' => 'a credential']]);
check('POST /api/link/verify with a garbage credential is 400', $r['status'] === 400);
check(
    'link/verify with a garbage credential reports invalid_credential',
    ($r['json']['error'] ?? null) === 'invalid_credential'
);

$r = $client->post('/api/link/options', ['code' => $adminIssuedCode]);
check('the link code still works for link/options after a failed verify attempt (not burned)', $r['status'] === 200);

// A well-formed but bogus credential (passes the shape check, fails
// attestation) must also leave the code usable afterwards. It cannot carry a
// real challenge match without a genuine ceremony, so this exercises the
// invalid_credential/challenge_invalid path rather than full attestation
// (which needs a real authenticator and is out of scope here).
$r = $client->post('/api/link/verify', [
    'code' => $adminIssuedCode,
    'credential' => [
        'id' => 'bogus-id',
        'rawId' => 'bogus-id',
        'type' => 'public-key',
        'response' => [
            'clientDataJSON' => base64_encode(json_encode(['type' => 'webauthn.create', 'challenge' => 'not-a-real-challenge'])),
            'attestationObject' => 'bogus',
        ],
    ],
]);
check('link/verify with a well-formed but bogus credential is rejected as 400', $r['status'] === 400);
check(
    'a bogus (non-matching) challenge reports challenge_invalid',
    ($r['json']['error'] ?? null) === 'challenge_invalid'
);

$r = $client->post('/api/link/options', ['code' => $adminIssuedCode]);
check('the link code still works for link/options after a bogus-credential verify attempt (not burned)', $r['status'] === 200);

// -------------------------------------------------------------- non-admin ---

$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user succeeds', $r['status'] === 200);
$r = $client->get('/api/admin/users');
check('a non-admin session gets 403 from /api/admin/users', $r['status'] === 403);
check('the 403 reports "forbidden"', ($r['json']['error'] ?? null) === 'forbidden');

// ----------------------------------------------------------------- admin ---

$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('test/login as the admin user succeeds', $r['status'] === 200);

$r = $client->get('/api/admin/users');
check('GET /api/admin/users as an admin is 200', $r['status'] === 200);
$adminUsers = $r['json']['users'] ?? [];
check('admin/users lists all three seeded users', is_array($adminUsers) && count($adminUsers) === 3);
$allPrefixed = true;
$allHavePaidCents = true;
foreach ($adminUsers as $row) {
    if (!str_starts_with((string) ($row['nameEncrypted'] ?? ''), 'rsa-oaep-sha1:')) {
        $allPrefixed = false;
    }
    if (!array_key_exists('paidCents', $row)) {
        $allHavePaidCents = false;
    }
}
check('every admin/users row carries an rsa-oaep-sha1: ciphertext', $allPrefixed);
check('every admin/users row carries paidCents', $allHavePaidCents);

$adminEncryptedName = null;
foreach ($adminUsers as $row) {
    if (($row['id'] ?? null) === $adminId) {
        $adminEncryptedName = $row['nameEncrypted'] ?? null;
    }
}

$r = $client->post('/api/admin/payment', ['userId' => $normalId, 'amountCents' => 500]);
check('admin/payment with a valid amount succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
$paidUser = $r['json']['user'] ?? [];
check('admin/payment adds to paidCents', ($paidUser['paidCents'] ?? null) === 500);
check(
    'admin/payment reduces the balance by exactly the paid amount',
    ($paidUser['balanceCents'] ?? null) === (3 * 150 - 500)
);

$r = $client->post('/api/admin/payment', ['userId' => $normalId, 'amountCents' => 0]);
check('admin/payment with amountCents 0 is 400', $r['status'] === 400);
check('admin/payment with amountCents 0 reports invalid_amount', ($r['json']['error'] ?? null) === 'invalid_amount');

$r = $client->post('/api/admin/payment', ['userId' => $normalId, 'amountCents' => 1.5]);
check('admin/payment with a non-integer amountCents is 400', $r['status'] === 400);
check('admin/payment with a float amountCents reports invalid_amount', ($r['json']['error'] ?? null) === 'invalid_amount');

$r = $client->post('/api/admin/payment', ['userId' => '999999', 'amountCents' => 500]);
check('admin/payment for an unknown user is 404', $r['status'] === 404);
check('admin/payment for an unknown user reports unknown_user', ($r['json']['error'] ?? null) === 'unknown_user');

// ------------------------------------------------- admin password login ---

// The seeded admin is "Admin Boss"; the password path finds the account by
// the HMAC of exactly that name, since there is no username in the schema.
$adminPassword = 'correct horse battery staple';

$r = $client->get('/api/admin/settings');
check('admin/settings reports passwordSet false before a password is set', ($r['json']['passwordSet'] ?? null) === false);
check('admin/settings reports passwordSetAt 0 before a password is set', ($r['json']['passwordSetAt'] ?? null) === 0);
check('admin/settings publishes the server-side minimum length', ($r['json']['passwordMinLength'] ?? null) === 12);

$r = $client->post('/api/admin/password', ['password' => 'short']);
check('admin/password with a too-short password is 400', $r['status'] === 400);
check('admin/password with a too-short password reports invalid_password', ($r['json']['error'] ?? null) === 'invalid_password');

$r = $client->post('/api/admin/password', []);
check('admin/password with no password field is 400', $r['status'] === 400);

$r = $client->post('/api/admin/password', ['password' => $adminPassword]);
check('admin/password with an acceptable password succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
check('admin/password echoes passwordSet true', ($r['json']['passwordSet'] ?? null) === true);
check('admin/password stamps passwordSetAt', is_int($r['json']['passwordSetAt'] ?? null) && ($r['json']['passwordSetAt'] ?? 0) > 0);

$r = $client->get('/api/admin/settings');
check('admin/settings reports passwordSet true afterwards', ($r['json']['passwordSet'] ?? null) === true);

// A non-admin session must not be able to give itself a password.
$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user for the password check succeeds', $r['status'] === 200);
$r = $client->post('/api/admin/password', ['password' => $adminPassword]);
check('admin/password from a non-admin session is 403', $r['status'] === 403);
check('the 403 from admin/password reports "forbidden"', ($r['json']['error'] ?? null) === 'forbidden');

// ------------------------------------------------ password sign-in itself ---

$client->clearCookies();

$r = $client->post('/api/login/password', [
    'firstName' => 'Admin',
    'lastName' => 'Boss',
    'password' => 'wrong password entirely',
]);
check('login/password with the wrong password is 401', $r['status'] === 401);
check('login/password with the wrong password reports "unauthorized"', ($r['json']['error'] ?? null) === 'unauthorized');
check('a failed login/password sets no session cookie', ($client->cookie('coffee_session') ?? '') === '');

$r = $client->post('/api/login/password', [
    'firstName' => 'Nobody',
    'lastName' => 'Here',
    'password' => $adminPassword,
]);
check('login/password for an unknown name is 401', $r['status'] === 401);
check(
    'an unknown name answers exactly like a wrong password (no account oracle)',
    ($r['json']['error'] ?? null) === 'unauthorized'
);

// A user with no password of their own can never be signed in by password,
// whatever is sent — including the admin's own password.
$r = $client->post('/api/login/password', [
    'firstName' => 'Normal',
    'lastName' => 'Person',
    'password' => $adminPassword,
]);
check('login/password against an account without a password is 401', $r['status'] === 401);

$r = $client->post('/api/login/password', [
    'firstName' => 'Admin',
    'lastName' => 'Boss',
    'password' => $adminPassword,
]);
check('login/password with the correct password is 200', $r['status'] === 200);
check('login/password returns the user view', ($r['json']['user']['id'] ?? null) === $adminId);
check('the password-authenticated user is admin', ($r['json']['user']['admin'] ?? null) === true);
check('login/password sets a session cookie', ($client->cookie('coffee_session') ?? '') !== '');

$r = $client->get('/api/me');
check('the session created by login/password is a real session', $r['status'] === 200);
check('GET /api/me after a password sign-in reports the admin account', ($r['json']['id'] ?? null) === $adminId);

// Name normalization is shared with registration (Crypto::normalizeNamePart),
// so surrounding whitespace must not decide whether the admin gets in. Letter
// case is NOT normalized there and deliberately still matters.
$client->clearCookies();
$r = $client->post('/api/login/password', [
    'firstName' => '  Admin  ',
    'lastName' => 'Boss',
    'password' => $adminPassword,
]);
check('login/password normalizes surrounding whitespace in the name', $r['status'] === 200);

// ------------------------------------------------------ password removal ---

$r = $client->post('/api/admin/password', ['remove' => true]);
check('admin/password with remove:true succeeds', $r['status'] === 200);
check('removal reports passwordSet false', ($r['json']['passwordSet'] ?? null) === false);
check('removal resets passwordSetAt', ($r['json']['passwordSetAt'] ?? null) === 0);

$client->clearCookies();
$r = $client->post('/api/login/password', [
    'firstName' => 'Admin',
    'lastName' => 'Boss',
    'password' => $adminPassword,
]);
check('the removed password no longer signs in', $r['status'] === 401);

// Back to an admin session for the sections that follow.
$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('test/login as the admin user after the password tests succeeds', $r['status'] === 200);

// -------------------------------------------------------- price changes ---

// Regression coverage for retroactive re-pricing: booking a coffee, raising
// the configured price, then booking another must charge each booking its
// own price rather than re-pricing the first one at the new rate.
$r = $client->post('/api/test/login', ['userId' => $zeroId], $testHeaders);
check('test/login as the zero-coffee user for the price-change test succeeds', $r['status'] === 200);

$r = $client->post('/api/coffee');
check('first coffee at the original price 150 books fine', ($r['json']['coffees'] ?? null) === 1);
check('balance after the first coffee is 150', ($r['json']['balanceCents'] ?? null) === 150);

// The server re-reads config.php on every request, so rewriting the file
// directly is enough to simulate the admin changing the price mid-session.
// This must NOT go through write_test_config() again: that helper drops and
// recreates the database on a MySQL/MariaDB test run (via test_db_config()),
// which would wipe everything seeded above. Reading back the file that is
// already in place and only touching priceCents keeps the rest — including
// any 'db' override — untouched.
$priceTestConfig = require $configPath;
$priceTestConfig['priceCents'] = 300;
file_put_contents($configPath, "<?php\nreturn " . var_export($priceTestConfig, true) . ";\n");

$r = $client->post('/api/coffee');
check('second coffee after the price change books fine', ($r['json']['coffees'] ?? null) === 2);
check(
    'balance after the price change is the sum of each booking\'s own price (150 + 300), not 2 * 300',
    ($r['json']['balanceCents'] ?? null) === 150 + 300
);

$r = $client->get('/api/me');
check('me.priceCents reflects the newly configured price', ($r['json']['priceCents'] ?? null) === 300);
check(
    'me.balanceCents still reflects per-booking prices, not the new price retroactively applied',
    ($r['json']['balanceCents'] ?? null) === 150 + 300
);

// Undo must refund the last booking's own price (300), not the new
// configured price applied twice or the older price of the first booking.
$r = $client->post('/api/coffee/undo');
check('undo after the price change removes the last booking\'s own price', ($r['json']['balanceCents'] ?? null) === 150);

// Restore the original price so nothing lingers for whatever runs next
// (same direct-rewrite approach, for the same reason as above).
$priceTestConfig['priceCents'] = 150;
file_put_contents($configPath, "<?php\nreturn " . var_export($priceTestConfig, true) . ";\n");
$client->post('/api/coffee/undo'); // back to 0 coffees, zeroId is otherwise untouched below

// ------------------------------------------------------ offline idempotency ---

// zeroId is at 0 coffees here and otherwise unused for the rest of this
// file, so it is a safe account for exercising the offline-queue
// idempotency contract: a client-generated eventId lets a retried booking
// collapse into the original instead of double-booking.

$eventId = 'evt-' . bin2hex(random_bytes(6));
$r = $client->post('/api/coffee', ['eventId' => $eventId]);
check('booking with an eventId succeeds', $r['status'] === 200);
check('booking with an eventId increments the counter', ($r['json']['coffees'] ?? null) === 1);

$r = $client->post('/api/coffee', ['eventId' => $eventId]);
check('replaying the SAME eventId is still 200 (idempotent, not an error)', $r['status'] === 200);
check(
    'replaying the SAME eventId returns the unchanged counter, not a second increment',
    ($r['json']['coffees'] ?? null) === 1
);

$otherEventId = 'evt-' . bin2hex(random_bytes(6));
$r = $client->post('/api/coffee', ['eventId' => $otherEventId]);
check('a DIFFERENT eventId books normally and increments the counter', ($r['json']['coffees'] ?? null) === 2);

foreach (['short', str_repeat('a', 65), 'has spaces!', 'bad$chars'] as $badEventId) {
    $r = $client->post('/api/coffee', ['eventId' => $badEventId]);
    check("a malformed eventId ({$badEventId}) is 400", $r['status'] === 400);
    check("a malformed eventId ({$badEventId}) reports invalid_event", ($r['json']['error'] ?? null) === 'invalid_event');
}
$r = $client->get('/api/me');
check('the counter is unchanged after every malformed-eventId attempt', ($r['json']['coffees'] ?? null) === 2);

// Legacy clients that send no eventId at all must still work exactly as before.
$r = $client->post('/api/coffee');
check('booking WITHOUT an eventId (legacy client) still works', ($r['json']['coffees'] ?? null) === 3);

// Clean up: bring zeroId back to 0 coffees (three real bookings happened
// above: $eventId, $otherEventId, and the legacy one) so nothing lingers for
// whatever runs after this block.
$client->post('/api/coffee/undo');
$client->post('/api/coffee/undo');
$client->post('/api/coffee/undo');
$r = $client->get('/api/me');
check('zeroId is back to 0 coffees after cleanup', ($r['json']['coffees'] ?? null) === 0);

// ------------------------------------------------------------ clock shift ---

$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user for the clock test succeeds', $r['status'] === 200);

$r = $client->post('/api/test/clock', ['offsetSeconds' => 86400], $testHeaders);
check('test/clock accepts a one-day offset', $r['status'] === 200 && ($r['json']['offsetSeconds'] ?? null) === 86400);

$client->post('/api/coffee');
$r = $client->get('/api/history');
$shiftedHistory = $r['json'];
check(
    'after shifting the clock a day forward, history\'s last day is tomorrow',
    ($shiftedHistory['days'][27]['date'] ?? null) === gmdate('Y-m-d', time() + 86400)
);
check('booking on the shifted day shows up as today\'s count', ($shiftedHistory['today'] ?? null) >= 1);

$r = $client->get('/api/me');
check('streakDays is at least 1 right after booking on the shifted day', ($r['json']['streakDays'] ?? 0) >= 1);

// Restore the clock so nothing lingers for whatever runs next on this host.
$client->post('/api/test/clock', ['offsetSeconds' => 0], $testHeaders);

// ------------------------------------------------------------- reminders ---

// The reminder endpoints must be deterministic regardless of the real date
// this suite runs on, so every check below pins the server clock to a fixed
// timestamp via the test clock (offset = target - now).
$clockTo = static function (int $target) use ($client, $testHeaders): void {
    $client->post('/api/test/clock', ['offsetSeconds' => $target - time()], $testHeaders);
};

$client->clearCookies();
$r = $client->get('/api/reminders');
check('GET /api/reminders without a session is 401', $r['status'] === 401);

// Sessions idle out after 30 days, so every clock jump below is followed by
// a fresh test/login (which is public and stamps the session at the shifted
// clock). normalId's balance at this point: tab 4 * 150 = 600, paid 500 ->
// +100 open.
$clockTo(gmmktime(12, 0, 0, 6, 15, 2031)); // mid-month: nothing is due
$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('test/login as the non-admin user for the reminder tests succeeds', $r['status'] === 200);

$r = $client->get('/api/reminders');
check('GET /api/reminders mid-month is 200', $r['status'] === 200);
check('mid-month, no month-end reminder is due', ($r['json']['monthEnd'] ?? null) === null && array_key_exists('monthEnd', $r['json']));
check('without an admin request, no admin reminder is due', ($r['json']['admin'] ?? null) === null && array_key_exists('admin', $r['json']));

// A non-admin cannot queue reminders for others.
$r = $client->post('/api/admin/remind', ['userId' => $adminId]);
check('POST /api/admin/remind as a non-admin is 403', $r['status'] === 403);

$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('test/login as the admin for the reminder tests succeeds', $r['status'] === 200);

$r = $client->post('/api/admin/remind', ['userId' => '999999']);
check('admin/remind for an unknown user is 404', $r['status'] === 404);
check('admin/remind for an unknown user reports unknown_user', ($r['json']['error'] ?? null) === 'unknown_user');

$r = $client->post('/api/admin/remind', ['userId' => $normalId]);
check('admin/remind for a real user succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);

$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user after the admin queued a reminder succeeds', $r['status'] === 200);

$r = $client->get('/api/reminders');
$adminReminder = $r['json']['admin'] ?? null;
check('after admin/remind, GET /api/reminders carries the admin reminder', is_array($adminReminder));
$remindRequestedAt = $adminReminder['requestedAt'] ?? null;
check('the admin reminder carries a positive requestedAt timestamp', is_int($remindRequestedAt) && $remindRequestedAt > 0);
check('the admin reminder carries the open balance', ($adminReminder['balanceCents'] ?? null) === 100);
check('the admin request alone does not make a month-end reminder due', ($r['json']['monthEnd'] ?? null) === null && array_key_exists('monthEnd', $r['json']));

$clockTo(gmmktime(12, 0, 0, 6, 30, 2031)); // last day of the month
$r = $client->get('/api/reminders');
$monthEnd = $r['json']['monthEnd'] ?? null;
check('on the last day of the month, the month-end reminder is due', is_array($monthEnd));
check('the month-end reminder names the current month', ($monthEnd['month'] ?? null) === '2031-06');
check('the month-end reminder carries the open balance', ($monthEnd['balanceCents'] ?? null) === 100);

// Reading is not consuming: a second GET still reports both reminders.
$r = $client->get('/api/reminders');
check('a second GET still reports the month-end reminder (read does not consume)', is_array($r['json']['monthEnd'] ?? null));
check('a second GET still reports the admin reminder (read does not consume)', is_array($r['json']['admin'] ?? null));

// Malformed acks are rejected wholesale.
$r = $client->post('/api/reminders/ack', []);
check('an empty ack is 400', $r['status'] === 400);
check('an empty ack reports invalid_ack', ($r['json']['error'] ?? null) === 'invalid_ack');
$r = $client->post('/api/reminders/ack', ['month' => '2031-13']);
check('an ack with an impossible month is 400', $r['status'] === 400);
$r = $client->post('/api/reminders/ack', ['adminRequestedAt' => 0]);
check('an ack with a zero adminRequestedAt is 400', $r['status'] === 400);

// A stale admin ack (device showed an older request) must not clear the
// currently open one.
$r = $client->post('/api/reminders/ack', ['adminRequestedAt' => $remindRequestedAt - 1]);
check('an ack with a stale requestedAt still succeeds', $r['status'] === 200);
$r = $client->get('/api/reminders');
check('the open admin reminder survives a stale ack', is_array($r['json']['admin'] ?? null));

$r = $client->post('/api/reminders/ack', ['month' => '2031-06', 'adminRequestedAt' => $remindRequestedAt]);
check('acknowledging both shown reminders succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
$r = $client->get('/api/reminders');
check('after the ack, the month-end reminder is gone', ($r['json']['monthEnd'] ?? null) === null && array_key_exists('monthEnd', $r['json']));
check('after the ack, the admin reminder is gone', ($r['json']['admin'] ?? null) === null && array_key_exists('admin', $r['json']));

// Catch-up: shortly into the next month the previous month's reminder is
// still the due one -- but here it was already acknowledged, so nothing shows.
$clockTo(gmmktime(12, 0, 0, 7, 3, 2031));
$r = $client->get('/api/reminders');
check('early next month, the already-acknowledged previous month stays silent', ($r['json']['monthEnd'] ?? null) === null && array_key_exists('monthEnd', $r['json']));

// A month that was never acknowledged IS caught up early in the next month.
$clockTo(gmmktime(12, 0, 0, 8, 5, 2031)); // July was never shown; Aug 5 is in the window
$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user after the two-month jump succeeds', $r['status'] === 200);
$r = $client->get('/api/reminders');
check('a missed month-end reminder is caught up early in the following month', (($r['json']['monthEnd']['month'] ?? null) === '2031-07'));

// Without an open balance there is nothing to remind about.
$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('re-login as the admin to settle the balance succeeds', $r['status'] === 200);
$r = $client->post('/api/admin/payment', ['userId' => $normalId, 'amountCents' => 100]);
check('settling the remaining 100 cents succeeds', $r['status'] === 200);
$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the non-admin user with a settled tab succeeds', $r['status'] === 200);
$r = $client->get('/api/reminders');
check('with a settled tab, no month-end reminder is due even in the window', ($r['json']['monthEnd'] ?? null) === null && array_key_exists('monthEnd', $r['json']));

// An admin reminder for a settled tab would tell the user to pay "0.00 €",
// so it is withheld exactly like the month-end notice.
$r = $client->post('/api/test/login', ['userId' => $adminId], $testHeaders);
check('re-login as the admin to queue a reminder on a settled tab succeeds', $r['status'] === 200);
$r = $client->post('/api/admin/remind', ['userId' => $normalId]);
check('queueing a reminder for the settled user succeeds', $r['status'] === 200);
$r = $client->post('/api/test/login', ['userId' => $normalId], $testHeaders);
check('re-login as the settled non-admin user succeeds', $r['status'] === 200);
$r = $client->get('/api/reminders');
check(
    'an admin reminder is withheld while nothing is outstanding',
    ($r['json']['admin'] ?? null) === null && array_key_exists('admin', $r['json'])
);

// Once the user owes something again, the queued reminder does show up.
$r = $client->post('/api/coffee');
check('booking a coffee puts the account back in debt', ($r['json']['balanceCents'] ?? 0) > 0);
$r = $client->get('/api/reminders');
check('the queued admin reminder appears once there is a balance again', is_array($r['json']['admin'] ?? null));
$client->post('/api/coffee/undo');

// Restore the clock so nothing lingers for whatever runs next on this host.
$client->post('/api/test/clock', ['offsetSeconds' => 0], $testHeaders);

// -------------------------------------------------------- response headers ---

// The shell carries the framing/CSP defenses; every state-changing control
// lives on it, so it must not be embeddable.
$r = $client->get('/');
check('the app shell is served', $r['status'] === 200);
$shellHeaders = array_change_key_case($r['headers'], CASE_LOWER);
check('the shell sends a Content-Security-Policy', isset($shellHeaders['content-security-policy']));
check(
    'the shell forbids being framed',
    str_contains((string) ($shellHeaders['content-security-policy'] ?? ''), "frame-ancestors 'none'")
);
check('the shell sends X-Frame-Options: DENY', ($shellHeaders['x-frame-options'] ?? null) === 'DENY');

$r = $client->get('/api/setup/status');
$apiHeaders = array_change_key_case($r['headers'], CASE_LOWER);
check('API responses are not cached', ($apiHeaders['cache-control'] ?? null) === 'no-store');
check('API responses forbid framing too', isset($apiHeaders['content-security-policy']));

// ------------------------------------------------------ oversized request ---

// An unbounded body would be buffered until memory_limit turned it into a
// 500; it is refused with a real status instead.
$r = $client->post('/api/coffee', ['eventId' => str_repeat('a', 300000)]);
check('an oversized request body is refused with 413', $r['status'] === 413);
check('an oversized request body reports payload_too_large', ($r['json']['error'] ?? null) === 'payload_too_large');

// ------------------------------------------------------ decryption roundtrip ---

check('the admin user\'s encrypted name was captured earlier', $adminEncryptedName !== null);
$cipherB64 = substr((string) $adminEncryptedName, strlen('rsa-oaep-sha1:'));
$cipher = base64_decode($cipherB64, true);
check('the ciphertext decodes as valid Base64', $cipher !== false);
$opened = '';
$decrypted = $cipher !== false
    && openssl_private_decrypt($cipher, $opened, $keys['private'], OPENSSL_PKCS1_OAEP_PADDING);
check('the matching private key decrypts the sealed name', $decrypted === true);
$payload = json_decode($opened, true);
check('the decrypted payload carries the seeded first name', ($payload['firstName'] ?? null) === 'Admin');
check('the decrypted payload carries the seeded last name', ($payload['lastName'] ?? null) === 'Boss');

stop_php_server($serverProcess);
$serverProcess = null;

// -------------------------------------------------- first-run setup wizard ---

// A separate server against a separate, completely fresh database: no
// config.php adminPublicKey, no admins list, no users at all — exactly what
// a brand-new deployment looks like before anyone has visited the wizard.
$setupWorkspace = make_temp_workspace('coffee-setup');
$setupKeys = generate_rsa_keypair();
$setupToken = 'setup-token-' . bin2hex(random_bytes(8));
$setupConfigPath = write_test_config($setupWorkspace, [
    'adminPublicKey' => '',
    'admins' => [],
    'invite' => '',
    'namePepper' => '',
    'dbPath' => $setupWorkspace . '/data/coffee.sqlite',
    'testMode' => true,
    'testToken' => $setupToken,
]);

/** @var resource|null $setupServerProcess */
$setupServerProcess = null;
register_shutdown_function(static function () use (&$setupServerProcess): void {
    if ($setupServerProcess !== null) {
        stop_php_server($setupServerProcess);
    }
});

[$setupServerProcess, $setupPort] = start_php_server($projectRoot . '/public', $setupConfigPath);
$setupClient = new HttpClient('http://127.0.0.1:' . $setupPort);
$setupHeaders = ['X-Test-Token' => $setupToken];

$r = $setupClient->get('/api/setup/status');
check('GET /api/setup/status on a fresh deployment is 200', $r['status'] === 200);
check('setup/status reports needsSetup true before init', ($r['json']['needsSetup'] ?? null) === true);
check('setup/status reports the default priceCents before init', ($r['json']['priceCents'] ?? null) === 150);

// Bad input is rejected before a successful init is ever attempted.
$r = $setupClient->post('/api/setup/init', [
    'adminPublicKey' => 'not-a-valid-pem-key',
    'priceCents' => 200,
    'invite' => 'SETUP-INVITE',
]);
check('setup/init with a garbage public key is 400', $r['status'] === 400);
check('setup/init with a garbage public key reports invalid_key', ($r['json']['error'] ?? null) === 'invalid_key');

$r = $setupClient->post('/api/setup/init', [
    'adminPublicKey' => $setupKeys['public'],
    'priceCents' => 0,
    'invite' => 'SETUP-INVITE',
]);
check('setup/init with priceCents 0 is 400', $r['status'] === 400);
check('setup/init with priceCents 0 reports invalid_price', ($r['json']['error'] ?? null) === 'invalid_price');

$r = $setupClient->post('/api/setup/init', [
    'adminPublicKey' => $setupKeys['public'],
    'priceCents' => 200,
    'invite' => 'abc',
]);
check('setup/init with a too-short invite is 400', $r['status'] === 400);
check('setup/init with a too-short invite reports invalid_invite', ($r['json']['error'] ?? null) === 'invalid_invite');

check('setup/status still reports needsSetup true after only rejected attempts', ($setupClient->get('/api/setup/status')['json']['needsSetup'] ?? null) === true);

$r = $setupClient->post('/api/setup/init', [
    'adminPublicKey' => $setupKeys['public'],
    'priceCents' => 200,
    'invite' => 'SETUP-INVITE',
]);
check('setup/init with valid data succeeds', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);

$r = $setupClient->get('/api/setup/status');
check('setup/status reports needsSetup false after a successful init', ($r['json']['needsSetup'] ?? null) === false);
check('setup/status reports the price chosen during init', ($r['json']['priceCents'] ?? null) === 200);

$r = $setupClient->post('/api/setup/init', [
    'adminPublicKey' => $setupKeys['public'],
    'priceCents' => 200,
    'invite' => 'SETUP-INVITE',
]);
check('a second setup/init is 409', $r['status'] === 409);
check('a second setup/init reports already_initialized', ($r['json']['error'] ?? null) === 'already_initialized');

// Crypto is now configured entirely via settings (no config.php edit): the
// standard test-seed path, which encrypts names through Crypto::fromConfig(),
// must work exactly as it does with a config.php-configured key.
$r = $setupClient->post('/api/test/seed', [
    'users' => [
        ['firstName' => 'First', 'lastName' => 'User', 'coffees' => 0, 'paidCents' => 0],
        ['firstName' => 'Second', 'lastName' => 'User', 'coffees' => 0, 'paidCents' => 0],
    ],
], $setupHeaders);
check('seeding users after setup succeeds', $r['status'] === 200);
$setupAdminId = $r['json']['users'][0]['id'] ?? null;
$setupOtherId = $r['json']['users'][1]['id'] ?? null;

// The very first user ever created in this database becomes admin via the
// is_admin flag alone — config.php's admins list is empty here.
$r = $setupClient->post('/api/test/login', ['userId' => $setupOtherId], $setupHeaders);
check('test/login as the second (non-first) setup user succeeds', $r['status'] === 200);
$r = $setupClient->get('/api/me');
check('GET /api/me works for the second setup user', $r['status'] === 200);
check('the second user created after setup is not admin', ($r['json']['admin'] ?? null) === false);
$r = $setupClient->get('/api/admin/settings');
check('a non-admin session gets 403 from /api/admin/settings', $r['status'] === 403);

$r = $setupClient->post('/api/test/login', ['userId' => $setupAdminId], $setupHeaders);
check('test/login as the first (admin) setup user succeeds', $r['status'] === 200);
$r = $setupClient->get('/api/me');
check('GET /api/me works for the first setup user', $r['status'] === 200);
check(
    'the first user created after setup is admin via the DB flag alone (empty config admins list)',
    ($r['json']['admin'] ?? null) === true
);
check('me.priceCents reflects the price chosen during setup', ($r['json']['priceCents'] ?? null) === 200);

$r = $setupClient->get('/api/admin/settings');
check('GET /api/admin/settings as the setup admin is 200', $r['status'] === 200);
check('admin/settings reports the price chosen during setup', ($r['json']['priceCents'] ?? null) === 200);
check('admin/settings reports the invite chosen during setup', ($r['json']['invite'] ?? null) === 'SETUP-INVITE');

$r = $setupClient->post('/api/admin/settings/update', []);
check('admin/settings/update with no fields is 400', $r['status'] === 400);
check('admin/settings/update with no fields reports invalid_settings', ($r['json']['error'] ?? null) === 'invalid_settings');

$r = $setupClient->post('/api/admin/settings/update', ['priceCents' => 250]);
check('POST /api/admin/settings/update accepts a new price', $r['status'] === 200 && ($r['json']['ok'] ?? false) === true);
check('admin/settings/update echoes the new price', ($r['json']['priceCents'] ?? null) === 250);
check('admin/settings/update leaves the invite unchanged', ($r['json']['invite'] ?? null) === 'SETUP-INVITE');

$r = $setupClient->post('/api/coffee');
check('a coffee booked after the settings update is billed at the new price', ($r['json']['balanceCents'] ?? null) === 250);

// ----------------------------------------------------------- paypal handle ---

check('no PayPal handle is configured to begin with', ($r = $setupClient->get('/api/admin/settings'))
    && ($r['json']['paypalHandle'] ?? null) === '');
$r = $setupClient->get('/api/me');
check('/api/me reports an empty PayPal handle while none is set', ($r['json']['paypalHandle'] ?? null) === '');

$r = $setupClient->post('/api/admin/settings/update', ['paypalHandle' => 'https://paypal.me/CoffeeKitchen']);
check('admin/settings/update accepts a pasted paypal.me link', $r['status'] === 200);
check('the pasted link is stored as the bare handle', ($r['json']['paypalHandle'] ?? null) === 'CoffeeKitchen');
check('setting only the handle leaves the price alone', ($r['json']['priceCents'] ?? null) === 250);

$r = $setupClient->get('/api/me');
check('/api/me carries the handle so every user can settle their tab', ($r['json']['paypalHandle'] ?? null) === 'CoffeeKitchen');

$r = $setupClient->post('/api/admin/settings/update', ['paypalHandle' => 'not a handle']);
check('a handle that is not one is refused with 400', $r['status'] === 400);
check('a refused handle reports invalid_paypal', ($r['json']['error'] ?? null) === 'invalid_paypal');
$r = $setupClient->get('/api/admin/settings');
check('the refused handle left the stored one untouched', ($r['json']['paypalHandle'] ?? null) === 'CoffeeKitchen');

$r = $setupClient->post('/api/admin/settings/update', ['paypalHandle' => 123]);
check('a non-string handle is refused with 400', $r['status'] === 400);
check('a non-string handle reports invalid_settings', ($r['json']['error'] ?? null) === 'invalid_settings');

$r = $setupClient->post('/api/admin/settings/update', ['paypalHandle' => '   ']);
check('an emptied field is accepted and switches the button off', $r['status'] === 200);
check('an emptied field clears the stored handle', ($r['json']['paypalHandle'] ?? null) === '');

stop_php_server($setupServerProcess);
$setupServerProcess = null;

summarize_and_exit();
