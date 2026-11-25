<?php

declare(strict_types=1);

namespace Rustine\Trivy;

enum Severity: string
{
    case Unknown = 'UNKNOWN';
    case Low = 'LOW';
    case Medium = 'MEDIUM';
    case High = 'HIGH';
    case Critical = 'CRITICAL';

    public static function fromTrivy(?string $value): self
    {
        return self::tryFrom(strtoupper($value ?? '')) ?? self::Unknown;
    }

    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    public function rank(): int
    {
        return match ($this) {
            self::Unknown => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::Critical => 4,
        };
    }

    public function isAtLeast(self $other): bool
    {
        return $this->rank() >= $other->rank();
    }
}
