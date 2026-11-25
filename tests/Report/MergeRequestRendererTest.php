<?php

declare(strict_types=1);

namespace Rustine\Tests\Report;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\LockChange;
use Rustine\Apply\ToolVersions;
use Rustine\Apply\VerifyMode;
use Rustine\I18n\TranslatorFactory;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
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

        $this->assertSame('Security: fix Yarn dependencies (critical and high severity)', $content->title);
        $this->assertStringContainsString('> Preview of the plan', $content->description);
        $this->assertStringContainsString('| `brace-expansion` | `1.1.12` → `1.1.21` |', $content->description);
        $this->assertStringContainsString('## Fixes', $content->description);
        $this->assertStringNotContainsString('## Verification', $content->description);
        $this->assertStringContainsString('planned', $content->description);
    }

    #[Test]
    public function itListsThePackagesNeedingAManualActionWithTheirCves(): void
    {
        $content = $this->renderer('en')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        $this->assertStringContainsString('## Needs manual attention', $content->description);
        // `braces` has CVEs without any fixed version.
        $this->assertMatchesRegularExpression(
            '/- \*\*braces\*\* \(`3\.0\.3`\): .*no fixed version has been published/',
            $content->description,
        );
        $this->assertGreaterThan(0, $content->attentionItems);
    }

    #[Test]
    public function theSameContentIsRenderedInFrench(): void
    {
        $content = $this->renderer('fr')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        $this->assertSame('Sécurité : corriger les dépendances Yarn (sévérité critique et haute)', $content->title);
        $this->assertStringContainsString('## Corrections', $content->description);
        $this->assertStringContainsString('## Action manuelle requise', $content->description);
        $this->assertStringContainsString('| Paquet | Version | CVE |', $content->description);
        $this->assertStringContainsString(
            '| `brace-expansion` | `1.1.12` → `1.1.21` |',
            $content->description,
            'Data is not translated.',
        );
    }

    #[Test]
    public function aTransitiveDependencyShowsItsChain(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $fix = $this->withChain($fix, ['micromatch@4.0.5', 'braces@3.0.2']);

        $content = $this->renderer('en')->render(Fixtures::plan($fix));

        $this->assertStringContainsString('transitive, via `micromatch` → `braces`', $content->description);
    }

    #[Test]
    public function anAppliedResultIsVerifiedAndFlagsResolutionsAsDebt(): void
    {
        $fix = Fixtures::fix('minimist', '0.0.8', '0.2.4', DependencyKind::Transitive);
        $result = new ApplyResult(
            [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::Resolution, ['resolution: ok'])],
        );
        $tools = new ToolVersions(['node' => '22.11.0', 'yarn' => '4.2.2']);

        $content = $this->renderer('en')->render(Fixtures::plan($fix), $result, $tools);

        $this->assertStringNotContainsString('Preview', $content->description);
        $this->assertStringContainsString('## Technical debt', $content->description);
        $this->assertStringContainsString('`minimist` is pinned through `resolutions`', $content->description);
        $this->assertStringContainsString('## Verification', $content->description);
        $this->assertStringContainsString('The lockfile is consistent with the manifest.', $content->description);
        $this->assertStringContainsString(
            'Tools of the CI environment: `node 22.11.0, yarn 4.2.2`.',
            $content->description,
        );
        $this->assertSame(1, $content->fixedPackages);
    }

    #[Test]
    public function failuresAndUnsupportedSourcesKeepTheirTechnicalLogInACollapsedBlock(): void
    {
        $failed = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $git = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $result = new ApplyResult([
            new FixOutcome($failed, FixOutcomeStatus::Failed, null, ['lockfile-refresh: boom']),
            new FixOutcome(
                $git,
                FixOutcomeStatus::UnsupportedSource,
                null,
                ['declared as "org/repo#x" in package.json'],
            ),
        ], 'YN0028: lockfile would be modified');

        $content = $this->renderer('en')->render(Fixtures::plan($failed, $git), $result);

        $this->assertStringContainsString('no automatic fix worked', $content->description);
        $this->assertStringContainsString('declared with a source that Rustine does not change', $content->description);
        $this->assertStringContainsString('<details><summary>Technical details</summary>', $content->description);
        $this->assertStringContainsString('lockfile-refresh: boom', $content->description);
        $this->assertStringContainsString('The lockfile consistency check failed:', $content->description);
        $this->assertStringContainsString('YN0028: lockfile would be modified', $content->description);
        $this->assertSame(0, $content->fixedPackages);
        $this->assertNull($content->severity);
        $this->assertSame(2, $content->attentionItems);
    }

    #[Test]
    public function anAppliedFixThatTrivyStillReportsNeedsAManualCheck(): void
    {
        $fix = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $result = new ApplyResult(
            [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh, [], stillReported: true)],
        );

        $content = $this->renderer('fr')->render(Fixtures::plan($fix), $result);

        $this->assertStringContainsString('Trivy signale encore', $content->description);
        $this->assertSame(1, $content->fixedPackages);
        $this->assertSame(1, $content->attentionItems);
    }

    #[Test]
    public function theVerificationTellsWhenTrivyWasRun(): void
    {
        $fix = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $outcomes = [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh)];
        $render = fn(VerifyMode $mode): string => $this->renderer('en')
                                                       ->render(
                                                           Fixtures::plan($fix),
                                                           new ApplyResult($outcomes, verify: $mode),
                                                       )->description;

        $this->assertStringContainsString('after each change', $render(VerifyMode::Each));
        $this->assertStringContainsString('once every change was applied', $render(VerifyMode::Final));
        $this->assertStringContainsString('Trivy was not run again', $render(VerifyMode::None));
    }

    #[Test]
    public function skippedPackagesAreReportedFromThePlanStatus(): void
    {
        $major = Fixtures::fix('major', '1.0.0', '2.0.0', status: PackageFixStatus::MajorBumpRequired);
        $result = new ApplyResult([new FixOutcome($major, FixOutcomeStatus::Skipped)]);

        $content = $this->renderer('en')->render(Fixtures::plan($major), $result);

        $this->assertSame(0, $content->fixedPackages);
        $this->assertStringNotContainsString('## Fixes', $content->description);
    }

    #[Test]
    public function theLockfileChangesAreListedAndTheFixedPackagesAreHighlighted(): void
    {
        $fix = Fixtures::fix('ckeditor4', '4.22.1', '4.25.0');
        $result = new ApplyResult(
            [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh)],
            null,
            [
                new LockChange('ckeditor4', ['4.22.1'], ['4.25.0']),
                new LockChange('lodash', ['4.17.20'], ['4.17.21']),
                new LockChange('left-pad', [], ['1.3.0']),
            ],
        );

        $en = $this->renderer('en')->render(Fixtures::plan($fix), $result)->description;
        $fr = $this->renderer('fr')->render(Fixtures::plan($fix), $result)->description;

        $this->assertStringContainsString('## Lockfile changes', $en);
        $this->assertStringContainsString('`yarn.lock` changes for 3 packages, 1 of them fixed above', $en);
        $this->assertStringContainsString('| **`ckeditor4`** | `4.22.1` | `4.25.0` |', $en);
        $this->assertStringContainsString('| `lodash` | `4.17.20` | `4.17.21` |', $en);
        $this->assertStringContainsString('| `left-pad` | — | `1.3.0` |', $en);
        $this->assertStringContainsString('## Changements du lockfile', $fr);
        $this->assertStringContainsString('paquets (corrigés ci-dessus : 1)', $fr);
    }

    #[Test]
    public function checkFindingsThatWereAlreadyThereAreShownAsSuch(): void
    {
        $fix = Fixtures::fix();
        $result = new ApplyResult(
            [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh)],
            existingCheckFindings: '- some unrelated error',
        );

        $en = $this->renderer('en')->render(Fixtures::plan($fix), $result)->description;

        $this->assertStringContainsString('already reported findings before the fixes and reports no new one:', $en);
        $this->assertStringContainsString('- some unrelated error', $en);
        $this->assertStringNotContainsString('The lockfile is consistent with the manifest', $en);
    }

    #[Test]
    public function theLockfileTableIsCapped(): void
    {
        $fix = Fixtures::fix();
        $changes = array_map(
            static fn(int $i): LockChange => new LockChange('pkg-' . $i, ['1.0.0'], ['1.0.1']),
            range(1, 250),
        );
        $result = new ApplyResult(
            [new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh)],
            null,
            $changes,
        );

        $description = $this->renderer('en')->render(Fixtures::plan($fix), $result)->description;

        $this->assertStringContainsString('pkg-200', $description);
        $this->assertStringNotContainsString('pkg-201', $description);
        $this->assertStringContainsString('… and 50 more', $description);
    }

    #[Test]
    public function withoutLockfileChangesThereIsNoSection(): void
    {
        $fix = Fixtures::fix();
        $result = new ApplyResult([new FixOutcome($fix, FixOutcomeStatus::Applied, AttemptKind::LockfileRefresh)]);

        $this->assertStringNotContainsString(
            'Lockfile changes',
            $this->renderer('en')->render(Fixtures::plan($fix), $result)->description,
        );
        $this->assertStringNotContainsString(
            'Lockfile changes',
            $this->renderer('en')->render(Fixtures::plan($fix))->description,
        );
    }

    #[Test]
    public function theCommitKeepsTheConventionalPrefixAndLocalizesTheSentence(): void
    {
        $plan = Fixtures::plan(Fixtures::fix('ckeditor4', '4.22.1', '4.25.0'));

        $en = $this->renderer('en')->render($plan)->commitMessage;
        $fr = $this->renderer('fr')->render($plan)->commitMessage;

        $this->assertStringStartsWith('build(deps): fix vulnerable Yarn dependencies (', $en);
        $this->assertStringStartsWith('build(deps): corriger les dépendances Yarn vulnérables (', $fr);
        $this->assertStringStartsWith('build(deps): fix vulnerable Yarn dependencies (others)', $en);
        $this->assertLessThanOrEqual(
            72,
            mb_strlen(explode("\n", $fr)[0]),
            'The subject stays short in every language.',
        );
        $this->assertStringContainsString('- ckeditor4 4.22.1 → 4.25.0 (CVE-2024-0001)', $en);
        $this->assertStringContainsString('- ckeditor4 4.22.1 → 4.25.0 (CVE-2024-0001)', $fr);
    }

    #[Test]
    public function theDescriptionEndsWithTheMarkerCarryingTheFingerprint(): void
    {
        $content = $this->renderer('en')->render(Fixtures::plan(Fixtures::fix()));

        $this->assertStringContainsString(
            sprintf('<!-- rustine:v1 fingerprint=%s -->', $content->fingerprint),
            $content->description,
        );
        $this->assertStringContainsString('Generated by Rustine 1.2.3', $content->description);
    }

    #[Test]
    public function theFingerprintIgnoresOrderAndLanguageButNotVersions(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $bBumped = Fixtures::fix('bbb', '2.0.0', '2.0.2');

        $one = $this->renderer('en')->render(Fixtures::plan($a, $b))->fingerprint;

        $this->assertSame($one, $this->renderer('en')->render(Fixtures::plan($b, $a))->fingerprint);
        $this->assertSame($one, $this->renderer('fr')->render(Fixtures::plan($a, $b))->fingerprint);
        $this->assertNotSame($one, $this->renderer('en')->render(Fixtures::plan($a, $bBumped))->fingerprint);
    }

    #[Test]
    public function theSeverityIsTheHighestOfTheFixedPackages(): void
    {
        $content = $this->renderer('en')->render($this->yarnPlan(SeverityGroup::CriticalHigh));

        // Only the packages actually fixed count: the CRITICAL ones of this fixture have no fix.
        $this->assertSame(Severity::High, $content->severity);

        $mixed = $this->renderer('en')->render(
            Fixtures::plan(
                Fixtures::fix('aaa', '1.0.0', '1.0.1'),
                $this->withSeverity(Fixtures::fix('bbb', '2.0.0', '2.0.1'), Severity::Critical),
            ),
        );
        $this->assertSame(Severity::Critical, $mixed->severity);
    }

    private function renderer(string $locale): MergeRequestRenderer
    {
        return new MergeRequestRenderer(new TranslatorFactory()->create($locale), '1.2.3');
    }

    private function yarnPlan(SeverityGroup $group): GroupPlan
    {
        $report = new TrivyReportParser()->parseFile(__DIR__ . '/../fixtures/trivy/yarn-report.json');

        foreach (new Planner()->plan($report) as $plan) {
            if (Ecosystem::Yarn === $plan->ecosystem && $group === $plan->group) {
                return $plan;
            }
        }

        throw new \LogicException('No such plan in the fixture.');
    }

    private function withSeverity(PackageFix $fix, Severity $severity): PackageFix
    {
        return new PackageFix(
            $fix->ecosystem,
            $fix->packageName,
            $fix->installedVersion,
            $fix->status,
            $fix->targetVersion,
            $severity,
            $fix->group,
            $fix->dependencyKind,
            $fix->dependencyChain,
            $fix->vulnerabilities,
            $fix->fixedIds,
            $fix->majorBumpIds,
            $fix->withoutFixIds,
        );
    }

    /**
     * @param list<string> $chain
     */
    private function withChain(PackageFix $fix, array $chain): PackageFix
    {
        return new PackageFix(
            $fix->ecosystem,
            $fix->packageName,
            $fix->installedVersion,
            $fix->status,
            $fix->targetVersion,
            $fix->severity,
            $fix->group,
            $fix->dependencyKind,
            $chain,
            $fix->vulnerabilities,
            $fix->fixedIds,
            $fix->majorBumpIds,
            $fix->withoutFixIds,
        );
    }
}
