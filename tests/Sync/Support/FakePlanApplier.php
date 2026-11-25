<?php

declare(strict_types=1);

namespace Rustine\Tests\Sync\Support;

use Rustine\Apply\AppliedPlan;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\LockChange;
use Rustine\Apply\PlanApplier;
use Rustine\Plan\GroupPlan;
use Rustine\Trivy\TrivyReport;

/**
 * "Applies" a plan by writing a given content to yarn.lock, and reports every fix as applied (or failed).
 */
final class FakePlanApplier implements PlanApplier
{
    public int $calls = 0;

    public ?string $finalCheckError = null;

    public bool $succeed = true;

    public ?\Throwable $throw = null;

    /** @var list<LockChange> */
    public array $lockChanges = [];

    public function __construct(private readonly string $projectDir, public string $lock = "lock v2\n")
    {
    }

    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan
    {
        ++$this->calls;
        if ($this->throw instanceof \Throwable) {
            file_put_contents($this->projectDir . '/yarn.lock', "half-applied\n");

            throw $this->throw;
        }

        $outcomes = [];

        foreach ($plan->fixes as $fix) {
            $outcomes[] = !$fix->isFixable()
                ? new FixOutcome($fix, FixOutcomeStatus::Skipped)
                : ($this->succeed ? new FixOutcome(
                    $fix,
                    FixOutcomeStatus::Applied,
                    AttemptKind::DirectBump,
                ) : new FixOutcome($fix, FixOutcomeStatus::Failed, null, ['nothing worked']));
        }

        if ($this->succeed) {
            file_put_contents($this->projectDir . '/yarn.lock', $this->lock);
        }

        return new AppliedPlan(new ApplyResult($outcomes, $this->finalCheckError, $this->lockChanges));
    }
}
