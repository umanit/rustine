<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Pinning\ToolPinning;

/**
 * What applying a plan produced, and the tools that were used (for the merge request description).
 */
final readonly class AppliedPlan
{
    public function __construct(
        public ApplyResult $result,
        public ?ToolPinning $pinning = null,
    ) {
    }
}
