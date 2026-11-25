<?php

declare(strict_types=1);

namespace Rustine\Apply;

final readonly class ApplyResult
{
    /**
     * @param list<FixOutcome> $outcomes
     * @param string|null      $finalCheckError null when the final frozen-lockfile install passed
     * @param list<LockChange> $lockChanges     what the fixes changed in the lockfile, collateral changes included
     * @param VerifyMode       $verify          when Trivy confirmed the fixes
     * @param string|null      $existingCheckFindings what the final check reported, when it reported nothing it had not
     *                                                already reported before the fixes (null otherwise)
     */
    public function __construct(
        public array $outcomes,
        public ?string $finalCheckError = null,
        public array $lockChanges = [],
        public VerifyMode $verify = VerifyMode::Final,
        public ?string $existingCheckFindings = null,
    ) {
    }

    public function hasChanges(): bool
    {
        foreach ($this->outcomes as $outcome) {
            if (FixOutcomeStatus::Applied === $outcome->status) {
                return true;
            }
        }

        return false;
    }

    public function isHealthy(): bool
    {
        if (null !== $this->finalCheckError) {
            return false;
        }
        foreach ($this->outcomes as $outcome) {
            if (FixOutcomeStatus::Failed === $outcome->status) {
                return false;
            }
        }

        return true;
    }
}
