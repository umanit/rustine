<?php

declare(strict_types=1);

namespace Rustine\Tests\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Plan\PackageFixStatus;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\TrivyReportParser;

final class PlannerTest extends TestCase
{
    private const string FIXTURES = __DIR__ . '/../fixtures/trivy';

    #[Test]
    public function itSplitsYarnPackagesIntoSeverityGroups(): void
    {
        $plans = $this->plan('yarn-report.json');

        $this->assertSame(
            ['security/rustine-yarn-critical-high', 'security/rustine-yarn-others'],
            array_map(static fn(GroupPlan $p): string => $p->branchName(), $plans),
        );

        $this->assertSame(
            ['@babel/plugin-transform-modules-systemjs', 'brace-expansion', 'braces', 'browserslist'],
            $this->names($plans[0]),
        );
    }

    #[Test]
    public function itKeepsOnlyTheRequestedEcosystem(): void
    {
        $report = $this->report('yarn-report.json');

        $this->assertSame([], new Planner()->plan($report, null, Ecosystem::Composer));
        $this->assertEquals(new Planner()->plan($report), new Planner()->plan($report, null, Ecosystem::Yarn));
    }

    #[Test]
    public function itPicksTheHighestMinimalFixCoveringAllCvesOfAnInstance(): void
    {
        $plans = $this->plan('yarn-report.json');

        // 7 CVEs on brace-expansion@1.1.12: the smallest version fixing them all is the highest of the per-CVE minimums
        $v1 = $this->fix($plans[0], 'brace-expansion', '1.1.12');
        $this->assertSame(PackageFixStatus::Fixable, $v1->status);
        $this->assertSame('1.1.21', $v1->targetVersion);
        $this->assertCount(7, $v1->fixedIds);

        // Two instances of the same package remain separate fixes, each within its own major.
        $v2 = $this->fix($plans[0], 'brace-expansion', '2.0.2');
        $this->assertSame('2.1.7', $v2->targetVersion);
    }

    #[Test]
    public function itFlagsPackagesWithoutFix(): void
    {
        $plans = $this->plan('yarn-report.json');

        $braces = $this->fix($plans[0], 'braces', '3.0.3');
        $this->assertSame(PackageFixStatus::NoFixAvailable, $braces->status);
        $this->assertNull($braces->targetVersion);
        $this->assertSame(['CVE-2026-93687'], $braces->withoutFixIds);
        $this->assertFalse($braces->isFixable());
    }

    #[Test]
    public function itGroupsByHighestSeverityAndPrefersStableFixes(): void
    {
        $plans = $this->plan('yarn-report.json');
        $others = $plans[1];

        $ckeditor = $this->fix($others, 'ckeditor4', '4.22.1');
        $this->assertSame(Severity::Medium, $ckeditor->severity);
        $this->assertSame(SeverityGroup::Others, $ckeditor->group);
        $this->assertSame('4.25.0', $ckeditor->targetVersion);
        $this->assertSame(DependencyKind::Direct, $ckeditor->dependencyKind);
        $this->assertSame(['ckeditor4@4.22.1'], $ckeditor->dependencyChain);
    }

    #[Test]
    public function itResolvesTheDependencyChainOfTransitivePackages(): void
    {
        $fix = $this->fix($this->plan('yarn-report.json')[0], 'brace-expansion', '1.1.12');

        $this->assertSame(DependencyKind::Transitive, $fix->dependencyKind);
        $this->assertGreaterThanOrEqual(3, \count($fix->dependencyChain));
        $this->assertSame('brace-expansion@1.1.12', $fix->dependencyChain[\count($fix->dependencyChain) - 1]);
        $this->assertContains('minimatch@3.1.2', $fix->dependencyChain);
    }

    #[Test]
    public function itFiltersByGroup(): void
    {
        $plans = new Planner()->plan($this->report('yarn-report.json'), [SeverityGroup::Others]);

        $this->assertCount(1, $plans);
        $this->assertSame(SeverityGroup::Others, $plans[0]->group);
        $this->assertNotContains('braces', $this->names($plans[0]));
    }

    #[Test]
    public function itPlansComposerPackages(): void
    {
        $plans = $this->plan('composer-report.json');
        $high = $plans[0];

        $this->assertSame('security/rustine-composer-critical-high', $high->branchName());

        $spreadsheet = $this->fix($high, 'phpoffice/phpspreadsheet', '1.30.0');
        $this->assertSame(Severity::Critical, $spreadsheet->severity);
        $this->assertSame('1.30.6', $spreadsheet->targetVersion);

        $foundation = $this->fix($high, 'symfony/http-foundation', 'v6.4.33');
        $this->assertSame('6.4.41', $foundation->targetVersion);
        $this->assertSame(Ecosystem::Composer, $foundation->ecosystem);

        $browsershot = $this->fix($high, 'spatie/browsershot', '3.61.0');
        // 5 CVEs are only fixed in 5.x (major bump from 3.61.0), the last one has no fix at all.
        $this->assertSame(PackageFixStatus::MajorBumpRequired, $browsershot->status);
        $this->assertNull($browsershot->targetVersion);
        $this->assertCount(5, $browsershot->majorBumpIds);
        $this->assertSame('5.0.5', $browsershot->majorBumpIds['CVE-2025-1022']);
        $this->assertSame(['CVE-2025-3192'], $browsershot->withoutFixIds);
    }

    #[Test]
    public function itReportsMajorBumpsWithoutApplyingThem(): void
    {
        $json = $this->singleVuln('foo', '1.9.0', '2.0.1, 3.1.0', 'HIGH');

        $fix = new Planner()->plan(new TrivyReportParser()->parse($json))[0]->fixes[0];

        $this->assertSame(PackageFixStatus::MajorBumpRequired, $fix->status);
        $this->assertNull($fix->targetVersion);
        $this->assertSame(['CVE-X' => '2.0.1'], $fix->majorBumpIds);
    }

    #[Test]
    public function itFixesWhatItCanAndFlagsTheRestOfAPackage(): void
    {
        $json = json_encode([
            'Results' => [
                [
                    'Target'          => 'yarn.lock',
                    'Type'            => 'yarn',
                    'Vulnerabilities' => [
                        [
                            'VulnerabilityID'  => 'CVE-A',
                            'PkgName'          => 'foo',
                            'PkgID'            => 'foo@1.0.0',
                            'InstalledVersion' => '1.0.0',
                            'FixedVersion'     => '1.0.5',
                            'Severity'         => 'HIGH',
                        ],
                        [
                            'VulnerabilityID'  => 'CVE-B',
                            'PkgName'          => 'foo',
                            'PkgID'            => 'foo@1.0.0',
                            'InstalledVersion' => '1.0.0',
                            'FixedVersion'     => '2.0.0',
                            'Severity'         => 'HIGH',
                        ],
                        [
                            'VulnerabilityID'  => 'CVE-C',
                            'PkgName'          => 'foo',
                            'PkgID'            => 'foo@1.0.0',
                            'InstalledVersion' => '1.0.0',
                            'Severity'         => 'HIGH',
                        ],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $fix = new Planner()->plan(new TrivyReportParser()->parse($json))[0]->fixes[0];

        $this->assertSame(PackageFixStatus::Fixable, $fix->status);
        $this->assertSame('1.0.5', $fix->targetVersion);
        $this->assertSame(['CVE-A'], $fix->fixedIds);
        $this->assertSame(['CVE-B' => '2.0.0'], $fix->majorBumpIds);
        $this->assertSame(['CVE-C'], $fix->withoutFixIds);
    }

    #[Test]
    public function itHandlesUnparsableInstalledVersions(): void
    {
        $json = $this->singleVuln('foo', 'dev-main', '1.0.0', 'CRITICAL');

        $fix = new Planner()->plan(new TrivyReportParser()->parse($json))[0]->fixes[0];

        $this->assertSame(PackageFixStatus::UnparsableVersion, $fix->status);
    }

    #[Test]
    public function itReportsUnknownDependencyKindWithoutPackageList(): void
    {
        $json = $this->singleVuln('foo', '1.0.0', '1.0.1', 'LOW');

        $fix = new Planner()->plan(new TrivyReportParser()->parse($json))[0]->fixes[0];

        $this->assertSame(DependencyKind::Unknown, $fix->dependencyKind);
        $this->assertSame([], $fix->dependencyChain);
    }

    #[Test]
    public function itKeepsAPackageInASingleGroupEvenIfInstancesDifferInSeverity(): void
    {
        $json = json_encode([
            'Results' => [
                [
                    'Target'          => 'yarn.lock',
                    'Type'            => 'yarn',
                    'Vulnerabilities' => [
                        [
                            'VulnerabilityID'  => 'CVE-A',
                            'PkgName'          => 'foo',
                            'PkgID'            => 'foo@1.0.0',
                            'InstalledVersion' => '1.0.0',
                            'FixedVersion'     => '1.0.5',
                            'Severity'         => 'CRITICAL',
                        ],
                        [
                            'VulnerabilityID'  => 'CVE-B',
                            'PkgName'          => 'foo',
                            'PkgID'            => 'foo@2.0.0',
                            'InstalledVersion' => '2.0.0',
                            'FixedVersion'     => '2.0.5',
                            'Severity'         => 'LOW',
                        ],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);

        $plans = new Planner()->plan(new TrivyReportParser()->parse($json));

        $this->assertCount(1, $plans);
        $this->assertSame(SeverityGroup::CriticalHigh, $plans[0]->group);
        $this->assertCount(2, $plans[0]->fixes);
    }

    #[Test]
    public function itReturnsNoPlanWithoutVulnerabilities(): void
    {
        $this->assertSame([], new Planner()->plan(new TrivyReportParser()->parse('{}')));
    }

    /**
     * @return list<GroupPlan>
     */
    private function plan(string $fixture): array
    {
        return new Planner()->plan($this->report($fixture));
    }

    private function report(string $fixture): TrivyReport
    {
        return new TrivyReportParser()->parseFile(self::FIXTURES . '/' . $fixture);
    }

    /**
     * @return list<string>
     */
    private function names(GroupPlan $plan): array
    {
        return array_values(array_unique(array_map(static fn(PackageFix $f): string => $f->packageName, $plan->fixes)));
    }

    private function fix(GroupPlan $plan, string $name, string $installed): PackageFix
    {
        foreach ($plan->fixes as $fix) {
            if ($fix->packageName === $name && $fix->installedVersion === $installed) {
                return $fix;
            }
        }

        self::fail(sprintf('No fix for %s@%s in %s', $name, $installed, $plan->branchName()));
    }

    private function singleVuln(string $name, string $installed, string $fixed, string $severity): string
    {
        return json_encode([
            'Results' => [
                [
                    'Target'          => 'yarn.lock',
                    'Type'            => 'yarn',
                    'Vulnerabilities' => [
                        [
                            'VulnerabilityID'  => 'CVE-X',
                            'PkgName'          => $name,
                            'PkgID'            => $name . '@' . $installed,
                            'InstalledVersion' => $installed,
                            'FixedVersion'     => $fixed,
                            'Severity'         => $severity,
                        ],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR);
    }
}
