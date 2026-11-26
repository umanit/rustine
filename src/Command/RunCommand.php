<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Application;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ProjectPlanApplier;
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
use Rustine\Progress\ConsoleProgress;
use Rustine\Progress\NullProgress;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Sync\MergeRequestSynchronizer;
use Rustine\Sync\NoteRenderer;
use Rustine\Sync\PlanAction;
use Rustine\Sync\PlanOutcome;
use Rustine\Sync\RunSummary;
use Rustine\Sync\SyncSettings;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
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
final readonly class RunCommand
{
    public function __construct(private ?HttpClientInterface $httpClient = null)
    {
    }

    /**
     * Exit code: 0 = done, 1 = a group failed (or the command was misused), 2 = some packages need a manual action.
     *
     * @param list<string> $group
     * @param list<string> $trivyArg
     * @param list<string> $label
     * @param list<string> $assignee
     * @param list<string> $notify
     * @param list<string> $reviewer
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Project directory (a git repository)')]
        string $project = '.',
        #[Option(description: 'Trivy JSON report: read by --dry-run, written by the scan of a real run')]
        string $report = 'trivy-report.json',
        #[Option(
            description: 'Severity group to process: critical-high or others (repeatable, default: $RUSTINE_GROUP'
            . ' separated by commas, then every group in one merge request per ecosystem)',
        )]
        array $group = [],
        #[Option(description: 'Ecosystem to process: yarn or composer (default: $RUSTINE_ECOSYSTEM, then both)')]
        ?string $ecosystem = null,
        #[Option(description: 'Language of the merge request and commit texts (default: $RUSTINE_LOCALE, then en)')]
        ?string $locale = null,
        #[Option(
            description: 'Write the merge request description(s) and the status of each group to this Markdown file',
        )]
        ?string $reportFile = null,
        #[Option(description: 'Preview the merge requests from an existing report, without touching git or GitLab')]
        bool $dryRun = false,
        #[Option(description: 'Trivy binary (default: $RUSTINE_TRIVY_BIN, then trivy)')]
        ?string $trivyBin = null,
        #[Option(
            description: 'Extra Trivy argument for the scan and the re-scans (repeatable, default: $RUSTINE_TRIVY_ARG'
            . ' separated by spaces)',
        )]
        array $trivyArg = [],
        #[Option(
            description: 'Also scan the development dependencies (require-dev, devDependencies); default:'
            . ' $RUSTINE_INCLUDE_DEV, then no',
        )]
        bool $includeDev = false,
        #[Option(
            description: 'When Trivy confirms the fixes: each (after every attempt, slow), final (once at the end) or'
            . ' none (the lockfile alone decides); default: $RUSTINE_VERIFY, then final',
        )]
        ?string $verify = null,
        #[Option(
            description: 'Command that runs Composer, e.g. "/usr/bin/php8.1 /usr/local/bin/composer2" (default:' .
            ' $RUSTINE_COMPOSER_BIN, then composer)',
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
            description: 'GitLab API root, or just the address of the instance: /api/v4 is added when missing (default:'
            . ' $CI_API_V4_URL). The token comes from $RUSTINE_GITLAB_TOKEN or $GITLAB_TOKEN',
        )]
        ?string $gitlabUrl = null,
        #[Option(description: 'GitLab project id or path (default: $CI_PROJECT_ID)')]
        ?string $projectId = null,
        #[Option(
            description: 'Branch to analyze: the fixes start from it and the merge requests target it (default:'
            . ' $RUSTINE_BRANCH, then $CI_DEFAULT_BRANCH, the default branch of the project)',
        )]
        ?string $branch = null,
        #[Option(description: 'Alias of --branch, kept for compatibility')]
        ?string $defaultBranch = null,
        #[Option(description: 'Git remote to fetch from and push to (default: $RUSTINE_REMOTE, then origin)')]
        ?string $remote = null,
        #[Option(
            description: 'Label set on every merge request, never translated (repeatable, default: $RUSTINE_LABEL'
            . ' separated by commas, then security)',
        )]
        array $label = [],
        #[Option(
            description: 'Label of the highest severity of a merge request; {severity} is replaced by critical, high…'
            . ' (default: $RUSTINE_SEVERITY_LABEL_FORMAT, then severity::{severity})',
        )]
        ?string $severityLabelFormat = null,
        #[Option(
            description: 'Username assigned to the merge requests Rustine opens (repeatable, default:'
            . ' $RUSTINE_ASSIGNEE separated by commas)',
        )]
        array $assignee = [],
        #[Option(
            description: 'Username asked to review the merge requests Rustine opens (repeatable, default:'
            . ' $RUSTINE_REVIEWER separated by commas)',
        )]
        array $reviewer = [],
        #[Option(
            description: 'Username (or group) mentioned in the description of the merge requests, to notify them'
            . ' without assigning (repeatable, default: $RUSTINE_NOTIFY separated by commas)',
        )]
        array $notify = [],
        #[Option(
            description: 'Do not open new merge requests while this many Rustine merge requests are open (default:'
            . ' $RUSTINE_MAX_OPEN_MERGE_REQUESTS, then no limit)',
        )]
        ?int $maxOpenMergeRequests = null,
        #[Option(
            description: 'Do not open again a merge request closed by hand for the same fixes less than this many days'
            . ' ago, 0 = always open (default: $RUSTINE_CLOSED_GRACE_DAYS, then 30)',
        )]
        ?int $closedGraceDays = null,
        #[Option(
            description: 'Name of the commits author (default: $RUSTINE_GIT_AUTHOR_NAME, then name of the token owner)',
        )]
        ?string $gitAuthorName = null,
        #[Option(
            description: 'Email of the commits author (default: $RUSTINE_GIT_AUTHOR_EMAIL, then commit email of the'
            . ' token owner, then its GitLab private commit email)',
        )]
        ?string $gitAuthorEmail = null,
        #[Option(description: ConfigFile::OPTION_DESCRIPTION)]
        ?string $config = null,
    ): int {
        try {
            $file = ConfigFile::load($config, $project);
        } catch (InvalidOptionException $invalidOptionException) {
            $io->error($invalidOptionException->getMessage());

            return Command::FAILURE;
        }

        if ($io->isVerbose() && null !== $file->path) {
            $io->writeln('Configuration file: ' . $file->path);
        }

        $factory = new TranslatorFactory();
        $locale = Environment::string(
            $locale ?? $file->string('locale'),
            'RUSTINE_LOCALE',
            TranslatorFactory::FALLBACK_LOCALE,
        );
        if (!\in_array($locale, $factory->availableLocales(), true)) {
            $io->error(
                sprintf('Unknown locale "%s" (available: %s).', $locale, implode(', ', $factory->availableLocales())),
            );

            return Command::FAILURE;
        }
        $translator = $factory->create($locale);

        $trivyBin = Environment::string($trivyBin, 'RUSTINE_TRIVY_BIN', 'trivy');
        $trivyArg = Environment::list($trivyArg, 'RUSTINE_TRIVY_ARG', '\s+', $file->list('trivy_args'));
        $remote = Environment::string($remote, 'RUSTINE_REMOTE', 'origin');
        $label = Environment::list($label, 'RUSTINE_LABEL', ',', $file->list('labels'));
        $severityLabelFormat = Environment::string(
            $severityLabelFormat ?? $file->string('severity_label_format'),
            'RUSTINE_SEVERITY_LABEL_FORMAT',
            'severity::{severity}',
        );
        $assignee = Environment::list($assignee, 'RUSTINE_ASSIGNEE', ',', $file->list('assignee'));
        $reviewer = Environment::list($reviewer, 'RUSTINE_REVIEWER', ',', $file->list('reviewer'));
        $gitAuthorName = Environment::optionalString(
            $gitAuthorName ?? $file->string('git_author_name'),
            'RUSTINE_GIT_AUTHOR_NAME',
        );
        $gitAuthorEmail = Environment::optionalString(
            $gitAuthorEmail ?? $file->string('git_author_email'),
            'RUSTINE_GIT_AUTHOR_EMAIL',
        );

        try {
            $groups = Options::groups(Environment::list($group, 'RUSTINE_GROUP', ',', $file->list('group')));
            $verifyMode = Options::verifyMode($verify ?? $file->string('verify'));
            $wanted = Options::ecosystem(
                Environment::optionalString($ecosystem ?? $file->string('ecosystem'), 'RUSTINE_ECOSYSTEM'),
            );
            $mentions = Options::mentions(Environment::list($notify, 'RUSTINE_NOTIFY', ',', $file->list('notify')));
            $maxOpenMergeRequests = Environment::int(
                $maxOpenMergeRequests ?? $file->int('max_open_merge_requests'),
                'RUSTINE_MAX_OPEN_MERGE_REQUESTS',
                null,
            );
            $closedGraceDays = Environment::int(
                $closedGraceDays ?? $file->int('closed_grace_days'),
                'RUSTINE_CLOSED_GRACE_DAYS',
                30,
            ) ?? 30;
        } catch (InvalidOptionException $invalidOptionException) {
            $io->error($invalidOptionException->getMessage());

            return Command::FAILURE;
        }

        $trivyArg = TrivyScanner::withDevDependencies(
            $trivyArg,
            Environment::flag($includeDev, 'RUSTINE_INCLUDE_DEV', $file->bool('include_dev')),
        );

        if ($dryRun) {
            return $this->preview($io, $translator, $mentions, $report, $groups, $wanted, $reportFile);
        }

        $projectDir = realpath($project);
        if (false === $projectDir) {
            $io->error(sprintf('Project directory "%s" does not exist.', $project));

            return Command::FAILURE;
        }

        try {
            $config = GitLabConfig::resolve(
                getenv(),
                $gitlabUrl,
                $projectId,
                Environment::optionalString(
                    $branch ?? $defaultBranch ?? $file->string('branch'),
                    'RUSTINE_BRANCH',
                ),
            );
        } catch (GitLabConfigException $gitLabConfigException) {
            $io->error($gitLabConfigException->getMessage());

            return Command::FAILURE;
        }

        try {
            $tools = ToolCommands::resolve($composerBin, $yarnBin, $nodeBin);
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $io->error($invalidArgumentException->getMessage());

            return Command::FAILURE;
        }

        $client = new HttpGitLabClient(
            $this->httpClient ?? HttpClient::create(),
            $config->apiUrl,
            $config->projectId,
            $config->token,
        );

        try {
            $user = $client->currentUser();
        } catch (GitLabException $gitLabException) {
            $io->error('Cannot authenticate to GitLab: ' . $gitLabException->getMessage());

            return Command::FAILURE;
        }

        $identity = $user->gitIdentity($config->host(), $gitAuthorName, $gitAuthorEmail);
        $io->writeln(
            sprintf('GitLab user: %s; commits authored by %s <%s>', $user->username, $identity->name, $identity->email),
        );

        try {
            new TrivyScanner($trivyBin)->scan(
                $projectDir,
                $report,
                $trivyArg,
                static fn(string $buffer) => $io->getErrorStyle()->write($buffer),
            );
            $parsedReport = new TrivyReportParser()->parseFile($report);
        } catch (InvalidTrivyReportException | \RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ([] !== $parsedReport->ignoredTargets) {
            $io->writeln(
                sprintf(
                    'Trivy targets ignored (unsupported type, or not at the project root): %s',
                    implode(', ', $parsedReport->ignoredTargets),
                ),
            );
        }

        $planner = new Planner();
        $plans = $planner->withEmptyPlans(
            $planner->plan($parsedReport, [] === $groups ? null : $groups, $wanted),
            [] === $groups ? null : $groups,
            $wanted,
        );

        $projectDefault = getenv('CI_DEFAULT_BRANCH');
        if (\is_string($projectDefault) && '' !== $projectDefault && $projectDefault !== $config->defaultBranch) {
            $plans = array_map(static fn(GroupPlan $plan): GroupPlan => $plan->forBase($config->defaultBranch), $plans);
        }

        $progress = $io->isVerbose() ? new ConsoleProgress($io) : new NullProgress();
        $runner = new ProcessCommandRunner();
        $synchronizer = new MergeRequestSynchronizer(
            $client,
            new BranchPublisher(
                new Git($runner, $projectDir, new GitAccess($config->token)),
                $config->defaultBranch,
                $identity,
                $remote,
            ),
            new ProjectPlanApplier(
                $runner,
                $projectDir,
                $trivyBin,
                TrivyScanner::forRescan($trivyArg),
                $tools->composer,
                $tools->yarn,
                $tools->node,
                $verifyMode,
                $progress,
            ),
            new MergeRequestRenderer($translator, Application::VERSION, $mentions),
            new NoteRenderer($translator),
            new SyncSettings(
                $config->defaultBranch,
                [] === $label ? ['security'] : $label,
                $severityLabelFormat,
                $assignee,
                $reviewer,
                $maxOpenMergeRequests,
                $closedGraceDays,
            ),
            $progress,
        );

        $outcomes = [];

        foreach ($plans as $plan) {
            $io->section($plan->branchName());
            $outcome = $synchronizer->sync($plan, $parsedReport);
            $outcomes[] = $outcome;

            $io->writeln(
                $outcome->isFailure()
                    ? '<error>' . $outcome->branch . ': failed</error>'
                    : '  ' . RunSummary::line($outcome),
            );

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
            default => $io->success(
                array_all($outcomes, static fn(PlanOutcome $o): bool => PlanAction::Clear === $o->action)
                    ? 'Nothing to fix: no merge request to open.'
                    : 'Done.',
            ),
        };

        return $exitCode;
    }

    /**
     * @param list<string>        $mentions usernames mentioned in the descriptions
     * @param list<SeverityGroup> $groups   every group when empty
     */
    private function preview(
        SymfonyStyle $io,
        TranslatorInterface $translator,
        array $mentions,
        string $report,
        array $groups,
        ?Ecosystem $wanted,
        ?string $reportFile,
    ): int {
        try {
            $parsedReport = new TrivyReportParser()->parseFile($report);
        } catch (InvalidTrivyReportException $invalidTrivyReportException) {
            $io->error($invalidTrivyReportException->getMessage());

            return Command::FAILURE;
        }

        $plans = new Planner()->plan($parsedReport, [] === $groups ? null : $groups, $wanted);
        if ([] === $plans) {
            $io->success('Nothing to fix: no merge request would be opened.');

            return Command::SUCCESS;
        }

        $renderer = new MergeRequestRenderer($translator, Application::VERSION, $mentions);
        $files = [];

        foreach ($plans as $plan) {
            // A preview does not run the fixes: nothing is verified, and a group with no fixable package
            // would not open a merge request (the manual items stay in the job report).
            $content = $renderer->render($plan);

            $io->section($plan->branchName());
            if (0 === $content->fixedPackages) {
                $io->writeln(
                    'No package is fixable automatically: no merge request would be opened. Items for the job report:',
                );
            } else {
                $io->definitionList(
                    ['Branch' => $plan->branchName()],
                    ['Title' => $content->title],
                    ['Highest severity' => $content->severity->value ?? '-'],
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
}
