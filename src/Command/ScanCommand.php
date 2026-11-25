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
        #[Option(description: 'Trivy binary (default: $RUSTINE_TRIVY_BIN, then trivy)')]
        ?string $trivyBin = null,
        #[Option(description: 'Extra Trivy argument (repeatable, default: $RUSTINE_TRIVY_ARG separated by spaces), e.g.'
            . ' --trivy-arg=--skip-db-update')]
        array $trivyArg = [],
        #[Option(
            description: 'Also scan the development dependencies (require-dev, devDependencies); default:'
            . ' $RUSTINE_INCLUDE_DEV, then no',
        )]
        bool $includeDev = false,
    ): int {
        try {
            new TrivyScanner(Environment::string($trivyBin, 'RUSTINE_TRIVY_BIN', 'trivy'))->scan(
                $project,
                $output,
                TrivyScanner::withDevDependencies(
                    Environment::list($trivyArg, 'RUSTINE_TRIVY_ARG', '\\s+'),
                    Environment::flag($includeDev, 'RUSTINE_INCLUDE_DEV'),
                ),
                static fn(string $buffer) => $io->getErrorStyle()->write($buffer),
            );
        } catch (\Throwable $throwable) {
            $io->error($throwable->getMessage());

            return Command::FAILURE;
        }

        $io->success(sprintf('Trivy report written to %s', $output));

        return Command::SUCCESS;
    }
}
