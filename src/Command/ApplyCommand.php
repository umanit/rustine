<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\FixStrategy;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ProjectPlanApplier;
use Rustine\Apply\ToolingException;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\Planner;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'apply',
    description: 'Applies the planned fixes (Yarn, Composer) to the project working tree (no commit, no push)',
)]
final class ApplyCommand
{
    /**
     * @param list<string> $group
     * @param list<string> $trivyArg
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Project directory (a git repository)')]
        string $project = '.',
        #[Option(description: 'Trivy JSON report of the project')]
        string $report = 'trivy-report.json',
        #[Option(
            description: 'Severity group to process: critical-high or others (exactly one, as each group is one MR)',
        )]
        array $group = [],
        #[Option(description: 'Ecosystem to process: yarn or composer (required when the group has both)')]
        ?string $ecosystem = null,
        #[Option(description: 'Trivy binary (default: $RUSTINE_TRIVY_BIN, then trivy)')]
        ?string $trivyBin = null,
        #[Option(
            description: 'Extra Trivy argument for the re-scans (repeatable, default: $RUSTINE_TRIVY_ARG separated by'
            . ' spaces)',
        )]
        array $trivyArg = [],
        #[Option(
            description: 'Also check the development dependencies in the re-scans: pass it when the report was made'
            . ' with it; default: $RUSTINE_INCLUDE_DEV, then no',
        )]
        bool $includeDev = false,
        #[Option(
            description: 'Command that runs Composer, e.g. "/usr/bin/php8.1 /usr/local/bin/composer2"'
            . ' (default: $RUSTINE_COMPOSER_BIN, then composer)',
        )]
        ?string $composerBin = null,
        #[Option(
            description: 'Command that runs Yarn, e.g. "/opt/yarn/bin/yarn" (default: $RUSTINE_YARN_BIN, then yarn)',
        )]
        ?string $yarnBin = null,
        #[Option(
            description: 'Command that runs Node, e.g. "/opt/node20/bin/node"; given by path, its directory goes first'
            . ' on the PATH of Yarn (default: $RUSTINE_NODE_BIN, then node)',
        )]
        ?string $nodeBin = null,
        #[Option(
            description: 'When Trivy confirms the fixes: each (after every attempt, slow), final (once at the end) or'
            . ' none (the lockfile alone decides); default: $RUSTINE_VERIFY, then final',
        )]
        ?string $verify = null,
        #[Option(description: 'Only print the commands that would run; the project is left untouched')]
        bool $dryRun = false,
    ): int {
        try {
            $groups = Options::exactlyOneGroup($group);
            $verifyMode = Options::verifyMode($verify);
            $wanted = Options::ecosystem($ecosystem);
        } catch (InvalidOptionException $invalidOptionException) {
            $io->error($invalidOptionException->getMessage());

            return Command::INVALID;
        }

        $projectDir = realpath($project);
        if (false === $projectDir) {
            $io->error(sprintf('Project directory "%s" does not exist.', $project));

            return Command::FAILURE;
        }

        try {
            $parsedReport = new TrivyReportParser()->parseFile($report);
        } catch (InvalidTrivyReportException $invalidTrivyReportException) {
            $io->error($invalidTrivyReportException->getMessage());

            return Command::FAILURE;
        }

        $plans = new Planner()->plan($parsedReport, $groups, $wanted);
        if ([] === $plans) {
            $io->success('Nothing to fix in this group.');

            return Command::SUCCESS;
        }
        if (\count($plans) > 1) {
            $io->error(
                'This group has several ecosystems (each one is a merge request): pass --ecosystem=yarn or'
                . ' --ecosystem=composer.',
            );

            return Command::INVALID;
        }
        $plan = $plans[0];

        try {
            $tools = ToolCommands::resolve($composerBin, $yarnBin, $nodeBin);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage());

            return Command::INVALID;
        }

        $planApplier = new ProjectPlanApplier(
            new ProcessCommandRunner(),
            $projectDir,
            Environment::string($trivyBin, 'RUSTINE_TRIVY_BIN', 'trivy'),
            TrivyScanner::withDevDependencies(
                Environment::list($trivyArg, 'RUSTINE_TRIVY_ARG', '\\s+'),
                Environment::flag($includeDev, 'RUSTINE_INCLUDE_DEV'),
            ),
            $tools->composer,
            $tools->yarn,
            $tools->node,
            $verifyMode,
        );

        try {
            $strategy = $planApplier->strategy($plan->ecosystem);
        } catch (UnsupportedProjectException $unsupportedProjectException) {
            $io->error($unsupportedProjectException->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->printDryRun($io, $plan, $strategy, $projectDir);

            return Command::SUCCESS;
        }

        try {
            $applied = $planApplier->apply($plan, $parsedReport);
        } catch (UnsupportedProjectException | ToolingException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $result = $applied->result;
        $io->writeln(sprintf('Tools of the environment: %s', $applied->tools?->describe() ?? '-'));

        foreach ($result->outcomes as $outcome) {
            $io->writeln($this->describe($outcome));
        }

        if (null !== $result->finalCheckError) {
            $io->error('`yarn install --immutable` fails on the result: ' . $result->finalCheckError);

            return Command::FAILURE;
        }

        if (!$result->hasChanges()) {
            $io->warning('No fix could be applied.');
        } else {
            $io->success('Fixes applied to the working tree (not committed).');
        }

        return $result->isHealthy() ? Command::SUCCESS : Command::FAILURE;
    }

    private function printDryRun(SymfonyStyle $io, GroupPlan $plan, FixStrategy $strategy, string $projectDir): void
    {
        $io->writeln(sprintf('Dry run for %s (nothing is executed):', $plan->branchName()));

        foreach ($plan->fixes as $fix) {
            if (!$fix->isFixable()) {
                $io->writeln(
                    sprintf(
                        '  - %s@%s: not fixable automatically (%s)',
                        $fix->packageName,
                        $fix->installedVersion,
                        $fix->status->value,
                    ),
                );

                continue;
            }
            $unsupported = $strategy->unsupportedReason($fix, $projectDir);
            if (null !== $unsupported) {
                $io->writeln(
                    sprintf(
                        '  - %s@%s: needs a manual fix (%s)',
                        $fix->packageName,
                        $fix->installedVersion,
                        $unsupported,
                    ),
                );

                continue;
            }
            $io->writeln(sprintf('  - %s@%s → %s', $fix->packageName, $fix->installedVersion, $fix->targetVersion));

            foreach ($strategy->attempts($fix, $projectDir) as $i => $attempt) {
                $io->writeln(sprintf('      %d. [%s] %s', $i + 1, $attempt->kind->value, $attempt->describe()));
            }
        }
    }

    private function describe(FixOutcome $outcome): string
    {
        $fix = $outcome->fix;
        $label = sprintf('%s@%s → %s', $fix->packageName, $fix->installedVersion, $fix->targetVersion ?? '?');

        return match ($outcome->status) {
            FixOutcomeStatus::Applied => $outcome->stillReported
                ? sprintf(
                    '  ⚠ %s (%s): Trivy still reports its CVEs, check the result by hand',
                    $label,
                    $outcome->strategy?->value,
                )
                : sprintf('  ✔ %s (%s)', $label, $outcome->strategy?->value),
            FixOutcomeStatus::AlreadyFixed => sprintf('  ✔ %s already fixed by a previous change', $label),
            FixOutcomeStatus::UnsupportedSource => sprintf(
                "  ✘ %s needs a manual fix\n      %s",
                $label,
                implode("\n      ", $outcome->log),
            ),
            FixOutcomeStatus::Skipped => sprintf('  ✘ %s skipped (%s)', $label, $fix->status->value),
            FixOutcomeStatus::Failed => sprintf("  ✘ %s failed\n      %s", $label, implode("\n      ", $outcome->log)),
        };
    }
}
