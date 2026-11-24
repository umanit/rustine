<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Trivy\TrivyScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'scan', description: 'Runs Trivy on a project and writes its JSON report')]
final class ScanCommand
{
    /**
     * @param list<string> $trivyArg
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Project directory to scan')]
        string $project = '.',
        #[Option(description: 'Path of the JSON report to write')]
        string $output = 'trivy-report.json',
        #[Option(description: 'Trivy binary')]
        string $trivyBin = 'trivy',
        #[Option(description: 'Extra Trivy argument (repeatable), e.g. --trivy-arg=--skip-db-update')]
        array $trivyArg = [],
    ): int {
        try {
            (new TrivyScanner($trivyBin))->scan(
                $project,
                $output,
                $trivyArg,
                static fn (string $buffer) => $io->getErrorStyle()->write($buffer),
            );
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Trivy report written to %s', $output));

        return Command::SUCCESS;
    }
}
