<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\VerifyMode;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;

/**
 * Parsing of the options shared by several commands. Each method throws an InvalidOptionException with the message
 * to show, and the command decides the exit code.
 */
final class Options
{
    /**
     * @param list<string> $names
     *
     * @return list<SeverityGroup>
     */
    public static function groups(array $names): array
    {
        return array_map(
            static fn(string $name): SeverityGroup => SeverityGroup::tryFrom($name) ?? throw new InvalidOptionException(
                sprintf(
                    'Unknown group "%s" (expected: %s).',
                    $name,
                    implode(', ', array_column(SeverityGroup::cases(), 'value')),
                ),
            ),
            $names,
        );
    }

    /**
     * @param list<string> $names
     *
     * @return list<SeverityGroup> a single group
     */
    public static function exactlyOneGroup(array $names): array
    {
        $groups = self::groups($names);

        if (1 !== \count($groups)) {
            throw new InvalidOptionException(
                'Pass exactly one --group: each severity group is a separate merge request.',
            );
        }

        return $groups;
    }

    public static function verifyMode(?string $option): VerifyMode
    {
        $value = Environment::string($option, 'RUSTINE_VERIFY', VerifyMode::Final->value);

        return VerifyMode::tryFrom($value) ?? throw new InvalidOptionException(
            sprintf(
                'Unknown verification "%s" (expected: %s).',
                $value,
                implode(', ', array_column(VerifyMode::cases(), 'value')),
            ),
        );
    }

    public static function ecosystem(?string $option): ?Ecosystem
    {
        if (null === $option) {
            return null;
        }

        return Ecosystem::tryFrom($option) ?? throw new InvalidOptionException(
            sprintf('Unknown ecosystem "%s" (expected: yarn, composer).', $option),
        );
    }
}
