<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Application;
use Rustine\I18n\TranslatorFactory;
use Rustine\Pinning\PinningDetector;
use Rustine\Pinning\PinningException;
use Rustine\Pinning\ToolPinning;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReportParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'run', description: 'Entry point of the CI job: one merge request per ecosystem and severity group')]
final class RunCommand
{
    /**
     * @param list<string> $group
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Project directory')]
        string $project = '.',
        #[Option(description: 'Trivy JSON report of the project')]
        string $report = 'trivy-report.json',
        #[Option(description: 'Severity group to process: critical-high or others (exactly one)')]
        array $group = [],
        #[Option(description: 'Ecosystem to process: yarn or composer (default: both)')]
        ?string $ecosystem = null,
        #[Option(description: 'Language of the merge request and commit texts (default: $RUSTINE_LOCALE, then en)')]
        ?string $locale = null,
        #[Option(description: 'Write the merge request description(s) to this Markdown file')]
        ?string $reportFile = null,
        #[Option(description: 'Show what would be done without touching git or GitLab (the only mode available yet)')]
        bool $dryRun = false,
    ): int {
        $factory = new TranslatorFactory();
        $locale ??= (false === getenv('RUSTINE_LOCALE') || '' === getenv('RUSTINE_LOCALE')) ? TranslatorFactory::FALLBACK_LOCALE : (string) getenv('RUSTINE_LOCALE');
        if (!\in_array($locale, $factory->availableLocales(), true)) {
            $io->error(sprintf('Unknown locale "%s" (available: %s).', $locale, implode(', ', $factory->availableLocales())));

            return Command::INVALID;
        }
        $translator = $factory->create($locale);

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

        $wanted = null === $ecosystem ? null : Ecosystem::tryFrom($ecosystem);
        if (null !== $ecosystem && null === $wanted) {
            $io->error(sprintf('Unknown ecosystem "%s" (expected: yarn, composer).', $ecosystem));

            return Command::INVALID;
        }

        if (!$dryRun) {
            $io->error('Only --dry-run is available yet: pushing branches and opening merge requests is not implemented.');

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
            $io->success('Nothing to fix in this group: no merge request would be opened.');

            return Command::SUCCESS;
        }

        $renderer = new MergeRequestRenderer($translator, Application::VERSION);
        $pinning = $this->pinning($project);
        $files = [];

        foreach ($plans as $plan) {
            // A preview does not run the fixes: nothing is verified, and a group with no fixable package
            // would not open a merge request (the manual items stay in the job report).
            $content = $renderer->render($plan, null, Ecosystem::Yarn === $plan->ecosystem ? $pinning : null);

            $io->section($plan->branchName());
            if (0 === $content->fixedPackages) {
                $io->writeln('No package is fixable automatically: no merge request would be opened. Items for the job report:');
            } else {
                $io->definitionList(
                    ['Branch' => $plan->branchName()],
                    ['Title' => $content->title],
                    ['Highest severity' => null === $content->severity ? '-' : $content->severity->value],
                    ['Commit' => str_replace("\n", "\n         ", $content->commitMessage)],
                );
            }
            $io->writeln($content->description);

            $files[] = sprintf("<!-- %s -->\n%s", $plan->branchName(), $content->description);
        }

        if (null !== $reportFile) {
            file_put_contents($reportFile, implode("\n", $files));
            $io->success(sprintf('Report written to %s', $reportFile));
        }

        return Command::SUCCESS;
    }

    private function pinning(string $project): ?ToolPinning
    {
        try {
            return (new PinningDetector())->detect($project);
        } catch (PinningException) {
            return null;
        }
    }
}
