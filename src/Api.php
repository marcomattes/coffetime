<?php

declare(strict_types=1);

namespace Coffee;

use Throwable;

/**
 * Front-Controller-Logik: Routing und alle Endpunkte.
 */
final class Api
{
    /** Endpunkte ohne Sitzungszwang. */
    private const PUBLIC_PATHS = [
        '/api/register/options',
        '/api/register/verify',
        '/api/login/options',
        '/api/login/verify',
        '/api/setup/status',
        '/api/setup/init',
    ];

    public static function dispatch(): void
    {
        // Datenbank und Migrationen laufen bei jedem Start, bevor irgendetwas
        // beantwortet wird. Ein Request, der die Datenbank nicht braucht, soll
        // sie trotzdem angelegt und aktuell vorfinden.
        Db::pdo();

        $path = Http::path();
        $method = Http::method();

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            self::dispatchApi($path, $method);
        }

        self::serveFrontend($path);
    }

    private static function dispatchApi(string $path, string $method): never
    {
        // Die Teststeuerung existiert nur mit testMode und passendem Token.
        if (str_starts_with($path, '/api/test/')) {
            self::dispatchTest($path, $method);
        }

        $routes = [
            '/api/register/options' => ['POST', 'registerOptions'],
            '/api/register/verify' => ['POST', 'registerVerify'],
            '/api/login/options' => ['POST', 'loginOptions'],
            '/api/login/verify' => ['POST', 'loginVerify'],
            '/api/logout' => ['POST', 'logout'],
            '/api/me' => ['GET', 'me'],
            '/api/coffee' => ['POST', 'coffee'],
            '/api/coffee/undo' => ['POST', 'coffeeUndo'],
            '/api/stats' => ['GET', 'stats'],
            '/api/history' => ['GET', 'history'],
            '/api/admin/users' => ['GET', 'adminUsers'],
            '/api/admin/payment' => ['POST', 'adminPayment'],
            // Ein Pfad kann in dieser Routing-Tabelle nur eine Methode
            // tragen – GET und POST für die Einstellungen leben deshalb auf
            // zwei Pfaden statt auf einem gemeinsamen.
            '/api/admin/settings' => ['GET', 'adminSettingsGet'],
            '/api/admin/settings/update' => ['POST', 'adminSettingsUpdate'],
            '/api/setup/status' => ['GET', 'setupStatus'],
            '/api/setup/init' => ['POST', 'setupInit'],
        ];

        if (!isset($routes[$path])) {
            Http::error('not_found', 404);
        }

        [$expectedMethod, $handler] = $routes[$path];
        $needsSession = !in_array($path, self::PUBLIC_PATHS, true);

        // Ohne Sitzung gibt es bei geschützten Endpunkten grundsätzlich 401 –
        // auch dann, wenn die Methode nicht passt.
        $user = $needsSession ? Sessions::currentUser() : null;
        if ($needsSession && $user === null) {
            Http::error('unauthorized', 401);
        }
        if ($method !== $expectedMethod) {
            Http::error('method_not_allowed', 405);
        }

        /** @var callable $callable */
        $callable = [self::class, $handler];
        $callable($user);
        Http::error('server_error', 500);
    }

    // ---------------------------------------------------------------- Auth ---

    /**
     * Prüft den Einladungscode. Das passiert auf beiden Registrierungs-
     * Endpunkten als Erstes – vor jeder Validierung und vor jedem Schreibzugriff.
     *
     * @param array<string, mixed> $body
     */
    private static function requireInvite(array $body): void
    {
        $expected = Config::invite();
        $given = Http::stringField($body, 'invite');
        if ($expected === '' || $given === null || $given === '' || !hash_equals($expected, $given)) {
            Http::error('invalid_invite', 403);
        }
    }

    /**
     * Prüft Vor- und Nachname.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: string}
     */
    private static function requireNames(array $body): array
    {
        $first = Http::stringField($body, 'firstName');
        $last = Http::stringField($body, 'lastName');
        if ($first === null || $last === null) {
            Http::error('invalid_name', 400);
        }
        foreach ([$first, $last] as $part) {
            if (mb_strlen($part) > Crypto::NAME_MAX_LENGTH) {
                Http::error('invalid_name', 400);
            }
        }
        $firstNormalized = Crypto::normalizeNamePart($first);
        $lastNormalized = Crypto::normalizeNamePart($last);
        if ($firstNormalized === '' || $lastNormalized === '') {
            Http::error('invalid_name', 400);
        }

        return [$firstNormalized, $lastNormalized];
    }

    private static function crypto(): Crypto
    {
        try {
            return Crypto::fromConfig();
        } catch (Throwable $e) {
            error_log('[coffee] crypto unavailable: ' . $e->getMessage());
            Http::error('server_misconfigured', 500);
        }
    }

    private static function registerOptions(): never
    {
        $body = Http::body();
        self::requireInvite($body);
        [$first, $last] = self::requireNames($body);
        $crypto = self::crypto();

        $nameHash = $crypto->nameHash($first, $last);
        if (Users::idForNameHash($nameHash) !== null) {
            Http::error('name_taken', 409);
        }
        $handleRaw = random_bytes(16);
        $handleEncoded = Encoding::base64UrlEncode($handleRaw);
        $options = WebAuthnService::creationOptions($handleRaw, self::userLabel($handleEncoded));
        $payload = [
            'nameEncrypted' => $crypto->sealName($first, $last),
            'nameHash' => $nameHash,
            'userHandle' => $handleEncoded,
        ];

        Ceremonies::create(
            Ceremonies::KIND_REGISTER,
            Encoding::base64UrlEncode($options->challenge),
            WebAuthnService::optionsToJson($options),
            $payload
        );

        Http::json(WebAuthnService::optionsToArray($options));
    }

    private static function registerVerify(): never
    {
        $body = Http::body();
        self::requireInvite($body);
        self::requireNames($body);

        $raw = self::credentialFromBody($body);
        if ($raw === null) {
            Http::error('invalid_credential', 400);
        }
        $challenge = WebAuthnService::challengeFromCredential($raw);
        if ($challenge === null) {
            Http::error('invalid_credential', 400);
        }

        $ceremony = Ceremonies::consume(Ceremonies::KIND_REGISTER, $challenge);
        if ($ceremony === null) {
            Http::error('challenge_invalid', 400);
        }
        $options = WebAuthnService::creationOptionsFromJson((string) ($ceremony['options'] ?? ''));
        if ($options === null) {
            Http::error('challenge_invalid', 400);
        }
        $payload = json_decode((string) ($ceremony['payload'] ?? '[]'), true);
        if (!is_array($payload)) {
            Http::error('challenge_invalid', 400);
        }

        $credential = WebAuthnService::parseCredential($raw);
        if ($credential === null) {
            Http::error('invalid_credential', 400);
        }
        $record = WebAuthnService::verifyAttestation($credential, $options);
        if ($record === null) {
            Http::error('verification_failed', 400);
        }

        if (Credentials::exists(Encoding::base64UrlEncode($record->publicKeyCredentialId))) {
            Http::error('credential_exists', 409);
        }

        $nameHash = (string) ($payload['nameHash'] ?? '');
        $nameEncrypted = (string) ($payload['nameEncrypted'] ?? '');
        $handle = (string) ($payload['userHandle'] ?? '');
        if ($nameHash === '' || $nameEncrypted === '' || $handle === '') {
            Http::error('challenge_invalid', 400);
        }
        if (Users::idForNameHash($nameHash) !== null) {
            Http::error('name_taken', 409);
        }

        // Registrierung ist die einzige Stelle, an der ein Benutzer entsteht.
        $user = Users::create($nameEncrypted, $nameHash, $handle);
        Credentials::store((string) $user['id'], $record);
        Sessions::start((string) $user['id']);

        Http::json(['ok' => true, 'user' => self::meView($user)]);
    }

    private static function loginOptions(): never
    {
        $options = WebAuthnService::requestOptions();
        Ceremonies::create(
            Ceremonies::KIND_LOGIN,
            Encoding::base64UrlEncode($options->challenge),
            WebAuthnService::optionsToJson($options)
        );

        Http::json(WebAuthnService::optionsToArray($options));
    }

    private static function loginVerify(): never
    {
        $body = Http::body();
        $raw = self::credentialFromBody($body);
        if ($raw === null) {
            Http::error('invalid_credential', 400);
        }
        $challenge = WebAuthnService::challengeFromCredential($raw);
        if ($challenge === null) {
            Http::error('invalid_credential', 400);
        }

        // Die Challenge wird sofort verbraucht: ein Replay derselben
        // clientDataJSON schlägt fehl.
        $ceremony = Ceremonies::consume(Ceremonies::KIND_LOGIN, $challenge);
        if ($ceremony === null) {
            Http::error('unauthorized', 401);
        }
        $options = WebAuthnService::requestOptionsFromJson((string) ($ceremony['options'] ?? ''));
        if ($options === null) {
            Http::error('unauthorized', 401);
        }

        $credentialId = self::credentialIdFromRaw($raw);
        if ($credentialId === null) {
            Http::error('invalid_credential', 400);
        }
        $row = Credentials::findByCredentialId($credentialId);
        if ($row === null) {
            Http::error('unauthorized', 401);
        }
        $user = Users::find((string) ($row['user_id'] ?? ''));
        if ($user === null) {
            Http::error('unauthorized', 401);
        }
        $record = Credentials::toRecord($row, $user);
        if ($record === null) {
            Http::error('unauthorized', 401);
        }

        $credential = WebAuthnService::parseCredential($raw);
        if ($credential === null) {
            Http::error('invalid_credential', 400);
        }
        $verified = WebAuthnService::verifyAssertion($credential, $record, $options);
        if ($verified === null) {
            Http::error('unauthorized', 401);
        }

        // Geprüfter Signaturzähler zurückschreiben.
        Credentials::updateSignCount((string) $row['id'], $verified->counter);
        Sessions::start((string) $user['id']);

        Http::json(['ok' => true, 'user' => self::meView($user)]);
    }

    /** @param array<string, mixed> $user */
    private static function logout(array $user): never
    {
        Sessions::logoutCurrent();
        Http::json(['ok' => true]);
    }

    // ------------------------------------------------------------- Zähler ---

    /** @param array<string, mixed> $user */
    private static function me(array $user): never
    {
        Http::json(self::meView($user));
    }

    /** @param array<string, mixed> $user */
    private static function coffee(array $user): never
    {
        // Der Body wird bewusst ignoriert – der Zähler ist serverautoritativ.
        $updated = Users::addCoffee((string) $user['id']);
        Http::json([
            'coffees' => Users::coffees($updated),
            'balanceCents' => Users::balanceCents($updated),
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function coffeeUndo(array $user): never
    {
        $updated = Users::undoCoffee((string) $user['id']);
        Http::json([
            'coffees' => Users::coffees($updated),
            'balanceCents' => Users::balanceCents($updated),
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function stats(array $user): never
    {
        Http::json(Users::stats((string) $user['id']));
    }

    /** @param array<string, mixed> $user */
    private static function history(array $user): never
    {
        Http::json(Users::history((string) $user['id']));
    }

    // -------------------------------------------------------------- Admin ---

    /** @param array<string, mixed> $user */
    private static function adminUsers(array $user): never
    {
        self::requireAdmin($user);
        $users = [];
        foreach (Users::all() as $row) {
            $users[] = Users::adminView($row);
        }
        Http::json(['users' => $users]);
    }

    /** @param array<string, mixed> $user */
    private static function adminPayment(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $userId = Http::stringField($body, 'userId');
        if ($userId === null || !Users::isValidId($userId) || Users::find($userId) === null) {
            Http::error('unknown_user', 404);
        }

        // Nur echte Ganzzahlen zulassen – kein bool, kein float, kein String.
        $amountRaw = $body['amountCents'] ?? null;
        if (!is_int($amountRaw) || $amountRaw === 0 || $amountRaw < -1000000 || $amountRaw > 1000000) {
            Http::error('invalid_amount', 400);
        }

        $updated = Users::addPayment($userId, $amountRaw);
        Http::json(['ok' => true, 'user' => Users::adminView($updated)]);
    }

    /** @param array<string, mixed> $user */
    private static function requireAdmin(array $user): void
    {
        // Admin ist, wer in config.php gelistet ist ODER dessen Zeile das
        // is_admin-Flag trägt (der erste registrierte Nutzer, siehe
        // Users::create()).
        if (!Config::isAdmin((string) ($user['id'] ?? '')) && !Users::isAdminRow($user)) {
            Http::error('forbidden', 403);
        }
    }

    /** @param array<string, mixed> $user */
    private static function adminSettingsGet(array $user): never
    {
        self::requireAdmin($user);
        Http::json([
            'priceCents' => Config::priceCents(),
            'invite' => Config::invite(),
        ]);
    }

    /**
     * Ändert Preis und/oder Einladungscode zur Laufzeit. Bewusst OHNE
     * Möglichkeit, adminPublicKey oder namePepper zu ändern: beides würde
     * bereits verschlüsselte Namen unlesbar machen bzw. bestehende
     * Namens-HMACs entwerten und so Duplikatsprüfung/Entschlüsselung für
     * Altbestand brechen.
     *
     * @param array<string, mixed> $user
     */
    private static function adminSettingsUpdate(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $hasPrice = array_key_exists('priceCents', $body);
        $hasInvite = array_key_exists('invite', $body);
        if (!$hasPrice && !$hasInvite) {
            Http::error('invalid_settings', 400);
        }

        $pairs = [];
        if ($hasPrice) {
            $priceRaw = $body['priceCents'];
            if (!is_int($priceRaw) || $priceRaw < 1 || $priceRaw > 100000) {
                Http::error('invalid_settings', 400);
            }
            $pairs['priceCents'] = (string) $priceRaw;
        }
        if ($hasInvite) {
            $inviteRaw = $body['invite'];
            $invite = is_string($inviteRaw) ? trim($inviteRaw) : '';
            if (mb_strlen($invite) < 4 || mb_strlen($invite) > 64) {
                Http::error('invalid_settings', 400);
            }
            $pairs['invite'] = $invite;
        }

        Settings::setMany($pairs);

        Http::json([
            'ok' => true,
            'priceCents' => Config::priceCents(),
            'invite' => Config::invite(),
        ]);
    }

    // ------------------------------------------------------- Einrichtung ---

    /**
     * Ein frisches Deployment braucht keine handbearbeitete config.php mehr:
     * ohne konfigurierten adminPublicKey und ohne Benutzer verlangt die
     * Oberfläche den Einrichtungsassistenten statt Registrierung/Login.
     */
    private static function needsSetup(): bool
    {
        return Config::adminPublicKey() === '' && Users::count() === 0;
    }

    private static function setupStatus(): never
    {
        Http::json([
            'needsSetup' => self::needsSetup(),
            'priceCents' => Config::priceCents(),
        ]);
    }

    /**
     * Einmaliger Abschluss der Einrichtung: der öffentliche Admin-Schlüssel
     * wird lokal im Browser erzeugt (der private Teil verlässt den Browser
     * nie) und hier zusammen mit Preis und Einladungscode hinterlegt. Der
     * erste danach registrierte Benutzer wird automatisch Admin (siehe
     * Users::create()).
     */
    private static function setupInit(): never
    {
        if (!self::needsSetup()) {
            Http::error('already_initialized', 409);
        }

        $body = Http::body();

        $publicKey = Http::stringField($body, 'adminPublicKey');
        if ($publicKey === null || $publicKey === '') {
            Http::error('invalid_key', 400);
        }
        try {
            // Nur zur Validierung instanziiert – der Pepper ist hier
            // irrelevant, es geht ausschliesslich um den öffentlichen
            // Schlüssel.
            new Crypto($publicKey, 'probe');
        } catch (Throwable) {
            Http::error('invalid_key', 400);
        }

        $priceRaw = $body['priceCents'] ?? null;
        if (!is_int($priceRaw) || $priceRaw < 1 || $priceRaw > 100000) {
            Http::error('invalid_price', 400);
        }

        $inviteRaw = Http::stringField($body, 'invite');
        $invite = $inviteRaw !== null ? trim($inviteRaw) : '';
        if (mb_strlen($invite) < 4 || mb_strlen($invite) > 64) {
            Http::error('invalid_invite', 400);
        }

        // Kleines, bewusst in Kauf genommenes Race-Fenster: zwei parallele
        // erste Requests könnten beide bis hierher kommen, bevor einer von
        // ihnen seine Einstellungen geschrieben hat. Der Assistent läuft
        // genau einmal beim allerersten Deployment, nicht unter Last –
        // ein echtes Lock lohnt den Aufwand hier nicht.
        if (!self::needsSetup()) {
            Http::error('already_initialized', 409);
        }

        $pairs = [
            'adminPublicKey' => $publicKey,
            'priceCents' => (string) $priceRaw,
            'invite' => $invite,
        ];
        if (Config::namePepper() === '') {
            // Niemals einen bereits vorhandenen Pepper überschreiben – das
            // würde alle bestehenden Namens-HMACs entwerten.
            $pairs['namePepper'] = bin2hex(random_bytes(32));
        }

        Settings::setMany($pairs);

        Http::json(['ok' => true]);
    }

    // --------------------------------------------------------- Teststeuerung ---

    private static function dispatchTest(string $path, string $method): never
    {
        $token = Config::testToken();
        $given = Http::header('X-Test-Token');
        // Ohne testMode oder mit falschem Token existieren diese Endpunkte nicht.
        if (!Config::testMode() || $token === '' || $given === '' || !hash_equals($token, $given)) {
            Http::error('not_found', 404);
        }

        $routes = [
            '/api/test/reset' => ['POST', 'testReset'],
            '/api/test/seed' => ['POST', 'testSeed'],
            '/api/test/state' => ['GET', 'testState'],
            '/api/test/clock' => ['POST', 'testClock'],
            '/api/test/login' => ['POST', 'testLogin'],
        ];
        if (!isset($routes[$path])) {
            Http::error('not_found', 404);
        }
        [$expectedMethod, $handler] = $routes[$path];
        if ($method !== $expectedMethod) {
            Http::error('method_not_allowed', 405);
        }

        /** @var callable $callable */
        $callable = [self::class, $handler];
        $callable();
        Http::error('server_error', 500);
    }

    private static function testReset(): never
    {
        $mysql = Db::driver() === 'mysql';

        Db::transaction(static function (\PDO $pdo) use ($mysql): void {
            foreach (['sessions', 'credentials', 'ceremonies', 'coffee_events', 'users'] as $table) {
                if (Db::tableExists($pdo, $table)) {
                    $pdo->exec('DELETE FROM ' . $table);
                }
            }
            // sqlite_sequence gibt es nur unter SQLite.
            if (!$mysql && Db::tableExists($pdo, 'sqlite_sequence')) {
                $pdo->exec('DELETE FROM sqlite_sequence');
            }
        });

        if ($mysql) {
            // ALTER TABLE committet implizit – deshalb erst nach Abschluss der
            // Transaktion und ausserhalb davon, sonst risse es sie mitten durch.
            $pdo = Db::pdo();
            foreach (['users', 'credentials', 'ceremonies', 'coffee_events'] as $table) {
                if (Db::tableExists($pdo, $table)) {
                    $pdo->exec('ALTER TABLE ' . $table . ' AUTO_INCREMENT = 1');
                }
            }
        }

        Http::json(['ok' => true]);
    }

    private static function testSeed(): never
    {
        $body = Http::body();
        $input = $body['users'] ?? [];
        if (!is_array($input)) {
            Http::error('invalid_users', 400);
        }
        $crypto = self::crypto();

        $created = [];
        foreach ($input as $entry) {
            if (!is_array($entry)) {
                Http::error('invalid_users', 400);
            }
            [$first, $last] = self::requireNames($entry);
            $coffees = isset($entry['coffees']) && is_numeric($entry['coffees']) ? (int) $entry['coffees'] : 0;
            $paid = isset($entry['paidCents']) && is_numeric($entry['paidCents']) ? (int) $entry['paidCents'] : 0;

            // Exakt derselbe Weg wie bei einer echten Registrierung.
            $nameHash = $crypto->nameHash($first, $last);
            if (Users::idForNameHash($nameHash) !== null) {
                Http::error('name_taken', 409);
            }
            $user = Users::create(
                $crypto->sealName($first, $last),
                $nameHash,
                Users::newHandle(),
                $coffees,
                $paid
            );
            $created[] = [
                'id' => (string) $user['id'],
                'firstName' => $first,
                'lastName' => $last,
            ];
        }

        Http::json(['users' => $created]);
    }

    private static function testState(): never
    {
        $users = [];
        foreach (Users::all() as $row) {
            $users[] = [
                'id' => (string) ($row['id'] ?? ''),
                'coffees' => Users::coffees($row),
                'paidCents' => Users::paidCents($row),
                'balanceCents' => Users::balanceCents($row),
                'admin' => Config::isAdmin((string) ($row['id'] ?? '')) || Users::isAdminRow($row),
            ];
        }

        Http::json([
            'users' => $users,
            'credentials' => Credentials::count(),
            'sessions' => Sessions::count(),
            'config' => [
                'priceCents' => Config::priceCents(),
                'admins' => Config::admins(),
            ],
        ]);
    }

    private static function testClock(): never
    {
        $body = Http::body();
        $offset = $body['offsetSeconds'] ?? 0;
        if (is_bool($offset) || !is_numeric($offset)) {
            Http::error('invalid_offset', 400);
        }
        Clock::setOffset((int) $offset);

        Http::json(['ok' => true, 'offsetSeconds' => Clock::offset(), 'now' => Clock::now()]);
    }

    /** Meldet einen Testbenutzer ohne WebAuthn-Zeremonie an – nur mit Testtoken erreichbar. */
    private static function testLogin(): never
    {
        $body = Http::body();
        $userId = Http::stringField($body, 'userId');
        if ($userId === null || !Users::isValidId($userId) || Users::find($userId) === null) {
            Http::error('unknown_user', 404);
        }

        Sessions::start($userId);
        Http::json(['ok' => true, 'userId' => $userId]);
    }

    // ------------------------------------------------------------- Helfer ---

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>|null
     */
    private static function credentialFromBody(array $body): ?array
    {
        $credential = $body['credential'] ?? null;
        if (is_array($credential) && isset($credential['response'])) {
            return $credential;
        }
        // Auch ein nacktes PublicKeyCredential als Body wird akzeptiert.
        if (isset($body['response']) && is_array($body['response'])) {
            return $body;
        }

        return null;
    }

    /** @param array<mixed> $raw */
    private static function credentialIdFromRaw(array $raw): ?string
    {
        foreach (['id', 'rawId'] as $key) {
            $value = $raw[$key] ?? null;
            if (is_string($value) && $value !== '') {
                $binary = Encoding::base64UrlDecode($value);
                if ($binary !== null && $binary !== '') {
                    return Encoding::base64UrlEncode($binary);
                }
            }
        }

        return null;
    }

    private static function userLabel(string $handle): string
    {
        // Opakes Label: es darf nie ein Klarname in die Optionen geraten.
        return 'kaffee-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $handle) ?? '', 0, 10);
    }

    /**
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private static function meView(array $user): array
    {
        $id = (string) ($user['id'] ?? '');

        return [
            'id' => $id,
            'admin' => Config::isAdmin($id) || Users::isAdminRow($user),
            'coffees' => Users::coffees($user),
            'balanceCents' => Users::balanceCents($user),
            'priceCents' => Config::priceCents(),
            'streakDays' => Users::streakDays($id),
        ];
    }

    // --------------------------------------------------------- Frontend ---

    /** Anwendungsrouten, die den HTML-Rumpf ausliefern. */
    private const APP_PATHS = ['/', '/app', '/admin', '/login'];

    private static function serveFrontend(string $path): never
    {
        // Nur bekannte Anwendungsrouten liefern die Oberfläche. Alles andere
        // existiert nicht – dieser Controller liest niemals eine Datei vom
        // Dateisystem und kann darum auch keine ausliefern.
        if (!in_array($path, self::APP_PATHS, true)) {
            Http::error('not_found', 404);
        }

        Http::html(Frontend::shell());
    }
}
