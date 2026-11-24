<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\GitLab\GitLabConfig;
use Symfony\Component\Process\Process;

/**
 * Runs commands with the environment of Rustine, minus the GitLab token: Yarn, Composer, Trivy or git never need it
 * (git gets its credentials from {@see \Rustine\Git\GitAccess}), and a Yarn plugin could read it.
 */
final readonly class ProcessCommandRunner implements CommandRunner
{
    public function __construct(private float $timeout = 1800.0)
    {
    }

    public function run(array $command, string $cwd, array $env = []): CommandResult
    {
        $process = new Process($command, $cwd, [...GitLabConfig::tokenUnsetEnv(), ...$env], null, $this->timeout);
        $process->run();

        return new CommandResult((int) $process->getExitCode(), $process->getOutput() . $process->getErrorOutput());
    }
}
