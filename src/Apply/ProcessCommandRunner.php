<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Symfony\Component\Process\Process;

final readonly class ProcessCommandRunner implements CommandRunner
{
    public function __construct(private float $timeout = 1800.0)
    {
    }

    public function run(array $command, string $cwd, array $env = []): CommandResult
    {
        $process = new Process($command, $cwd, [] === $env ? null : $env, null, $this->timeout);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
