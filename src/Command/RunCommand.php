<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ProjectPlanApplier;
use Rustine\Apply\VerifyMode;
use Rustine\Apply\YarnRuntime;
use Rustine\Application;
use Rustine\Git\BranchPublisher;
use Rustine\Git\Git;
use Rustine\Git\GitAccess;
use Rustine\GitLab\GitLabConfig;
use Rustine\GitLab\GitLabConfigException;
use Rustine\GitLab\GitLabException;
use Rustine\GitLab\HttpGitLabClient;
use Rustine\I18n\TranslatorFactory;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Sync\MergeRequestSynchronizer;
use Rustine\Sync\NoteRenderer;
use Rustine\Sync\RunSummary;
use Rustine\Sync\SyncSettings;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsCommand(name: 'run', description: 'Entry point of the CI job: one merge request per ecosystem and severity group')]
final class RunCommand
{
    public function __construct(private readonly ?HttpClientInterface $httpClient = null)
    {
    }

    /**
     * Exit code: 0 = done, 1 = a group failed (or the command was misused), 2 = some packages need a manual action.
     *
     * @param list<string> $group
     * @param list<string> $trivyArg
     * @param list<string> $label
     * @param list<string> $assignee
     * @param list<string> $reviewer
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Project directory (a git repository)')]
        string $project = '.',
        #[Option(description: 'Trivy JSON report: read by --dry-run, written by the scan of a real run')]
        string $report = 'trivy-report.json',
        #[Option(description: 'Severity group to process: critical-high or others (exactly one)')]
        array $group = [],
        #[Option(description: 'Ecosystem to process: yarn or composer (default: both)')]
        ?string $ecosystem = null,
        #[Option(description: 'Language of the merge request and commit texts (default: $RUSTINE_LOCALE, then en)')]
        ?string $locale = null,
        #[Option(description: 'Write the merge request description(s) and the status of each group to this Markdown file')]
        ?string $reportFile = null,
        #[Option(description: 'Preview the merge requests from an existing report, without touching git or GitLab')]
        bool $dryRun = false,
        #[Option(description: 'Trivy binary')]
        string $trivyBin = 'trivy',
        #[Option(description: 'Extra Trivy argument for the scan and the re-scans (repeatable)')]
        array $trivyArg = [],
        #[Option(description: 'When Trivy confirms the fixes: each (after every attempt, slow), final (once at the end) or none (the lockfile alone decides); default: $RUSTINE_VERIFY, then final')]
        ?string $verify = null,
        #[Option(description: 'Command that runs Composer, e.g. "/usr/bin/php8.1 /usr/local/bin/composer2" (default: $RUSTINE_COMPOSER_BIN, then composer)')]
        ?string $composerBin = null,
        #[Option(description: 'Command that runs Yarn, e.g. "/opt/yarn/bin/yarn" (default: $RUSTINE_YARN_BIN, then yarn)')]
        ?string $yarnBin = null,
        #[Option(description: 'Command that runs Node, e.g. "/opt/node20/bin/node"; given by path, its directory goes first on the PATH of Yarn (default: $RUSTINE_NODE_BIN, then node)')]
        ?string $nodeBin = null,
        #[Option(description: 'GitLab API root (default: $CI_API_V4_URL). The token comes from $RUSTINE_GITLAB_TOKEN or $GITLAB_TOKEN')]
        ?string $gitlabUrl = null,
        #[Option(description: 'GitLab project id or path (default: $CI_PROJECT_ID)')]
        ?string $projectId = null,
        #[Option(description: 'Default branch the fixes start from and the merge requests target (default: $CI_DEFAULT_BRANCH)')]
        ?string $defaultBranch = null,
        #[Option(description: 'Git remote to fetch from and push to')]
        string $remote = 'origin',
        #[Option(description: 'Label set on every merge request, never translated (repeatable, default: security)')]
        array $label = [],
        #[Option(description: 'Label of the highest severity of a merge request; {severity} is replaced by critical, high…')]
        string $severityLabelFormat = 'severity::{severity}',
        #[Option(description: 'Username assigned to the merge requests Rustine opens (repeatable)')]
        array $assignee = [],
        #[Option(description: 'Username asked to review the merge requests Rustine opens (repeatable)')]
        array $reviewer = [],
        #[Option(description: 'Do not open new merge requests while this many Rustine merge requests are open')]
        ?int $maxOpenMergeRequests = null,
        #[Option(description: 'Name of the commits author (default: name of the token owner)')]
        ?string $gitAuthorName = null,
        #[Option(description: 'Email of the commits author (default: commit email of the token owner, then its noreply address)')]
        ?string $gitAuthorEmail = null,
    ): int {
        $factory = new TranslatorFactory();
        $locale = Environment::string($locale, 'RUSTINE_LOCALE', TranslatorFactory::FALLBACK_LOCALE);
        if (!\in_array($locale, $factory->availableLocales(), true)) {
            $io->error(sprintf('Unknown locale "%s" (available: %s).', $locale, implode(', ', $factory->availableLocales())));

            return Command::FAILURE;
        }
        $translator = $factory->create($locale);

        $groups = [];
        foreach ($group as $name) {
            $parsed = SeverityGroup::tryFrom($name);
            if (null === $parsed) {
                $io->error(sprintf('Unknown group "%s" (expected: %s).', $name, implode(', ', array_column(SeverityGroup::cases(), 'value'))));

                return Command::FAILURE;
            }
            $groups[] = $parsed;
        }
        if (1 !== \count($groups)) {
            $io->error('Pass exactly one --group: each severity group is a separate merge request.');

            return Command::FAILURE;
        }

        $verify = Environment::string($verify, 'RUSTINE_VERIFY', VerifyMode::Final->value);
        $verifyMode = VerifyMode::tryFrom($verify);
        if (null === $verifyMode) {
            $io->error(sprintf('Unknown verification "%s" (expected: %s).', $verify, implode(', ', array_column(VerifyMode::cases(), 'value'))));

            return Command::FAILURE;
        }

        $wanted = null === $ecosystem ? null : Ecosystem::tryFrom($ecosystem);
        if (null !== $ecosystem && null === $wanted) {
            $io->error(sprintf('Unknown ecosystem "%s" (expected: yarn, composer).', $ecosystem));

            return Command::FAILURE;
        }

        if ($dryRun) {
            return $this->preview($io, $translator, $report, $groups, $wanted, $reportFile);
        }

        $projectDir = realpath($project);
        if (false === $projectDir) {
            $io->error(sprintf('Project directory "%s" does not exist.', $project));

            return Command::FAILURE;
        }

        try {
            $config = GitLabConfig::resolve(getenv(), $gitlabUrl, $projectId, $defaultBranch);
        } catch (GitLabConfigException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $composerCommand = Environment::string($composerBin, 'RUSTINE_COMPOSER_BIN', 'composer');
        $yarnCommand = Environment::string($yarnBin, 'RUSTINE_YARN_BIN', 'yarn');
        $nodeCommand = Environment::string($nodeBin, 'RUSTINE_NODE_BIN', 'node');
        try {
            ComposerStrategy::fromCommandLine($composerCommand);
            YarnRuntime::fromCommandLines($yarnCommand, $nodeCommand);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $client = new HttpGitLabClient($this->httpClient ?? HttpClient::create(), $config->apiUrl, $config->projectId, $config->token);
        try {
            $user = $client->currentUser();
        } catch (GitLabException $e) {
            $io->error('Cannot authenticate to GitLab: ' . $e->getMessage());

            return Command::FAILURE;
        }
        $identity = $user->gitIdentity($config->host(), $gitAuthorName, $gitAuthorEmail);
        $io->writeln(sprintf('GitLab user: %s; commits authored by %s <%s>', $user->username, $identity->name, $identity->email));

        try {
            (new TrivyScanner($trivyBin))->scan($projectDir, $report, $trivyArg, static fn (string $buffer) => $io->getErrorStyle()->write($buffer));
            $parsedReport = (new TrivyReportParser())->parseFile($report);
        } catch (InvalidTrivyReportException|\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $parsedReport->ignoredTargets) {
            $io->writeln(sprintf('Trivy targets ignored (unsupported type, or not at the project root): %s', implode(', ', $parsedReport->ignoredTargets)));
        }

        $plans = $this->plans($parsedReport, $groups, $wanted);
        if ([] === $plans) {
            $io->success('Nothing to fix in this group: no merge request to open.');

            return Command::SUCCESS;
        }

        $runner = new ProcessCommandRunner();
        $synchronizer = new MergeRequestSynchronizer(
            $client,
            new BranchPublisher(new Git($runner, $projectDir, new GitAccess($config->token)), $config->defaultBranch, $identity, $remote),
            new ProjectPlanApplier($runner, $projectDir, $trivyBin, $trivyArg, $composerCommand, $yarnCommand, $nodeCommand, $verifyMode),
            new MergeRequestRenderer($translator, Application::VERSION),
            new NoteRenderer($translator),
            new SyncSettings($config->defaultBranch, [] === $label ? ['security'] : $label, $severityLabelFormat, $assignee, $reviewer, $maxOpenMergeRequests),
        );

        $outcomes = [];
        foreach ($plans as $plan) {
            $io->section($plan->branchName());
            $outcome = $synchronizer->sync($plan, $parsedReport);
            $outcomes[] = $outcome;

            $io->writeln($outcome->isFailure() ? '<error>' . $outcome->branch . ': failed</error>' : '  ' . RunSummary::line($outcome));
            foreach ($outcome->messages as $message) {
                $io->writeln('  ' . $message);
            }
        }

        $summary = new RunSummary($outcomes);
        if (null !== $reportFile) {
            if (false === @file_put_contents($reportFile, $summary->toMarkdown())) {
                $io->error(sprintf('Unable to write the report to %s.', $reportFile));

                return RunSummary::EXIT_FAILURE;
            }
            $io->writeln(sprintf('Report written to %s', $reportFile));
        }

        $exitCode = $summary->exitCode();
        match ($exitCode) {
            RunSummary::EXIT_FAILURE => $io->error('At least one group failed.'),
            RunSummary::EXIT_MANUAL_ACTION => $io->warning('Some packages need a manual action: see the report.'),
            default => $io->success('Done.'),
        };

        return $exitCode;
    }

    /**
     * @param list<SeverityGroup> $groups
     */
    private function preview(SymfonyStyle $io, TranslatorInterface $translator, string $report, array $groups, ?Ecosystem $wanted, ?string $reportFile): int
    {
        try {
            $parsedReport = (new TrivyReportParser())->parseFile($report);
        } catch (InvalidTrivyReportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $plans = $this->plans($parsedReport, $groups, $wanted);
        if ([] === $plans) {
            $io->success('Nothing to fix in this group: no merge request would be opened.');

            return Command::SUCCESS;
        }

        $renderer = new MergeRequestRenderer($translator, Application::VERSION);
        $files = [];

        foreach ($plans as $plan) {
            // A preview does not run the fixes: nothing is verified, and a group with no fixable package
            // would not open a merge request (the manual items stay in the job report).
            $content = $renderer->render($plan);

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
            if (false === @file_put_contents($reportFile, implode("\n", $files))) {
                $io->error(sprintf('Unable to write the report to %s.', $reportFile));

                return Command::FAILURE;
            }
            $io->success(sprintf('Report written to %s', $reportFile));
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<SeverityGroup> $groups
     *
     * @return list<GroupPlan>
     */
    private function plans(TrivyReport $report, array $groups, ?Ecosystem $wanted): array
    {
        return array_values(array_filter(
            (new Planner())->plan($report, $groups),
            static fn (GroupPlan $plan): bool => null === $wanted || $wanted === $plan->ecosystem,
        ));
    }
}
