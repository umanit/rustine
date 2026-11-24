<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * What applying a plan produced, and the versions of the tools of the environment that were used (for the merge
 * request description).
 */
final readonly class AppliedPlan
{
    public function __construct(
        public ApplyResult $result,
        public ?ToolVersions $tools = null,
    ) {
    }
}
