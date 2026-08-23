<?php

declare(strict_types=1);

/**
 * Framework-free unit coverage for the pure-function and DB-layer parts of
 * the application: no web server involved, just COFFEE_CONFIG_PATH and a
 * temp SQLite database. Http::json()/Http::error() call exit(), so this file
 * never touches them or the API handlers that call them.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../src/Bootstrap.php';

use Coffee\Bootstrap;
use Coffee\Clock;
use Coffee\Config;
use Coffee\Db;
use Coffee\Encoding;
use Coffee\Http;
use Coffee\LinkCodes;
use Coffee\RateLimit;
use Coffee\Sessions;
use Coffee\Settings;
use Coffee\Users;
use Coffee\Version;

// Literals reused across many checks below, named for what they mean rather
// than what they look like.
const CONFIG_PATH_ENV = 'COFFEE_CONFIG_PATH=';
const SQL_COUNT_EVENTS_FOR_USER = 'SELECT COUNT(*) FROM coffee_events WHERE user_id = ?';
const SQL_SELECT_SESSION = 'SELECT * FROM sessions WHERE id = ?';
const SQL_SESSION_EXISTS = 'SELECT 1 AS found FROM sessions WHERE id = ?';
// The month tag (YYYY-MM) exercised throughout the reminderMonthTag/ackReminders checks.
const REMINDER_MONTH_TAG = '2031-06';
// 198.51.100.0/24 is TEST-NET-2 (RFC 5737): reserved for documentation and
// examples, never routable, so it is safe to hardcode as a fixture caller IP.
const CLIENT_IP = '198.51.100.7';
const OTHER_CLIENT_IP = '198.51.100.8';
// Forged X-Forwarded-For prefixes used to prove that reading the header from
// the right (not the left) defeats a spoofing attempt. Drawn from TEST-NET-1
// and TEST-NET-3 (RFC 5737) for the same reason as the caller IPs above: the
// values only have to differ from each other and from the real client, so
// there is no call to put a live address such as a public resolver in here.
const SPOOFED_XFF_PREFIXES = ['192.0.2.1', '192.0.2.2', '203.0.113.1', '203.0.113.2'];

Bootstrap::init();

$workspace = makeTempWorkspace('coffee-unit');

// --------------------------------------------------------------- Encoding ---

$binary = random_bytes(37); // deliberately not a multiple of 3, to exercise padding
$encoded = Encoding::base64UrlEncode($binary);
check('base64url encoding has no padding', !str_contains($encoded, '='));
check('base64url encoding uses url-safe alphabet', !str_contains($encoded, '+') && !str_contains($encoded, '/'));
check('base64url roundtrip returns the original bytes', Encoding::base64UrlDecode($encoded) === $binary);
check('base64url decodes plain ascii', Encoding::base64UrlDecode(Encoding::base64UrlEncode('hello world')) === 'hello world');
check('base64url decode rejects invalid characters', Encoding::base64UrlDecode('not!!valid$$base64') === null);
check('base64url decode of empty string is empty string', Encoding::base64UrlDecode('') === '');

// -------------------------------------------------------------------- Http ---

$originalUri = $_SERVER['REQUEST_URI'] ?? null;

$_SERVER['REQUEST_URI'] = '/a//b/';
check('Http::path collapses doubled slashes and trims a trailing slash', Http::path() === '/a/b');

$_SERVER['REQUEST_URI'] = '/x?y=1';
check('Http::path strips the query string', Http::path() === '/x');

$_SERVER['REQUEST_URI'] = '';
check('Http::path falls back to / for an empty URI', Http::path() === '/');

$_SERVER['REQUEST_URI'] = '/';
check('Http::path keeps the bare root as /', Http::path() === '/');

$_SERVER['REQUEST_URI'] = '///';
check('Http::path collapses an all-slash URI to /', Http::path() === '/');

if ($originalUri !== null) {
    $_SERVER['REQUEST_URI'] = $originalUri;
} else {
    unset($_SERVER['REQUEST_URI']);
}

check(
    'Http::stringField returns the string value when present',
    Http::stringField(['name' => 'Ada'], 'name') === 'Ada'
);
check(
    'Http::stringField returns null for a missing key',
    Http::stringField(['name' => 'Ada'], 'missing') === null
);
check(
    'Http::stringField returns null for a non-string value',
    Http::stringField(['name' => 123], 'name') === null
);

// ------------------------------------------------------------------ Config ---

// Phase 1: no config file at all — Config::all() must fall back to defaults.
// These accessors never touch the filesystem beyond is_file(), so this is
// safe even though the path does not exist.
putenv(CONFIG_PATH_ENV . $workspace . '/no-such-config.php');
Config::forget();
check('Config::priceCents defaults to 150 without a config file', Config::priceCents() === 150);
check('Config::invite defaults to empty string', Config::invite() === '');
check('Config::admins defaults to an empty list', Config::admins() === []);
check('Config::isAdmin is false for anyone without admins configured', Config::isAdmin('1') === false);
check('Config::testMode defaults to false', Config::testMode() === false);
check('Config::rpId defaults to localhost', Config::rpId() === 'localhost');

// Without configured values, rpId and origin derive from the Host header,
// so an unconfigured instance works on any host/port.
$originalHost = $_SERVER['HTTP_HOST'] ?? null;
$_SERVER['HTTP_HOST'] = 'localhost:8123';
Config::forget();
check('Config::rpId derives the host (without port) from the request', Config::rpId() === 'localhost');
check('Config::origin derives scheme and host:port from the request', Config::origin() === 'http://localhost:8123');
$_SERVER['HTTP_HOST'] = 'bad host!';
Config::forget();
check('Config::rpId rejects an invalid Host header', Config::rpId() === 'localhost');
check('Config::origin rejects an invalid Host header', Config::origin() === '');
if ($originalHost !== null) {
    $_SERVER['HTTP_HOST'] = $originalHost;
} else {
    unset($_SERVER['HTTP_HOST']);
}
Config::forget();

// Phase 2: a real config file with overrides.
$configPath = writeTestConfig($workspace, [
    'priceCents' => 200,
    'admins' => ['42', 7],
    'testMode' => true,
    'testToken' => 'phase-two-token',
    'dbPath' => $workspace . '/data/coffee.sqlite',
]);
putenv(CONFIG_PATH_ENV . $configPath);
Config::forget();

check('Config::priceCents reflects the loaded value', Config::priceCents() === 200);
check('Config::isAdmin matches a configured string id', Config::isAdmin('42') === true);
check('Config::isAdmin matches an int admin id compared as a string', Config::isAdmin('7') === true);
check('Config::isAdmin is a strict string comparison, not numeric', Config::isAdmin('07') === false);
check('Config::isAdmin rejects an id that is not configured', Config::isAdmin('1') === false);
check('Config::testMode reflects the loaded value', Config::testMode() === true);
check('Config::testToken reflects the loaded value', Config::testToken() === 'phase-two-token');

// ------------------------------------------------------------ Db::migrate ---

$migratePath = $workspace . '/migrate-idempotence.sqlite';
$migratePdo = new PDO('sqlite:' . $migratePath);
$migratePdo->exec('PRAGMA foreign_keys = ON');

Db::migrate($migratePdo);
Db::migrate($migratePdo); // must be a no-op the second time

check(
    'Db::migrate reaches the target schema version',
    Db::userVersion($migratePdo) === Db::SCHEMA_VERSION
);
check('Db::migrate reaches schema version 11', Db::userVersion($migratePdo) === 11);
foreach (['users', 'credentials', 'sessions', 'ceremonies', 'coffee_events', 'settings', 'link_codes', 'rate_limits'] as $table) {
    check("Db::migrate creates the {$table} table", Db::tableExists($migratePdo, $table));
}
foreach (['name_encrypted', 'name_hash', 'user_handle', 'coffees', 'paid_cents', 'tab_cents', 'is_admin', 'remind_requested_at', 'reminded_month', 'password_hash', 'password_set_at'] as $column) {
    check(
        "Db::migrate gives users a {$column} column",
        in_array($column, Db::columns($migratePdo, 'users'), true)
    );
}
check(
    'Db::migrate gives coffee_events a price_cents column',
    in_array('price_cents', Db::columns($migratePdo, 'coffee_events'), true)
);
check(
    'Db::migrate gives coffee_events a client_event_id column',
    in_array('client_event_id', Db::columns($migratePdo, 'coffee_events'), true)
);
foreach (['name', 'value'] as $column) {
    check(
        "Db::migrate gives settings a {$column} column",
        in_array($column, Db::columns($migratePdo, 'settings'), true)
    );
}
$indexNames = $migratePdo
    ->query("SELECT name FROM sqlite_master WHERE type = 'index'")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach (['idx_users_name_hash', 'idx_users_handle', 'idx_credentials_credential_id', 'idx_coffee_events_user_created', 'idx_coffee_events_user_client', 'idx_link_codes_hash', 'idx_link_codes_user'] as $index) {
    check("Db::migrate creates the {$index} index", in_array($index, $indexNames, true));
}
// The offline-queue key is unique per user, not globally: the pre-v10 global
// index must be gone, or one account's event id could still block another's.
check(
    'Db::migrate removes the old global client_event_id index',
    !in_array('idx_coffee_events_client', $indexNames, true)
);

// Migrating twice in a row must not raise, even starting from an empty file.
$freshPath = $workspace . '/migrate-fresh.sqlite';
$freshPdo = new PDO('sqlite:' . $freshPath);
try {
    Db::migrate($freshPdo);
    Db::migrate($freshPdo);
    check('Db::migrate on a brand-new database twice does not throw', true);
} catch (Throwable $e) {
    check('Db::migrate on a brand-new database twice does not throw', false);
}
check(
    'Db::migrate on a fresh database also reaches SCHEMA_VERSION',
    Db::userVersion($freshPdo) === Db::SCHEMA_VERSION
);

// ------------------------------------------------------- Users arithmetic ---

Db::reset();
Db::pdo(); // creates the configured dbPath and runs migrations

// -------------------------------------------------------------- Settings ---

// Baseline: config.php (Phase 2 above) sets priceCents to 200, and no
// settings row exists yet in this freshly migrated database.
check('Config::priceCents() reflects config.php (200) before any settings row exists', Config::priceCents() === 200);
check('Settings::get returns null for a key with no row', Settings::get('priceCents') === null);

Settings::set('priceCents', '999');
check('Settings::get returns the value just set', Settings::get('priceCents') === '999');
Settings::set('priceCents', '111');
check('Settings::set overwrites an existing value on a second call (upsert)', Settings::get('priceCents') === '111');
check(
    'Config::priceCents() prefers the database value (111) over config.php\'s 200',
    Config::priceCents() === 111
);

Settings::setMany(['invite' => 'DB-INVITE', 'adminPublicKey' => 'db-key']);
check(
    'Settings::setMany stores every pair in one call',
    Settings::get('invite') === 'DB-INVITE' && Settings::get('adminPublicKey') === 'db-key'
);
check('Config::invite() prefers the database value over config.php', Config::invite() === 'DB-INVITE');
check('Config::adminPublicKey() prefers the database value over config.php', Config::adminPublicKey() === 'db-key');

// An explicit cache reset (what a fresh request would start from) must still
// see the same, already-committed database values.
Settings::reset();
check('Config::priceCents() still reflects the database value after Settings::reset()', Config::priceCents() === 111);

// Clean up so the phases below (which rely on config.php's own
// priceCents/invite/adminPublicKey) are not shadowed by leftover rows.
Db::pdo()->exec('DELETE FROM settings');
Settings::reset();
check('Config::priceCents() falls back to config.php once the settings rows are gone', Config::priceCents() === 200);
check('Config::invite() falls back to config.php\'s own value once settings are gone', Config::invite() === 'TEST-INVITE');

// ------------------------------------------------------- Users arithmetic ---

$alice = Users::create('cipher-alice', 'hash-alice', 'handle-alice', coffees: 3, paidCents: 100);
check('Users::create stores the requested initial coffees', Users::coffees($alice) === 3);
check('Users::create stores the requested initial paidCents', Users::paidCents($alice) === 100);
check(
    'balanceCents = coffees * priceCents - paidCents at creation',
    Users::balanceCents($alice) === 3 * 200 - 100
);
check('the first user ever created in a database is flagged is_admin', Users::isAdminRow($alice) === true);

$bob = Users::create('cipher-bob', 'hash-bob', 'handle-bob');
$carol = Users::create('cipher-carol', 'hash-carol', 'handle-carol');
check('the second user created is not flagged is_admin', Users::isAdminRow($bob) === false);
check('the third user created is not flagged is_admin', Users::isAdminRow($carol) === false);
check('Users::isAdminRow is false for a row without the key at all', Users::isAdminRow([]) === false);

// -------------------------------------------------------- addCoffee/undo ---

$before = Users::coffees(Users::find((string) $alice['id']));
$updated = Users::addCoffee((string) $alice['id']);
check('addCoffee increments the counter by one', Users::coffees($updated) === $before + 1);

$eventsBefore = Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $alice['id']]);
$updated = Users::undoCoffee((string) $alice['id']);
check('undoCoffee decrements the counter by one', Users::coffees($updated) === $before);
$eventsAfter = Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $alice['id']]);
check('undoCoffee removes exactly one coffee_events row', (int) $eventsAfter === (int) $eventsBefore - 1);

// Floors at zero: bob has never booked a coffee.
$updated = Users::undoCoffee((string) $bob['id']);
check('undoCoffee on a user at zero coffees stays at zero', Users::coffees($updated) === 0);
$updated = Users::undoCoffee((string) $bob['id']);
check('undoCoffee never goes negative even when called repeatedly', Users::coffees($updated) === 0);

// ------------------------------------------------------------ undo window ---

// Undo is a way out of a mis-tap, not an editor for the tab: a booking that
// has outlived Config::undoWindowSeconds() stands, however often undo is
// called. The clock is moved instead of the row so the check exercises the
// same Clock::now() the endpoint uses.
$windowUser = Users::create('cipher-window', 'hash-window', 'handle-window');
$windowId = (string) $windowUser['id'];
Users::addCoffee($windowId);
Users::addCoffee($windowId);
check('a fresh booking is undoable', Users::undoableSeconds($windowId) > 0);
check(
    'the reported window never exceeds the configured one',
    Users::undoableSeconds($windowId) <= Config::undoWindowSeconds()
);

Clock::setOffset(Config::undoWindowSeconds() + 1);
check('once the window has passed nothing is undoable', Users::undoableSeconds($windowId) === 0);
$updated = Users::undoCoffee($windowId);
check('undo past the window leaves the counter alone', Users::coffees($updated) === 2);
check('undo past the window leaves the tab alone', Users::tabCents($updated) === 2 * Config::priceCents());
check(
    'undo past the window keeps both coffee_events rows',
    (int) Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $windowId]) === 2
);
$updated = Users::undoCoffee($windowId);
check('repeating undo past the window changes nothing either', Users::coffees($updated) === 2);

// Back inside the window, the same two bookings can be taken back again --
// a double tap is exactly what undo is for.
Clock::setOffset(0);
check('the booking is undoable again once the clock is back', Users::undoableSeconds($windowId) > 0);
$updated = Users::undoCoffee($windowId);
check('undo inside the window removes one booking', Users::coffees($updated) === 1);
$updated = Users::undoCoffee($windowId);
check('a second undo inside the window removes the other one', Users::coffees($updated) === 0);
check('undoing both bookings clears the tab', Users::tabCents($updated) === 0);
check('with nothing booked there is nothing left to undo', Users::undoableSeconds($windowId) === 0);

// undo removes only the latest event, not an arbitrary one.
Users::addCoffee((string) $carol['id']);
Users::addCoffee((string) $carol['id']);
$latestEventId = Db::fetchValue(
    'SELECT id FROM coffee_events WHERE user_id = ? ORDER BY id DESC LIMIT 1',
    [(int) $carol['id']]
);
Users::undoCoffee((string) $carol['id']);
$stillThere = Db::fetchValue(
    'SELECT COUNT(*) FROM coffee_events WHERE user_id = ? AND id = ?',
    [(int) $carol['id'], $latestEventId]
);
check('undoCoffee deletes the most recently booked event', (int) $stillThere === 0);

// ------------------------------------------------ addCoffee (clientEventId) ---

// Offline-queue idempotency: Users::addCoffee(id, null) must behave exactly
// as before (this is the default, exercised throughout the rest of this
// file), and a booking made without a client id stores a NULL there.
$eve = Users::create('cipher-eve', 'hash-eve', 'handle-eve');
$eveBefore = Users::coffees(Users::find((string) $eve['id']));
$eveUpdated = Users::addCoffee((string) $eve['id']);
check(
    'addCoffee(id) without a clientEventId still increments the counter by one, as before',
    Users::coffees($eveUpdated) === $eveBefore + 1
);
check(
    'a booking made without a clientEventId stores a NULL client_event_id',
    Db::fetchValue(
        'SELECT client_event_id FROM coffee_events WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [(int) $eve['id']]
    ) === null
);

$eveEventsBefore = (int) Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $eve['id']]);
$eveCoffeesBefore = Users::coffees(Users::find((string) $eve['id']));
$eveTabBefore = Users::tabCents(Users::find((string) $eve['id']));
$clientId = 'client-' . bin2hex(random_bytes(6));
$price = Config::priceCents();

$firstBooking = Users::addCoffee((string) $eve['id'], $clientId);
check('addCoffee with a client id increments the counter by one', Users::coffees($firstBooking) === $eveCoffeesBefore + 1);
check(
    'addCoffee with a client id adds the price once to tab_cents',
    Users::tabCents($firstBooking) === $eveTabBefore + $price
);

$secondBooking = Users::addCoffee((string) $eve['id'], $clientId);
check(
    'replaying the same client id a second time does not increment the counter again',
    Users::coffees($secondBooking) === $eveCoffeesBefore + 1
);
check(
    'replaying the same client id a second time does not add the price again',
    Users::tabCents($secondBooking) === $eveTabBefore + $price
);

$eveEventsAfter = (int) Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $eve['id']]);
check(
    'exactly one coffee_events row was created by the two identical client-id calls',
    $eveEventsAfter === $eveEventsBefore + 1
);
check(
    'the stored event row carries the client event id',
    Db::fetchValue(
        'SELECT client_event_id FROM coffee_events WHERE user_id = ? ORDER BY id DESC LIMIT 1',
        [(int) $eve['id']]
    ) === $clientId
);

// ------------------------------------------------------------ addPayment ---

$updated = Users::addPayment((string) $bob['id'], 500);
check('addPayment adds to paidCents', Users::paidCents($updated) === 500);
$updated = Users::addPayment((string) $bob['id'], -100000);
check('addPayment clamps paidCents at zero for a large negative amount', Users::paidCents($updated) === 0);

// -------------------------------------------------------- price changes ---

// A price change must never re-price coffees that were already booked: each
// booking keeps the price that was in effect when it happened.
Db::reset();
Config::forget();
$priceConfigPath = writeTestConfig($workspace, [
    'priceCents' => 150,
    'dbPath' => $workspace . '/data/price-change.sqlite',
]);
putenv(CONFIG_PATH_ENV . $priceConfigPath);
Config::forget();
Db::reset();

$dave = Users::create('cipher-dave', 'hash-dave', 'handle-dave');
Users::addCoffee((string) $dave['id']);
$updated = Users::addCoffee((string) $dave['id']);
check('two coffees booked at 150 give a tab of 300', Users::tabCents($updated) === 300);

// Rewrite the config file with a higher price and forget the process-local
// cache, exactly as a real deployment would after the admin edits config.php.
// This writes the file directly (not via writeTestConfig()) because that
// helper drops and recreates the database on a MySQL/MariaDB test run — fine
// for a fresh phase, but it would wipe the data booked above.
// Deliberately require (not require_once): src/Config.php already loads this
// same path via plain require, which registers it in PHP's include-once
// table; require_once here would then return the cached `true` sentinel
// instead of re-reading the array this test just rewrote on disk.
$priceConfig = require $priceConfigPath;
$priceConfig['priceCents'] = 200;
file_put_contents($priceConfigPath, "<?php\nreturn " . var_export($priceConfig, true) . ";\n");
Config::forget();
check('Config::priceCents reflects the rewritten price', Config::priceCents() === 200);

$updated = Users::addCoffee((string) $dave['id']);
check(
    'a coffee booked after the price change adds the new price, not the old one',
    Users::tabCents($updated) === 500
);
check(
    'balanceCents after the price change is tabCents minus paidCents',
    Users::balanceCents($updated) === 500 - Users::paidCents($updated)
);

// Undo must remove exactly the price of the last booking (200), not the
// price of an earlier one (150) and not the now-current price.
$updated = Users::undoCoffee((string) $dave['id']);
check('undo after a price change refunds the last booking\'s own price', Users::tabCents($updated) === 300);
check('undo after a price change leaves the coffee count at 2', Users::coffees($updated) === 2);

// Legacy user: coffees and a tab, but no coffee_events rows at all (as a
// pre-v5 database would have). There is no event to date, so the grace
// window cannot tell a mis-tap from last month's coffee -- the booking
// stands rather than being silently refunded.
$legacyId = Db::transaction(static function (PDO $pdo): string {
    $statement = $pdo->prepare(
        'INSERT INTO users (name, name_encrypted, name_hash, user_handle, coffees, paid_cents, tab_cents, created_at)
         VALUES (NULL, ?, ?, ?, ?, ?, ?, ?)'
    );
    $statement->execute(['cipher-legacy', 'hash-legacy', 'handle-legacy', 1, 0, 150, Clock::now()]);

    return (string) $pdo->lastInsertId();
});
check(
    'the manually inserted legacy user has no coffee_events rows',
    (int) Db::fetchValue(SQL_COUNT_EVENTS_FOR_USER, [(int) $legacyId]) === 0
);
check('a legacy user without events reports nothing to undo', Users::undoableSeconds($legacyId) === 0);
$updated = Users::undoCoffee($legacyId);
check(
    'undo on a legacy user without events leaves counter and tab untouched',
    Users::tabCents($updated) === 150 && Users::coffees($updated) === 1
);

// --------------------------------------------------------------- version ---

// Running from a checkout there is no build.json, so the commit has to come
// out of .git -- that is the only reason a developer sees a real hash too.
$version = Version::current();
check('Version::current reports a version string', isset($version['version']) && is_string($version['version']));
check('Version::current reports a builtAt timestamp', isset($version['builtAt']) && is_int($version['builtAt']));
check(
    'the version is a short commit hash or the dev placeholder',
    $version['version'] === Version::UNKNOWN || preg_match('/^[0-9a-f]{7}$/', $version['version']) === 1
);
check('Version::current is cached per request', Version::current() === $version);

// A release bundle carries build.json and it has to win: the deployed tree
// has no .git at all, and where both exist the bundle is the newer truth.
$buildFile = __DIR__ . '/../src/build.json';
$hadBuildFile = is_file($buildFile);
$previousBuildFile = $hadBuildFile ? (string) file_get_contents($buildFile) : null;
try {
    file_put_contents($buildFile, '{"version":"abcdef1234567","builtAt":1700000000}');
    Version::forget();
    $bundled = Version::current();
    check('build.json takes precedence over .git', $bundled['version'] === 'abcdef1');
    check('build.json supplies the build timestamp', $bundled['builtAt'] === 1700000000);

    // Anything unusable falls back rather than showing rubbish in the footer.
    file_put_contents($buildFile, 'not json at all');
    Version::forget();
    check('an unreadable build.json falls back instead of reporting nonsense', Version::current()['version'] !== 'not json at all');

    file_put_contents($buildFile, '{"version":"nope"}');
    Version::forget();
    check('a build.json whose version is not a hash falls back too', Version::current()['version'] !== 'nope');
} finally {
    if ($previousBuildFile === null) {
        @unlink($buildFile);
    } else {
        file_put_contents($buildFile, $previousBuildFile);
    }
    Version::forget();
}

// ---------------------------------------------------------------- paypal ---

// Whatever an admin pastes into the field has to come out as a bare handle,
// and anything that is not one has to come out empty -- a "Pay with PayPal"
// button pointing at a broken page is worse than no button.
$paypalCases = [
    'coffeekitchen' => 'coffeekitchen',
    '  coffeekitchen  ' => 'coffeekitchen',
    'paypal.me/coffeekitchen' => 'coffeekitchen',
    'https://paypal.me/coffeekitchen' => 'coffeekitchen',
    'https://www.paypal.me/coffeekitchen/' => 'coffeekitchen',
    // Deliberately plain http:// (not https://): normalizePaypalHandle must
    // accept either scheme, so this case is the one that proves http:// input
    // is not silently rejected. It is a fixture value, never dereferenced.
    'http://PayPal.Me/CoffeeKitchen' => 'CoffeeKitchen',
    'https://www.paypal.com/paypalme/coffeekitchen' => 'coffeekitchen',
    'Coffee Kitchen' => '',
    'coffee-kitchen' => '',
    'coffee.kitchen' => '',
    '' => '',
    '   ' => '',
    'paypal.me/' => '',
    str_repeat('a', 21) => '',
    'a/../b' => '',
    'evil.example.com/pay' => '',
];
foreach ($paypalCases as $input => $expected) {
    check(
        'normalizePaypalHandle(' . var_export((string) $input, true) . ') === ' . var_export($expected, true),
        Config::normalizePaypalHandle((string) $input) === $expected
    );
}
check('a handle of exactly 20 characters is accepted', Config::normalizePaypalHandle(str_repeat('a', 20)) === str_repeat('a', 20));
check('no configured handle means no button', Config::paypalHandle() === '');
Settings::set('paypalHandle', 'https://paypal.me/coffeekitchen');
check('Config::paypalHandle normalizes what the settings table holds', Config::paypalHandle() === 'coffeekitchen');
Settings::set('paypalHandle', '');
check('an emptied handle switches the button off again', Config::paypalHandle() === '');

// ----------------------------------------------------------------- stats ---

Db::reset();
Config::forget();
$statsConfig = writeTestConfig($workspace, [
    'priceCents' => 150,
    'dbPath' => $workspace . '/data/stats.sqlite',
]);
putenv(CONFIG_PATH_ENV . $statsConfig);
Config::forget();
Db::reset();

$s1 = Users::create('e1', 'h1', 'ha1');
$s2 = Users::create('e2', 'h2', 'ha2');
$s3 = Users::create('e3', 'h3', 'ha3');
for ($i = 0; $i < 5; $i++) {
    Users::addCoffee((string) $s1['id']);
    Users::addCoffee((string) $s2['id']);
}
for ($i = 0; $i < 3; $i++) {
    Users::addCoffee((string) $s3['id']);
}

$stats = Users::stats((string) $s1['id']);
check('stats total sums every user\'s coffees', $stats['total'] === 13);
check('stats users counts every row', $stats['users'] === 3);
check('stats gives tied leaders rank 1', $stats['distribution'][0]['rank'] === 1 && $stats['distribution'][1]['rank'] === 1);
check('stats keeps competition ranking: the next distinct score is rank 3', $stats['distribution'][2]['rank'] === 3);
check('stats reports the caller\'s own rank', $stats['rank'] === 1);
$statsForThird = Users::stats((string) $s3['id']);
check('stats reports rank 3 for the trailing user', $statsForThird['rank'] === 3);

// ------------------------------------------------------------- streakDays ---

Db::reset();
$streakConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/streak.sqlite',
]);
putenv(CONFIG_PATH_ENV . $streakConfig);
Config::forget();
Db::reset();

$streakUser = Users::create('e', 'h', 'ha');
$gapUser = Users::create('e2', 'h2', 'ha2');

Clock::setOffset(-2 * 86400);
Users::addCoffee((string) $streakUser['id']);
Clock::setOffset(-1 * 86400);
Users::addCoffee((string) $streakUser['id']);
Clock::setOffset(0);
check(
    'streakDays counts yesterday even before today has a booking',
    Users::streakDays((string) $streakUser['id']) === 2
);
Users::addCoffee((string) $streakUser['id']);
check(
    'streakDays extends to 3 once today is also booked',
    Users::streakDays((string) $streakUser['id']) === 3
);

// A booking three days ago with nothing since breaks the streak entirely.
Clock::setOffset(-3 * 86400);
Users::addCoffee((string) $gapUser['id']);
Clock::setOffset(0);
check(
    'streakDays is 0 once the gap since the last booking exceeds one day',
    Users::streakDays((string) $gapUser['id']) === 0
);

// -------------------------------------------------------------- history ---

Db::reset();
$historyConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/history.sqlite',
]);
putenv(CONFIG_PATH_ENV . $historyConfig);
Config::forget();
Db::reset();

$historyUser = Users::create('e', 'h', 'ha');

Clock::setOffset(0);
Users::addCoffee((string) $historyUser['id']);
Users::addCoffee((string) $historyUser['id']);
Clock::setOffset(-1 * 86400);
Users::addCoffee((string) $historyUser['id']);
Clock::setOffset(-3 * 86400);
Users::addCoffee((string) $historyUser['id']);
Clock::setOffset(0);

$history = Users::history((string) $historyUser['id']);
check('history returns 28 days', count($history['days']) === 28);
check('history is sorted ascending by date', $history['days'][0]['date'] < $history['days'][27]['date']);
check('history\'s last day is today', $history['days'][27]['date'] === gmdate('Y-m-d'));
check('history reports today\'s count both in "today" and the last day', $history['today'] === 2 && $history['days'][27]['coffees'] === 2);

$yesterday = gmdate('Y-m-d', time() - 86400);
$threeDaysAgo = gmdate('Y-m-d', time() - 3 * 86400);
$byDate = [];
foreach ($history['days'] as $entry) {
    $byDate[$entry['date']] = $entry['coffees'];
}
check('history counts yesterday\'s single booking', ($byDate[$yesterday] ?? null) === 1);
check('history counts the booking from three days ago', ($byDate[$threeDaysAgo] ?? null) === 1);
$zeroFilled = true;
foreach ($byDate as $date => $count) {
    if (!in_array($date, [gmdate('Y-m-d'), $yesterday, $threeDaysAgo], true) && $count !== 0) {
        $zeroFilled = false;
    }
}
check('history zero-fills every other day', $zeroFilled);

// ------------------------------------------------------------- reminders ---

// reminderMonthTag is a pure function of the timestamp: due on the last day
// of a month, still due (as the previous month) during the catch-up window,
// otherwise not due at all.
$midMonth = gmmktime(12, 0, 0, 6, 15, 2031);
check('reminderMonthTag is null in the middle of a month', Users::reminderMonthTag($midMonth) === null);
check('reminderMonthTag fires on the last day of a month', Users::reminderMonthTag(gmmktime(12, 0, 0, 6, 30, 2031)) === REMINDER_MONTH_TAG);
check('reminderMonthTag fires on the last day of a 31-day month', Users::reminderMonthTag(gmmktime(23, 59, 0, 7, 31, 2031)) === '2031-07');
check('reminderMonthTag fires on Feb 29 of a leap year', Users::reminderMonthTag(gmmktime(0, 0, 0, 2, 29, 2028)) === '2028-02');
check('reminderMonthTag does NOT fire on Feb 28 of a leap year', Users::reminderMonthTag(gmmktime(12, 0, 0, 2, 28, 2028)) === null);
check('reminderMonthTag catches up to the previous month early in the next one', Users::reminderMonthTag(gmmktime(12, 0, 0, 7, 3, 2031)) === REMINDER_MONTH_TAG);
check('the catch-up window ends after day 7', Users::reminderMonthTag(gmmktime(12, 0, 0, 7, 8, 2031)) === null);
check('the catch-up window crosses a year boundary', Users::reminderMonthTag(gmmktime(12, 0, 0, 1, 2, 2031)) === '2030-12');
check('reminderMonthTag fires on Dec 31', Users::reminderMonthTag(gmmktime(12, 0, 0, 12, 31, 2030)) === '2030-12');

// Persistence: an admin reminder is stored, survives a stale ack, and is
// cleared only by acknowledging the exact open request.
$remindUser = Users::create('cipher-remind', 'hash-remind', 'handle-remind');
$remindId = (string) $remindUser['id'];
check('a fresh user has no open admin reminder', Users::remindRequestedAt($remindUser) === 0);
check('a fresh user has no acknowledged month', Users::remindedMonth($remindUser) === '');

Users::requestReminder($remindId);
$row = Users::find($remindId);
$requestedAt = Users::remindRequestedAt($row);
check('requestReminder stores a positive timestamp', $requestedAt > 0);

Users::ackReminders($remindId, null, $requestedAt - 1);
check(
    'acknowledging a stale timestamp leaves the open reminder untouched',
    Users::remindRequestedAt(Users::find($remindId)) === $requestedAt
);

Users::ackReminders($remindId, REMINDER_MONTH_TAG, $requestedAt);
$row = Users::find($remindId);
check('acknowledging the open timestamp clears the admin reminder', Users::remindRequestedAt($row) === 0);
check('acknowledging a month stores it as remindedMonth', Users::remindedMonth($row) === REMINDER_MONTH_TAG);

// -------------------------------------------------------------- Sessions ---

Db::reset();
$sessionsConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/sessions.sqlite',
]);
putenv(CONFIG_PATH_ENV . $sessionsConfig);
Config::forget();
Db::reset();

$sessionUser = Users::create('e', 'h', 'ha');
$token = Sessions::start((string) $sessionUser['id']);
$expectedId = hash('sha256', $token);
$row = Db::fetchRow(SQL_SELECT_SESSION, [$expectedId]);
check('Sessions::start stores sha256(token) as the row id', $row !== null);
check('Sessions::start records the correct user_id', $row !== null && (int) $row['user_id'] === (int) $sessionUser['id']);

$_COOKIE[Sessions::COOKIE_NAME] = $token;
$currentUser = Sessions::currentUser();
check('Sessions::currentUser resolves the user from the cookie token', ($currentUser['id'] ?? null) === $sessionUser['id']);
unset($_COOKIE[Sessions::COOKIE_NAME]);

// An expired row must be pruned the next time start() runs.
$now = Clock::now();
$expiredId = 'expired-' . bin2hex(random_bytes(4));
Db::pdo()->prepare('INSERT INTO sessions (id, user_id, created_at, expires_at) VALUES (?, ?, ?, ?)')
    ->execute([$expiredId, (int) $sessionUser['id'], $now - 1000, $now - 500]);
check('the manually inserted expired session exists before pruning', Db::fetchRow(SQL_SELECT_SESSION, [$expiredId]) !== null);
Sessions::start((string) $sessionUser['id']);
check('Sessions::start prunes expired sessions as a side effect', Db::fetchRow(SQL_SELECT_SESSION, [$expiredId]) === null);

// -------------------------------------------------------------- LinkCodes ---

Db::reset();
$linkConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/link-codes.sqlite',
]);
putenv(CONFIG_PATH_ENV . $linkConfig);
Config::forget();
Db::reset();

// normalize(): formatting, dashes/lowercase are accepted, wrong length fails.
check('LinkCodes::normalize uppercases and strips the dash', LinkCodes::normalize('ab2d-3efg') === 'AB2D3EFG');
check('LinkCodes::normalize strips arbitrary non-alphabet characters', LinkCodes::normalize(' AB2D 3EFG!') === 'AB2D3EFG');
check('LinkCodes::normalize rejects a too-short result', LinkCodes::normalize('AB2D-3EF') === '');
check('LinkCodes::normalize rejects a too-long result', LinkCodes::normalize('AB2D-3EFGH') === '');
check('LinkCodes::normalize of an empty string is empty string', LinkCodes::normalize('') === '');

$linkUser = Users::create('cipher-link', 'hash-link', 'handle-link');
$linkUserId = (string) $linkUser['id'];

$beforeCreate = Clock::now();
$created = LinkCodes::create($linkUserId, 'self', LinkCodes::SELF_TTL);
check('LinkCodes::create returns a code in XXXX-XXXX format', preg_match('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $created['code']) === 1);
check('LinkCodes::create returns an expiresAt in the future', $created['expiresAt'] > $beforeCreate);
check(
    'LinkCodes::create sets expiresAt exactly SELF_TTL seconds ahead',
    $created['expiresAt'] === $beforeCreate + LinkCodes::SELF_TTL
);

// The plaintext code must never be stored – only its sha256 hash.
$normalizedCreated = LinkCodes::normalize($created['code']);
$rowByHash = Db::fetchRow('SELECT * FROM link_codes WHERE code_hash = ?', [hash('sha256', $normalizedCreated)]);
check('the stored row is found by sha256(code), not by the plaintext code', $rowByHash !== null);
check(
    'the stored code_hash column never equals the plaintext code',
    ($rowByHash['code_hash'] ?? null) !== $normalizedCreated
);
check('no row stores the plaintext code as code_hash anywhere', Db::fetchRow('SELECT * FROM link_codes WHERE code_hash = ?', [$normalizedCreated]) === null);

// peek() finds the code and does NOT consume it.
$peeked = LinkCodes::peek($created['code']);
check('LinkCodes::peek finds a freshly created code', $peeked !== null);
check('LinkCodes::peek reports the correct user_id', $peeked !== null && (int) $peeked['user_id'] === (int) $linkUserId);
$peekedAgain = LinkCodes::peek($created['code']);
check('LinkCodes::peek does not consume the code (a second peek still finds it)', $peekedAgain !== null);

// consume() marks it used; a second consume() must fail.
$consumed = LinkCodes::consume($created['code']);
check('LinkCodes::consume returns the row on first use', $consumed !== null);
$consumedAgain = LinkCodes::consume($created['code']);
check('LinkCodes::consume returns null on a second attempt (already used)', $consumedAgain === null);
check('a consumed code no longer peeks as valid', LinkCodes::peek($created['code']) === null);

// One active code per account: creating a second code invalidates the first.
$first = LinkCodes::create($linkUserId, 'self', LinkCodes::SELF_TTL);
check('the first of two codes peeks fine right after creation', LinkCodes::peek($first['code']) !== null);
$second = LinkCodes::create($linkUserId, 'self', LinkCodes::SELF_TTL);
check('creating a second code invalidates the first', LinkCodes::peek($first['code']) === null);
check('the second code is still valid', LinkCodes::peek($second['code']) !== null);
check('LinkCodes::consume also fails for the invalidated first code', LinkCodes::consume($first['code']) === null);

// Expiry: move the clock (and thus Clock::now()) past expires_at.
$expiring = LinkCodes::create($linkUserId, 'admin', LinkCodes::ADMIN_TTL);
check('LinkCodes::create honors a custom TTL (ADMIN_TTL)', $expiring['expiresAt'] === Clock::now() + LinkCodes::ADMIN_TTL);
Clock::setOffset(LinkCodes::ADMIN_TTL + 60);
check('an expired code no longer peeks as valid', LinkCodes::peek($expiring['code']) === null);
check('an expired code cannot be consumed either', LinkCodes::consume($expiring['code']) === null);
Clock::setOffset(0);

// ------------------------------------------------------------- RateLimit ---

Db::reset();
// testMode short-circuits the limiter (the test control surface exists to
// drive these flows in a loop), so this phase deliberately turns it off.
$rateConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/rate-limit.sqlite',
    'testMode' => false,
]);
putenv(CONFIG_PATH_ENV . $rateConfig);
Config::forget();
Db::reset();
Settings::reset();
Db::pdo();

$_SERVER['REMOTE_ADDR'] = CLIENT_IP;
$allowed = 0;
for ($i = 0; $i < 5; $i++) {
    if (RateLimit::allow('unit-probe', 3, 600)) {
        $allowed++;
    }
}
check('RateLimit allows exactly the configured number of attempts', $allowed === 3);
check('RateLimit keeps refusing once the window is exhausted', RateLimit::allow('unit-probe', 3, 600) === false);
check(
    'RateLimit counts each endpoint group separately',
    RateLimit::allow('unit-other', 3, 600) === true
);

// A different caller gets its own counter -- one user must not lock out the
// whole office.
$_SERVER['REMOTE_ADDR'] = OTHER_CLIENT_IP;
check('RateLimit counts each caller separately', RateLimit::allow('unit-probe', 3, 600) === true);

// Once the window rolls over the counter starts again.
$_SERVER['REMOTE_ADDR'] = CLIENT_IP;
Clock::setOffset(1200);
check('RateLimit starts a fresh window after it elapses', RateLimit::allow('unit-probe', 3, 600) === true);
Clock::setOffset(0);

// ------------------------------------------- RateLimit: forwarded callers ---

// X-Forwarded-For must be read from the RIGHT. A proxy only ever appends the
// peer it saw, so anything already in the header arrived with the request and
// belongs to the caller. Keying counters on the left-most entry let a caller
// mint a fresh counter per request by changing one header -- which silently
// un-throttled the invite code, the login and the admin password.
Db::reset();
$proxyConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/rate-limit-proxy.sqlite',
    'testMode' => false,
    'trustProxy' => true,
    'trustedProxyHops' => 1,
]);
putenv(CONFIG_PATH_ENV . $proxyConfig);
Config::forget();
Db::reset();
Settings::reset();
Db::pdo();

// One proxy in front: REMOTE_ADDR is the proxy, the last XFF entry is what it
// observed. The caller's forged prefix must not be able to change the counter.
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.20';
$forwardedAllowed = 0;
for ($i = 0; $i < 5; $i++) {
    if (RateLimit::allow('unit-fwd', 3, 600)) {
        $forwardedAllowed++;
    }
}
check('a forwarded caller is throttled like any other', $forwardedAllowed === 3);

// The attack: prepend a different value on every attempt. Reading from the
// right ignores it, so the counter stays exhausted.
$exhausted = true;
foreach (SPOOFED_XFF_PREFIXES as $spoofed) {
    $_SERVER['HTTP_X_FORWARDED_FOR'] = $spoofed . ', 198.51.100.20';
    if (RateLimit::allow('unit-fwd', 3, 600)) {
        $exhausted = false;
    }
}
check('prepending a forged X-Forwarded-For entry does not mint a fresh counter', $exhausted);

// A genuinely different client behind the same proxy still gets its own.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.21';
check('a different client behind the same proxy keeps its own counter', RateLimit::allow('unit-fwd', 3, 600) === true);

// A header shorter than the configured hop count means fewer proxies than
// configured; fall back to REMOTE_ADDR, the one value nobody can forge,
// rather than to a caller-supplied entry.
Db::reset();
$hopsConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/rate-limit-hops.sqlite',
    'testMode' => false,
    'trustProxy' => true,
    'trustedProxyHops' => 2,
]);
putenv(CONFIG_PATH_ENV . $hopsConfig);
Config::forget();
Db::reset();
Settings::reset();
Db::pdo();

$_SERVER['REMOTE_ADDR'] = '203.0.113.10';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30, 203.0.113.11';
check('with two hops the second entry from the right is used', RateLimit::allow('unit-hops', 1, 600) === true);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.30, 203.0.113.99';
check('a changed proxy entry does not change that caller\'s counter', RateLimit::allow('unit-hops', 1, 600) === false);

// Only one entry, but two hops configured: too short, so REMOTE_ADDR wins.
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.31';
check('a too-short header falls back to REMOTE_ADDR', RateLimit::allow('unit-hops', 1, 600) === true);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.32';
check('the fallback keys on REMOTE_ADDR, not on the forged entry', RateLimit::allow('unit-hops', 1, 600) === false);

// trustProxy off: the header is ignored entirely.
Db::reset();
$noProxyConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/rate-limit-noproxy.sqlite',
    'testMode' => false,
    'trustProxy' => false,
]);
putenv(CONFIG_PATH_ENV . $noProxyConfig);
Config::forget();
Db::reset();
Settings::reset();
Db::pdo();

$_SERVER['REMOTE_ADDR'] = '203.0.113.12';
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.40';
check('without trustProxy the header is ignored', RateLimit::allow('unit-noproxy', 1, 600) === true);
$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.41';
check('without trustProxy a changed header cannot reset the counter', RateLimit::allow('unit-noproxy', 1, 600) === false);

unset($_SERVER['HTTP_X_FORWARDED_FOR']);

// Restore the plain rate-limit config for anything that follows.
putenv(CONFIG_PATH_ENV . $rateConfig);
Config::forget();
Db::reset();
Settings::reset();
Db::pdo();
$_SERVER['REMOTE_ADDR'] = CLIENT_IP;

check(
    'RateLimit never stores a bare client address',
    Db::fetchRow('SELECT 1 AS found FROM rate_limits WHERE bucket LIKE ?', ['%198.51.100%']) === null
);
unset($_SERVER['REMOTE_ADDR']);

// ------------------------------------------------- Sessions: absolute cap ---

Db::reset();
$absoluteConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/sessions-absolute.sqlite',
]);
putenv(CONFIG_PATH_ENV . $absoluteConfig);
Config::forget();
Db::reset();

$capUser = Users::create('e', 'h', 'ha');
$capToken = Sessions::start((string) $capUser['id']);
$_COOKIE[Sessions::COOKIE_NAME] = $capToken;
check('a fresh session resolves normally', Sessions::currentUser() !== null);

// Sliding renewal alone would keep a stolen token alive forever; past the
// hard ceiling the session ends regardless of how recently it was used.
Db::pdo()->prepare('UPDATE sessions SET created_at = ?, expires_at = ? WHERE id = ?')->execute([
    Clock::now() - Sessions::ABSOLUTE_LIFETIME - 60,
    Clock::now() + Sessions::IDLE_LIFETIME,
    hash('sha256', $capToken),
]);
check('a session past ABSOLUTE_LIFETIME is refused even while still "fresh"', Sessions::currentUser() === null);
check(
    'the expired session row is deleted, not just ignored',
    Db::fetchRow(SQL_SESSION_EXISTS, [hash('sha256', $capToken)]) === null
);
unset($_COOKIE[Sessions::COOKIE_NAME]);

// Recovery: an admin-issued link must be able to end every other session of
// that account while sparing the one just created.
$revokeUser = Users::create('e2', 'h2', 'ha2');
$revokeId = (string) $revokeUser['id'];
$oldToken = Sessions::start($revokeId);
$keptToken = Sessions::start($revokeId);
check('two sessions exist for the account before revocation', Sessions::count() >= 2);
Sessions::destroyForUser($revokeId, $keptToken);
check(
    'destroyForUser removes the other session',
    Db::fetchRow(SQL_SESSION_EXISTS, [hash('sha256', $oldToken)]) === null
);
check(
    'destroyForUser spares the session it was told to keep',
    Db::fetchRow(SQL_SESSION_EXISTS, [hash('sha256', $keptToken)]) !== null
);

// ------------------------------------------ per-user offline-queue idempotency ---

Db::reset();
$eventConfig = writeTestConfig($workspace, [
    'priceCents' => 150,
    'dbPath' => $workspace . '/data/client-events.sqlite',
]);
putenv(CONFIG_PATH_ENV . $eventConfig);
Config::forget();
Db::reset();

$one = Users::create('c1', 'h1', 'hh1');
$two = Users::create('c2', 'h2', 'hh2');
$sharedEventId = 'shared-event-id';

Users::addCoffee((string) $one['id'], $sharedEventId);
check('the first account books its coffee', Users::coffees(Users::find((string) $one['id'])) === 1);

// The id is generated on the client, so two devices can pick the same one.
// Scoped per user, the second account still gets its coffee; as a global key
// this booking was silently swallowed.
$twoUpdated = Users::addCoffee((string) $two['id'], $sharedEventId);
check(
    'a second account using the same client event id still books its own coffee',
    Users::coffees($twoUpdated) === 1
);
check(
    'replaying the id within the same account is still idempotent',
    Users::coffees(Users::addCoffee((string) $two['id'], $sharedEventId)) === 1
);

// ------------------------------------------------------------ day offset ---

Db::reset();
$offsetConfig = writeTestConfig($workspace, [
    'dbPath' => $workspace . '/data/day-offset.sqlite',
    // +14h pushes a late-evening UTC booking into the next local day.
    'dayOffsetMinutes' => 840,
]);
putenv(CONFIG_PATH_ENV . $offsetConfig);
Config::forget();
Db::reset();

check('Config::dayOffsetSeconds reflects the configured minutes', Config::dayOffsetSeconds() === 840 * 60);
check(
    'Db::dayExpr shifts the SQL day boundary by the same amount',
    str_contains(Db::dayExpr('created_at'), '+ ' . (840 * 60))
);

$offsetUser = Users::create('o', 'ho', 'hho');
Users::addCoffee((string) $offsetUser['id']);
$offsetHistory = Users::history((string) $offsetUser['id']);
// The PHP-side day and the SQL-side day have to agree, or "today" silently
// reads zero while the booking sits on a neighbouring bar.
check(
    'history\'s last day matches the shifted calendar day',
    $offsetHistory['days'][27]['date'] === gmdate('Y-m-d', Clock::now() + 840 * 60)
);
check('the booking lands on the shifted "today"', $offsetHistory['today'] === 1);
check('streakDays agrees with the shifted day boundary', Users::streakDays((string) $offsetUser['id']) === 1);

// The month-end reminder uses the same boundary.
$lastDayUtcEvening = gmmktime(23, 0, 0, 6, 29, 2031); // 29 June 23:00 UTC = 30 June 13:00 at +14h
check(
    'reminderMonthTag follows the configured day boundary',
    Users::reminderMonthTag($lastDayUtcEvening) === REMINDER_MONTH_TAG
);

Db::reset();
Config::forget();
summarizeAndExit();
