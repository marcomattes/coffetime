<?php

declare(strict_types=1);

namespace Coffee;

/**
 * Which build is running.
 *
 * The deployed tree has no `.git` — it is an FTP mirror of the release
 * bundle — so `scripts/build-release.sh` writes `src/build.json` into that
 * bundle and this class reads it back. Running straight from a checkout
 * there is no such file, and the commit is read from `.git` instead, so a
 * developer sees a real hash too rather than a placeholder.
 *
 * The value is public on purpose: it is shown in the footer so anyone can
 * tell at a glance what is deployed. This repository is public, so the
 * commit hash discloses nothing that a `git log` would not.
 */
final class Version
{
    /** Neither a release bundle nor a checkout — nothing to report. */
    public const UNKNOWN = 'dev';

    /** @var array{version: string, builtAt: int}|null */
    private static ?array $cache = null;

    /** @return array{version: string, builtAt: int} */
    public static function current(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        return self::$cache = self::fromBundle()
            ?? self::fromGit()
            ?? ['version' => self::UNKNOWN, 'builtAt' => 0];
    }

    /** Discards the per-request cache (tests only). */
    public static function forget(): void
    {
        self::$cache = null;
    }

    /** @return array{version: string, builtAt: int}|null */
    private static function fromBundle(): ?array
    {
        $raw = @file_get_contents(__DIR__ . '/build.json');
        $data = is_string($raw) && trim($raw) !== '' ? json_decode($raw, true) : null;
        if (!is_array($data)) {
            return null;
        }

        $version = self::shorten($data['version'] ?? null);
        if ($version === null) {
            return null;
        }
        $builtAt = isset($data['builtAt']) && is_numeric($data['builtAt']) ? (int) $data['builtAt'] : 0;

        return ['version' => $version, 'builtAt' => $builtAt];
    }

    /**
     * Reads HEAD out of `.git` directly instead of shelling out to git: the
     * web user usually has no shell, and `exec()` is often disabled outright
     * on shared hosting.
     *
     * @return array{version: string, builtAt: int}|null
     */
    /** @return array{version: string, builtAt: int}|null */
    private static function fromGit(): ?array
    {
        $gitDir = dirname(__DIR__) . '/.git';
        $head = self::readHead($gitDir);
        if ($head === null) {
            return null;
        }

        // Detached HEAD holds the commit itself; otherwise it points at a ref.
        if (!str_starts_with($head, 'ref: ')) {
            $version = self::shorten($head);

            return $version === null ? null : ['version' => $version, 'builtAt' => self::mtime($gitDir . '/HEAD')];
        }

        $ref = self::parseRef($head);

        return $ref === null ? null : self::versionFromRef($gitDir, $ref);
    }

    private static function readHead(string $gitDir): ?string
    {
        $head = @file_get_contents($gitDir . '/HEAD');

        return is_string($head) ? trim($head) : null;
    }

    /** Validates and extracts the ref path from a "ref: refs/..." HEAD line. */
    private static function parseRef(string $head): ?string
    {
        $ref = trim(substr($head, 5));
        // The file is ours, but a ref is still a path: keep it inside .git.
        if (preg_match('#^refs/[A-Za-z0-9._/-]+$#', $ref) !== 1 || str_contains($ref, '..')) {
            return null;
        }

        return $ref;
    }

    /**
     * Resolves a ref to a commit, trying the loose ref file first and
     * falling back to a scan of packed-refs for a ref that has been packed
     * away.
     *
     * @return array{version: string, builtAt: int}|null
     */
    private static function versionFromRef(string $gitDir, string $ref): ?array
    {
        $loose = $gitDir . '/' . $ref;
        $version = self::shorten(@file_get_contents($loose));
        if ($version !== null) {
            return ['version' => $version, 'builtAt' => self::mtime($loose)];
        }

        // A ref that has been packed away has no file of its own.
        $packed = @file_get_contents($gitDir . '/packed-refs');
        if (!is_string($packed)) {
            return null;
        }

        $commit = self::findPackedRef($packed, $ref);

        return $commit === null ? null : ['version' => $commit, 'builtAt' => self::mtime($gitDir . '/packed-refs')];
    }

    /** Scans packed-refs text for the commit a ref was packed with. */
    private static function findPackedRef(string $packed, string $ref): ?string
    {
        foreach (explode("\n", $packed) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            $parts = preg_split('/\s+/', $line, 2);
            if (is_array($parts) && count($parts) === 2 && $parts[1] === $ref) {
                $version = self::shorten($parts[0]);
                if ($version !== null) {
                    return $version;
                }
            }
        }

        return null;
    }

    /** A full commit hash cut to the short form, or null if it is not one. */
    private static function shorten(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $value = trim($raw);
        if (preg_match('/^[0-9a-f]{7,40}$/i', $value) !== 1) {
            return null;
        }

        return strtolower(substr($value, 0, 7));
    }

    private static function mtime(string $path): int
    {
        $stamp = @filemtime($path);

        return is_int($stamp) ? $stamp : 0;
    }
}
