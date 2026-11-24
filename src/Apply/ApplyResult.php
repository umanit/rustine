<?php

declare(strict_types=1);

namespace Rustine\Apply;

final readonly class ApplyResult
{
    /**
     * @param list<FixOutcome> $outcomes
     * @param string|null      $finalCheckError null when the final frozen-lockfile install passed
     */
    public function __construct(
        public array $outcomes,
        public ?string $finalCheckError = null,
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
