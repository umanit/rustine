<?php

declare(strict_types=1);

namespace Rustine\Trivy;

enum Ecosystem: string
{
    case Composer = 'composer';
    case Yarn = 'yarn';

    /** Returns null for unsupported Trivy result types (npm, pnpm, os-pkgs, etc.). */
    public static function fromTrivyType(string $type): ?self
    {
        return self::tryFrom($type);
    }

    public function lockFile(): string
    {
        return match ($this) {
            self::Composer => 'composer.lock',
            self::Yarn => 'yarn.lock',
        };
    }
}
