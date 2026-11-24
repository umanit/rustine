<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;

final readonly class FixOutcome
{
    /**
     * @param list<string> $log one line per attempt, for diagnostics and MR descriptions
     */
    public function __construct(
        public PackageFix $fix,
        public FixOutcomeStatus $status,
        public ?AttemptKind $strategy = null,
        public array $log = [],
    ) {
    }
}
