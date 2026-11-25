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
use Rustine\Trivy\Vulnerability;

final class TrivyReportParserTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/trivy';

    #[Test]
    public function itParsesARealYarnReport(): void
    {
        $report = new TrivyReportParser()->parseFile(self::FIXTURES . '/yarn-report.json');

        $this->assertCount(24, $report->vulnerabilities);
        $this->assertSame([], $report->ignoredTargets);
        $this->assertTrue($report->hasPackageList(Ecosystem::Yarn));
        $this->assertFalse($report->hasPackageList(Ecosystem::Composer));

        $braces = array_values(
            array_filter(
                $report->vulnerabilities,
                static fn(Vulnerability $v): bool => 'braces' === $v->packageName,
            ),
        );
        $this->assertCount(1, $braces);
        $this->assertSame('CVE-2026-93687', $braces[0]->id);
        $this->assertSame([], $braces[0]->fixedVersions, 'empty FixedVersion = no fix available');
        $this->assertSame(Severity::High, $braces[0]->severity);
        $this->assertSame('braces@3.0.3', $braces[0]->packageId);
        $this->assertSame(Ecosystem::Yarn, $braces[0]->ecosystem);
    }

    #[Test]
    public function itSplitsMultipleFixedVersions(): void
    {
        $report = new TrivyReportParser()->parseFile(self::FIXTURES . '/yarn-report.json');

        $multi = array_values(
            array_filter(
                $report->vulnerabilities,
                static fn(Vulnerability $v,
                ): bool => 'CVE-2026-69152' === $v->id && '1.1.12' === $v->installedVersion,
            ),
        );

        $this->assertCount(1, $multi);
        $this->assertSame(['1.1.18', '2.1.4', '3.0.6', '5.0.9'], $multi[0]->fixedVersions);
    }

    #[Test]
    public function itExposesRelationshipsAndDependsOn(): void
    {
        $report = new TrivyReportParser()->parseFile(self::FIXTURES . '/yarn-report.json');
        $packages = $report->packagesOf(Ecosystem::Yarn);

        $this->assertSame(Relationship::Indirect, $packages['brace-expansion@1.1.12']->relationship);
        $this->assertSame(Relationship::Direct, $packages['ckeditor4@4.22.1']->relationship);
        $this->assertSame(Relationship::Root, $packages['package.json']->relationship);
        $this->assertSame(['brace-expansion@1.1.12'], $packages['minimatch@3.1.2']->dependsOn);
    }

    #[Test]
    public function itParsesARealComposerReport(): void
    {
        $report = new TrivyReportParser()->parseFile(self::FIXTURES . '/composer-report.json');

        $this->assertCount(17, $report->vulnerabilities);
        $this->assertSame(Ecosystem::Composer, $report->vulnerabilities[0]->ecosystem);
        $this->assertTrue($report->hasPackageList(Ecosystem::Composer));
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

        $report = new TrivyReportParser()->parse($json);

        $this->assertSame([], $report->vulnerabilities);
        $this->assertSame(['package-lock.json'], $report->ignoredTargets);
    }

    #[Test]
    public function itOnlyKeepsTheLockfilesAtTheRootOfTheProject(): void
    {
        $vuln = [
            'VulnerabilityID'  => 'CVE-1',
            'PkgName'          => 'x',
            'InstalledVersion' => '1.0.0',
            'FixedVersion'     => '1.0.1',
        ];
        $json = json_encode([
            'Results' => [
                ['Target' => 'tools/php-cs-fixer/composer.lock', 'Type' => 'composer', 'Vulnerabilities' => [$vuln]],
                ['Target' => 'node_modules/some-package/yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => [$vuln]],
                [
                    'Target'          => './composer.lock',
                    'Type'            => 'composer',
                    'Vulnerabilities' => [[...$vuln, 'PkgName' => 'root/pkg']],
                ],
                ['Target' => 'yarn.lock', 'Type' => 'yarn', 'Vulnerabilities' => [[...$vuln, 'PkgName' => 'root-pkg']]],
            ],
        ], \JSON_THROW_ON_ERROR);

        $report = new TrivyReportParser()->parse($json);

        $this->assertSame(
            ['root/pkg', 'root-pkg'],
            array_map(static fn(Vulnerability $v): string => $v->packageName, $report->vulnerabilities),
        );
        $this->assertSame(
            ['tools/php-cs-fixer/composer.lock', 'node_modules/some-package/yarn.lock'],
            $report->ignoredTargets,
        );
    }

    #[Test]
    public function itAcceptsAReportWithoutResults(): void
    {
        $report = new TrivyReportParser()->parse('{"SchemaVersion":2}');

        $this->assertSame([], $report->vulnerabilities);
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

        $report = new TrivyReportParser()->parse($json);

        $this->assertCount(1, $report->vulnerabilities);
        $this->assertSame(Severity::Low, $report->vulnerabilities[0]->severity);
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        $this->expectException(InvalidTrivyReportException::class);

        new TrivyReportParser()->parse('{nope');
    }

    #[Test]
    public function itRejectsAMissingFile(): void
    {
        $this->expectException(InvalidTrivyReportException::class);

        new TrivyReportParser()->parseFile(self::FIXTURES . '/absent.json');
    }
}
