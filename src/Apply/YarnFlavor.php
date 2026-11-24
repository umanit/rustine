<?php

declare(strict_types=1);

namespace Rustine\Apply;

enum YarnFlavor: string
{
    case Classic = 'classic';
    case Berry = 'berry';

    /**
     * Berry lockfiles start with a `__metadata` entry; Classic ones (`# yarn lockfile v1`) have none.
     */
    public static function ofLockfile(string $content): self
    {
        return 1 === preg_match('/^__metadata:/m', $content) ? self::Berry : self::Classic;
    }

    /**
     * @return self|null null when the version cannot be read
     */
    public static function ofVersion(string $version): ?self
    {
        if (1 !== preg_match('/^v?(\d+)\./', $version, $m)) {
            return null;
        }

        return 1 === (int) $m[1] ? self::Classic : self::Berry;
    }
}
