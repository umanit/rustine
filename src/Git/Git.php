<?php

declare(strict_types=1);

namespace Rustine\Git;

use Rustine\Apply\CommandResult;
use Rustine\Apply\CommandRunner;

/**
 * Thin wrapper around the `git` binary for one working directory.
 *
 * Commands that reach the remote ask for `$remote = true` and get the credentials; error messages are redacted.
 */
final readonly class Git
{
    public function __construct(
        private CommandRunner $runner,
        private string $cwd,
        private ?GitAccess $access = null,
    ) {
    }

    /**
     * Runs git and returns its output; a non-zero exit code is an error.
     *
     * @param list<string> $args arguments after `git`
     *
     * @throws GitException
     */
    public function run(array $args, bool $remote = false): string
    {
        $result = $this->attempt($args, $remote);
        if (!$result->isSuccessful()) {
            throw new GitException($this->redact(sprintf('"git %s" failed: %s', implode(' ', $args), trim($result->output))));
        }

        return $result->output;
    }

    /**
     * Runs git and returns the raw result, whatever the exit code.
     *
     * @param list<string> $args arguments after `git`
     */
    public function attempt(array $args, bool $remote = false): CommandResult
    {
        $env = ['GIT_TERMINAL_PROMPT' => '0'];
        if ($remote && null !== $this->access) {
            $env = [...$env, ...$this->access->environment()];
        }

        $result = $this->runner->run(['git', ...$args], $this->cwd, $env);

        return new CommandResult($result->exitCode, $this->redact($result->output));
    }

    private function redact(string $text): string
    {
        return null === $this->access ? $text : $this->access->redact($text);
    }
}
