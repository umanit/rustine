<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
use Rustine\Apply\ProjectPlanApplier;
use Rustine\Apply\ToolingException;
use Rustine\Apply\ToolVersions;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Tests\Apply\Support\FakeRunner;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Trivy\Ecosystem;

final class ProjectPlanApplierTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-project-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/yarn.lock');
        @rmdir($this->dir);
    }

    #[Test]
    public function theYarnStrategyComesFromTheLockfileWithoutRunningAnything(): void
    {
        $runner = new FakeRunner();
        $applier = new ProjectPlanApplier($runner, $this->dir);

        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        self::assertInstanceOf(YarnBerryStrategy::class, $applier->strategy(Ecosystem::Yarn));

        file_put_contents($this->dir . '/yarn.lock', "# yarn lockfile v1\n");
        self::assertInstanceOf(YarnClassicStrategy::class, $applier->strategy(Ecosystem::Yarn));

        self::assertSame([], $runner->commands);
    }

    #[Test]
    public function aYarnProjectWithoutLockfileIsRefused(): void
    {
        $this->expectException(UnsupportedProjectException::class);

        (new ProjectPlanApplier(new FakeRunner(), $this->dir))->strategy(Ecosystem::Yarn);
    }

    #[Test]
    public function aBerryProjectOnYarn4StopsAtTheLockfile(): void
    {
        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        $fix = Fixtures::fix();
        $applier = new ProjectPlanApplier(new FakeRunner(), $this->dir);

        $withYarn4 = $applier->strategy(Ecosystem::Yarn, new ToolVersions(['yarn' => '4.14.1']))->attempts($fix, $this->dir);
        $withYarn3 = $applier->strategy(Ecosystem::Yarn, new ToolVersions(['yarn' => '3.8.0']))->attempts($fix, $this->dir);
        $unknown = $applier->strategy(Ecosystem::Yarn)->attempts($fix, $this->dir);

        self::assertSame(['yarn', 'up', '-R', 'ckeditor4', '--mode=update-lockfile'], $withYarn4[0]->commands[0]);
        self::assertSame(['yarn', 'up', '-R', 'ckeditor4'], $withYarn3[0]->commands[0]);
        self::assertSame(['yarn', 'up', '-R', 'ckeditor4'], $unknown[0]->commands[0]);
    }

    #[Test]
    public function nothingIsAttemptedWhenAToolIsMissing(): void
    {
        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        $runner = new FakeRunner();
        $runner->handler = static fn (): CommandResult => new CommandResult(127, "not found\n");

        try {
            (new ProjectPlanApplier($runner, $this->dir))->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
            self::fail('A missing tool must stop the run.');
        } catch (ToolingException $e) {
            self::assertStringContainsString('Rustine installs no tool', $e->getMessage());
        }

        self::assertSame([['node', '--version']], $runner->commands, 'Nothing is installed, nothing else is run.');
    }
}
