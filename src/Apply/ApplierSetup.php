<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Pinning\ToolPinning;

/**
 * Strategy and tool pinning chosen for the ecosystem of a plan.
 */
final readonly class ApplierSetup
{
    public function __construct(
        public FixStrategy $strategy,
        public ?ToolPinning $pinning,
    ) {
    }
}
