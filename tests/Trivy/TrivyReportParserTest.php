<?php

declare(strict_types=1);

namespace Rustine\Tests\Trivy;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\Relationship;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyReportParser;

final class TrivyReportParserTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/trivy';

    #[Test]
    public function itParsesARealYarnReport(): void
    {
        $report = (new TrivyReportParser())->parseFile(self::FIXTURES . '/yarn-report.json');

        self::assertCount(24, $report->vulnerabilities);
        self::assertSame([], $report->ignoredTargets);
        self::assertTrue($report->hasPackageList(Ecosystem::Yarn));
        self::assertFalse($report->hasPackageList(Ecosystem::Composer));

        $braces = array_values(
            array_filter($report->vulnerabilities, static fn($v): bool => 'braces' === $v->packageName),
        );
        self::assertCount(1, $braces);
        self::assertSame('CVE-2026-93687', $braces[0]->id);
        self::assertSame([], $braces[0]->fixedVersions, 'empty FixedVersion = no fix available');
        self::assertSame(Severity::High, $braces[0]->severity);
        self::assertSame('braces@3.0.3', $braces[0]->packageId);
        self::assertSame(Ecosystem::Yarn, $braces[0]->ecosystem);
    }

    #[Test]
    public function itSplitsMultipleFixedVersions(): void
    {
        $report = (new TrivyReportParser())->parseFile(self::FIXTURES . '/yarn-report.json');

        $multi = array_values(
            array_filter(
                $report->vulnerabilities,
                static fn($v): bool => 'CVE-2026-69152' === $v->id && '1.1.12' === $v->installedVersion,
            ),
        );

        self::assertCount(1, $multi);
        self::assertSame(['1.1.18', '2.1.4', '3.0.6', '5.0.9'], $multi[0]->fixedVersions);
    }

    #[Test]
    public function itExposesRelationshipsAndDependsOn(): void
    {
        $report = (new TrivyReportParser())->parseFile(self::FIXTURES . '/yarn-report.json');
        $packages = $report->packagesOf(Ecosystem::Yarn);

        self::assertSame(Relationship::Indirect, $packages['brace-expansion@1.1.12']->relationship);
        self::assertSame(Relationship::Direct, $packages['ckeditor4@4.22.1']->relationship);
        self::assertSame(Relationship::Root, $packages['package.json']->relationship);
        self::assertSame(['brace-expansion@1.1.12'], $packages['minimatch@3.1.2']->dependsOn);
    }

    #[Test]
    public function itParsesARealComposerReport(): void
    {
        $report = (new TrivyReportParser())->parseFile(self::FIXTURES . '/composer-report.json');

        self::assertCount(17, $report->vulnerabilities);
        self::assertSame(Ecosystem::Composer, $report->vulnerabilities[0]->ecosystem);
        self::assertTrue($report->hasPackageList(Ecosystem::Composer));
    }

    #[Test]
    public function itIgnoresUnsupportedTypesAndEmptyResults(): void
    {
        $json = json_encode([
            'Results' => [
                [
                    'Target'          => 'package-lock.json',
                    'Type'            => 'npm',
                    'Vulnerabilities' => [
                        ['VulnerabilityID' => 'CVE-1', 'PkgName' => 'x', 'InstalledVersion' => '1.0.0'],
                    ],
                ],
                ['Target' => 'composer.lock', 'Type' => 'composer'],
                ['Target' => 'yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => null],
            ],
        ], \JSON_THROW_ON_ERROR);

        $report = (new TrivyReportParser())->parse($json);

        self::assertSame([], $report->vulnerabilities);
        self::assertSame(['package-lock.json'], $report->ignoredTargets);
    }

    #[Test]
    public function itOnlyKeepsTheLockfilesAtTheRootOfTheProject(): void
    {
        $vuln = ['VulnerabilityID' => 'CVE-1', 'PkgName' => 'x', 'InstalledVersion' => '1.0.0', 'FixedVersion' => '1.0.1'];
        $json = json_encode([
            'Results' => [
                ['Target' => 'tools/php-cs-fixer/composer.lock', 'Type' => 'composer', 'Vulnerabilities' => [$vuln]],
                ['Target' => 'node_modules/some-package/yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => [$vuln]],
                ['Target' => './composer.lock', 'Type' => 'composer', 'Vulnerabilities' => [[...$vuln, 'PkgName' => 'root/pkg']]],
                ['Target' => 'yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => [[...$vuln, 'PkgName' => 'root-pkg']]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $report = (new TrivyReportParser())->parse($json);

        self::assertSame(['root/pkg', 'root-pkg'], array_map(static fn ($v): string => $v->packageName, $report->vulnerabilities));
        self::assertSame(['tools/php-cs-fixer/composer.lock', 'node_modules/some-package/yarn.lock'], $report->ignoredTargets);
    }

    #[Test]
    public function itAcceptsAReportWithoutResults(): void
    {
        $report = (new TrivyReportParser())->parse('{"SchemaVersion":2}');

        self::assertSame([], $report->vulnerabilities);
    }

    #[Test]
    public function itDeduplicatesTheSameVulnerabilityOnTheSamePackage(): void
    {
        $vuln = [
            'VulnerabilityID'  => 'CVE-1',
            'PkgName'          => 'a',
            'PkgID'            => 'a@1.0.0',
            'InstalledVersion' => '1.0.0',
            'FixedVersion'     => '1.0.1',
            'Severity'         => 'low',
        ];
        $json = json_encode(
            ['Results' => [['Target' => 'yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => [$vuln, $vuln]]]],
            \JSON_THROW_ON_ERROR,
        );

        $report = (new TrivyReportParser())->parse($json);

        self::assertCount(1, $report->vulnerabilities);
        self::assertSame(Severity::Low, $report->vulnerabilities[0]->severity);
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        $this->expectException(InvalidTrivyReportException::class);

        (new TrivyReportParser())->parse('{nope');
    }

    #[Test]
    public function itRejectsAMissingFile(): void
    {
        $this->expectException(InvalidTrivyReportException::class);

        (new TrivyReportParser())->parseFile(self::FIXTURES . '/absent.json');
    }
}
