<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

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
    public function anUnfixablePackageHasNoAttempt(): void
    {
        $fix = Fixtures::fix(status: PackageFixStatus::NoFixAvailable);

        self::assertSame([], (new YarnBerryStrategy())->attempts($fix));
    }
}
