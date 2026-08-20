<?php

declare(strict_types=1);

namespace Coffee;

use Symfony\Component\Serializer\SerializerInterface;
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
 * Verdrahtung von web-auth/webauthn-lib.
 *
 * Registrierung: PublicKeyCredentialCreationOptions →
 * AuthenticatorAttestationResponseValidator.
 * Anmeldung: PublicKeyCredentialRequestOptions →
 * AuthenticatorAssertionResponseValidator.
 * `rpId` und `origin` kommen ausschliesslich aus der Konfiguration.
 */
final class WebAuthnService
{
    /** Challenge-Länge in Bytes. */
    private const CHALLENGE_BYTES = 32;

    private static ?SerializerInterface $serializer = null;

    public static function serializer(): SerializerInterface
    {
        if (self::$serializer === null) {
            $support = new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
            self::$serializer = (new WebauthnSerializerFactory($support))->create();
        }

        return self::$serializer;
    }

    private static function ceremonyFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory();
        // Nur genau die konfigurierte Origin ist zulässig.
        $factory->setAllowedOrigins([Config::origin()]);

        return $factory;
    }

    public static function challenge(): string
    {
        return random_bytes(self::CHALLENGE_BYTES);
    }

    /**
     * Optionen für eine Registrierung: auffindbarer Passkey (Resident Key) mit
     * zwingender Nutzerverifikation.
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
            // In der Entität steht bewusst kein Klarname: die Optionen gehen an
            // den Browser und dürfen keinen Namen preisgeben.
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
     * Optionen für die Anmeldung ohne Benutzernamen: keine allowCredentials,
     * Nutzerverifikation zwingend.
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
     * Wandelt das rohe Credential-JSON des Browsers in ein Objekt der
     * Bibliothek.
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
     * Liest die Challenge aus dem clientDataJSON des Credentials. Beim Verify
     * kommt nur das nackte Credential an – die Challenge ist der einzige Bezug
     * zur anstehenden Ceremonie.
     *
     * @param array<mixed> $raw
     */
    public static function challengeFromCredential(array $raw): ?string
    {
        $clientData = $raw['response']['clientDataJSON'] ?? null;
        if (!is_string($clientData) || $clientData === '') {
            return null;
        }
        $decoded = Encoding::base64UrlDecode($clientData);
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

            // userHandle bleibt null: die Anmeldung ist namenlos, der Benutzer
            // wird über die Credential-ID gefunden. Die Bibliothek prüft dann,
            // dass das Handle aus der Assertion zum gespeicherten passt.
            return $validator->check($record, $response, $options, Config::rpId(), null);
        } catch (Throwable $e) {
            error_log('[coffee] assertion rejected: ' . $e->getMessage());

            return null;
        }
    }

}
