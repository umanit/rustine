<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * One way of fixing a package. Attempts are tried in order until the CVEs disappear.
 */
final readonly class Attempt
{
    /**
     * @param list<list<string>>        $commands       commands to run, in order (before tool wrapping)
     * @param array{string, string}|null $resolutionEdit [package, range] to write in `resolutions` before the commands
     */
    public function __construct(
        public AttemptKind $kind,
        public array $commands,
        public ?array $resolutionEdit = null,
    ) {
    }

    public function describe(): string
    {
        $parts = [];
        if (null !== $this->resolutionEdit) {
            $parts[] = sprintf('package.json: resolutions["%s"] = "%s"', ...$this->resolutionEdit);
        }
        foreach ($this->commands as $command) {
            $parts[] = implode(' ', $command);
        }

        return implode(' && ', $parts);
    }
}
