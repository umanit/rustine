<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Application;
use Rustine\Command\ConfigFile;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class CommandsTest extends TestCase
{
    private const string REPORT = __DIR__ . '/../fixtures/trivy/yarn-report.json';

    private const array GITLAB = [
        '--gitlab-url'     => 'https://gitlab.example.com/api/v4',
        '--project-id'     => '42',
        '--default-branch' => 'main',
    ];

    /** @var list<string> */
    private array $dirs = [];

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }

        foreach ($this->dirs as $dir) {
            @unlink($dir . '/yarn.lock');
            @unlink($dir . '/composer.json');
            @rmdir($dir);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, string|null>, string|null}>
     */
    public static function branchSources(): iterable
    {
        yield '--branch' => [['--branch' => 'release/1.x'], [], null];
        yield '--default-branch alias' => [['--default-branch' => 'release/1.x'], [], null];
        yield 'RUSTINE_BRANCH' => [[], ['RUSTINE_BRANCH' => 'release/1.x'], null];
        yield 'branch key of the file' => [[], [], "branch: release/1.x\n"];
        yield 'CI_DEFAULT_BRANCH' => [[], ['CI_DEFAULT_BRANCH' => 'main'], null];
    }

    #[Test]
    public function planPrintsTheTextPlan(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('brace-expansion@1.1.12 → 1.1.21', $tester->getDisplay());
    }

    #[Test]
    public function planFiltersByGroup(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--group' => ['others']]);

        $this->assertStringContainsString('security/rustine-yarn-others', $tester->getDisplay());
        $this->assertStringNotContainsString('critical-high', $tester->getDisplay());
    }

    #[Test]
    public function planPrintsJson(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--format' => 'json']);

        $this->assertJson($tester->getDisplay());
        $this->assertStringContainsString('"branch": "security/rustine-yarn"', $tester->getDisplay());
        $this->assertStringContainsString('"group": null', $tester->getDisplay());
    }

    #[Test]
    public function planRejectsUnknownGroups(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--group' => ['nope']]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function planRejectsUnknownFormats(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--format' => 'xml']);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function planFailsOnAMissingReport(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => '/nonexistent.json']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function scanWritesTheReport(): void
    {
        $output = sys_get_temp_dir() . '/rustine-scan-' . bin2hex(random_bytes(4)) . '.json';

        $tester = $this->tester('scan');
        $tester->execute([
            '--project'   => sys_get_temp_dir(),
            '--output'    => $output,
            '--trivy-bin' => __DIR__ . '/../fixtures/bin/fake-trivy',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertFileExists($output);
        unlink($output);
    }

    #[Test]
    public function scanLeavesTheDevelopmentDependenciesOutUnlessAsked(): void
    {
        $this->assertNotContains('--include-dev-deps', $this->scanArguments([]));
        $this->assertContains('--include-dev-deps', $this->scanArguments(['--include-dev' => true]));
        $this->assertContains(
            '--include-dev-deps',
            $this->scanArguments(['--trivy-arg' => ['--severity=HIGH']], ['RUSTINE_INCLUDE_DEV' => '1']),
        );
        $this->assertContains(
            '--severity=HIGH',
            $this->scanArguments(['--include-dev' => true, '--trivy-arg' => ['--severity=HIGH']]),
        );
    }

    #[Test]
    public function scanFailsWhenTrivyFails(): void
    {
        $tester = $this->tester('scan');
        $tester->execute(['--trivy-bin' => 'false', '--output' => sys_get_temp_dir() . '/never.json']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function applyDryRunPrintsTheAttemptsWithoutRunningAnything(): void
    {
        $dir = $this->projectWithLockfile("__metadata:\n  version: 8\n");

        $tester = $this->tester('apply');
        $tester->execute(
            ['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('Dry run for security/rustine-yarn-others', $tester->getDisplay());
        $this->assertStringContainsString('[lockfile-refresh] yarn up -R', $tester->getDisplay());
        $this->assertStringContainsString('resolutions[', $tester->getDisplay());
    }

    #[Test]
    public function applyRequiresExactlyOneGroup(): void
    {
        $tester = $this->tester('apply');
        $tester->execute(['--report' => self::REPORT, '--dry-run' => true]);

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function applyDryRunUsesTheClassicLadderForYarnClassic(): void
    {
        $dir = $this->projectWithLockfile("# yarn lockfile v1\n");

        $tester = $this->tester('apply');
        $tester->execute(
            ['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('yarn.lock: remove the entries of', $tester->getDisplay());
        $this->assertStringContainsString('resolutions["**/', $tester->getDisplay());
    }

    #[Test]
    public function applyFailsWithoutAYarnLockfile(): void
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $tester = $this->tester('apply');
        $tester->execute(
            ['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true],
        );
        rmdir($dir);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('No readable yarn.lock', $tester->getDisplay());
    }

    #[Test]
    public function applyDryRunWorksOnAPhpOnlyProject(): void
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/composer.json', '{"require":{"symfony/http-foundation":"^6.4"}}');
        $this->dirs[] = $dir;

        $tester = $this->tester('apply');
        $tester->execute([
            '--project' => $dir,
            '--report'  => __DIR__ . '/../fixtures/trivy/composer-report.json',
            '--group'   => ['critical-high'],
            '--dry-run' => true,
        ]);
        @unlink($dir . '/composer.json');

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString(
            'composer update symfony/http-foundation --with-all-dependencies',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('composer require symfony/http-foundation:^6.4.41', $tester->getDisplay());
        $this->assertStringContainsString(
            'spatie/browsershot@3.61.0: not fixable automatically',
            $tester->getDisplay(),
        );
    }

    #[Test]
    public function applyRunsComposerThroughTheConfiguredCommand(): void
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/composer.json', '{"require":{"symfony/http-foundation":"^6.4"}}');
        $this->dirs[] = $dir;
        $arguments = [
            '--project' => $dir,
            '--report'  => __DIR__ . '/../fixtures/trivy/composer-report.json',
            '--group'   => ['critical-high'],
            '--dry-run' => true,
        ];

        $viaOption = $this->tester('apply');
        $viaOption->execute([...$arguments, '--composer-bin' => '/usr/bin/php8.1 /usr/local/bin/composer2']);

        $viaEnvironment = $this->tester('apply');
        $this->withEnvironment(
            ['RUSTINE_COMPOSER_BIN' => '/opt/composer'],
            static fn(): int => $viaEnvironment->execute($arguments),
        );

        $this->assertStringContainsString(
            '/usr/bin/php8.1 /usr/local/bin/composer2 update symfony/http-foundation',
            $viaOption->getDisplay(),
        );
        $this->assertStringContainsString(
            '/opt/composer update symfony/http-foundation',
            $viaEnvironment->getDisplay(),
        );
        $this->assertStringNotContainsString('[lockfile-refresh] composer update', $viaOption->getDisplay());
    }

    #[Test]
    public function applyRunsYarnThroughTheConfiguredCommand(): void
    {
        $arguments = [
            '--project' => $this->projectWithLockfile("__metadata:\n  version: 8\n"),
            '--report'  => self::REPORT,
            '--group'   => ['others'],
            '--dry-run' => true,
        ];

        $viaOption = $this->tester('apply');
        $viaOption->execute(
            [
                ...$arguments,
                '--yarn-bin' => '/opt/node20/bin/node /opt/yarn/yarn-4.2.2.cjs',
                '--node-bin' => '/opt/node20/bin/node',
            ],
        );

        $viaEnvironment = $this->tester('apply');
        $this->withEnvironment(
            ['RUSTINE_YARN_BIN' => '/opt/yarn/bin/yarn'],
            static fn(): int => $viaEnvironment->execute($arguments),
        );

        $this->assertSame(Command::SUCCESS, $viaOption->getStatusCode());
        $this->assertStringContainsString(
            '[lockfile-refresh] /opt/node20/bin/node /opt/yarn/yarn-4.2.2.cjs up -R',
            $viaOption->getDisplay(),
        );
        $this->assertStringContainsString('[lockfile-refresh] /opt/yarn/bin/yarn up -R', $viaEnvironment->getDisplay());
    }

    #[Test]
    public function applyRejectsAnEmptyNodeCommand(): void
    {
        $tester = $this->tester('apply');
        $tester->execute(
            [
                '--project'  => $this->projectWithLockfile("__metadata:\n"),
                '--report'   => self::REPORT,
                '--group'    => ['others'],
                '--dry-run'  => true,
                '--node-bin' => ' ',
            ],
        );

        $this->assertNotSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('The Node command is empty.', $tester->getDisplay());
    }

    #[Test]
    public function applyRejectsAnUnknownEcosystem(): void
    {
        $tester = $this->tester('apply');
        $tester->execute(
            ['--report' => self::REPORT, '--group' => ['others'], '--ecosystem' => 'pip', '--dry-run' => true],
        );

        $this->assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function scanReadsTheConfigurationFileBetweenTheOptionsAndTheEnvironment(): void
    {
        $config = $this->configFile("include_dev: true\ntrivy_args:\n  - --severity=HIGH\n");

        $arguments = $this->scanArguments(['--config' => $config], ['RUSTINE_TRIVY_ARG' => '--skip-db-update']);
        $this->assertContains('--include-dev-deps', $arguments);
        $this->assertContains('--severity=HIGH', $arguments);
        $this->assertNotContains('--skip-db-update', $arguments);

        $arguments = $this->scanArguments(['--config' => $config, '--trivy-arg' => ['--skip-db-update']]);
        $this->assertContains('--skip-db-update', $arguments);
        $this->assertNotContains('--severity=HIGH', $arguments);

        $off = $this->configFile("include_dev: false\n");
        $this->assertNotContains(
            '--include-dev-deps',
            $this->scanArguments(['--config' => $off], ['RUSTINE_INCLUDE_DEV' => '1']),
        );
    }

    #[Test]
    public function theConfigurationFileCanBeGivenByAVariable(): void
    {
        $config = $this->configFile("trivy_args: [--severity=LOW]\n");

        $this->assertContains('--severity=LOW', $this->scanArguments([], ['RUSTINE_CONFIG' => $config]));
    }

    #[Test]
    public function runDryRunReadsTheLocaleOfTheConfigurationFile(): void
    {
        $config = $this->configFile("locale: fr\n");
        $arguments = ['--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true];

        $tester = $this->tester('run');
        $tester->execute([...$arguments, '--config' => $config]);
        $fromFile = $tester->getDisplay();

        $tester = $this->tester('run');
        $tester->execute([...$arguments, '--config' => $config, '--locale' => 'en']);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertNotSame($fromFile, $tester->getDisplay());
    }

    #[Test]
    public function aMissingOrInvalidConfigurationFileStopsTheRun(): void
    {
        $tester = $this->tester('run');
        $tester->execute(['--report' => self::REPORT, '--dry-run' => true, '--config' => '/nonexistent/rustine.yaml']);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());

        $tester = $this->tester('run');
        $tester->execute(
            ['--report' => self::REPORT, '--dry-run' => true, '--config' => $this->configFile("reviewers: [a]\n")],
        );

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('"reviewers"', $tester->getDisplay());
    }

    #[Test]
    public function runDryRunPrintsTheMergeRequestThatWouldBeOpened(): void
    {
        $tester = $this->tester('run');
        $tester->execute(
            [
                '--report'  => self::REPORT,
                '--group'   => ['critical-high'],
                '--dry-run' => true,
                '--project' => sys_get_temp_dir(),
            ],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('security/rustine-yarn-critical-high', $tester->getDisplay());
        $this->assertStringContainsString(
            'Security: fix Yarn dependencies (critical and high severity)',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('build(deps): fix vulnerable Yarn dependencies', $tester->getDisplay());
        $this->assertStringContainsString('rustine:v1 fingerprint=', $tester->getDisplay());
    }

    #[Test]
    public function runWithoutGroupPutsEveryGroupInASingleMergeRequest(): void
    {
        $tester = $this->tester('run');
        $tester->execute(['--report' => self::REPORT, '--dry-run' => true, '--project' => sys_get_temp_dir()]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('security/rustine-yarn', $tester->getDisplay());
        $this->assertStringNotContainsString('rustine-yarn-', $tester->getDisplay());
        $this->assertStringContainsString('Security: fix Yarn dependencies', $tester->getDisplay());
        $this->assertStringNotContainsString('critical and high severity', $tester->getDisplay());
        $this->assertStringContainsString('build(deps): fix vulnerable Yarn dependencies', $tester->getDisplay());
        $this->assertStringContainsString('braces', $tester->getDisplay());
        $this->assertStringContainsString('ckeditor4', $tester->getDisplay());
    }

    #[Test]
    public function runAcceptsSeveralGroups(): void
    {
        $tester = $this->tester('run');
        $tester->execute(
            [
                '--report'  => self::REPORT,
                '--group'   => ['others', 'critical-high'],
                '--dry-run' => true,
                '--project' => sys_get_temp_dir(),
            ],
        );

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString('security/rustine-yarn-critical-high', $tester->getDisplay());
        $this->assertStringContainsString('security/rustine-yarn-others', $tester->getDisplay());
    }

    #[Test]
    public function runUsesTheRequestedLocaleAndWritesTheReportFile(): void
    {
        $file = sys_get_temp_dir() . '/rustine-report-' . bin2hex(random_bytes(4)) . '.md';

        $tester = $this->tester('run');
        $tester->execute([
            '--report'      => self::REPORT,
            '--group'       => ['others'],
            '--locale'      => 'fr',
            '--report-file' => $file,
            '--dry-run'     => true,
            '--project'     => sys_get_temp_dir(),
        ]);
        $written = (string) file_get_contents($file);
        @unlink($file);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        $this->assertStringContainsString(
            'build(deps): corriger les dépendances Yarn vulnérables',
            $tester->getDisplay(),
        );
        $this->assertStringContainsString('## 🔧 Corrections', $written);
        $this->assertStringStartsWith('<!-- security/rustine-yarn-others -->', $written);
    }

    #[Test]
    public function runFailsWhenTheReportFileCannotBeWritten(): void
    {
        $tester = $this->tester('run');
        $tester->execute([
            '--report'      => self::REPORT,
            '--group'       => ['others'],
            '--report-file' => sys_get_temp_dir() . '/rustine-missing-' . bin2hex(random_bytes(4)) . '/report.md',
            '--dry-run'     => true,
            '--project'     => sys_get_temp_dir(),
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('Unable to write the report', $tester->getDisplay());
    }

    #[Test]
    public function scanReadsTheTrivyArgumentsFromTheEnvironment(): void
    {
        $arguments = $this->scanArguments([], ['RUSTINE_TRIVY_ARG' => '--severity=HIGH,CRITICAL  --skip-db-update']);

        $this->assertContains('--severity=HIGH,CRITICAL', $arguments);
        $this->assertContains('--skip-db-update', $arguments);
    }

    #[Test]
    public function runReadsTheGroupsFromTheEnvironment(): void
    {
        $this->withEnvironment(['RUSTINE_GROUP' => 'others, critical-high'], function (): void {
            $tester = $this->tester('run');
            $tester->execute(['--report' => self::REPORT, '--dry-run' => true, '--project' => sys_get_temp_dir()]);

            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
            $this->assertStringContainsString('security/rustine-yarn-critical-high', $tester->getDisplay());
            $this->assertStringContainsString('security/rustine-yarn-others', $tester->getDisplay());
        });
    }

    #[Test]
    public function runOptionsWinOverTheEnvironment(): void
    {
        $this->withEnvironment(['RUSTINE_GROUP' => 'others'], function (): void {
            $tester = $this->tester('run');
            $tester->execute(
                [
                    '--report'  => self::REPORT,
                    '--group'   => ['critical-high'],
                    '--dry-run' => true,
                    '--project' => sys_get_temp_dir(),
                ],
            );

            $this->assertStringContainsString('security/rustine-yarn-critical-high', $tester->getDisplay());
            $this->assertStringNotContainsString('rustine-yarn-others', $tester->getDisplay());
        });
    }

    #[Test]
    public function runRejectsAnInvalidIntegerVariable(): void
    {
        $this->withEnvironment(['RUSTINE_CLOSED_GRACE_DAYS' => 'soon'], function (): void {
            $tester = $this->tester('run');
            $tester->execute(['--report' => self::REPORT, '--dry-run' => true, '--project' => sys_get_temp_dir()]);

            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertStringContainsString('RUSTINE_CLOSED_GRACE_DAYS', $tester->getDisplay());
        });
    }

    #[Test]
    public function runRejectsAnUnknownEcosystemFromTheEnvironment(): void
    {
        $this->withEnvironment(['RUSTINE_ECOSYSTEM' => 'pip'], function (): void {
            $tester = $this->tester('run');
            $tester->execute(['--report' => self::REPORT, '--dry-run' => true, '--project' => sys_get_temp_dir()]);

            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertStringContainsString('Unknown ecosystem "pip"', $tester->getDisplay());
        });
    }

    #[Test]
    public function runRejectsAnUnknownLocale(): void
    {
        // Exit code 2 means "manual action required" for `run`: a usage error must be a plain failure (1).
        $tester = $this->tester('run');
        $tester->execute([
            '--report'  => self::REPORT,
            '--group'   => ['others'],
            '--locale'  => 'xx',
            '--dry-run' => true,
        ]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        $this->assertStringContainsString('available: en, fr', $tester->getDisplay());
    }

    #[Test]
    public function runNeedsTheGitLabSettings(): void
    {
        $this->withEnvironment(
            [
                'GITLAB_TOKEN'         => null,
                'RUSTINE_GITLAB_TOKEN' => null,
                'CI_API_V4_URL'        => null,
                'CI_PROJECT_ID'        => null,
                'CI_DEFAULT_BRANCH'    => null,
            ],
            function (): void {
                $tester = $this->tester('run');
                $tester->execute(['--group' => ['others'], '--project' => sys_get_temp_dir()]);

                $this->assertSame(Command::FAILURE, $tester->getStatusCode());
                $this->assertStringContainsString('RUSTINE_GITLAB_TOKEN', $tester->getDisplay());
            },
        );
    }

    #[Test]
    public function runStopsWhenGitLabRejectsTheToken(): void
    {
        $http = new MockHttpClient(new MockResponse('{"message":"401 Unauthorized"}', ['http_code' => 401]));

        $this->withEnvironment(['GITLAB_TOKEN' => 'glpat-bad'], function () use ($http): void {
            $tester = $this->tester('run', $http);
            $tester->execute(['--group' => ['others'], '--project' => sys_get_temp_dir(), ...self::GITLAB]);

            $this->assertSame(Command::FAILURE, $tester->getStatusCode());
            $this->assertStringContainsString('Cannot authenticate to GitLab', $tester->getDisplay());
            $this->assertStringNotContainsString('glpat-bad', $tester->getDisplay());
        });
    }

    /**
     * @param array<string, mixed>       $options
     * @param array<string, string|null> $environment
     */
    #[Test]
    #[DataProvider('branchSources')]
    public function runKnowsTheBranchToAnalyzeFromEachSource(
        array $options,
        array $environment,
        ?string $configFile,
    ): void {
        $project = sys_get_temp_dir() . '/rustine-branch-' . bin2hex(random_bytes(4));
        mkdir($project);
        $this->dirs[] = $project;

        if (null !== $configFile) {
            file_put_contents($project . '/' . ConfigFile::DEFAULT_NAME, $configFile);
            $this->files[] = $project . '/' . ConfigFile::DEFAULT_NAME;
        }

        $trivy = $this->fakeTrivy(__DIR__ . '/../fixtures/trivy/yarn-nofix-report.json');
        $this->files[] = $project . '/trivy-report.json';
        $http = new MockHttpClient([
            new MockResponse('{"id":7,"username":"rustine-bot","name":"Rustine Bot","commit_email":null}'),
            new MockResponse('[]'),
            new MockResponse('[]'),
        ]);

        $this->withEnvironment(
            [
                'GITLAB_TOKEN'      => 'glpat-ok',
                'RUSTINE_BRANCH'    => null,
                'CI_DEFAULT_BRANCH' => null,
                ...$environment,
            ],
            function () use ($http, $trivy, $project, $options): void {
                $tester = $this->tester('run', $http);
                $tester->execute([
                    '--group'      => ['critical-high'],
                    '--project'    => $project,
                    '--report'     => $project . '/trivy-report.json',
                    '--trivy-bin'  => $trivy,
                    '--gitlab-url' => 'https://gitlab.example.com/api/v4',
                    '--project-id' => '42',
                    ...$options,
                ]);

                $this->assertSame(2, $tester->getStatusCode(), $tester->getDisplay());
            },
        );
    }

    #[Test]
    public function runNamesTheBranchAfterTheAnalyzedBranchOnlyWhenItIsNotTheDefaultOne(): void
    {
        foreach (['release/1.x' => '-release-1-x', 'main' => ''] as $branch => $suffix) {
            $project = sys_get_temp_dir() . '/rustine-slug-' . bin2hex(random_bytes(4));
            mkdir($project);
            $this->dirs[] = $project;
            $this->files[] = $project . '/trivy-report.json';
            $trivy = $this->fakeTrivy(__DIR__ . '/../fixtures/trivy/yarn-nofix-report.json');
            $http = new MockHttpClient([
                new MockResponse('{"id":7,"username":"rustine-bot","name":"Rustine Bot","commit_email":null}'),
                new MockResponse('[]'),
                new MockResponse('[]'),
            ]);

            $this->withEnvironment(
                ['GITLAB_TOKEN' => 'glpat-ok', 'RUSTINE_BRANCH' => null, 'CI_DEFAULT_BRANCH' => 'main'],
                function () use ($http, $trivy, $project, $branch, $suffix): void {
                    $tester = $this->tester('run', $http);
                    $tester->execute([
                        '--group'      => ['critical-high'],
                        '--project'    => $project,
                        '--report'     => $project . '/trivy-report.json',
                        '--trivy-bin'  => $trivy,
                        '--gitlab-url' => 'https://gitlab.example.com/api/v4',
                        '--project-id' => '42',
                        '--branch'     => $branch,
                    ]);

                    $this->assertStringContainsString(
                        'security/rustine-yarn-critical-high' . $suffix . ': nothing fixable',
                        $tester->getDisplay(),
                    );
                },
            );
        }
    }

    #[Test]
    public function runClosesTheOpenMergeRequestsWhenTheScanComesBackClean(): void
    {
        $project = sys_get_temp_dir() . '/rustine-clean-' . bin2hex(random_bytes(4));
        mkdir($project);
        $this->dirs[] = $project;
        $this->files[] = $project . '/trivy-report.json';
        $trivy = $this->fakeTrivy(__DIR__ . '/../fixtures/trivy/clean-report.json');

        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url) use (&$requests): MockResponse {
            $requests[] = $method . ' ' . $url;

            return match (true) {
                str_ends_with($url, '/user') => new MockResponse(
                    '{"id":7,"username":"rustine-bot","name":"Rustine Bot","commit_email":null}',
                ),
                'GET' === $method && str_ends_with($url, 'source_branch=security/rustine-yarn') => new MockResponse(
                    '[{"iid":5,"title":"t","description":"d","web_url":"https://gitlab.example.com/mr/5",'
                    . '"source_branch":"security/rustine-yarn","labels":[]}]',
                ),
                'GET' === $method => new MockResponse('[]'),
                default => new MockResponse('{}'),
            };
        });

        $this->withEnvironment(
            ['GITLAB_TOKEN' => 'glpat-ok', 'RUSTINE_BRANCH' => null, 'CI_DEFAULT_BRANCH' => null],
            function () use ($http, $trivy, $project): void {
                $tester = $this->tester('run', $http);
                $tester->execute([
                    '--project'    => $project,
                    '--report'     => $project . '/trivy-report.json',
                    '--trivy-bin'  => $trivy,
                    '--gitlab-url' => 'https://gitlab.example.com/api/v4',
                    '--project-id' => '42',
                    '--branch'     => 'main',
                ]);

                $this->assertSame(Command::SUCCESS, $tester->getStatusCode(), $tester->getDisplay());
                $this->assertStringContainsString('merge request closed', $tester->getDisplay());
                $this->assertStringContainsString('security/rustine-composer: nothing to fix', $tester->getDisplay());
            },
        );

        $this->assertContains('POST https://gitlab.example.com/api/v4/projects/42/merge_requests/5/notes', $requests);
        $this->assertContains('PUT https://gitlab.example.com/api/v4/projects/42/merge_requests/5', $requests);
    }

    #[Test]
    public function runAsksForTheBranchWhenNoSourceGivesIt(): void
    {
        $this->withEnvironment(
            [
                'GITLAB_TOKEN'      => 'glpat-ok',
                'RUSTINE_BRANCH'    => null,
                'CI_DEFAULT_BRANCH' => null,
            ],
            function (): void {
                $tester = $this->tester('run');
                $tester->execute([
                    '--group'      => ['others'],
                    '--project'    => sys_get_temp_dir(),
                    '--gitlab-url' => 'https://gitlab.example.com/api/v4',
                    '--project-id' => '42',
                ]);

                $this->assertSame(Command::FAILURE, $tester->getStatusCode());
                $this->assertStringContainsString('RUSTINE_BRANCH', $tester->getDisplay());
            },
        );
    }

    #[Test]
    public function runExitsWithTwoWhenOnlyManualItemsRemain(): void
    {
        $trivy = $this->fakeTrivy(__DIR__ . '/../fixtures/trivy/yarn-nofix-report.json');
        $reportFile = sys_get_temp_dir() . '/rustine-run-' . bin2hex(random_bytes(4)) . '.md';
        $trivyReport = sys_get_temp_dir() . '/rustine-run-trivy-' . bin2hex(random_bytes(4)) . '.json';
        $this->files = [...$this->files, $reportFile, $trivyReport];
        $http = new MockHttpClient([
            new MockResponse('{"id":7,"username":"rustine-bot","name":"Rustine Bot","commit_email":null}'),
            new MockResponse('[]'),
            new MockResponse('[]'),
        ]);

        $this->withEnvironment(
            ['GITLAB_TOKEN' => 'glpat-ok'],
            function () use ($http, $trivy, $reportFile, $trivyReport): void {
                $tester = $this->tester('run', $http);
                $tester->execute([
                    '--group'       => ['critical-high'],
                    '--project'     => sys_get_temp_dir(),
                    '--report'      => $trivyReport,
                    '--trivy-bin'   => $trivy,
                    '--report-file' => $reportFile,
                    ...self::GITLAB,
                ]);

                $this->assertSame(2, $tester->getStatusCode());
                $this->assertStringContainsString(
                    'commits authored by Rustine Bot <7-rustine-bot@users.noreply.gitlab.example.com>',
                    $tester->getDisplay(),
                );
                $this->assertStringContainsString(
                    'security/rustine-yarn-critical-high: nothing fixable automatically, no merge request'
                    . ' (manual action required)',
                    $tester->getDisplay(),
                );
            },
        );

        $written = (string) file_get_contents($reportFile);
        @unlink($reportFile);
        $this->assertStringContainsString('## ⚠️ Needs manual attention', $written);
        $this->assertStringContainsString('braces', $written);
    }

    #[Test]
    public function runSaysWhenNoMergeRequestWouldBeOpened(): void
    {
        $tester = $this->tester('run');
        $tester->execute([
            '--report'    => __DIR__ . '/../fixtures/trivy/composer-report.json',
            '--group'     => ['others'],
            '--ecosystem' => 'composer',
            '--dry-run'   => true,
            '--project'   => sys_get_temp_dir(),
        ]);

        $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    private function configFile(string $yaml): string
    {
        $path = sys_get_temp_dir() . '/rustine-config-' . bin2hex(random_bytes(4)) . '.yaml';
        file_put_contents($path, $yaml);
        $this->files[] = $path;

        return $path;
    }

    private function projectWithLockfile(string $yarnLock): string
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/yarn.lock', $yarnLock);
        $this->dirs[] = $dir;

        return $dir;
    }

    /**
     * Arguments `scan` gives to Trivy.
     *
     * @param array<string, mixed>       $options
     * @param array<string, string|null> $environment
     *
     * @return list<string>
     */
    private function scanArguments(array $options, array $environment = []): array
    {
        $record = sys_get_temp_dir() . '/rustine-trivy-args-' . bin2hex(random_bytes(4));
        $script = sys_get_temp_dir() . '/rustine-recording-trivy-' . bin2hex(random_bytes(4));
        file_put_contents(
            $script,
            "#!/bin/sh\nprintf '%s\\n' \"\$@\" > " . escapeshellarg(
                $record,
            ) . "\nwhile [ \$# -gt 0 ]; do\n  if [ \"\$1\" = \"--output\" ]; then out=\"\$2\"; fi\n"
            . "  shift\ndone\ncp " . escapeshellarg(__DIR__ . '/../fixtures/trivy/yarn-report.json')
            . " \"\$out\"\n",
        );
        chmod($script, 0755);
        $output = $record . '.json';
        $this->files = [...$this->files, $script, $record, $output];

        $this->withEnvironment($environment, function () use ($script, $output, $options): void {
            $tester = $this->tester('scan');
            $tester->execute(
                ['--project' => sys_get_temp_dir(), '--output' => $output, '--trivy-bin' => $script, ...$options],
            );
            $this->assertSame(Command::SUCCESS, $tester->getStatusCode());
        });

        return array_values(
            array_filter(explode("\n", (string) file_get_contents($record)), static fn(string $a): bool => '' !== $a),
        );
    }

    private function tester(string $command, ?HttpClientInterface $http = null): CommandTester
    {
        return new CommandTester(new Application($http)->find($command));
    }

    /**
     * A Trivy stand-in that "scans" by copying the given report.
     */
    private function fakeTrivy(string $report): string
    {
        $script = sys_get_temp_dir() . '/rustine-fake-trivy-' . bin2hex(random_bytes(4));
        file_put_contents(
            $script,
            "#!/bin/sh\nwhile [ \$# -gt 0 ]; do\n  if [ \"\$1\" = \"--output\" ]; then out=\"\$2\"; fi\n"
            . "  shift\ndone\ncp " . escapeshellarg($report) . " \"\$out\"\n",
        );
        chmod($script, 0755);
        $this->files[] = $script;

        return $script;
    }

    /**
     * @param array<string, string|null> $variables null unsets the variable
     */
    private function withEnvironment(array $variables, \Closure $test): void
    {
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = getenv($name);
            putenv(null === $value ? $name : $name . '=' . $value);
        }

        try {
            $test();
        } finally {
            foreach ($previous as $name => $value) {
                putenv(false === $value ? $name : $name . '=' . $value);
            }
        }
    }
}
