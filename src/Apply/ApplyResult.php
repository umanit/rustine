<?php

declare(strict_types=1);

namespace Rustine\Apply;

final readonly class ApplyResult
{
    /**
     * @param list<FixOutcome>            $outcomes
     * @param string|null                 $finalCheckError       null when the final frozen-lockfile install passed
     * @param list<LockChange>            $lockChanges           what the fixes changed in the lockfile, collateral
     *                                                           changes included
     * @param VerifyMode                  $verify                when Trivy confirmed the fixes
     * @param string|null                 $existingCheckFindings what the final check reported, when it reported
     *                                                           nothing it had not already reported before the fixes
     *                                                           (null otherwise)
     * @param array<string, list<string>> $yarnLinks             `name (spec)` entries of `package.json` that point
     *                                    into a Composer package, by lowercase package name
     */
    public function __construct(
        public array $outcomes,
        public ?string $finalCheckError = null,
        public array $lockChanges = [],
        public VerifyMode $verify = VerifyMode::Final,
        public ?string $existingCheckFindings = null,
        public array $yarnLinks = [],
    ) {
    }

    /**
     * @param array<string, list<string>> $yarnLinks
     */
    public function withYarnLinks(array $yarnLinks): self
    {
        return new self(
            $this->outcomes,
            $this->finalCheckError,
            $this->lockChanges,
            $this->verify,
            $this->existingCheckFindings,
            $yarnLinks,
        );
    }

    /**
     * Yarn entries of `package.json` that point into a Composer package this run changed: `yarn.lock` is stale.
     *
     * @return list<string>
     */
    public function staleYarnSources(FixOutcome $outcome): array
    {
        return FixOutcomeStatus::Applied === $outcome->status
            ? ($this->yarnLinks[strtolower($outcome->fix->packageName)] ?? [])
            : [];
    }

    public function hasChanges(): bool
    {
        return array_any(
            $this->outcomes,
            static fn(FixOutcome $outcome): bool => FixOutcomeStatus::Applied === $outcome->status,
        );
    }

    public function isHealthy(): bool
    {
        if (null !== $this->finalCheckError) {
            return false;
        }

        return array_all(
            $this->outcomes,
            static fn(FixOutcome $outcome): bool => FixOutcomeStatus::Failed !== $outcome->status,
        );
    }
}
