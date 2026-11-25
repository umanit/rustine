<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * One way of fixing a package. Attempts are tried in order until the CVEs disappear.
 */
final readonly class Attempt
{
    /**
     * @param list<list<string>>         $commands       commands to run, in order (before tool wrapping)
     * @param array{string, string}|null $resolutionEdit [key, range] to write in `resolutions` before the commands
     * @param string|null                $pruneLockfile  package whose entries are removed from `yarn.lock` first
     *                                                   (Yarn Classic: forces a new resolution within the ranges)
     * @param array{string, string}|null $rangeEdit      [package, range] set in place in `package.json` first
     */
    public function __construct(
        public AttemptKind $kind,
        public array $commands,
        public ?array $resolutionEdit = null,
        public ?string $pruneLockfile = null,
        public ?array $rangeEdit = null,
    ) {
    }

    public function describe(): string
    {
        $parts = [];
        if (null !== $this->pruneLockfile) {
            $parts[] = sprintf('yarn.lock: remove the entries of "%s"', $this->pruneLockfile);
        }
        if (null !== $this->rangeEdit) {
            $parts[] = sprintf('package.json: set the range of "%s" to "%s"', ...$this->rangeEdit);
        }
        if (null !== $this->resolutionEdit) {
            $parts[] = sprintf('package.json: resolutions["%s"] = "%s"', ...$this->resolutionEdit);
        }

        foreach ($this->commands as $command) {
            $parts[] = implode(' ', $command);
        }

        return implode(' && ', $parts);
    }
}
