<?php

declare(strict_types=1);

namespace Rustine\Command;

/**
 * Options that fall back to an environment variable, then to a default.
 */
final class Environment
{
    public static function string(?string $option, string $variable, string $default): string
    {
        if (null !== $option && '' !== $option) {
            return $option;
        }

        $value = getenv($variable);

        return false === $value || '' === $value ? $default : $value;
    }
}
