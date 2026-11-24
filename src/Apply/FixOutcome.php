<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;

final readonly class FixOutcome
{
    /**
     * @param list<string> $log           one line per attempt, for diagnostics and MR descriptions
     * @param bool         $stillReported true when the final Trivy run still reports the CVEs of an applied fix
     */
    public function __construct(
        public PackageFix $fix,
        public FixOutcomeStatus $status,
        public ?AttemptKind $strategy = null,
        public array $log = [],
        public bool $stillReported = false,
    ) {
    }
}
