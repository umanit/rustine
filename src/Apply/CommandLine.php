<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * A command given as one string in an option or an environment variable.
 */
final class CommandLine
{
    /**
     * @param string $commandLine words separated by spaces (no quoting: paths with spaces are not supported)
     * @param string $tool        tool name, for the error message
     *
     * @return non-empty-list<string>
     *
     * @throws \InvalidArgumentException when empty
     */
    public static function split(string $commandLine, string $tool): array
    {
        $words = preg_split('/\s+/', trim($commandLine), -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        if ([] === $words) {
            throw new \InvalidArgumentException(sprintf('The %s command is empty.', $tool));
        }

        return $words;
    }
}
