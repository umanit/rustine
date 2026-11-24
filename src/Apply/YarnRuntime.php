<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Commands that run Yarn and Node, `yarn` and `node` from the `PATH` unless overridden.
 *
 * When Node is given by path, its directory goes first on the `PATH` of the Yarn commands: Yarn starts with
 * `#!/usr/bin/env node`, so it then runs with that Node too.
 */
final readonly class YarnRuntime
{
    /**
     * @param non-empty-list<string> $yarn e.g. `['yarn']` or `['/opt/node20/bin/node', '/opt/yarn/yarn-4.2.2.cjs']`
     * @param non-empty-list<string> $node e.g. `['node']` or `['/opt/node20/bin/node']`
     */
    public function __construct(
        public array $yarn = ['yarn'],
        public array $node = ['node'],
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a command is empty
     */
    public static function fromCommandLines(string $yarn, string $node): self
    {
        return new self(CommandLine::split($yarn, 'Yarn'), CommandLine::split($node, 'Node'));
    }

    /**
     * @return non-empty-list<string>
     */
    public function yarn(string ...$arguments): array
    {
        return [...$this->yarn, ...array_values($arguments)];
    }

    /**
     * @return array<string, string>
     */
    public function env(): array
    {
        $node = $this->node[0];
        if (!str_contains($node, '/')) {
            return [];
        }

        $path = getenv('PATH');

        return ['PATH' => \dirname($node) . (false === $path || '' === $path ? '' : \PATH_SEPARATOR . $path)];
    }
}
