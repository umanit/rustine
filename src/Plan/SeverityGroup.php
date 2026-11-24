<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Severity;

enum SeverityGroup: string
{
    case CriticalHigh = 'critical-high';
    case Others = 'others';

    public static function forSeverity(Severity $severity): self
    {
        return $severity->isAtLeast(Severity::High) ? self::CriticalHigh : self::Others;
    }
}
