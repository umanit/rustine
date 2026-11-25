<?php

declare(strict_types=1);

namespace Rustine\Tests\Trivy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Trivy\TrivyScanner;

final class TrivyScannerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/scanner-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function itBuildsACommandThatListsAllPackages(): void
    {
        $command = new TrivyScanner('/opt/trivy')->buildCommand(
            '/tmp/out.json',
            ['--skip-db-update', '--ignorefile', 'x'],
        );

        $this->assertSame([
            '/opt/trivy',
            'fs',
            '--no-progress',
            '--disable-telemetry',
            '--skip-dirs',
            '**/vendor,**/node_modules',
            '--scanners',
            'vuln',
            '--list-all-pkgs',
            '--format',
            'json',
            '--output',
            '/tmp/out.json',
            '--skip-db-update',
            '--ignorefile',
            'x',
            '.',
        ], $command);
    }

    #[Test]
    public function developmentDependenciesAreOnlyScannedWhenAsked(): void
    {
        $this->assertSame(['--skip-db-update'], TrivyScanner::withDevDependencies(['--skip-db-update'], false));
        $this->assertSame(
            ['--skip-db-update', '--include-dev-deps'],
            TrivyScanner::withDevDependencies(['--skip-db-update'], true),
        );
        $this->assertSame(
            ['--include-dev-deps'],
            TrivyScanner::withDevDependencies(['--include-dev-deps'], true),
            'Never twice.',
        );
        $this->assertNotContains(
            '--include-dev-deps',
            new TrivyScanner()->buildCommand('/tmp/out.json'),
            'Opt-in: not by default.',
        );
    }

    #[Test]
    public function theRescansOfARunSkipTheDatabaseUpdate(): void
    {
        $this->assertSame(['--severity=HIGH', '--skip-db-update'], TrivyScanner::forRescan(['--severity=HIGH']));
        $this->assertSame(['--skip-db-update'], TrivyScanner::forRescan(['--skip-db-update']), 'Never twice.');
    }

    #[Test]
    public function itWritesTheReportProducedByTrivy(): void
    {
        $output = $this->dir . '/report.json';

        new TrivyScanner(__DIR__ . '/../fixtures/bin/fake-trivy')->scan($this->dir, $output);

        $this->assertFileExists($output);
        $this->assertJson((string) file_get_contents($output));
    }

    #[Test]
    public function itFailsWhenTrivyFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trivy failed');

        new TrivyScanner('false')->scan($this->dir, $this->dir . '/report.json');
    }

    #[Test]
    public function itFailsOnAMissingProjectDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new TrivyScanner()->scan($this->dir . '/nope', $this->dir . '/report.json');
    }
}
