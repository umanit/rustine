<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Checks that the tools an ecosystem needs are available in the environment, and reads their versions.
 *
 * Rustine never installs anything: a missing tool, or a Yarn that does not match the lockfile of the project, stops
 * the run with an explicit error.
 */
final readonly class ToolProbe
{
    private const string VERSION = '\d+\.\d+(?:\.\d+)?(?:[-+][\w.+-]*)?';

    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
    ) {
    }

    /**
     * @throws ToolingException
     */
    public function yarn(YarnFlavor $lockfile, YarnRuntime $runtime = new YarnRuntime()): ToolVersions
    {
        $node = $this->version([...$runtime->node, '--version'], 'Node');
        $yarn = $this->version($runtime->yarn('--version'), 'Yarn', $runtime->env());

        $flavor = YarnFlavor::ofVersion($yarn);
        if ($lockfile !== $flavor) {
            throw new ToolingException(
                sprintf(
                    'yarn.lock is a Yarn %s lockfile but the environment provides Yarn %s. Rustine installs no tool: '
                    . 'the environment must provide the Yarn version of the project.',
                    YarnFlavor::Berry === $lockfile ? 'Berry (2+)' : 'Classic (1.x)',
                    $yarn,
                ),
            );
        }

        return new ToolVersions(['node' => $node, 'yarn' => $yarn]);
    }

    /**
     * @param non-empty-list<string> $composer command that runs Composer
     *
     * @throws ToolingException
     */
    public function composer(array $composer): ToolVersions
    {
        $output = $this->output([...$composer, '--version', '--no-ansi'], 'Composer');

        $versions = [
            'composer' => $this->match('/Composer version (' . self::VERSION . ')/', $output)
                ?? $this->firstLine($output),
        ];
        $php = $this->match('/PHP version (' . self::VERSION . ')/', $output);
        if (null !== $php) {
            $versions['php'] = $php;
        }

        return new ToolVersions($versions);
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>  $env
     *
     * @throws ToolingException
     */
    private function version(array $command, string $tool, array $env = []): string
    {
        $output = $this->output($command, $tool, $env);

        return $this->match('/v?(' . self::VERSION . ')/', $output) ?? $this->firstLine($output);
    }

    /**
     * @param non-empty-list<string> $command
     * @param array<string, string>  $env
     *
     * @throws ToolingException
     */
    private function output(array $command, string $tool, array $env = []): string
    {
        $result = $this->runner->run($command, $this->projectDir, $env);
        if ($result->isSuccessful() && '' !== trim($result->output)) {
            return $result->output;
        }

        throw new ToolingException(
            sprintf(
                '%s is not available: `%s` failed (exit %d)%s. Rustine installs no tool: the environment that'
                . ' runs it must provide %s, in the version the project expects.',
                $tool,
                implode(' ', $command),
                $result->exitCode,
                '' === trim($result->output) ? '' : ': ' . $this->firstLine($result->output),
                $tool,
            ),
        );
    }

    private function match(string $pattern, string $output): ?string
    {
        return 1 === preg_match($pattern, $output, $m) ? $m[1] : null;
    }

    private function firstLine(string $output): string
    {
        return trim(strtok(trim($output), "\n") ?: '');
    }
}
