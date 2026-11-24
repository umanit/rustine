<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
use Rustine\Apply\ToolingException;
use Rustine\Apply\ToolProbe;
use Rustine\Apply\YarnFlavor;
use Rustine\Tests\Apply\Support\FakeRunner;

final class ToolProbeTest extends TestCase
{
    /** @var array<string, string> */
    private array $outputs = [];

    #[Test]
    public function itReadsTheNodeAndYarnVersionsOfTheEnvironmentWithoutWrappingThem(): void
    {
        $runner = $this->runner(['node' => "v22.11.0\n", 'yarn' => "4.2.2\n"]);

        $tools = (new ToolProbe($runner, '/p'))->yarn(YarnFlavor::Berry);

        self::assertSame(['node' => '22.11.0', 'yarn' => '4.2.2'], $tools->versions);
        self::assertSame('node 22.11.0, yarn 4.2.2', $tools->describe());
        self::assertSame([['node', '--version'], ['yarn', '--version']], $runner->commands);
    }

    #[Test]
    public function aMissingToolStopsWithAnExplicitError(): void
    {
        $runner = $this->runner(['node' => "v22.11.0\n"]);

        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('Yarn is not available: `yarn --version` failed (exit 127): sh: yarn: not found. Rustine installs no tool');

        (new ToolProbe($runner, '/p'))->yarn(YarnFlavor::Berry);
    }

    #[Test]
    public function nodeIsCheckedBeforeYarn(): void
    {
        $runner = $this->runner(['yarn' => "1.22.19\n"]);

        try {
            (new ToolProbe($runner, '/p'))->yarn(YarnFlavor::Classic);
            self::fail('A missing Node must stop the run.');
        } catch (ToolingException $e) {
            self::assertStringStartsWith('Node is not available', $e->getMessage());
        }
        self::assertSame([['node', '--version']], $runner->commands);
    }

    #[Test]
    public function aYarnThatDoesNotMatchTheLockfileIsRefused(): void
    {
        $runner = $this->runner(['node' => "v20.11.0\n", 'yarn' => "1.22.19\n"]);

        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('yarn.lock is a Yarn Berry (2+) lockfile but the environment provides Yarn 1.22.19');

        (new ToolProbe($runner, '/p'))->yarn(YarnFlavor::Berry);
    }

    #[Test]
    public function itReadsTheComposerAndPhpVersionsThroughTheConfiguredCommand(): void
    {
        $runner = $this->runner(['/usr/bin/php8.1' => "Composer version 2.8.3 2024-11-17 13:13:04\nPHP version 8.1.31 (/usr/bin/php8.1)\n"]);

        $tools = (new ToolProbe($runner, '/p'))->composer(['/usr/bin/php8.1', '/usr/local/bin/composer2']);

        self::assertSame(['composer' => '2.8.3', 'php' => '8.1.31'], $tools->versions);
        self::assertSame([['/usr/bin/php8.1', '/usr/local/bin/composer2', '--version', '--no-ansi']], $runner->commands);
    }

    #[Test]
    public function aMissingComposerStopsWithAnExplicitError(): void
    {
        $this->expectException(ToolingException::class);
        $this->expectExceptionMessage('Composer is not available: `composer --version --no-ansi` failed (exit 127)');

        (new ToolProbe($this->runner([]), '/p'))->composer(['composer']);
    }

    /**
     * @param array<string, string> $outputs output per program; any other program is "not found"
     */
    private function runner(array $outputs): FakeRunner
    {
        $this->outputs = $outputs;
        $runner = new FakeRunner();
        $runner->handler = $this->respond(...);

        return $runner;
    }

    /**
     * @param list<string> $command
     */
    private function respond(array $command): CommandResult
    {
        $program = $command[0];

        return isset($this->outputs[$program])
            ? new CommandResult(0, $this->outputs[$program])
            : new CommandResult(127, sprintf("sh: %s: not found\n", $program));
    }
}
