<?php

declare(strict_types=1);

/**
 * Framework-free unit coverage for the pure-function and DB-layer parts of
 * the application: no web server involved, just COFFEE_CONFIG_PATH and a
 * temp SQLite database. Http::json()/Http::error() call exit(), so this file
 * never touches them or the API handlers that call them.
 */

require __DIR__ . '/helpers.php';
require __DIR__ . '/../src/Bootstrap.php';

use Coffee\Bootstrap;
use Coffee\Clock;
use Coffee\Config;
use Coffee\Db;
use Coffee\Encoding;
use Coffee\Http;
use Coffee\Sessions;
use Coffee\Users;

Bootstrap::init();

$workspace = make_temp_workspace('coffee-unit');

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
putenv('COFFEE_CONFIG_PATH=' . $workspace . '/no-such-config.php');
Config::forget();
check('Config::priceCents defaults to 150 without a config file', Config::priceCents() === 150);
check('Config::invite defaults to empty string', Config::invite() === '');
check('Config::admins defaults to an empty list', Config::admins() === []);
check('Config::isAdmin is false for anyone without admins configured', Config::isAdmin('1') === false);
check('Config::testMode defaults to false', Config::testMode() === false);
check('Config::rpId defaults to localhost', Config::rpId() === 'localhost');

// Phase 2: a real config file with overrides.
$configPath = write_test_config($workspace, [
    'priceCents' => 200,
    'admins' => ['42', 7],
    'testMode' => true,
    'testToken' => 'phase-two-token',
    'dbPath' => $workspace . '/data/coffee.sqlite',
]);
putenv('COFFEE_CONFIG_PATH=' . $configPath);
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
foreach (['users', 'credentials', 'sessions', 'ceremonies', 'coffee_events'] as $table) {
    check("Db::migrate creates the {$table} table", Db::tableExists($migratePdo, $table));
}
foreach (['name_encrypted', 'name_hash', 'user_handle', 'coffees', 'paid_cents'] as $column) {
    check(
        "Db::migrate gives users a {$column} column",
        in_array($column, Db::columns($migratePdo, 'users'), true)
    );
}
$indexNames = $migratePdo
    ->query("SELECT name FROM sqlite_master WHERE type = 'index'")
    ->fetchAll(PDO::FETCH_COLUMN);
foreach (['idx_users_name_hash', 'idx_users_handle', 'idx_credentials_credential_id', 'idx_coffee_events_user_created'] as $index) {
    check("Db::migrate creates the {$index} index", in_array($index, $indexNames, true));
}

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

$alice = Users::create('cipher-alice', 'hash-alice', 'handle-alice', coffees: 3, paidCents: 100);
check('Users::create stores the requested initial coffees', Users::coffees($alice) === 3);
check('Users::create stores the requested initial paidCents', Users::paidCents($alice) === 100);
check(
    'balanceCents = coffees * priceCents - paidCents at creation',
    Users::balanceCents($alice) === 3 * 200 - 100
);

$bob = Users::create('cipher-bob', 'hash-bob', 'handle-bob');
$carol = Users::create('cipher-carol', 'hash-carol', 'handle-carol');

// -------------------------------------------------------- addCoffee/undo ---

$before = Users::coffees(Users::find((string) $alice['id']));
$updated = Users::addCoffee((string) $alice['id']);
check('addCoffee increments the counter by one', Users::coffees($updated) === $before + 1);

$eventsBefore = Db::fetchValue('SELECT COUNT(*) FROM coffee_events WHERE user_id = ?', [(int) $alice['id']]);
$updated = Users::undoCoffee((string) $alice['id']);
check('undoCoffee decrements the counter by one', Users::coffees($updated) === $before);
$eventsAfter = Db::fetchValue('SELECT COUNT(*) FROM coffee_events WHERE user_id = ?', [(int) $alice['id']]);
check('undoCoffee removes exactly one coffee_events row', (int) $eventsAfter === (int) $eventsBefore - 1);

// Floors at zero: bob has never booked a coffee.
$updated = Users::undoCoffee((string) $bob['id']);
check('undoCoffee on a user at zero coffees stays at zero', Users::coffees($updated) === 0);
$updated = Users::undoCoffee((string) $bob['id']);
check('undoCoffee never goes negative even when called repeatedly', Users::coffees($updated) === 0);

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

// ------------------------------------------------------------ addPayment ---

$updated = Users::addPayment((string) $bob['id'], 500);
check('addPayment adds to paidCents', Users::paidCents($updated) === 500);
$updated = Users::addPayment((string) $bob['id'], -100000);
check('addPayment clamps paidCents at zero for a large negative amount', Users::paidCents($updated) === 0);

// ----------------------------------------------------------------- stats ---

Db::reset();
Config::forget();
$statsConfig = write_test_config($workspace, [
    'priceCents' => 150,
    'dbPath' => $workspace . '/data/stats.sqlite',
]);
putenv('COFFEE_CONFIG_PATH=' . $statsConfig);
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
$streakConfig = write_test_config($workspace, [
    'dbPath' => $workspace . '/data/streak.sqlite',
]);
putenv('COFFEE_CONFIG_PATH=' . $streakConfig);
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
$historyConfig = write_test_config($workspace, [
    'dbPath' => $workspace . '/data/history.sqlite',
]);
putenv('COFFEE_CONFIG_PATH=' . $historyConfig);
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

// -------------------------------------------------------------- Sessions ---

Db::reset();
$sessionsConfig = write_test_config($workspace, [
    'dbPath' => $workspace . '/data/sessions.sqlite',
]);
putenv('COFFEE_CONFIG_PATH=' . $sessionsConfig);
Config::forget();
Db::reset();

$sessionUser = Users::create('e', 'h', 'ha');
$token = Sessions::start((string) $sessionUser['id']);
$expectedId = hash('sha256', $token);
$row = Db::fetchRow('SELECT * FROM sessions WHERE id = ?', [$expectedId]);
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
check('the manually inserted expired session exists before pruning', Db::fetchRow('SELECT * FROM sessions WHERE id = ?', [$expiredId]) !== null);
Sessions::start((string) $sessionUser['id']);
check('Sessions::start prunes expired sessions as a side effect', Db::fetchRow('SELECT * FROM sessions WHERE id = ?', [$expiredId]) === null);

Db::reset();
Config::forget();
summarize_and_exit();
