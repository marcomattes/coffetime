<?php

declare(strict_types=1);

namespace Coffee;

use RuntimeException;

/**
 * A cryptographic operation that should have succeeded did not — e.g. OpenSSL
 * refusing to seal an otherwise-valid payload. Extends RuntimeException so any
 * existing `catch (\RuntimeException)` keeps working unchanged; this exists to
 * give Crypto's own failures a name more specific than the generic base.
 */
final class CryptoException extends RuntimeException
{
}
