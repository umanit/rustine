<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Runs external commands (yarn, git…). An interface so that tests never spawn real processes.
 */
interface CommandRunner
{
    /**
     * @param list<string>          $command
     * @param array<string, string> $env extra environment variables
     */
    public function run(array $command, string $cwd, array $env = []): CommandResult;
}
