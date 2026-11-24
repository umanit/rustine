<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Tests\Apply\Support\Fixtures;

final class YarnBerryStrategyTest extends TestCase
{
    #[Test]
    public function aDirectDependencyIsRefreshedThenItsRangeIsRaised(): void
    {
        $attempts = (new YarnBerryStrategy())->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        self::assertSame([AttemptKind::LockfileRefresh, AttemptKind::DirectBump], array_map(static fn ($a) => $a->kind, $attempts));
        self::assertSame([['yarn', 'up', '-R', 'ckeditor4']], $attempts[0]->commands);
        self::assertSame([['yarn', 'up', 'ckeditor4@^4.25.0']], $attempts[1]->commands);
        self::assertNull($attempts[1]->resolutionEdit);
    }

    #[Test]
    public function aTransitiveDependencyFallsBackToAResolution(): void
    {
        $attempts = (new YarnBerryStrategy())->attempts(Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive));

        self::assertSame([AttemptKind::LockfileRefresh, AttemptKind::Resolution], array_map(static fn ($a) => $a->kind, $attempts));
        self::assertSame(['braces', '^3.0.3'], $attempts[1]->resolutionEdit);
        self::assertSame([['yarn', 'install']], $attempts[1]->commands);
    }

    #[Test]
    public function inLockfileOnlyModeEveryAttemptSkipsTheLinkStep(): void
    {
        $strategy = new YarnBerryStrategy(lockfileOnly: true);

        $direct = $strategy->attempts(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));
        $transitive = $strategy->attempts(Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive));

        self::assertSame([['yarn', 'up', '-R', 'ckeditor4', '--mode=update-lockfile']], $direct[0]->commands);
        self::assertSame([['yarn', 'up', 'ckeditor4@^4.25.0', '--mode=update-lockfile']], $direct[1]->commands);
        self::assertSame([['yarn', 'install', '--mode=update-lockfile']], $transitive[1]->commands);
        // `--immutable` cannot be combined with that mode: the final check stays a full install.
        self::assertSame(['yarn', 'install', '--immutable'], $strategy->checkCommand());
    }

    #[Test]
    #[DataProvider('yarnVersions')]
    public function theLockfileOnlyModeIsUsedFromTheVersionItWasCheckedWith(?string $version, bool $expected): void
    {
        self::assertSame($expected, YarnBerryStrategy::supportsLockfileOnly($version));
    }

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
    public function anUnfixablePackageHasNoAttempt(): void
    {
        $fix = Fixtures::fix(status: PackageFixStatus::NoFixAvailable);

        self::assertSame([], (new YarnBerryStrategy())->attempts($fix));
    }
}
