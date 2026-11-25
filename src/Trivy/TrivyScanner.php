<?php

declare(strict_types=1);

namespace Rustine\Trivy;

use Rustine\GitLab\GitLabConfig;
use Symfony\Component\Process\Process;

final readonly class TrivyScanner
{
    /**
     * Installed dependencies, at any depth: Rustine only handles the lockfiles of the project root, so what Trivy finds
     * there is ignored anyway, and reading it makes every scan (and every re-scan) slower.
     */
    public const SKIPPED_DIRS = ['**/vendor', '**/node_modules'];

    /** Without it Trivy leaves out the development dependencies (`require-dev`, `devDependencies`). */
    public const INCLUDE_DEV_ARG = '--include-dev-deps';

    /**
     * Arguments of the re-scans of a run that has just scanned the project itself: the vulnerability database is then
     * fresh, and checking it again costs time at every re-scan.
     *
     * @param list<string> $args
     *
     * @return list<string>
     */
    public static function forRescan(array $args): array
    {
        return \in_array('--skip-db-update', $args, true) ? $args : [...$args, '--skip-db-update'];
    }

    /**
     * Extra Trivy arguments for the scan and the re-scans, with the development dependencies when asked: both must
     * agree, otherwise a fix of a development dependency would be "confirmed" by a re-scan that does not see it.
     *
     * @param list<string> $args
     *
     * @return list<string>
     */
    public static function withDevDependencies(array $args, bool $include): array
    {
        return $include && !\in_array(self::INCLUDE_DEV_ARG, $args, true) ? [...$args, self::INCLUDE_DEV_ARG] : $args;
    }

    public function __construct(private string $trivyBin = 'trivy')
    {
    }

    /**
     * @param list<string> $extraArgs additional Trivy arguments (e.g. `--skip-db-update`, `--severity`, `--ignorefile`)
     *
     * @return list<string>
     */
    public function buildCommand(string $outputFile, array $extraArgs = []): array
    {
        // `--list-all-pkgs` is required to tell direct dependencies from transitive ones.
        // `--no-progress` : the progress bar redraws itself with `\r`, which turns into a mess once relayed.
        return [
            $this->trivyBin, 'fs',
            '--no-progress',
            '--disable-telemetry',
            '--skip-dirs', implode(',', self::SKIPPED_DIRS),
            '--scanners', 'vuln',
            '--list-all-pkgs',
            '--format', 'json',
            '--output', $outputFile,
            ...$extraArgs,
            '.',
        ];
    }

    /**
     * Runs Trivy in the project directory and writes its JSON report to `$outputFile`.
     *
     * @param list<string>                 $extraArgs
     * @param (callable(string): void)|null $onOutput  receives Trivy's output as it is produced
     */
    public function scan(string $projectDir, string $outputFile, array $extraArgs = [], ?callable $onOutput = null): void
    {
        if (!is_dir($projectDir)) {
            throw new \InvalidArgumentException(sprintf('Project directory "%s" does not exist.', $projectDir));
        }

        // Trivy runs inside the project: a relative output path must not end up there.
        $absoluteOutput = str_starts_with($outputFile, '/') ? $outputFile : (getcwd() ?: '.') . '/' . $outputFile;

        $process = new Process($this->buildCommand($absoluteOutput, $extraArgs), $projectDir, GitLabConfig::tokenUnsetEnv(), null, 1800);
        $process->run(null === $onOutput ? null : static function (string $type, string $buffer) use ($onOutput): void {
            $onOutput($buffer);
        });

        if (!$process->isSuccessful()) {
            throw new \RuntimeException(sprintf('Trivy failed (exit code %d).', (int) $process->getExitCode()));
        }

        if (!is_file($absoluteOutput)) {
            throw new \RuntimeException(sprintf('Trivy did not produce the report "%s".', $absoluteOutput));
        }
    }
}
