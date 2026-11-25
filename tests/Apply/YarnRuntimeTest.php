<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Apply\YarnRuntime;
use Rustine\Tests\Apply\Support\Fixtures;

final class YarnRuntimeTest extends TestCase
{
    #[Test]
    public function yarnAndNodeComeFromThePathByDefault(): void
    {
        $runtime = new YarnRuntime();

        $this->assertSame(['yarn', 'up'], $runtime->yarn('up'));
        $this->assertSame(['node'], $runtime->node);
        $this->assertSame([], $runtime->env(), 'The PATH is left alone.');
    }

    #[Test]
    public function aNodeGivenByPathGoesFirstOnThePathOfYarn(): void
    {
        $runtime = YarnRuntime::fromCommandLines(
            ' /opt/node20/bin/node  /opt/yarn/yarn-4.2.2.cjs ',
            '/opt/node20/bin/node',
        );

        $this->assertSame(
            ['/opt/node20/bin/node', '/opt/yarn/yarn-4.2.2.cjs', '--version'],
            $runtime->yarn('--version'),
        );
        $this->assertStringStartsWith('/opt/node20/bin' . \PATH_SEPARATOR, $runtime->env()['PATH'] ?? '');
    }

    #[Test]
    public function anEmptyCommandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The Yarn command is empty.');

        YarnRuntime::fromCommandLines('  ', 'node');
    }

    #[Test]
    public function theStrategiesRunTheConfiguredYarnWithItsEnvironment(): void
    {
        $runtime = new YarnRuntime(['/opt/yarn/bin/yarn'], ['/opt/node20/bin/node']);
        $berry = new YarnBerryStrategy($runtime);
        $classic = new YarnClassicStrategy($runtime);
        $fix = Fixtures::fix('ckeditor4', '4.22.1', '4.25.0');

        $this->assertSame(['/opt/yarn/bin/yarn', 'up', '-R', 'ckeditor4'], $berry->attempts($fix)[0]->commands[0]);
        $this->assertSame(['/opt/yarn/bin/yarn', 'install', '--immutable'], $berry->checkCommand());
        $this->assertSame(
            ['/opt/yarn/bin/yarn', 'install', '--frozen-lockfile', '--non-interactive', '--ignore-scripts'],
            $classic->checkCommand(),
        );

        foreach ([$berry->mutatingEnv(), $berry->checkEnv(), $classic->mutatingEnv(), $classic->checkEnv()] as $env) {
            $this->assertStringStartsWith('/opt/node20/bin' . \PATH_SEPARATOR, $env['PATH'] ?? '');
        }

        $this->assertSame('false', $berry->mutatingEnv()['YARN_ENABLE_SCRIPTS']);
    }
}
