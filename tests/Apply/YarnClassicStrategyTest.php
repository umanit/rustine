<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Tests\Apply\Support\Fixtures;

final class YarnClassicStrategyTest extends TestCase
{
    #[Test]
    public function aTransitiveDependencyIsRefreshedByPruningTheLockfileThenPinnedByAResolution(): void
    {
        $attempts = (new YarnClassicStrategy())->attempts(Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive));

        self::assertSame([AttemptKind::LockfileRefresh, AttemptKind::Resolution], array_map(static fn ($a) => $a->kind, $attempts));
        self::assertSame('braces', $attempts[0]->pruneLockfile);
        self::assertSame([['yarn', 'install', '--non-interactive', '--ignore-scripts']], $attempts[0]->commands);
        self::assertSame(['**/braces', '^3.0.3'], $attempts[1]->resolutionEdit);
        self::assertNull($attempts[1]->pruneLockfile);
    }

    #[Test]
    public function aDirectDependencyHasItsRangeEditedInPlace(): void
    {
        $attempts = (new YarnClassicStrategy())->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        self::assertSame(AttemptKind::DirectBump, $attempts[1]->kind);
        self::assertSame(['ckeditor4', '^4.25.0'], $attempts[1]->rangeEdit);
        self::assertSame([['yarn', 'install', '--non-interactive', '--ignore-scripts']], $attempts[1]->commands);
    }

    #[Test]
    public function itChecksTheLockfileWithoutRunningScripts(): void
    {
        $strategy = new YarnClassicStrategy();

        self::assertSame(['yarn', 'install', '--frozen-lockfile', '--non-interactive', '--ignore-scripts'], $strategy->checkCommand());
        self::assertSame([], $strategy->mutatingEnv());
    }

    #[Test]
    public function anUnfixablePackageHasNoAttempt(): void
    {
        self::assertSame([], (new YarnClassicStrategy())->attempts(Fixtures::fix(status: PackageFixStatus::NoFixAvailable)));
    }
}
