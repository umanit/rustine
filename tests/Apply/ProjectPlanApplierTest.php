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
        foreach (['yarn.lock', 'composer.json', 'composer.lock', 'package.json'] as $file) {
            @unlink($this->dir . '/' . $file);
        }

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
        file_put_contents(
            $this->dir . '/composer.json',
            '{"extra":{"symfony":{"require":"6.4.*"}},"config":{"allow-plugins":{"symfony/flex":true}}}',
        );
        $this->installFlex('vendor');
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
            $this->removeFlex('vendor');
        }
    }

    #[Test]
    public function aFlexThatComposerWouldBlockDoesNotCount(): void
    {
        // Installed, but `allow-plugins` does not list it: a non-interactive Composer would not load it.
        file_put_contents(
            $this->dir . '/composer.json',
            '{"extra":{"symfony":{"require":"6.4.*"}},"config":{"allow-plugins":{"other/plugin":true}}}',
        );
        $this->installFlex('vendor');

        try {
            new ProjectPlanApplier($this->composerRunner(globalFlex: false), $this->dir)->tools(Ecosystem::Composer);
            self::fail('A Flex that is not allowed would not run: Rustine must stop.');
        } catch (ToolingException $toolingException) {
            $this->assertStringContainsString('allow-plugins', $toolingException->getMessage());
        } finally {
            $this->removeFlex('vendor');
        }
    }

    #[Test]
    public function aVendorDirectoryWithoutAnInstalledFlexDoesNotCount(): void
    {
        file_put_contents(
            $this->dir . '/composer.json',
            '{"extra":{"symfony":{"require":"6.4.*"}},"config":{"allow-plugins":true}}',
        );
        mkdir($this->dir . '/vendor/symfony/flex', 0o777, true);

        try {
            new ProjectPlanApplier($this->composerRunner(globalFlex: false), $this->dir)->tools(Ecosystem::Composer);
            self::fail('Flex is not in installed.json: Rustine must stop.');
        } catch (ToolingException) {
            $this->addToAssertionCount(1);
        } finally {
            rmdir($this->dir . '/vendor/symfony/flex');
            rmdir($this->dir . '/vendor/symfony');
            rmdir($this->dir . '/vendor');
        }
    }

    #[Test]
    public function aGlobalFlexBlockedByTheGlobalConfigurationDoesNotCount(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"symfony":{"require":"6.4.*"}}}');

        $this->expectException(ToolingException::class);

        $runner = $this->composerRunner(globalFlex: true, globalAllowPlugins: '{"foo/bar": true}');
        new ProjectPlanApplier($runner, $this->dir)->tools(Ecosystem::Composer);
    }

    #[Test]
    public function aGlobalAllowPluginsPatternCoversFlex(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"extra":{"symfony":{"require":"6.4.*"}}}');

        $tools = new ProjectPlanApplier(
            $this->composerRunner(globalFlex: true, globalAllowPlugins: '{"symfony/*": true}'),
            $this->dir,
        )->tools(Ecosystem::Composer);

        $this->assertSame('2.8.1', $tools->version('composer'));
    }

    #[Test]
    public function aVendorDirectoryOtherThanVendorIsLookedAt(): void
    {
        file_put_contents(
            $this->dir . '/composer.json',
            '{"extra":{"symfony":{"require":"6.4.*"}},"config":{"vendor-dir":"lib","allow-plugins":true}}',
        );
        $this->installFlex('lib');

        try {
            $this->assertSame(
                '2.8.1',
                new ProjectPlanApplier($this->composerRunner(globalFlex: false), $this->dir)->tools(
                    Ecosystem::Composer,
                )->version('composer'),
            );
        } finally {
            $this->removeFlex('lib');
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

    #[Test]
    public function aLockedPackageThatPackageJsonPointsIntoIsFetchedAloneBeforeYarnRuns(): void
    {
        $this->yarnProjectPointingInto('acme/ui');
        $runner = $this->yarnRunner(composerInstallExit: 1);

        try {
            new ProjectPlanApplier($runner, $this->dir)->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
            self::fail('A package that cannot be fetched must stop the run.');
        } catch (ToolingException $toolingException) {
            $this->assertStringContainsString('Composer could not fetch acme/ui', $toolingException->getMessage());
        }

        $this->assertSame(
            [
                'node --version',
                'yarn --version',
                'composer --version --no-ansi',
                'composer install --no-interaction --no-scripts --no-plugins --no-autoloader --no-progress --no-ansi',
            ],
            $runner->toolCommands(),
            'only Composer runs, on a throwaway project, before any Yarn command',
        );
        $install = array_search('install', array_column($runner->commands, 1), true);
        $this->assertIsInt($install);
        $this->assertStringStartsWith(
            sys_get_temp_dir() . '/rustine-vendor-',
            $runner->envs[$install]['COMPOSER_VENDOR_DIR'] ?? '',
        );
    }

    #[Test]
    public function aLocalSourceThatComposerLockDoesNotHoldIsRefusedWithoutRunningComposer(): void
    {
        $this->yarnProjectPointingInto('acme/other');
        $runner = $this->yarnRunner(composerInstallExit: 0);

        try {
            new ProjectPlanApplier($runner, $this->dir)->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
            self::fail('Yarn cannot resolve a missing local source.');
        } catch (UnsupportedProjectException $unsupportedProjectException) {
            $this->assertStringContainsString(
                'local sources that do not exist',
                $unsupportedProjectException->getMessage(),
            );
        }

        $this->assertNotContains('composer', array_column($runner->commands, 0));
    }

    private function installFlex(string $vendor): void
    {
        mkdir($this->dir . '/' . $vendor . '/composer', 0o777, true);
        file_put_contents(
            $this->dir . '/' . $vendor . '/composer/installed.json',
            '{"packages":[{"name":"symfony/flex","version":"v2.4.0"}]}',
        );
    }

    private function removeFlex(string $vendor): void
    {
        unlink($this->dir . '/' . $vendor . '/composer/installed.json');
        rmdir($this->dir . '/' . $vendor . '/composer');
        rmdir($this->dir . '/' . $vendor);
    }

    private function yarnProjectPointingInto(string $locked): void
    {
        file_put_contents($this->dir . '/yarn.lock', "__metadata:\n  version: 8\n");
        file_put_contents($this->dir . '/package.json', '{"dependencies":{"@acme/ui":"file:vendor/acme/ui/assets"}}');
        file_put_contents(
            $this->dir . '/composer.lock',
            '{"packages":[{"name":"' . $locked . '","version":"1.0.0","dist":{"type":"zip","url":"x"}}]}',
        );
    }

    private function yarnRunner(int $composerInstallExit): FakeRunner
    {
        $runner = new FakeRunner();
        $runner->handler = static fn(array $command): CommandResult => match ($command[1] ?? null) {
            'install' => new CommandResult($composerInstallExit, 'boom'),
            default => new CommandResult(
                0,
                'composer' === $command[0] ? "Composer version 2.8.1 2024-10-04\n" : "4.14.1\n",
            ),
        };

        return $runner;
    }

    private function composerRunner(bool $globalFlex, string $globalAllowPlugins = 'true'): FakeRunner
    {
        $runner = new FakeRunner();
        $runner->handler = static fn(array $command): CommandResult => match (true) {
            'config' === ($command[2] ?? null) => new CommandResult(0, $globalAllowPlugins . "\n"),
            'global' === ($command[1] ?? null) => new CommandResult($globalFlex ? 0 : 1, ''),
            default => new CommandResult(0, "Composer version 2.8.1 2024-10-04 PHP version 8.4.1\n"),
        };

        return $runner;
    }
}
