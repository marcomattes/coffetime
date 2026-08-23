<?php

declare(strict_types=1);

namespace Coffee;

use Symfony\Component\Serializer\Serializer;
use Throwable;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;

/**
 * Wiring for web-auth/webauthn-lib.
 *
 * Registration: PublicKeyCredentialCreationOptions →
 * AuthenticatorAttestationResponseValidator.
 * Login: PublicKeyCredentialRequestOptions →
 * AuthenticatorAssertionResponseValidator.
 * `rpId` and `origin` come exclusively from configuration.
 */
final class WebAuthnService
{
    /** Challenge length in bytes. */
    private const CHALLENGE_BYTES = 32;

    private static ?Serializer $serializer = null;

    /**
     * The factory declares SerializerInterface, but callers also need the
     * (de)normalization side, so pin the concrete Symfony Serializer.
     */
    public static function serializer(): Serializer
    {
        if (self::$serializer === null) {
            $support = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
            $created = (new WebauthnSerializerFactory($support))->create();
            if (!$created instanceof Serializer) {
                throw new WebAuthnException('Unexpected serializer implementation from webauthn-lib');
            }
            self::$serializer = $created;
        }

        return self::$serializer;
    }

    private static function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        // Only exactly the configured origin is permitted.
        $factory->setAllowedOrigins([Config::origin()]);

        return $factory;
    }

    public static function challenge(): string
    {
        return random_bytes(self::CHALLENGE_BYTES);
    }

    /**
     * Options for a registration: discoverable passkey (resident key) with
     * mandatory user verification.
     *
     * @param list<PublicKeyCredentialDescriptor> $excludeCredentials
     */
    public static function creationOptions(
        string $userHandle,
        string $userLabel,
        array $excludeCredentials = []
    ): PublicKeyCredentialCreationOptions {
        return PublicKeyCredentialCreationOptions::create(
            PublicKeyCredentialRpEntity::create('Coffee Time', Config::rpId()),
            // The entity deliberately carries no real name: these options
            // go to the browser and must not expose a name.
            PublicKeyCredentialUserEntity::create($userLabel, $userHandle, $userLabel),
            self::challenge(),
            [
                PublicKeyCredentialParameters::createPk(-7),
                PublicKeyCredentialParameters::createPk(-257),
            ],
            AuthenticatorSelectionCriteria::create(
                null,
                AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
                AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_REQUIRED
            ),
            PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
            $excludeCredentials,
            120000
        );
    }

    /**
     * Options for username-less login: no allowCredentials, mandatory user
     * verification.
     */
    public static function requestOptions(): PublicKeyCredentialRequestOptions
    {
        return PublicKeyCredentialRequestOptions::create(
            self::challenge(),
            Config::rpId(),
            [],
            PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            120000
        );
    }

    /** @return array<string, mixed> */
    public static function optionsToArray(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options
    ): array {
        $decoded = json_decode(self::optionsToJson($options), true);

        return is_array($decoded) ? $decoded : [];
    }

    public static function optionsToJson(
        PublicKeyCredentialCreationOptions|PublicKeyCredentialRequestOptions $options
    ): string {
        return self::serializer()->serialize($options, 'json');
    }

    public static function creationOptionsFromJson(string $json): ?PublicKeyCredentialCreationOptions
    {
        try {
            $options = self::serializer()->deserialize($json, PublicKeyCredentialCreationOptions::class, 'json');

            return $options instanceof PublicKeyCredentialCreationOptions ? $options : null;
        } catch (Throwable $e) {
            error_log('[coffee] creation options restore failed: ' . $e->getMessage());

            return null;
        }
    }

    public static function requestOptionsFromJson(string $json): ?PublicKeyCredentialRequestOptions
    {
        try {
            $options = self::serializer()->deserialize($json, PublicKeyCredentialRequestOptions::class, 'json');

            return $options instanceof PublicKeyCredentialRequestOptions ? $options : null;
        } catch (Throwable $e) {
            error_log('[coffee] request options restore failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Converts the browser's raw credential JSON into a library object.
     *
     * @param array<mixed> $raw
     */
    public static function parseCredential(array $raw): ?PublicKeyCredential
    {
        try {
            $json = json_encode($raw, JSON_THROW_ON_ERROR);
            $credential = self::serializer()->deserialize($json, PublicKeyCredential::class, 'json');

            return $credential instanceof PublicKeyCredential ? $credential : null;
        } catch (Throwable $e) {
            error_log('[coffee] credential parse failed: ' . $e->getMessage());

            return null;
        }
    }

    /**
     * Reads the challenge from the credential's clientDataJSON. At verify
     * time only the bare credential arrives — the challenge is the sole
     * link back to the pending ceremony.
     *
     * @param array<mixed> $raw
     */
    public static function challengeFromCredential(array $raw): ?string
    {
        $clientData = $raw['response']['clientDataJSON'] ?? null;
        $decoded = is_string($clientData) && $clientData !== ''
            ? Encoding::base64UrlDecode($clientData)
            : null;
        if ($decoded === null) {
            return null;
        }
        $data = json_decode($decoded, true);
        if (!is_array($data) || !isset($data['challenge']) || !is_string($data['challenge'])) {
            return null;
        }

        return $data['challenge'];
    }

    public static function verifyAttestation(
        PublicKeyCredential $credential,
        PublicKeyCredentialCreationOptions $options
    ): ?CredentialRecord {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAttestationResponse) {
            return null;
        }
        try {
            $validator = AuthenticatorAttestationResponseValidator::create(
                self::ceremonyFactory()->creationCeremony()
            );

            return $validator->check($response, $options, Config::rpId());
        } catch (Throwable $e) {
            error_log('[coffee] attestation rejected: ' . $e->getMessage());

            return null;
        }
    }

    public static function verifyAssertion(
        PublicKeyCredential $credential,
        CredentialRecord $record,
        PublicKeyCredentialRequestOptions $options
    ): ?CredentialRecord {
        $response = $credential->response;
        if (!$response instanceof AuthenticatorAssertionResponse) {
            return null;
        }
        try {
            $validator = AuthenticatorAssertionResponseValidator::create(
                self::ceremonyFactory()->requestCeremony()
            );

            // userHandle stays null: login is username-less, the user is
            // found via the credential ID. The library then verifies that
            // the handle from the assertion matches the stored one.
            return $validator->check($record, $response, $options, Config::rpId(), null);
        } catch (Throwable $e) {
            error_log('[coffee] assertion rejected: ' . $e->getMessage());

            return null;
        }
    }

}
