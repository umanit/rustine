<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\FixStrategy;
use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ProjectPlanApplier;
use Rustine\Apply\VerifyMode;
use Rustine\Apply\ToolingException;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Apply\YarnRuntime;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReportParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'apply', description: 'Applies the planned fixes (Yarn, Composer) to the project working tree (no commit, no push)')]
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
        #[Option(description: 'Ecosystem to process: yarn or composer (required when the group has both)')]
        ?string $ecosystem = null,
        #[Option(description: 'Trivy binary')]
        string $trivyBin = 'trivy',
        #[Option(description: 'Extra Trivy argument for the re-scans (repeatable)')]
        array $trivyArg = [],
        #[Option(description: 'Command that runs Composer, e.g. "/usr/bin/php8.1 /usr/local/bin/composer2" (default: $RUSTINE_COMPOSER_BIN, then composer)')]
        ?string $composerBin = null,
        #[Option(description: 'Command that runs Yarn, e.g. "/opt/yarn/bin/yarn" (default: $RUSTINE_YARN_BIN, then yarn)')]
        ?string $yarnBin = null,
        #[Option(description: 'Command that runs Node, e.g. "/opt/node20/bin/node"; given by path, its directory goes first on the PATH of Yarn (default: $RUSTINE_NODE_BIN, then node)')]
        ?string $nodeBin = null,
        #[Option(description: 'When Trivy confirms the fixes: each (after every attempt, slow), final (once at the end) or none (the lockfile alone decides); default: $RUSTINE_VERIFY, then final')]
        ?string $verify = null,
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

        $verify = Environment::string($verify, 'RUSTINE_VERIFY', VerifyMode::Final->value);
        $verifyMode = VerifyMode::tryFrom($verify);
        if (null === $verifyMode) {
            $io->error(sprintf('Unknown verification "%s" (expected: %s).', $verify, implode(', ', array_column(VerifyMode::cases(), 'value'))));

            return Command::INVALID;
        }

        $wanted = null === $ecosystem ? null : Ecosystem::tryFrom($ecosystem);
        if (null !== $ecosystem && null === $wanted) {
            $io->error(sprintf('Unknown ecosystem "%s" (expected: yarn, composer).', $ecosystem));

            return Command::INVALID;
        }

        try {
            $parsedReport = (new TrivyReportParser())->parseFile($report);
        } catch (InvalidTrivyReportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $plans = array_values(array_filter(
            (new Planner())->plan($parsedReport, $groups),
            static fn (GroupPlan $plan): bool => null === $wanted || $wanted === $plan->ecosystem,
        ));
        if ([] === $plans) {
            $io->success('Nothing to fix in this group.');

            return Command::SUCCESS;
        }
        if (\count($plans) > 1) {
            $io->error('This group has several ecosystems (each one is a merge request): pass --ecosystem=yarn or --ecosystem=composer.');

            return Command::INVALID;
        }
        $plan = $plans[0];

        $runner = new ProcessCommandRunner();
        $composerCommand = Environment::string($composerBin, 'RUSTINE_COMPOSER_BIN', 'composer');
        $yarnCommand = Environment::string($yarnBin, 'RUSTINE_YARN_BIN', 'yarn');
        $nodeCommand = Environment::string($nodeBin, 'RUSTINE_NODE_BIN', 'node');
        try {
            ComposerStrategy::fromCommandLine($composerCommand);
            YarnRuntime::fromCommandLines($yarnCommand, $nodeCommand);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::INVALID;
        }
        $planApplier = new ProjectPlanApplier($runner, $projectDir, $trivyBin, $trivyArg, $composerCommand, $yarnCommand, $nodeCommand, $verifyMode);

        try {
            $strategy = $planApplier->strategy($plan->ecosystem);
        } catch (UnsupportedProjectException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($dryRun) {
            $this->printDryRun($io, $plan, $strategy, $projectDir);

            return Command::SUCCESS;
        }

        try {
            $applied = $planApplier->apply($plan, $parsedReport);
        } catch (UnsupportedProjectException|ToolingException $e) {
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
                $io->writeln(sprintf('  - %s@%s: not fixable automatically (%s)', $fix->packageName, $fix->installedVersion, $fix->status->value));
                continue;
            }
            $unsupported = $strategy->unsupportedReason($fix, $projectDir);
            if (null !== $unsupported) {
                $io->writeln(sprintf('  - %s@%s: needs a manual fix (%s)', $fix->packageName, $fix->installedVersion, $unsupported));
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
                ? sprintf('  ⚠ %s (%s): Trivy still reports its CVEs, check the result by hand', $label, $outcome->strategy?->value)
                : sprintf('  ✔ %s (%s)', $label, $outcome->strategy?->value),
            FixOutcomeStatus::AlreadyFixed => sprintf('  ✔ %s already fixed by a previous change', $label),
            FixOutcomeStatus::UnsupportedSource => sprintf("  ✘ %s needs a manual fix\n      %s", $label, implode("\n      ", $outcome->log)),
            FixOutcomeStatus::Skipped => sprintf('  ✘ %s skipped (%s)', $label, $fix->status->value),
            FixOutcomeStatus::Failed => sprintf("  ✘ %s failed\n      %s", $label, implode("\n      ", $outcome->log)),
        };
    }
}
