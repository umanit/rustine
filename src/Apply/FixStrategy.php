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
}
