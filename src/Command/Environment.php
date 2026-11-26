<?php

declare(strict_types=1);

namespace Rustine\Command;

/**
 * Options that fall back to an environment variable, then to a default. Where a value from the configuration file can
 * exist, it is passed apart: it wins over the variable, not over the option.
 */
final class Environment
{
    /**
     * A switch: set by its option, else the file (`false` there turns it off), or by a variable holding `1`, `true`,
     * `yes` or `on`.
     */
    public static function flag(bool $option, string $variable, ?bool $file = null): bool
    {
        if ($option) {
            return true;
        }

        if (null !== $file) {
            return $file;
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

    /**
     * Like {@see string()}, for a value that has no default: null when neither the option nor the variable is set.
     */
    public static function optionalString(?string $option, string $variable): ?string
    {
        $value = self::string($option, $variable, '');

        return '' === $value ? null : $value;
    }

    /**
     * A repeatable option: its values, else the values of the variable split on `$separator` (a regular expression
     * without delimiters; commas by default). Blanks and empty items are dropped. The list of the file, even empty,
     * wins over the variable.
     *
     * @param list<string>      $option
     * @param list<string>|null $file
     *
     * @return list<string>
     */
    public static function list(
        array $option,
        string $variable,
        string $separator = ',',
        ?array $file = null,
    ): array {
        if ([] !== $option) {
            return $option;
        }

        if (null !== $file) {
            return $file;
        }

        $value = getenv($variable);
        if (false === $value) {
            return [];
        }

        $items = preg_split('/' . $separator . '/', $value, -1, \PREG_SPLIT_NO_EMPTY);

        return array_values(
            array_filter(
                array_map(trim(...), false === $items ? [] : $items),
                static fn(string $item): bool => '' !== $item,
            ),
        );
    }

    /**
     * @throws InvalidOptionException when the variable does not hold a non-negative integer
     */
    public static function int(?int $option, string $variable, ?int $default): ?int
    {
        if (null !== $option) {
            return $option;
        }

        $value = getenv($variable);
        if (false === $value || '' === trim($value)) {
            return $default;
        }

        if (1 !== preg_match('/^\d+$/', trim($value))) {
            throw new InvalidOptionException(
                sprintf('The variable %s must hold a non-negative integer, "%s" given.', $variable, $value),
            );
        }

        return (int) trim($value);
    }
}
