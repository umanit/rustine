<?php

declare(strict_types=1);

namespace Rustine\Trivy;

enum Relationship: string
{
    case Root = 'root';
    case Direct = 'direct';
    case Indirect = 'indirect';
    case Unknown = 'unknown';

    public static function fromTrivy(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Unknown;
    }
}
