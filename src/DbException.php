<?php

declare(strict_types=1);

namespace Coffee;

use RuntimeException;

/**
 * The database layer could not do its job for a reason that is not a failed
 * query — a missing data directory, an unusable storage path. Extends
 * RuntimeException so any existing `catch (\RuntimeException)` keeps working
 * unchanged; this exists to give Db's own failures a name more specific than
 * the generic base.
 */
final class DbException extends RuntimeException
{
}
