<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Application;
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
        $this->assertStringContainsString('"branch": "security/rustine-yarn-critical-high"', $tester->getDisplay());
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
