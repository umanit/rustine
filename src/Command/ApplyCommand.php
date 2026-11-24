<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\DependencySource;
use Rustine\Apply\FixApplier;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\FixStrategy;
use Rustine\Apply\PackageJsonEditor;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ToolEnvironment;
use Rustine\Apply\TrivyRescanner;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Apply\WorkingTree;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Pinning\PinningDetector;
use Rustine\Pinning\PinningException;
use Rustine\Pinning\YarnFlavor;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'apply', description: 'Applies the planned Yarn fixes to the project working tree (no commit, no push)')]
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
        #[Option(description: 'Severity group to process: critical-high or others (exactly one, as each group is one MR)')]
        array $group = [],
        #[Option(description: 'Trivy binary')]
        string $trivyBin = 'trivy',
        #[Option(description: 'Extra Trivy argument for the re-scans (repeatable)')]
        array $trivyArg = [],
        #[Option(description: 'Only print the commands that would run; the project is left untouched')]
        bool $dryRun = false,
    ): int {
        $groups = [];
        foreach ($group as $name) {
            $parsed = SeverityGroup::tryFrom($name);
            if (null === $parsed) {
                $io->error(sprintf('Unknown group "%s" (expected: %s).', $name, implode(', ', array_column(SeverityGroup::cases(), 'value'))));

                return Command::INVALID;
            }
            $groups[] = $parsed;
        }
        if (1 !== \count($groups)) {
            $io->error('Pass exactly one --group: each severity group is a separate merge request.');

            return Command::INVALID;
        }

        $projectDir = realpath($project);
        if (false === $projectDir) {
            $io->error(sprintf('Project directory "%s" does not exist.', $project));

            return Command::FAILURE;
        }

        try {
            $parsedReport = (new TrivyReportParser())->parseFile($report);
            $pinning = (new PinningDetector())->detect($projectDir);
        } catch (InvalidTrivyReportException | PinningException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $plans = array_values(array_filter(
            (new Planner())->plan($parsedReport, $groups),
            static fn (GroupPlan $plan): bool => Ecosystem::Yarn === $plan->ecosystem,
        ));
        if ([] === $plans) {
            $io->success('Nothing to fix for Yarn in this group.');

            return Command::SUCCESS;
        }
        $plan = $plans[0];

        $io->writeln(sprintf('Tool pinning: %s', $pinning->describe()));

        $flavor = $pinning->yarnFlavor();
        if (null === $flavor) {
            $io->error('Yarn is not pinned by the project: cannot choose a strategy.');

            return Command::FAILURE;
        }

        $strategy = YarnFlavor::Berry === $flavor ? new YarnBerryStrategy() : new YarnClassicStrategy();

        if ($dryRun) {
            $this->printDryRun($io, $plan, $strategy, $projectDir);

            return Command::SUCCESS;
        }

        $runner = new ProcessCommandRunner();
        $applier = new FixApplier(
            $runner,
            new ToolEnvironment($pinning, $projectDir),
            new WorkingTree($runner, $projectDir),
            new TrivyRescanner(new TrivyScanner($trivyBin), new TrivyReportParser(), $projectDir, $trivyArg),
            $strategy,
            new PackageJsonEditor(),
            $projectDir,
        );

        try {
            $result = $applier->apply($plan, $parsedReport);
        } catch (UnsupportedProjectException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

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
                $io->writeln(sprintf('  - %s@%s: not fixable automatically (%s)', $fix->packageName, $fix->installedVersion, $fix->status->value));
                continue;
            }
            $spec = (new PackageJsonEditor())->declaredSpec($projectDir . '/package.json', $fix->packageName);
            if (null !== $spec && !DependencySource::isRegistryRange($spec)) {
                $io->writeln(sprintf('  - %s@%s: declared as "%s", needs a manual fix (not a registry range)', $fix->packageName, $fix->installedVersion, $spec));
                continue;
            }
            $io->writeln(sprintf('  - %s@%s → %s', $fix->packageName, $fix->installedVersion, $fix->targetVersion));
            foreach ($strategy->attempts($fix) as $i => $attempt) {
                $io->writeln(sprintf('      %d. [%s] %s', $i + 1, $attempt->kind->value, $attempt->describe()));
            }
        }
    }

    private function describe(FixOutcome $outcome): string
    {
        $fix = $outcome->fix;
        $label = sprintf('%s@%s → %s', $fix->packageName, $fix->installedVersion, $fix->targetVersion ?? '?');

        return match ($outcome->status) {
            FixOutcomeStatus::Applied => sprintf('  ✔ %s (%s)', $label, $outcome->strategy?->value),
            FixOutcomeStatus::AlreadyFixed => sprintf('  ✔ %s already fixed by a previous change', $label),
            FixOutcomeStatus::UnsupportedSource => sprintf("  ✘ %s needs a manual fix\n      %s", $label, implode("\n      ", $outcome->log)),
            FixOutcomeStatus::Skipped => sprintf('  ✘ %s skipped (%s)', $label, $fix->status->value),
            FixOutcomeStatus::Failed => sprintf("  ✘ %s failed\n      %s", $label, implode("\n      ", $outcome->log)),
        };
    }
}
