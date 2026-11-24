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
    private const REPORT = __DIR__ . '/../fixtures/trivy/yarn-report.json';
    private const GITLAB = ['--gitlab-url' => 'https://gitlab.example.com/api/v4', '--project-id' => '42', '--default-branch' => 'main'];

    /** @var list<string> */
    private array $dirs = [];
    /** @var list<string> */
    private array $files = [];

    #[Test]
    public function planPrintsTheTextPlan(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('brace-expansion@1.1.12 → 1.1.21', $tester->getDisplay());
    }

    #[Test]
    public function planFiltersByGroup(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--group' => ['others']]);

        self::assertStringContainsString('security/rustine-yarn-others', $tester->getDisplay());
        self::assertStringNotContainsString('critical-high', $tester->getDisplay());
    }

    #[Test]
    public function planPrintsJson(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--format' => 'json']);

        self::assertJson($tester->getDisplay());
        self::assertStringContainsString('"branch": "security/rustine-yarn-critical-high"', $tester->getDisplay());
    }

    #[Test]
    public function planRejectsUnknownGroups(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--group' => ['nope']]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function planRejectsUnknownFormats(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--format' => 'xml']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function planFailsOnAMissingReport(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => '/nonexistent.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function scanWritesTheReport(): void
    {
        $output = sys_get_temp_dir() . '/rustine-scan-' . bin2hex(random_bytes(4)) . '.json';

        $tester = $this->tester('scan');
        $tester->execute([
            '--project' => sys_get_temp_dir(),
            '--output' => $output,
            '--trivy-bin' => __DIR__ . '/../fixtures/bin/fake-trivy',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($output);
        unlink($output);
    }

    #[Test]
    public function scanFailsWhenTrivyFails(): void
    {
        $tester = $this->tester('scan');
        $tester->execute(['--trivy-bin' => 'false', '--output' => sys_get_temp_dir() . '/never.json']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    #[Test]
    public function applyDryRunPrintsTheAttemptsWithoutRunningAnything(): void
    {
        $dir = $this->projectWithPinning("[tools]\nnode = \"22.11.0\"\nyarn = \"4.2.2\"\n");

        $tester = $this->tester('apply');
        $tester->execute(['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Dry run for security/rustine-yarn-others', $tester->getDisplay());
        self::assertStringContainsString('[lockfile-refresh] yarn up -R', $tester->getDisplay());
        self::assertStringContainsString('resolutions[', $tester->getDisplay());
    }

    #[Test]
    public function applyRequiresExactlyOneGroup(): void
    {
        $tester = $this->tester('apply');
        $tester->execute(['--report' => self::REPORT, '--dry-run' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function applyDryRunUsesTheClassicLadderForYarnClassic(): void
    {
        $dir = $this->projectWithPinning("[tools]\nyarn = \"1.22.19\"\n");

        $tester = $this->tester('apply');
        $tester->execute(['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('yarn.lock: remove the entries of', $tester->getDisplay());
        self::assertStringContainsString('resolutions["**/', $tester->getDisplay());
    }

    #[Test]
    public function applyFailsWithoutToolPinning(): void
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);

        $tester = $this->tester('apply');
        $tester->execute(['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true]);
        rmdir($dir);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No tool pinning found', $tester->getDisplay());
    }

    #[Test]
    public function applyDryRunWorksOnAPhpOnlyProjectWithoutPinning(): void
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/composer.json', '{"require":{"symfony/http-foundation":"^6.4"}}');
        $this->dirs[] = $dir;

        $tester = $this->tester('apply');
        $tester->execute([
            '--project' => $dir,
            '--report' => __DIR__ . '/../fixtures/trivy/composer-report.json',
            '--group' => ['critical-high'],
            '--dry-run' => true,
        ]);
        @unlink($dir . '/composer.json');

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Tool pinning: none', $tester->getDisplay());
        self::assertStringContainsString('composer update symfony/http-foundation --with-all-dependencies', $tester->getDisplay());
        self::assertStringContainsString('composer require symfony/http-foundation:^6.4.41', $tester->getDisplay());
        self::assertStringContainsString('spatie/browsershot@3.61.0: not fixable automatically', $tester->getDisplay());
    }

    #[Test]
    public function applyRejectsAnUnknownEcosystem(): void
    {
        $tester = $this->tester('apply');
        $tester->execute(['--report' => self::REPORT, '--group' => ['others'], '--ecosystem' => 'pip', '--dry-run' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
    }

    #[Test]
    public function runDryRunPrintsTheMergeRequestThatWouldBeOpened(): void
    {
        $tester = $this->tester('run');
        $tester->execute(['--report' => self::REPORT, '--group' => ['critical-high'], '--dry-run' => true, '--project' => sys_get_temp_dir()]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('security/rustine-yarn-critical-high', $tester->getDisplay());
        self::assertStringContainsString('Security: fix Yarn dependencies (critical and high severity)', $tester->getDisplay());
        self::assertStringContainsString('build(deps): fix vulnerable Yarn dependencies', $tester->getDisplay());
        self::assertStringContainsString('rustine:v1 fingerprint=', $tester->getDisplay());
    }

    #[Test]
    public function runUsesTheRequestedLocaleAndWritesTheReportFile(): void
    {
        $file = sys_get_temp_dir() . '/rustine-report-' . bin2hex(random_bytes(4)) . '.md';

        $tester = $this->tester('run');
        $tester->execute([
            '--report' => self::REPORT,
            '--group' => ['others'],
            '--locale' => 'fr',
            '--report-file' => $file,
            '--dry-run' => true,
            '--project' => sys_get_temp_dir(),
        ]);
        $written = (string) file_get_contents($file);
        @unlink($file);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('build(deps): corriger les dépendances Yarn vulnérables', $tester->getDisplay());
        self::assertStringContainsString('## Corrections', $written);
        self::assertStringStartsWith('<!-- security/rustine-yarn-others -->', $written);
    }

    #[Test]
    public function runRejectsAnUnknownLocale(): void
    {
        $tester = $this->tester('run');
        $tester->execute(['--report' => self::REPORT, '--group' => ['others'], '--locale' => 'xx', '--dry-run' => true]);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertStringContainsString('available: en, fr', $tester->getDisplay());
    }

    #[Test]
    public function runNeedsTheGitLabSettings(): void
    {
        $this->withEnvironment(['GITLAB_TOKEN' => null, 'RUSTINE_GITLAB_TOKEN' => null, 'CI_API_V4_URL' => null, 'CI_PROJECT_ID' => null, 'CI_DEFAULT_BRANCH' => null], function (): void {
            $tester = $this->tester('run');
            $tester->execute(['--group' => ['others'], '--project' => sys_get_temp_dir()]);

            self::assertSame(Command::INVALID, $tester->getStatusCode());
            self::assertStringContainsString('RUSTINE_GITLAB_TOKEN', $tester->getDisplay());
        });
    }

    #[Test]
    public function runStopsWhenGitLabRejectsTheToken(): void
    {
        $http = new MockHttpClient(new MockResponse('{"message":"401 Unauthorized"}', ['http_code' => 401]));

        $this->withEnvironment(['GITLAB_TOKEN' => 'glpat-bad'], function () use ($http): void {
            $tester = $this->tester('run', $http);
            $tester->execute(['--group' => ['others'], '--project' => sys_get_temp_dir(), ...self::GITLAB]);

            self::assertSame(Command::FAILURE, $tester->getStatusCode());
            self::assertStringContainsString('Cannot authenticate to GitLab', $tester->getDisplay());
            self::assertStringNotContainsString('glpat-bad', $tester->getDisplay());
        });
    }

    #[Test]
    public function runExitsWithTwoWhenOnlyManualItemsRemain(): void
    {
        $trivy = $this->fakeTrivy(__DIR__ . '/../fixtures/trivy/yarn-nofix-report.json');
        $reportFile = sys_get_temp_dir() . '/rustine-run-' . bin2hex(random_bytes(4)) . '.md';
        $http = new MockHttpClient([
            new MockResponse('{"id":7,"username":"rustine-bot","name":"Rustine Bot","commit_email":null}'),
            new MockResponse('[]'),
        ]);

        $this->withEnvironment(['GITLAB_TOKEN' => 'glpat-ok'], function () use ($http, $trivy, $reportFile): void {
            $tester = $this->tester('run', $http);
            $tester->execute([
                '--group' => ['critical-high'],
                '--project' => sys_get_temp_dir(),
                '--report' => sys_get_temp_dir() . '/rustine-run-trivy-' . bin2hex(random_bytes(4)) . '.json',
                '--trivy-bin' => $trivy,
                '--report-file' => $reportFile,
                ...self::GITLAB,
            ]);

            self::assertSame(2, $tester->getStatusCode());
            self::assertStringContainsString('commits authored by Rustine Bot <rustine-bot@noreply.gitlab.example.com>', $tester->getDisplay());
            self::assertStringContainsString('security/rustine-yarn-critical-high: nothing fixable automatically, no merge request (manual action required)', $tester->getDisplay());
        });

        $written = (string) file_get_contents($reportFile);
        @unlink($reportFile);
        self::assertStringContainsString('## Needs manual attention', $written);
        self::assertStringContainsString('braces', $written);
    }

    #[Test]
    public function runSaysWhenNoMergeRequestWouldBeOpened(): void
    {
        $tester = $this->tester('run');
        $tester->execute([
            '--report' => __DIR__ . '/../fixtures/trivy/composer-report.json',
            '--group' => ['others'],
            '--ecosystem' => 'composer',
            '--dry-run' => true,
            '--project' => sys_get_temp_dir(),
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
    }

    private function projectWithPinning(string $miseToml): string
    {
        $dir = sys_get_temp_dir() . '/rustine-apply-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/mise.toml', $miseToml);
        $this->dirs[] = $dir;

        return $dir;
    }

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/mise.toml');
            @unlink($dir . '/composer.json');
            @rmdir($dir);
        }
    }

    private function tester(string $command, ?HttpClientInterface $http = null): CommandTester
    {
        return new CommandTester((new Application($http))->find($command));
    }

    /**
     * A Trivy stand-in that "scans" by copying the given report.
     */
    private function fakeTrivy(string $report): string
    {
        $script = sys_get_temp_dir() . '/rustine-fake-trivy-' . bin2hex(random_bytes(4));
        file_put_contents($script, "#!/bin/sh\nwhile [ \$# -gt 0 ]; do\n  if [ \"\$1\" = \"--output\" ]; then out=\"\$2\"; fi\n  shift\ndone\ncp " . escapeshellarg($report) . " \"\$out\"\n");
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
