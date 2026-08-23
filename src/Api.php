<?php

declare(strict_types=1);

namespace Coffee;

use InvalidArgumentException;
use Throwable;

/**
 * Front controller logic: routing and all endpoints.
 */
final class Api
{
    /** Endpoints that do not require a session. */
    private const PUBLIC_PATHS = [
        '/api/register/options',
        '/api/register/verify',
        '/api/login/options',
        '/api/login/verify',
        '/api/login/password',
        '/api/setup/status',
        '/api/setup/init',
        '/api/link/options',
        '/api/link/verify',
        '/api/version',
    ];

    public static function dispatch(): void
    {
        // The database and its migrations run on every start, before anything
        // is answered. A request that does not need the database must still
        // find it created and up to date.
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
        // The test control surface exists only with testMode and a matching token.
        if (str_starts_with($path, '/api/test/')) {
            self::dispatchTest($path, $method);
        }

        $routes = [
            '/api/register/options' => ['POST', 'registerOptions'],
            '/api/register/verify' => ['POST', 'registerVerify'],
            '/api/login/options' => ['POST', 'loginOptions'],
            '/api/login/verify' => ['POST', 'loginVerify'],
            '/api/login/password' => ['POST', 'loginPassword'],
            '/api/logout' => ['POST', 'logout'],
            '/api/me' => ['GET', 'me'],
            '/api/coffee' => ['POST', 'coffee'],
            '/api/coffee/undo' => ['POST', 'coffeeUndo'],
            '/api/stats' => ['GET', 'stats'],
            '/api/history' => ['GET', 'history'],
            '/api/reminders' => ['GET', 'reminders'],
            '/api/reminders/ack' => ['POST', 'remindersAck'],
            '/api/admin/users' => ['GET', 'adminUsers'],
            '/api/admin/payment' => ['POST', 'adminPayment'],
            '/api/admin/remind' => ['POST', 'adminRemind'],
            '/api/admin/user/delete' => ['POST', 'adminUserDelete'],
            '/api/admin/link-code' => ['POST', 'adminLinkCode'],
            '/api/admin/password' => ['POST', 'adminPassword'],
            '/api/link/code' => ['POST', 'linkCode'],
            '/api/link/options' => ['POST', 'linkOptions'],
            '/api/link/verify' => ['POST', 'linkVerify'],
            // A path can carry only one method in this routing table – GET and
            // POST for the settings therefore live on two paths instead of one
            // shared path.
            '/api/admin/settings' => ['GET', 'adminSettingsGet'],
            '/api/admin/settings/update' => ['POST', 'adminSettingsUpdate'],
            '/api/version' => ['GET', 'version'],
            '/api/setup/status' => ['GET', 'setupStatus'],
            '/api/setup/init' => ['POST', 'setupInit'],
        ];

        if (!isset($routes[$path])) {
            Http::error('not_found', 404);
        }

        [$expectedMethod, $handler] = $routes[$path];
        $needsSession = !in_array($path, self::PUBLIC_PATHS, true);

        // Protected endpoints always answer 401 without a session – even when
        // the method does not match.
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

    // --------------------------------------------------------------- Auth ---

    /**
     * Validates the invite code. Both registration endpoints do this first –
     * before any other validation and before any write.
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
     * Validates first and last name and returns them normalized.
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

    /**
     * Encrypts a name, turning "this name cannot be sealed" into a 400 rather
     * than an uncaught 500. Crypto rejects a payload that would exceed the
     * RSA-OAEP plaintext capacity, which is a property of the input.
     */
    private static function sealName(Crypto $crypto, string $first, string $last): string
    {
        try {
            return $crypto->sealName($first, $last);
        } catch (InvalidArgumentException $e) {
            Http::error('invalid_name', 400);
        } catch (Throwable $e) {
            error_log('[coffee] sealing the name failed: ' . $e->getMessage());
            Http::error('server_misconfigured', 500);
        }
    }

    private static function registerOptions(): never
    {
        // Before the invite is even compared: an unthrottled invite check is
        // an offline-speed guessing oracle for a code as short as four
        // characters, and the 409 below additionally reveals whether a given
        // real name is registered.
        RateLimit::enforce('register', RateLimit::REGISTER_MAX);

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
            'nameEncrypted' => self::sealName($crypto, $first, $last),
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
        RateLimit::enforce('register', RateLimit::REGISTER_MAX);

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

        // Registration is the only place where a user comes into existence.
        // The user row and its first passkey are written as one transaction:
        // a half-finished registration would reserve the name forever without
        // anyone being able to sign in as it.
        $user = Users::createWithCredential($nameEncrypted, $nameHash, $handle, $record);
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
        RateLimit::enforce('login', RateLimit::LOGIN_MAX);

        $body = Http::body();
        $raw = self::credentialFromBody($body);
        if ($raw === null) {
            Http::error('invalid_credential', 400);
        }
        $challenge = WebAuthnService::challengeFromCredential($raw);
        if ($challenge === null) {
            Http::error('invalid_credential', 400);
        }

        // The challenge is consumed immediately: replaying the same
        // clientDataJSON fails.
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

        Credentials::updateSignCount((string) $row['id'], $verified->counter);
        Sessions::start((string) $user['id']);

        Http::json(['ok' => true, 'user' => self::meView($user)]);
    }

    /**
     * Password sign-in, available on administrator accounts only.
     *
     * The app is passkey-first and everybody else stays passkey-only; this
     * path exists for a managed workstation whose policy blocks authenticators
     * outright, which would otherwise leave the person responsible for the tab
     * with no way in at all.
     *
     * There is no username in the schema, so the account is found the same way
     * duplicate registrations are detected: by the keyed HMAC of the entered
     * name. Every failure answers a bare 401 — never "no such account" versus
     * "wrong password", and never a hint that an account has no password.
     */
    private static function loginPassword(): never
    {
        RateLimit::enforce('password', RateLimit::PASSWORD_MAX);

        $body = Http::body();
        [$first, $last] = self::requireNames($body);
        $password = Http::stringField($body, 'password');
        if ($password === null || $password === '') {
            Http::error('unauthorized', 401);
        }
        // Bound the work before hashing: an arbitrarily long body must not buy
        // an attacker a unit of server-side hashing per request.
        if (mb_strlen($password) > Passwords::MAX_LENGTH) {
            Http::error('unauthorized', 401);
        }

        $crypto = self::crypto();
        $nameHash = $crypto->nameHash($first, $last);

        // A second counter, keyed by the account rather than the caller.
        // Without it, every address an attacker controls would get its own
        // PASSWORD_MAX budget against the same account.
        RateLimit::enforceFor('password', $nameHash, RateLimit::PASSWORD_ACCOUNT_MAX);

        $user = Users::findByNameHash($nameHash);

        // Runs a full verification even when no such account exists, so an
        // unknown name does not answer measurably faster than a wrong password.
        $verified = Passwords::verify($user, $password);
        if (!$verified || $user === null) {
            Http::error('unauthorized', 401);
        }

        // A password is only ever a credential on an administrator account.
        // Checked here and not only where it is set: should a row ever keep a
        // hash without the flag, the password stops working rather than
        // quietly remaining a way in.
        if (!Config::isAdmin((string) $user['id']) && !Users::isAdminRow($user)) {
            Http::error('unauthorized', 401);
        }

        // PHP's default cost rises over time, and a successful sign-in is the
        // only moment the plaintext is at hand to re-hash with.
        if (Passwords::needsRehash(Passwords::hashOf($user))) {
            Users::rehashPassword((string) $user['id'], Passwords::hash($password));
        }

        Sessions::start((string) $user['id']);

        Http::json(['ok' => true, 'user' => self::meView($user)]);
    }

    /**
     * @param array<string, mixed> $user Unused by this handler. Kept anyway:
     *     dispatchApi() calls every protected handler as `$callable($user)`
     *     so the whole family shares one calling shape (see dispatchApi()
     *     and, e.g., me()/stats()/history() below); dropping it here only
     *     would make logout() the odd one out for no behavioural gain, since
     *     PHP happily ignores the extra argument either way.
     */
    private static function logout(array $user): never
    {
        Sessions::logoutCurrent();
        Http::json(['ok' => true]);
    }

    // ----------------------------------------------------- Device linking ---

    /**
     * Issues a single-use code with which the signed-in user can attach a
     * second device to their own account. The code is handed to the interface
     * exactly once – afterwards it is stored nowhere in cleartext.
     *
     * @param array<string, mixed> $user
     */
    private static function linkCode(array $user): never
    {
        $result = LinkCodes::create((string) $user['id'], 'self', LinkCodes::SELF_TTL);
        Http::json(['code' => $result['code'], 'expiresAt' => $result['expiresAt']]);
    }

    /**
     * Builds registration options for a new device on an EXISTING account.
     * Unlike registerOptions(), no new user is created here: the existing
     * user_handle is reused so that the new passkey counts as a further
     * factor of the same account. Publicly reachable because the new device
     * has no session yet – the single-use code itself is the proof of
     * authorization.
     */
    private static function linkOptions(): never
    {
        // The code is the only proof of authorization on this public
        // endpoint, so guessing attempts have to be throttled.
        RateLimit::enforce('link', RateLimit::LINK_MAX);

        $body = Http::body();
        $code = Http::stringField($body, 'code');
        if ($code === null) {
            Http::error('invalid_code', 400);
        }

        // Peek only, no consume: the same code has to keep working after an
        // aborted attempt on the new device.
        $row = LinkCodes::peek($code);
        if ($row === null) {
            Http::error('invalid_code', 400);
        }

        $userId = (string) ($row['user_id'] ?? '');
        $user = Users::find($userId);
        $handle = $user !== null ? (string) ($user['user_handle'] ?? '') : '';
        $handleRaw = $handle !== '' ? Encoding::base64UrlDecode($handle) : null;
        if ($user === null || $handleRaw === null || $handleRaw === '') {
            Http::error('invalid_code', 400);
        }

        $options = WebAuthnService::creationOptions($handleRaw, self::userLabel($handle));
        $payload = [
            'userId' => $userId,
            'code' => LinkCodes::normalize($code),
        ];

        Ceremonies::create(
            Ceremonies::KIND_LINK,
            Encoding::base64UrlEncode($options->challenge),
            WebAuthnService::optionsToJson($options),
            $payload
        );

        Http::json(WebAuthnService::optionsToArray($options));
    }

    /**
     * Completes the device link: verifies the WebAuthn attestation as
     * registerVerify() does, but then consumes the single-use code instead of
     * creating a new user. The code is deliberately consumed only AFTER a
     * successful attestation – a failed attempt (wrong credential, duplicate
     * credential ID) must not burn the code.
     */
    private static function linkVerify(): never
    {
        RateLimit::enforce('link', RateLimit::LINK_MAX);

        $body = Http::body();

        $raw = self::credentialFromBody($body);
        if ($raw === null) {
            Http::error('invalid_credential', 400);
        }
        $challenge = WebAuthnService::challengeFromCredential($raw);
        if ($challenge === null) {
            Http::error('invalid_credential', 400);
        }

        [$options, $userId, $bodyCode] = self::resolveLinkCeremony($challenge, $body);

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

        // Only now is the code consumed: the attestation is verified and the
        // credential ID is free – from here on the link can no longer fail,
        // unless the code was consumed elsewhere in the meantime (concurrent
        // attempt).
        $linkRow = LinkCodes::consume($bodyCode);
        if ($linkRow === null) {
            Http::error('challenge_invalid', 400);
        }

        $user = Users::find($userId);
        if ($user === null) {
            Http::error('challenge_invalid', 400);
        }

        Credentials::store($userId, $record);
        $token = Sessions::start($userId);

        // An admin-issued code is the lost-device recovery path: whoever
        // holds the lost device would otherwise keep a valid session for up
        // to 30 more days. A self-issued code is the opposite case (adding a
        // second device of one's own), so it leaves other sessions alone.
        if ((string) ($linkRow['created_by'] ?? '') === 'admin') {
            Sessions::destroyForUser($userId, $token);
        }

        Http::json(['ok' => true, 'user' => self::meView($user)]);
    }

    /**
     * Resolves the ceremony consumed by linkVerify(): decodes its stored
     * options and payload, and confirms the code in the request body is
     * exactly the one this ceremony was created for – otherwise a foreign
     * ceremony could be used to smuggle a different code through. Split out
     * of linkVerify() only to keep that method's cognitive complexity within
     * budget; behaviour (including every error response) is unchanged.
     *
     * @param array<string, mixed> $body
     * @return array{0: \Webauthn\PublicKeyCredentialCreationOptions, 1: string, 2: string} [$options, $userId, $bodyCode]
     */
    private static function resolveLinkCeremony(string $challenge, array $body): array
    {
        $ceremony = Ceremonies::consume(Ceremonies::KIND_LINK, $challenge);
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

        $userId = (string) ($payload['userId'] ?? '');
        $storedCode = (string) ($payload['code'] ?? '');
        if ($userId === '' || $storedCode === '') {
            Http::error('challenge_invalid', 400);
        }

        $bodyCode = Http::stringField($body, 'code');
        $normalizedBody = $bodyCode !== null ? LinkCodes::normalize($bodyCode) : '';
        if ($normalizedBody === '' || !hash_equals($storedCode, $normalizedBody)) {
            Http::error('challenge_invalid', 400);
        }

        return [$options, $userId, (string) $bodyCode];
    }

    // ------------------------------------------------------------ Counter ---

    /** @param array<string, mixed> $user */
    private static function me(array $user): never
    {
        Http::json(self::meView($user));
    }

    /** @param array<string, mixed> $user */
    private static function coffee(array $user): never
    {
        // The counter stays server-authoritative – only the optional eventId
        // from the body is read, for idempotency of the offline queue (see
        // Users::addCoffee). If it is absent (older clients), the booking
        // proceeds as before.
        $body = Http::body();
        $eventId = Http::stringField($body, 'eventId');
        if ($eventId !== null && preg_match('/^[A-Za-z0-9-]{8,64}$/', $eventId) !== 1) {
            Http::error('invalid_event', 400);
        }

        $updated = Users::addCoffee((string) $user['id'], $eventId);
        Http::json([
            'coffees' => Users::coffees($updated),
            'balanceCents' => Users::balanceCents($updated),
            'undoableSeconds' => self::undoableSeconds($updated),
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function coffeeUndo(array $user): never
    {
        // Nothing booked at all has always answered 200 with the unchanged
        // state – a client whose counter is stale is not an error. A booking
        // that exists but has outlived its grace window is the other case: the
        // server will not do what was asked, and saying so is the only way the
        // app can explain why the counter did not move.
        if (Users::coffees($user) > 0 && Users::undoableSeconds((string) $user['id']) === 0) {
            Http::error('undo_expired', 409);
        }

        $updated = Users::undoCoffee((string) $user['id']);
        Http::json([
            'coffees' => Users::coffees($updated),
            'balanceCents' => Users::balanceCents($updated),
            'undoableSeconds' => self::undoableSeconds($updated),
        ]);
    }

    /**
     * How long the caller may still press undo. Kept in one place because the
     * counter and the window have to agree: an account at zero has nothing to
     * take back, whatever events happen to be lying around.
     *
     * @param array<string, mixed> $user
     */
    private static function undoableSeconds(array $user): int
    {
        if (Users::coffees($user) <= 0) {
            return 0;
        }

        return Users::undoableSeconds((string) ($user['id'] ?? ''));
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

    // ---------------------------------------------------------- Reminders ---

    /**
     * Due reminders for the signed-in user. Read-only – the client (service
     * worker or page) shows the local notification and afterwards acknowledges
     * via /api/reminders/ack exactly what it displayed. Every reminder thus
     * appears at most once across all devices, and a fetch without a successful
     * display consumes nothing.
     *
     * @param array<string, mixed> $user
     */
    private static function reminders(array $user): never
    {
        $balance = Users::balanceCents($user);

        // Month-end notice only when an amount is actually outstanding, and
        // only as long as it has not been acknowledged for this month.
        $monthEnd = null;
        $tag = Users::reminderMonthTag(Clock::now());
        if ($tag !== null && $balance > 0 && Users::remindedMonth($user) !== $tag) {
            $monthEnd = ['month' => $tag, 'balanceCents' => $balance];
        }

        // Like the month-end notice, an admin reminder is only worth showing
        // while something is actually outstanding — otherwise a user who has
        // paid in the meantime gets told to settle "0.00 €".
        $requestedAt = Users::remindRequestedAt($user);
        $admin = $requestedAt > 0 && $balance > 0
            ? ['requestedAt' => $requestedAt, 'balanceCents' => $balance]
            : null;

        Http::json(['monthEnd' => $monthEnd, 'admin' => $admin]);
    }

    /** @param array<string, mixed> $user */
    private static function remindersAck(array $user): never
    {
        $body = Http::body();

        $month = Http::stringField($body, 'month');
        if ($month !== null && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            Http::error('invalid_ack', 400);
        }

        $requestedAtRaw = $body['adminRequestedAt'] ?? null;
        $requestedAt = null;
        if ($requestedAtRaw !== null) {
            if (!is_int($requestedAtRaw) || $requestedAtRaw <= 0) {
                Http::error('invalid_ack', 400);
            }
            $requestedAt = $requestedAtRaw;
        }

        if ($month === null && $requestedAt === null) {
            Http::error('invalid_ack', 400);
        }

        Users::ackReminders((string) $user['id'], $month, $requestedAt);
        Http::json(['ok' => true]);
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

        // Accept genuine integers only – no bool, no float, no string.
        $amountRaw = $body['amountCents'] ?? null;
        if (!is_int($amountRaw) || $amountRaw === 0 || $amountRaw < -1000000 || $amountRaw > 1000000) {
            Http::error('invalid_amount', 400);
        }

        $updated = Users::addPayment($userId, $amountRaw);
        Http::json(['ok' => true, 'user' => Users::adminView($updated)]);
    }

    /**
     * Queues a payment reminder for a user. It appears as a local notification
     * on that user's device the next time the device polls /api/reminders (app
     * start or periodic background sync).
     *
     * @param array<string, mixed> $user
     */
    private static function adminRemind(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $userId = Http::stringField($body, 'userId');
        if ($userId === null || !Users::isValidId($userId) || Users::find($userId) === null) {
            Http::error('unknown_user', 404);
        }

        Users::requestReminder($userId);
        Http::json(['ok' => true]);
    }

    /**
     * Removes an account for good – row, passkeys, sessions, bookings and any
     * pending link code.
     *
     * An outstanding balance does not block it. The moment you most want an
     * account gone is when its owner has left owing money, and a delete that
     * refuses exactly then is a delete that does not work. What the balance
     * does earn is a spelled-out confirmation in the admin UI.
     *
     * Deleting yourself is refused, though: an installation whose only
     * administrator removes their own account has no way back in, since the
     * is_admin flag is only ever handed out to the very first user of a fresh
     * database.
     *
     * @param array<string, mixed> $user
     */
    private static function adminUserDelete(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $userId = Http::stringField($body, 'userId');
        if ($userId === null || !Users::isValidId($userId)) {
            Http::error('unknown_user', 404);
        }
        if ($userId === (string) ($user['id'] ?? '')) {
            Http::error('cannot_delete_self', 400);
        }
        if (!Users::delete($userId)) {
            Http::error('unknown_user', 404);
        }

        Http::json(['ok' => true]);
    }

    /**
     * Issues a single-use code with which an admin can attach a new device to
     * SOMEONE ELSE'S account – the lost-device case. Valid longer than a
     * self-issued code (ADMIN_TTL instead of SELF_TTL), because the code still
     * has to be delivered to the affected user.
     *
     * @param array<string, mixed> $user
     */
    private static function adminLinkCode(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $userId = Http::stringField($body, 'userId');
        if ($userId === null || !Users::isValidId($userId) || Users::find($userId) === null) {
            Http::error('unknown_user', 404);
        }

        $result = LinkCodes::create($userId, 'admin', LinkCodes::ADMIN_TTL);
        Http::json(['code' => $result['code'], 'expiresAt' => $result['expiresAt']]);
    }

    /**
     * Sets, replaces or removes the signed-in administrator's OWN password.
     *
     * Scoped to the caller's own account on purpose: an admin session cannot
     * mint a password on somebody else's. That keeps this from being a new
     * escalation — the session already grants /api/admin/link-code for any
     * account, which is the stronger primitive of the two.
     *
     * @param array<string, mixed> $user
     */
    private static function adminPassword(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $id = (string) $user['id'];

        // Removal is an explicit boolean, not an empty password: an empty
        // string arriving from a cleared input must not silently disable the
        // password login.
        if (($body['remove'] ?? null) === true) {
            Users::setPasswordHash($id, null);
            Http::json(['ok' => true, 'passwordSet' => false, 'passwordSetAt' => 0]);
        }

        $password = Http::stringField($body, 'password');
        if ($password === null || !Passwords::isAcceptable($password)) {
            Http::error('invalid_password', 400);
        }

        Users::setPasswordHash($id, Passwords::hash($password));

        $updated = Users::find($id);

        Http::json([
            'ok' => true,
            'passwordSet' => true,
            'passwordSetAt' => $updated === null ? 0 : Passwords::setAt($updated),
        ]);
    }

    /** @param array<string, mixed> $user */
    private static function requireAdmin(array $user): void
    {
        // An admin is whoever is listed in config.php OR whose row carries the
        // is_admin flag (the first registered user, see Users::create()).
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
            'paypalHandle' => Config::paypalHandle(),
            'passwordSet' => Passwords::isSet($user),
            'passwordSetAt' => Passwords::setAt($user),
            'passwordMinLength' => Passwords::MIN_LENGTH,
        ]);
    }

    /**
     * Changes price, invite code and/or the PayPal.me handle at runtime.
     * Deliberately WITHOUT any way to change adminPublicKey or namePepper: the
     * former would make already encrypted names unreadable, the latter would
     * invalidate existing name HMACs, breaking duplicate detection and
     * decryption for existing data.
     *
     * @param array<string, mixed> $user
     */
    private static function adminSettingsUpdate(array $user): never
    {
        self::requireAdmin($user);

        $body = Http::body();
        $hasPrice = array_key_exists('priceCents', $body);
        $hasInvite = array_key_exists('invite', $body);
        $hasPaypal = array_key_exists('paypalHandle', $body);
        if (!$hasPrice && !$hasInvite && !$hasPaypal) {
            Http::error('invalid_settings', 400);
        }

        $pairs = [];
        if ($hasPrice) {
            $pairs['priceCents'] = self::validateSettingsPrice($body['priceCents']);
        }
        if ($hasInvite) {
            $pairs['invite'] = self::validateSettingsInvite($body['invite']);
        }
        if ($hasPaypal) {
            $pairs['paypalHandle'] = self::validateSettingsPaypal($body['paypalHandle']);
        }

        Settings::setMany($pairs);

        Http::json([
            'ok' => true,
            'priceCents' => Config::priceCents(),
            'invite' => Config::invite(),
            'paypalHandle' => Config::paypalHandle(),
        ]);
    }

    /**
     * Validates a new priceCents value, called only when the request body
     * actually included the key. Split out of adminSettingsUpdate() only to
     * keep that method's cognitive complexity within budget; behaviour is
     * unchanged.
     */
    private static function validateSettingsPrice(mixed $priceRaw): string
    {
        if (!is_int($priceRaw) || $priceRaw < 1 || $priceRaw > 100000) {
            Http::error('invalid_settings', 400);
        }

        return (string) $priceRaw;
    }

    /**
     * Validates a new invite code, called only when the request body
     * actually included the key. Split out of adminSettingsUpdate() only to
     * keep that method's cognitive complexity within budget; behaviour is
     * unchanged.
     */
    private static function validateSettingsInvite(mixed $inviteRaw): string
    {
        $invite = is_string($inviteRaw) ? trim($inviteRaw) : '';
        if (mb_strlen($invite) < 4 || mb_strlen($invite) > 64) {
            Http::error('invalid_settings', 400);
        }

        return $invite;
    }

    /**
     * Validates a new PayPal.me handle, called only when the request body
     * actually included the key. Split out of adminSettingsUpdate() only to
     * keep that method's cognitive complexity within budget; behaviour is
     * unchanged.
     */
    private static function validateSettingsPaypal(mixed $paypalRaw): string
    {
        if (!is_string($paypalRaw)) {
            Http::error('invalid_settings', 400);
        }
        // An empty field is how the button is switched off again, so only
        // a non-empty value that does not survive normalization is wrong.
        $handle = Config::normalizePaypalHandle($paypalRaw);
        if (trim($paypalRaw) !== '' && $handle === '') {
            Http::error('invalid_paypal', 400);
        }

        return $handle;
    }

    /**
     * The build the server is running. Public and session-free on purpose: it
     * is shown in the footer, including on the sign-in screen, and it is the
     * one value that must come from the network rather than the cached shell —
     * a shell served from the service-worker cache would report the build the
     * device last downloaded, which is exactly the question being asked.
     */
    private static function version(): never
    {
        Http::json(Version::current());
    }

    // -------------------------------------------------------------- Setup ---

    /**
     * A fresh deployment no longer needs a hand-edited config.php: with no
     * configured adminPublicKey and no users, the interface asks for the setup
     * wizard instead of registration/login.
     */
    private static function needsSetup(): bool
    {
        return Config::adminPublicKey() === '' && Users::count() === 0;
    }

    private static function setupStatus(): never
    {
        $needsSetup = self::needsSetup();
        // Generating the token here (not only in setupInit) means it is in the
        // log and on disk by the time the operator has the wizard on screen.
        // An attacker triggering this generates a secret they cannot read.
        $tokenAvailable = $needsSetup ? SetupToken::ensure() !== '' : false;

        Http::json([
            'needsSetup' => $needsSetup,
            // Neither the token nor where it lives is served: being able to
            // read it off the deployment is the whole proof it stands for,
            // and an absolute path would hand an unauthenticated caller the
            // server's directory layout for nothing. The log line written on
            // generation carries the exact path for the operator.
            'setupTokenReady' => $tokenAvailable,
            'priceCents' => Config::priceCents(),
        ]);
    }

    /**
     * One-time completion of the setup: the public admin key is generated
     * locally in the browser (the private half never leaves it) and stored here
     * together with price and invite code. The first user registered afterwards
     * automatically becomes admin (see Users::create()).
     */
    private static function setupInit(): never
    {
        RateLimit::enforce('register', RateLimit::REGISTER_MAX);

        if (!self::needsSetup()) {
            Http::error('already_initialized', 409);
        }

        $body = Http::body();

        // Before every other check: this endpoint decides the RSA key that all
        // names are sealed to and cannot be re-keyed afterwards, so whoever
        // gets here first would own the installation. The token proves
        // filesystem access to the deployment, which a remote caller has not.
        if (SetupToken::ensure() === '') {
            // Neither readable nor writable -- refuse rather than wave setup
            // through, and name the file so the operator can create it.
            error_log('[coffee] setup blocked: cannot read or create ' . SetupToken::path());
            Http::error('setup_token_unavailable', 500);
        }
        $givenToken = Http::stringField($body, 'setupToken');
        if ($givenToken === null || !SetupToken::verify(trim($givenToken))) {
            RateLimit::enforce('setup_token', RateLimit::SETUP_TOKEN_MAX);
            Http::error('invalid_setup_token', 403);
        }

        $publicKey = Http::stringField($body, 'adminPublicKey');
        if ($publicKey === null || $publicKey === '') {
            Http::error('invalid_key', 400);
        }
        try {
            // Validates the key alone. It deliberately does not construct a
            // Crypto instance: no pepper exists yet at this point, and a
            // throwaway one would now be rejected by the pepper check.
            Crypto::assertValidPublicKey($publicKey);
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

        // Small, deliberately accepted race window: two concurrent first
        // requests could both reach this point before either has written its
        // settings. The wizard runs exactly once on the very first deployment,
        // not under load – a real lock is not worth the effort here.
        // @phpstan-ignore booleanNot.alwaysFalse (deliberate re-check: state can change between the guard at the top and here)
        if (!self::needsSetup()) {
            Http::error('already_initialized', 409);
        }

        $pairs = [
            'adminPublicKey' => $publicKey,
            'priceCents' => (string) $priceRaw,
            'invite' => $invite,
        ];
        if (Config::namePepper() === '') {
            // Never overwrite an existing pepper – it would invalidate every
            // existing name HMAC.
            $pairs['namePepper'] = bin2hex(random_bytes(32));
        }

        Settings::setMany($pairs);
        // needsSetup() closes the wizard from here on, so the file is now just
        // a secret lying around in a web-served tree. Take it away.
        SetupToken::clear();

        Http::json(['ok' => true]);
    }

    // ------------------------------------------------------- Test control ---

    private static function dispatchTest(string $path, string $method): never
    {
        $token = Config::testToken();
        $given = Http::header('X-Test-Token');
        // Without testMode, or with a wrong token, these endpoints do not exist.
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
            foreach (['sessions', 'credentials', 'ceremonies', 'link_codes', 'coffee_events', 'users'] as $table) {
                if (Db::tableExists($pdo, $table)) {
                    $pdo->exec('DELETE FROM ' . $table);
                }
            }
            // sqlite_sequence exists under SQLite only.
            if (!$mysql && Db::tableExists($pdo, 'sqlite_sequence')) {
                $pdo->exec('DELETE FROM sqlite_sequence');
            }
        });

        if ($mysql) {
            // ALTER TABLE commits implicitly – hence only after the transaction
            // has finished and outside of it, otherwise it would tear the
            // transaction apart midway.
            $pdo = Db::pdo();
            foreach (['users', 'credentials', 'ceremonies', 'link_codes', 'coffee_events'] as $table) {
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

            // Exactly the same path as a real registration takes.
            $nameHash = $crypto->nameHash($first, $last);
            if (Users::idForNameHash($nameHash) !== null) {
                Http::error('name_taken', 409);
            }
            $user = Users::create(
                self::sealName($crypto, $first, $last),
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

    /** Signs in a test user without a WebAuthn ceremony – reachable only with the test token. */
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

    // ------------------------------------------------------------ Helpers ---

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
        // A bare PublicKeyCredential as the body is accepted as well.
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
        // Opaque label: a cleartext name must never end up in the options.
        return 'coffee-' . substr(preg_replace('/[^A-Za-z0-9]/', '', $handle) ?? '', 0, 10);
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
            'credentials' => Credentials::countForUser($id),
            'undoableSeconds' => self::undoableSeconds($user),
            // Everyone with a tab needs the handle to settle it; it is the
            // admin's public payment address, not a secret.
            'paypalHandle' => Config::paypalHandle(),
        ];
    }

    // ----------------------------------------------------------- Frontend ---

    /** Application routes that serve the HTML shell. */
    private const APP_PATHS = ['/', '/app', '/admin', '/login'];

    private static function serveFrontend(string $path): never
    {
        // Only known application routes serve the interface. Everything else
        // does not exist – this controller never reads a file from the file
        // system and therefore cannot serve one either.
        if (!in_array($path, self::APP_PATHS, true)) {
            Http::error('not_found', 404);
        }

        Http::html(Frontend::shell());
    }
}
