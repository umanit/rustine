<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\Attempt;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Tests\Apply\Support\Fixtures;

final class YarnBerryStrategyTest extends TestCase
{
    /**
     * @return iterable<string, array{string|null, bool}>
     */
    public static function yarnVersions(): iterable
    {
        yield 'Berry 4' => ['4.14.1', true];
        yield 'a later major' => ['5.0.0', true];
        yield 'with a v' => ['v4.0.0', true];
        yield 'Berry 3' => ['3.8.7', false];
        yield 'Berry 2' => ['2.4.3', false];
        yield 'unknown' => [null, false];
        yield 'unreadable' => ['dev', false];
    }

    #[Test]
    public function aDirectDependencyIsRefreshedThenItsRangeIsRaised(): void
    {
        $attempts = new YarnBerryStrategy()->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        $this->assertSame(
            [AttemptKind::LockfileRefresh, AttemptKind::DirectBump],
            array_map(static fn(Attempt $a): AttemptKind => $a->kind, $attempts),
        );
        $this->assertSame([['yarn', 'up', '-R', 'ckeditor4']], $attempts[0]->commands);
        $this->assertSame([['yarn', 'up', 'ckeditor4@^4.25.0']], $attempts[1]->commands);
        $this->assertNull($attempts[1]->resolutionEdit);
    }

    #[Test]
    public function aTransitiveDependencyFallsBackToAResolution(): void
    {
        $attempts = new YarnBerryStrategy()->attempts(
            Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive),
        );

        $this->assertSame(
            [AttemptKind::LockfileRefresh, AttemptKind::Resolution],
            array_map(static fn(Attempt $a): AttemptKind => $a->kind, $attempts),
        );
        $this->assertSame(['braces', '^3.0.3'], $attempts[1]->resolutionEdit);
        $this->assertSame([['yarn', 'install']], $attempts[1]->commands);
    }

    #[Test]
    public function inLockfileOnlyModeEveryAttemptSkipsTheLinkStep(): void
    {
        $strategy = new YarnBerryStrategy(lockfileOnly: true);

        $direct = $strategy->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));
        $transitive = $strategy->attempts(Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive));

        $this->assertSame([['yarn', 'up', '-R', 'ckeditor4', '--mode=update-lockfile']], $direct[0]->commands);
        $this->assertSame([['yarn', 'up', 'ckeditor4@^4.25.0', '--mode=update-lockfile']], $direct[1]->commands);
        $this->assertSame([['yarn', 'install', '--mode=update-lockfile']], $transitive[1]->commands);
        // `--immutable` cannot be combined with that mode: the final check stays a full install.
        $this->assertSame(['yarn', 'install', '--immutable'], $strategy->checkCommand());
    }

    #[Test]
    #[DataProvider('yarnVersions')]
    public function theLockfileOnlyModeIsUsedFromTheVersionItWasCheckedWith(?string $version, bool $expected): void
    {
        $this->assertSame($expected, YarnBerryStrategy::supportsLockfileOnly($version));
    }

    #[Test]
    public function anUnfixablePackageHasNoAttempt(): void
    {
        $fix = Fixtures::fix(status: PackageFixStatus::NoFixAvailable);

        $this->assertSame([], new YarnBerryStrategy()->attempts($fix));
    }
}
