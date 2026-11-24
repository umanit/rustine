<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * What a run changed for one package in the lockfile. A package may be installed in several versions at once, hence the
 * lists: an empty `$before` is a package that was added, an empty `$after` one that was removed.
 */
final readonly class LockChange
{
    /**
     * @param list<string> $before versions in the lockfile before the fixes (only those that disappeared)
     * @param list<string> $after  versions in the lockfile after the fixes (only those that appeared)
     */
    public function __construct(
        public string $package,
        public array $before,
        public array $after,
    ) {
    }
}
