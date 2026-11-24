<?php

declare(strict_types=1);

namespace Rustine\Trivy;

use Symfony\Component\Process\Process;

final readonly class TrivyScanner
{
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

        $process = new Process($this->buildCommand($absoluteOutput, $extraArgs), $projectDir, null, null, 1800);
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
