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

        self::assertSame(['yarn', 'up'], $runtime->yarn('up'));
        self::assertSame(['node'], $runtime->node);
        self::assertSame([], $runtime->env(), 'The PATH is left alone.');
    }

    #[Test]
    public function aNodeGivenByPathGoesFirstOnThePathOfYarn(): void
    {
        $runtime = YarnRuntime::fromCommandLines(' /opt/node20/bin/node  /opt/yarn/yarn-4.2.2.cjs ', '/opt/node20/bin/node');

        self::assertSame(['/opt/node20/bin/node', '/opt/yarn/yarn-4.2.2.cjs', '--version'], $runtime->yarn('--version'));
        self::assertStringStartsWith('/opt/node20/bin' . \PATH_SEPARATOR, $runtime->env()['PATH'] ?? '');
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

        self::assertSame(['/opt/yarn/bin/yarn', 'up', '-R', 'ckeditor4'], $berry->attempts($fix)[0]->commands[0]);
        self::assertSame(['/opt/yarn/bin/yarn', 'install', '--immutable'], $berry->checkCommand());
        self::assertSame(['/opt/yarn/bin/yarn', 'install', '--frozen-lockfile', '--non-interactive', '--ignore-scripts'], $classic->checkCommand());

        foreach ([$berry->mutatingEnv(), $berry->checkEnv(), $classic->mutatingEnv(), $classic->checkEnv()] as $env) {
            self::assertStringStartsWith('/opt/node20/bin' . \PATH_SEPARATOR, $env['PATH'] ?? '');
        }
        self::assertSame('false', $berry->mutatingEnv()['YARN_ENABLE_SCRIPTS']);
    }
}
