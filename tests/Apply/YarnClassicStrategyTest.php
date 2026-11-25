<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\Attempt;
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
        $attempts = new YarnClassicStrategy()->attempts(
            Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive),
        );

        $this->assertSame(
            [AttemptKind::LockfileRefresh, AttemptKind::Resolution],
            array_map(static fn(Attempt $a): AttemptKind => $a->kind, $attempts),
        );
        $this->assertSame('braces', $attempts[0]->pruneLockfile);
        $this->assertSame([['yarn', 'install', '--non-interactive', '--ignore-scripts']], $attempts[0]->commands);
        $this->assertSame(['**/braces', '^3.0.3'], $attempts[1]->resolutionEdit);
        $this->assertNull($attempts[1]->pruneLockfile);
    }

    #[Test]
    public function aDirectDependencyHasItsRangeEditedInPlace(): void
    {
        $attempts = new YarnClassicStrategy()->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        $this->assertSame(AttemptKind::DirectBump, $attempts[1]->kind);
        $this->assertSame(['ckeditor4', '^4.25.0'], $attempts[1]->rangeEdit);
        $this->assertSame([['yarn', 'install', '--non-interactive', '--ignore-scripts']], $attempts[1]->commands);
    }

    #[Test]
    public function itChecksTheLockfileWithoutRunningScripts(): void
    {
        $strategy = new YarnClassicStrategy();

        $this->assertSame(
            ['yarn', 'install', '--frozen-lockfile', '--non-interactive', '--ignore-scripts'],
            $strategy->checkCommand(),
        );
        $this->assertSame([], $strategy->mutatingEnv());
    }

    #[Test]
    public function anUnfixablePackageHasNoAttempt(): void
    {
        $this->assertSame(
            [],
            new YarnClassicStrategy()->attempts(Fixtures::fix(status: PackageFixStatus::NoFixAvailable)),
        );
    }
}
