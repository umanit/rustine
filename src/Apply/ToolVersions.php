<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Versions of the tools found in the environment, for logs and merge request descriptions.
 */
final readonly class ToolVersions
{
    /**
     * @param array<string, string> $versions version per tool (`node`, `yarn`, `composer`, `php`)
     */
    public function __construct(public array $versions)
    {
    }

    public function version(string $tool): ?string
    {
        return $this->versions[$tool] ?? null;
    }

    public function describe(): string
    {
        $parts = [];

        foreach ($this->versions as $tool => $version) {
            $parts[] = $tool . ' ' . $version;
        }

        return implode(', ', $parts);
    }
}
