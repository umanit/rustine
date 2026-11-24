<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CommandsTest extends TestCase
{
    private const REPORT = __DIR__ . '/../fixtures/trivy/yarn-report.json';

    /** @var list<string> */
    private array $dirs = [];

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

        self::assertStringContainsString('security/yarn/others', $tester->getDisplay());
        self::assertStringNotContainsString('critical-high', $tester->getDisplay());
    }

    #[Test]
    public function planPrintsJson(): void
    {
        $tester = $this->tester('plan');
        $tester->execute(['--report' => self::REPORT, '--format' => 'json']);

        self::assertJson($tester->getDisplay());
        self::assertStringContainsString('"branch": "security/yarn/critical-high"', $tester->getDisplay());
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
        self::assertStringContainsString('Dry run for security/yarn/others', $tester->getDisplay());
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
    public function applyRefusesYarnClassic(): void
    {
        $dir = $this->projectWithPinning("[tools]\nyarn = \"1.22.19\"\n");

        $tester = $this->tester('apply');
        $tester->execute(['--project' => $dir, '--report' => self::REPORT, '--group' => ['others'], '--dry-run' => true]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Yarn Classic is not supported yet', $tester->getDisplay());
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
        foreach ($this->dirs as $dir) {
            @unlink($dir . '/mise.toml');
            @rmdir($dir);
        }
    }

    private function tester(string $command): CommandTester
    {
        return new CommandTester((new Application())->find($command));
    }
}
