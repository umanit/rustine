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
        array_map('unlink', glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function itBuildsACommandThatListsAllPackages(): void
    {
        $command = (new TrivyScanner('/opt/trivy'))->buildCommand('/tmp/out.json', ['--skip-db-update', '--ignorefile', 'x']);

        self::assertSame(
            ['/opt/trivy', 'fs', '--no-progress', '--scanners', 'vuln', '--list-all-pkgs', '--format', 'json', '--output', '/tmp/out.json', '--skip-db-update', '--ignorefile', 'x', '.'],
            $command,
        );
    }

    #[Test]
    public function itWritesTheReportProducedByTrivy(): void
    {
        $output = $this->dir . '/report.json';

        (new TrivyScanner(__DIR__ . '/../fixtures/bin/fake-trivy'))->scan($this->dir, $output);

        self::assertFileExists($output);
        self::assertJson((string) file_get_contents($output));
    }

    #[Test]
    public function itFailsWhenTrivyFails(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Trivy failed');

        (new TrivyScanner('false'))->scan($this->dir, $this->dir . '/report.json');
    }

    #[Test]
    public function itFailsOnAMissingProjectDirectory(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new TrivyScanner())->scan($this->dir . '/nope', $this->dir . '/report.json');
    }
}
