<?php

declare(strict_types=1);

namespace Rustine\Tests\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\ToolVersions;
use Rustine\I18n\TranslatorFactory;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFixStatus;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyReportParser;

final class MergeRequestRendererTest extends TestCase
{
    #[Test]
    public function aPlanPreviewIsFlaggedAsNotVerified(): void
    {
        $content = $this->renderer('en')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        self::assertSame('Security: fix Yarn dependencies (critical and high severity)', $content->title);
        self::assertStringContainsString('> Preview of the plan', $content->description);
        self::assertStringContainsString('| `brace-expansion` | `1.1.12` → `1.1.21` |', $content->description);
        self::assertStringContainsString('## Fixes', $content->description);
        self::assertStringNotContainsString('## Verification', $content->description);
        self::assertStringContainsString('planned', $content->description);
    }

    #[Test]
    public function itListsThePackagesNeedingAManualActionWithTheirCves(): void
    {
        $content = $this->renderer('en')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        self::assertStringContainsString('## Needs manual attention', $content->description);
        // `braces` has CVEs without any fixed version.
        self::assertMatchesRegularExpression('/- \*\*braces\*\* \(`3\.0\.3`\): .*no fixed version has been published/', $content->description);
        self::assertGreaterThan(0, $content->attentionItems);
    }

    #[Test]
    public function theSameContentIsRenderedInFrench(): void
    {
        $content = $this->renderer('fr')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        self::assertSame('Sécurité : corriger les dépendances Yarn (sévérité critique et haute)', $content->title);
        self::assertStringContainsString('## Corrections', $content->description);
        self::assertStringContainsString('## Action manuelle requise', $content->description);
        self::assertStringContainsString('| Paquet | Version | CVE |', $content->description);
        self::assertStringContainsString('| `brace-expansion` | `1.1.12` → `1.1.21` |', $content->description, 'Data is not translated.');
    }

    #[Test]
    public function aTransitiveDependencyShowsItsChain(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $fix = $this->withChain($fix, ['micromatch@4.0.5', 'braces@3.0.2']);

        $content = $this->renderer('en')->render(Fixtures::plan($fix));

        self::assertStringContainsString('transitive, via `micromatch` → `braces`', $content->description);
    }

    #[Test]
    public function anAppliedResultIsVerifiedAndFlagsResolutionsAsDebt(): void
    {
        $fix = Fixtures::fix('minimist', '0.0.8', '0.2.4', DependencyKind::Transitive);
        $result = new ApplyResult([new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::Resolution, ['resolution: ok'])]);
        $tools = new ToolVersions(['node' => '22.11.0', 'yarn' => '4.2.2']);

        $content = $this->renderer('en')->render(Fixtures::plan($fix), $result, $tools);

        self::assertStringNotContainsString('Preview', $content->description);
        self::assertStringContainsString('## Technical debt', $content->description);
        self::assertStringContainsString('`minimist` is pinned through `resolutions`', $content->description);
        self::assertStringContainsString('## Verification', $content->description);
        self::assertStringContainsString('The lockfile is consistent with the manifest.', $content->description);
        self::assertStringContainsString('Tools of the CI environment: `node 22.11.0, yarn 4.2.2`.', $content->description);
        self::assertSame(1, $content->fixedPackages);
    }

    #[Test]
    public function failuresAndUnsupportedSourcesKeepTheirTechnicalLogInACollapsedBlock(): void
    {
        $failed = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $git = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $result = new ApplyResult([
            new FixOutcome($failed, FixOutcomeStatus::Failed, null, ['lockfile-refresh: boom']),
            new FixOutcome($git, FixOutcomeStatus::UnsupportedSource, null, ['declared as "org/repo#x" in package.json']),
        ], 'YN0028: lockfile would be modified');

        $content = $this->renderer('en')->render(Fixtures::plan($failed, $git), $result);

        self::assertStringContainsString('no automatic fix worked', $content->description);
        self::assertStringContainsString('declared with a source that Rustine does not change', $content->description);
        self::assertStringContainsString('<details><summary>Technical details</summary>', $content->description);
        self::assertStringContainsString('lockfile-refresh: boom', $content->description);
        self::assertStringContainsString('The lockfile consistency check failed:', $content->description);
        self::assertStringContainsString('YN0028: lockfile would be modified', $content->description);
        self::assertSame(0, $content->fixedPackages);
        self::assertNull($content->severity);
        self::assertSame(2, $content->attentionItems);
    }

    #[Test]
    public function skippedPackagesAreReportedFromThePlanStatus(): void
    {
        $major = Fixtures::fix('major', '1.0.0', '2.0.0', status: PackageFixStatus::MajorBumpRequired);
        $result = new ApplyResult([new FixOutcome($major, FixOutcomeStatus::Skipped)]);

        $content = $this->renderer('en')->render(Fixtures::plan($major), $result);

        self::assertSame(0, $content->fixedPackages);
        self::assertStringNotContainsString('## Fixes', $content->description);
    }

    #[Test]
    public function theCommitKeepsTheConventionalPrefixAndLocalizesTheSentence(): void
    {
        $plan = Fixtures::plan(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        $en = $this->renderer('en')->render($plan)->commitMessage;
        $fr = $this->renderer('fr')->render($plan)->commitMessage;

        self::assertStringStartsWith('build(deps): fix vulnerable Yarn dependencies (', $en);
        self::assertStringStartsWith('build(deps): corriger les dépendances Yarn vulnérables (', $fr);
        self::assertStringStartsWith('build(deps): fix vulnerable Yarn dependencies (others)', $en);
        self::assertLessThanOrEqual(72, mb_strlen(explode("\n", $fr)[0]), 'The subject stays short in every language.');
        self::assertStringContainsString('- ckeditor4 4.22.1 → 4.25.0 (CVE-2024-0001)', $en);
        self::assertStringContainsString('- ckeditor4 4.22.1 → 4.25.0 (CVE-2024-0001)', $fr);
    }

    #[Test]
    public function theDescriptionEndsWithTheMarkerCarryingTheFingerprint(): void
    {
        $content = $this->renderer('en')->render(Fixtures::plan(Fixtures::fix()));

        self::assertStringContainsString(sprintf('<!-- rustine:v1 fingerprint=%s -->', $content->fingerprint), $content->description);
        self::assertStringContainsString('Generated by Rustine 1.2.3', $content->description);
    }

    #[Test]
    public function theFingerprintIgnoresOrderAndLanguageButNotVersions(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $bBumped = Fixtures::fix('bbb', '2.0.0', '2.0.2');

        $one = $this->renderer('en')->render(Fixtures::plan($a, $b))->fingerprint;

        self::assertSame($one, $this->renderer('en')->render(Fixtures::plan($b, $a))->fingerprint);
        self::assertSame($one, $this->renderer('fr')->render(Fixtures::plan($a, $b))->fingerprint);
        self::assertNotSame($one, $this->renderer('en')->render(Fixtures::plan($a, $bBumped))->fingerprint);
    }

    #[Test]
    public function theSeverityIsTheHighestOfTheFixedPackages(): void
    {
        $content = $this->renderer('en')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        // Only the packages actually fixed count: the CRITICAL ones of this fixture have no fix.
        self::assertSame(Severity::High, $content->severity);

        $mixed = $this->renderer('en')->render(Fixtures::plan(
            Fixtures::fix('aaa', '1.0.0', '1.0.1'),
            $this->withSeverity(Fixtures::fix('bbb', '2.0.0', '2.0.1'), Severity::Critical),
        ));
        self::assertSame(Severity::Critical, $mixed->severity);
    }

    private function renderer(string $locale): MergeRequestRenderer
    {
        return new MergeRequestRenderer((new TranslatorFactory())->create($locale), '1.2.3');
    }

    private function yarnPlan(SeverityGroup $group): GroupPlan
    {
        $report = (new TrivyReportParser())->parseFile(__DIR__ . '/../fixtures/trivy/yarn-report.json');
        foreach ((new Planner())->plan($report) as $plan) {
            if (Ecosystem::Yarn === $plan->ecosystem && $group === $plan->group) {
                return $plan;
            }
        }

        throw new \LogicException('No such plan in the fixture.');
    }

    private function withSeverity(\Rustine\Plan\PackageFix $fix, Severity $severity): \Rustine\Plan\PackageFix
    {
        return new \Rustine\Plan\PackageFix(
            $fix->ecosystem, $fix->packageName, $fix->installedVersion, $fix->status, $fix->targetVersion,
            $severity, $fix->group, $fix->dependencyKind, $fix->dependencyChain, $fix->vulnerabilities,
            $fix->fixedIds, $fix->majorBumpIds, $fix->withoutFixIds,
        );
    }

    /**
     * @param list<string> $chain
     */
    private function withChain(\Rustine\Plan\PackageFix $fix, array $chain): \Rustine\Plan\PackageFix
    {
        return new \Rustine\Plan\PackageFix(
            $fix->ecosystem, $fix->packageName, $fix->installedVersion, $fix->status, $fix->targetVersion,
            $fix->severity, $fix->group, $fix->dependencyKind, $chain, $fix->vulnerabilities,
            $fix->fixedIds, $fix->majorBumpIds, $fix->withoutFixIds,
        );
    }
}
