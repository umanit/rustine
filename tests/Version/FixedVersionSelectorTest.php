<?php

declare(strict_types=1);

namespace Rustine\Tests\Version;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Version\FixedVersionSelector;
use Rustine\Version\Version;

final class FixedVersionSelectorTest extends TestCase
{
    /**
     * Cases taken from real Trivy reports.
     *
     * @return iterable<string, array{string, list<string>, string|null, bool}>
     */
    public static function cases(): iterable
    {
        yield 'one fixed version per branch, same major' => [
            '1.1.12',
            ['1.1.18', '2.1.4', '3.0.6', '5.0.9'],
            '1.1.18',
            false,
        ];
        yield 'branche 2.x' => ['2.0.2', ['5.0.9', '3.0.6', '2.1.4', '1.1.18'], '2.1.4', false];
        yield 'unsorted list, older versions ignored' => [
            'v7.4.5',
            [
                '5.3.0',
                '7.1.0',
                '7.4.0',
                '8.0.12',
                '7.4.12',
                '6.4.40',
            ],
            '7.4.12',
            false,
        ];
        yield 'v prefix on the installed version' => ['v6.4.33', ['8.0.13', '6.4.41', '7.4.13'], '6.4.41', false];
        yield 'same major wins over a higher-major pre-release' => [
            '7.27.1',
            ['7.29.4', '8.0.0-alpha.13'],
            '7.29.4',
            false,
        ];
        yield 'major bump only' => ['1.9.0', ['2.0.1', '3.1.0'], '2.0.1', true];
        yield 'stable higher major before its pre-release' => ['1.0.0', ['3.0.0-rc.1', '2.0.0'], '2.0.0', true];
        yield 'lts suffix accepted for lack of better' => ['4.22.1', ['4.24.0-lts'], '4.24.0-lts', false];
        yield 'stable same major before pre-release' => ['4.22.1', ['4.24.0-lts', '4.25.0'], '4.25.0', false];
        yield 'no fixed version' => ['3.0.3', [], null, false];
        yield 'all fixed versions are lower' => ['2.0.0', ['1.9.9', '2.0.0'], null, false];
        yield 'unparsable fixed versions' => ['1.0.0', ['dev-main'], null, false];
    }

    /**
     * @param list<string> $fixed
     */
    #[Test]
    #[DataProvider('cases')]
    public function itSelectsTheSmallestFixInTheSameMajor(
        string $installed,
        array $fixed,
        ?string $expected,
        bool $majorBump,
    ): void {
        $installedVersion = Version::tryParse($installed);
        self::assertNotNull($installedVersion);

        $candidate = (new FixedVersionSelector())->select($installedVersion, $fixed);

        if (null === $expected) {
            self::assertNull($candidate);

            return;
        }

        self::assertNotNull($candidate);
        self::assertSame($expected, $candidate->version->raw);
        self::assertSame($majorBump, $candidate->majorBump);
    }
}
