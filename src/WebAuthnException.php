<?php

declare(strict_types=1);

namespace Coffee;

use RuntimeException;

/**
 * Something the WebAuthn wiring assumes about web-auth/webauthn-lib did not
 * hold — e.g. the library returning a serializer implementation this class
 * does not know how to use. Extends RuntimeException so any existing
 * `catch (\RuntimeException)` keeps working unchanged; this exists to give
 * WebAuthnService's own failures a name more specific than the generic base.
 */
final class WebAuthnException extends RuntimeException
{
}
