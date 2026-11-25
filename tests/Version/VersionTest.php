<?php

declare(strict_types=1);

namespace Rustine\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Version\Version;

final class VersionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'v prefix ignored' => ['v7.4.5', '7.4.5', 0];
        yield 'higher patch' => ['1.1.21', '1.1.9', 1];
        yield 'lower major' => ['2.9.9', '10.0.0', -1];
        yield 'missing segments' => ['5', '5.0.0', 0];
        yield 'four segments' => ['1.2.3.4', '1.2.3', 1];
        yield 'stable > pre-release' => ['8.0.0', '8.0.0-rc.6', 1];
        yield 'rc.10 > rc.6' => ['8.0.0-rc.10', '8.0.0-rc.6', 1];
        yield 'alpha < rc' => ['8.0.0-alpha.13', '8.0.0-rc.1', -1];
        yield 'lts suffix before stable' => ['4.24.0-lts', '4.24.0', -1];
        yield 'build ignored' => ['1.0.0+build.5', '1.0.0', 0];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalid(): iterable
    {
        yield 'composer branch' => ['dev-main'];
        yield 'empty' => [''];
        yield 'text' => ['latest'];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function itComparesVersions(string $a, string $b, int $expected): void
    {
        $left = Version::tryParse($a);
        $right = Version::tryParse($b);

        $this->assertInstanceOf(Version::class, $left);
        $this->assertInstanceOf(Version::class, $right);
        $this->assertSame($expected, $left->compare($right));
    }

    #[Test]
    #[DataProvider('invalid')]
    public function itRejectsUnparsableVersions(string $raw): void
    {
        $this->assertNull(Version::tryParse($raw));
    }

    #[Test]
    public function itExposesMajorAndPrerelease(): void
    {
        $version = Version::tryParse('v8.0.0-rc.6');

        $this->assertInstanceOf(Version::class, $version);
        $this->assertSame(8, $version->major());
        $this->assertTrue($version->isPrerelease());
        $this->assertSame('v8.0.0-rc.6', $version->raw);
    }
}
