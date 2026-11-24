<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;

interface FixStrategy
{
    /**
     * Ways of fixing the package, cheapest first. Empty when the package is not fixable.
     *
     * @return list<Attempt>
     */
    public function attempts(PackageFix $fix): array;

    /**
     * Environment of the commands that rewrite the lockfile.
     *
     * @return array<string, string>
     */
    public function mutatingEnv(): array;

    /**
     * Final check: the lockfile must be consistent with the manifest without being modified.
     *
     * @return list<string>
     */
    public function checkCommand(): array;

    /**
     * @return array<string, string>
     */
    public function checkEnv(): array;
}
