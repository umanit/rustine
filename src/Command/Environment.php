<?php

declare(strict_types=1);

namespace Rustine\Command;

/**
 * Options that fall back to an environment variable, then to a default.
 */
final class Environment
{
    /**
     * A switch: set by its option, or by a variable holding `1`, `true`, `yes` or `on`.
     */
    public static function flag(bool $option, string $variable): bool
    {
        if ($option) {
            return true;
        }

        $value = getenv($variable);

        return false !== $value && \in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function string(?string $option, string $variable, string $default): string
    {
        if (null !== $option && '' !== $option) {
            return $option;
        }

        $value = getenv($variable);

        return false === $value || '' === $value ? $default : $value;
    }
}
