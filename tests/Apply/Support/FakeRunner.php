<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply\Support;

use Rustine\Apply\CommandResult;
use Rustine\Apply\CommandRunner;

class FakeRunner implements CommandRunner
{
    /** @var list<list<string>> */
    public array $commands = [];
    /** @var list<array<string, string>> */
    public array $envs = [];
    public string $status = '';
    public string $tracked = '';

    /** @var (\Closure(list<string>): ?CommandResult)|null */
    public ?\Closure $handler = null;

    public function run(array $command, string $cwd, array $env = []): CommandResult
    {
        $this->commands[] = $command;
        $this->envs[] = $env;

        if ('git' === $command[0]) {
            return new CommandResult(0, match ($command[1]) {
                'rev-parse' => "\n",
                'status' => $this->status,
                'ls-files' => $this->tracked,
                default => '',
            });
        }

        return (null === $this->handler ? null : ($this->handler)($command)) ?? new CommandResult(0, '');
    }

    /**
     * @return list<string> the commands other than git, as single lines
     */
    public function toolCommands(): array
    {
        $lines = [];
        foreach ($this->commands as $command) {
            if ('git' !== $command[0]) {
                $lines[] = implode(' ', $command);
            }
        }

        return $lines;
    }
}
