<?php

declare(strict_types=1);

namespace Rustine\Pinning;

final readonly class ToolPinning
{
    /**
     * @param array<string, string> $tools pinned versions per tool (`node`, `yarn`, `php`…)
     * @param string                $file  file the versions come from (path relative to the project)
     */
    public function __construct(
        public PinningSource $source,
        public array $tools,
        public string $file,
    ) {
    }

    public function version(string $tool): ?string
    {
        return $this->tools[$tool] ?? null;
    }

    /**
     * Yarn Classic (v1) or Berry (v2+) based on the pinned version; null if Yarn is not pinned or unusable.
     */
    public function yarnFlavor(): ?YarnFlavor
    {
        $version = $this->version('yarn');
        if (null === $version || 1 !== preg_match('/(\d+)/', $version, $m)) {
            return null;
        }

        return 1 === (int) $m[1] ? YarnFlavor::Classic : YarnFlavor::Berry;
    }

    /**
     * For logs and MR descriptions.
     */
    public function describe(): string
    {
        $versions = [];
        foreach ($this->tools as $tool => $version) {
            $versions[] = $tool . '@' . $version;
        }

        return sprintf('%s (%s)', $this->file, [] === $versions ? 'no tool' : implode(', ', $versions));
    }
}
