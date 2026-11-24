<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ToolEnvironment;
use Rustine\Pinning\PinningSource;
use Rustine\Pinning\ToolPinning;

final class ToolEnvironmentTest extends TestCase
{
    #[Test]
    public function miseTomlIsLeftToMise(): void
    {
        $env = new ToolEnvironment(new ToolPinning(PinningSource::Mise, ['node' => '22', 'yarn' => '4.2.2'], 'mise.toml'), '/p');

        self::assertSame(['mise', 'exec', '--', 'yarn', 'install'], $env->wrap(['yarn', 'install']));
    }

    #[Test]
    public function voltaVersionsArePassedToMise(): void
    {
        $env = new ToolEnvironment(new ToolPinning(PinningSource::Volta, ['node' => '20.11.0', 'yarn' => '1.22.19'], 'package.json'), '/p');

        self::assertSame(
            ['mise', 'exec', 'node@20.11.0', 'yarn@1.22.19', '--', 'yarn', 'up'],
            $env->wrap(['yarn', 'up']),
        );
    }

    #[Test]
    public function theProjectMiseConfigurationIsTrusted(): void
    {
        $env = new ToolEnvironment(new ToolPinning(PinningSource::Mise, [], 'mise.toml'), '/srv/project');

        self::assertSame(['MISE_TRUSTED_CONFIG_PATHS' => '/srv/project'], $env->env());
    }

    #[Test]
    public function voltaOnlyWrapsNodeAndYarnCommands(): void
    {
        $env = new ToolEnvironment(new ToolPinning(PinningSource::Volta, ['node' => '20.11.0', 'yarn' => '1.22.19'], 'package.json'), '/p');

        self::assertSame(['composer', 'update'], $env->wrap(['composer', 'update']), 'Composer is not pinned by volta.');
    }

    #[Test]
    public function withoutPinningCommandsRunAsIs(): void
    {
        $env = new ToolEnvironment(null, '/p');

        self::assertSame(['composer', 'update'], $env->wrap(['composer', 'update']));
        self::assertSame([], $env->env());
    }
}
