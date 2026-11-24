<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\GroupPlan;
use Rustine\Trivy\TrivyReport;

/**
 * Applies the fixes of a plan to the working tree. An interface so that the synchronization can be tested without
 * running Yarn, Composer or Trivy.
 */
interface PlanApplier
{
    /**
     * @throws UnsupportedProjectException when the project cannot be handled safely
     * @throws ToolingException            when a tool the ecosystem needs is missing or does not match the project
     */
    public function apply(GroupPlan $plan, TrivyReport $report): AppliedPlan;
}
