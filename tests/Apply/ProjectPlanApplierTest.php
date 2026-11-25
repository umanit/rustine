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
        @unlink($this->dir . '/composer.json');
        @rmdir($this->dir);
    }

    #[Test]
    public function theYarnStrategyComesFromTheLockfileWithoutRunningAnything(): void
    {
        $runner = new FakeRunner();
        $applier = new ProjectPlanApplier($runner, $this->dir);

        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        $this->assertInstanceOf(YarnBerryStrategy::class, $applier->strategy(Ecosystem::Yarn));

        file_put_contents($this->dir . '/yarn.lock', "# yarn lockfile v1\n");
        $this->assertInstanceOf(YarnClassicStrategy::class, $applier->strategy(Ecosystem::Yarn));

        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function aYarnProjectWithoutLockfileIsRefused(): void
    {
        $this->expectException(UnsupportedProjectException::class);

        new ProjectPlanApplier(new FakeRunner(), $this->dir)->strategy(Ecosystem::Yarn);
    }

    #[Test]
    public function aBerryProjectOnYarn4StopsAtTheLockfile(): void
    {
        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        $fix = Fixtures::fix();
        $applier = new ProjectPlanApplier(new FakeRunner(), $this->dir);

        $withYarn4 = $applier->strategy(Ecosystem::Yarn, new ToolVersions(['yarn' => '4.14.1']))->attempts(
            $fix,
            $this->dir,
        );
        $withYarn3 = $applier->strategy(Ecosystem::Yarn, new ToolVersions(['yarn' => '3.8.0']))->attempts(
            $fix,
            $this->dir,
        );
        $unknown = $applier->strategy(Ecosystem::Yarn)->attempts($fix, $this->dir);

        $this->assertSame(['yarn', 'up', '-R', 'ckeditor4', '--mode=update-lockfile'], $withYarn4[0]->commands[0]);
        $this->assertSame(['yarn', 'up', '-R', 'ckeditor4'], $withYarn3[0]->commands[0]);
        $this->assertSame(['yarn', 'up', '-R', 'ckeditor4'], $unknown[0]->commands[0]);
    }

    #[Test]
    public function aSymfonyRequirementNeedsFlexToBeInstalled(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"symfony":{"require":"6.4.*"}}}');
        $runner = $this->composerRunner(globalFlex: false);

        try {
            new ProjectPlanApplier($runner, $this->dir)->tools(Ecosystem::Composer);
            self::fail('Without Flex the requirement would be ignored: Rustine must stop.');
        } catch (ToolingException $toolingException) {
            $this->assertStringContainsString('extra.symfony.require to "6.4.*"', $toolingException->getMessage());
            $this->assertStringContainsString('composer global require symfony/flex', $toolingException->getMessage());
        }

        $this->assertContains(
            'composer global show symfony/flex --no-ansi',
            array_map(static fn(array $c): string => implode(' ', $c), $runner->commands),
        );
    }

    #[Test]
    public function aGlobalFlexIsEnough(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"symfony":{"require":"6.4.*"}}}');

        $tools = new ProjectPlanApplier($this->composerRunner(globalFlex: true), $this->dir)->tools(
            Ecosystem::Composer,
        );

        $this->assertSame('2.8.1', $tools->version('composer'));
    }

    #[Test]
    public function aFlexInTheVendorDirectoryIsEnoughAndGlobalOnesAreNotAsked(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"symfony":{"require":"6.4.*"}}}');
        mkdir($this->dir . '/vendor/symfony/flex', 0o777, true);
        $runner = $this->composerRunner(globalFlex: false);

        try {
            $this->assertSame(
                '2.8.1',
                new ProjectPlanApplier($runner, $this->dir)->tools(Ecosystem::Composer)->version('composer'),
            );
            $this->assertNotContains(
                'global',
                array_map(static fn(array $c): string => $c[1] ?? '', $runner->commands),
            );
        } finally {
            rmdir($this->dir . '/vendor/symfony/flex');
            rmdir($this->dir . '/vendor/symfony');
            rmdir($this->dir . '/vendor');
        }
    }

    #[Test]
    public function aVendorDirectoryOtherThanVendorIsLookedAt(): void
    {
        file_put_contents(
            $this->dir . '/composer.json',
            '{"extra":{"symfony":{"require":"6.4.*"}},"config":{"vendor-dir":"lib"}}',
        );
        mkdir($this->dir . '/lib/symfony/flex', 0o777, true);

        try {
            $this->assertSame(
                '2.8.1',
                new ProjectPlanApplier($this->composerRunner(globalFlex: false), $this->dir)->tools(
                    Ecosystem::Composer,
                )->version('composer'),
            );
        } finally {
            rmdir($this->dir . '/lib/symfony/flex');
            rmdir($this->dir . '/lib/symfony');
            rmdir($this->dir . '/lib');
        }
    }

    #[Test]
    public function aProjectWithoutSymfonyRequirementDoesNotNeedFlex(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require":{"twig/twig":"^3.0"}}');
        $runner = $this->composerRunner(globalFlex: false);

        $this->assertSame(
            '2.8.1',
            new ProjectPlanApplier($runner, $this->dir)->tools(Ecosystem::Composer)->version('composer'),
        );
        $this->assertNotContains('global', array_map(static fn(array $c): string => $c[1] ?? '', $runner->commands));
    }

    #[Test]
    public function nothingIsAttemptedWhenAToolIsMissing(): void
    {
        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        $runner = new FakeRunner();
        $runner->handler = static fn(): CommandResult => new CommandResult(127, "not found\n");

        try {
            new ProjectPlanApplier($runner, $this->dir)->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
            self::fail('A missing tool must stop the run.');
        } catch (ToolingException $toolingException) {
            $this->assertStringContainsString('Rustine installs no tool', $toolingException->getMessage());
        }

        $this->assertSame([['node', '--version']], $runner->commands, 'Nothing is installed, nothing else is run.');
    }

    private function composerRunner(bool $globalFlex): FakeRunner
    {
        $runner = new FakeRunner();
        $runner->handler = static fn(array $command): CommandResult => 'global' === ($command[1] ?? null)
            ? new CommandResult($globalFlex ? 0 : 1, '')
            : new CommandResult(0, "Composer version 2.8.1 2024-10-04 PHP version 8.4.1\n");

        return $runner;
    }
}
