<?php

declare(strict_types=1);

namespace Rustine\Tests\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\LockChange;
use Rustine\Apply\ToolingException;
use Rustine\Git\BranchPublisher;
use Rustine\Git\GitIdentity;
use Rustine\GitLab\MergeRequest;
use Rustine\I18n\TranslatorFactory;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFixStatus;
use Rustine\Progress\NullProgress;
use Rustine\Progress\Progress;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Sync\MergeRequestSynchronizer;
use Rustine\Sync\NoteRenderer;
use Rustine\Sync\PlanAction;
use Rustine\Sync\PlanOutcome;
use Rustine\Sync\SyncSettings;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Git\Support\GitSandbox;
use Rustine\Tests\Sync\Support\FakeGitLab;
use Rustine\Tests\Sync\Support\FakePlanApplier;

final class MergeRequestSynchronizerTest extends TestCase
{
    private const string BRANCH = 'security/rustine-yarn-others';

    private GitSandbox $sandbox;

    private FakeGitLab $gitlab;

    private FakePlanApplier $applier;

    protected function setUp(): void
    {
        $this->sandbox = new GitSandbox();
        $this->gitlab = new FakeGitLab();
        $this->applier = new FakePlanApplier($this->sandbox->project);
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function aFixOpensAMergeRequest(): void
    {
        $this->gitlab->users = ['alice' => 11];

        $outcome = $this->sync(
            settings: new SyncSettings(
                'main',
                ['security'],
                'severity::{severity}',
                ['alice', 'ghost'],
                ['alice'],
            ),
        );

        $this->assertSame(PlanAction::Created, $outcome->action);
        $this->assertSame('https://gitlab.example.com/mr/1', $outcome->mergeRequestUrl);
        $this->assertSame("lock v2\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        $created = $this->gitlab->lastCreated;
        $this->assertSame(self::BRANCH, $created?->sourceBranch);
        $this->assertSame('main', $created->targetBranch);
        $this->assertSame(['security', 'severity::medium'], $created->labels);
        $this->assertSame([11], $created->assigneeIds);
        $this->assertSame([11], $created->reviewerIds);
        $this->assertStringContainsString('GitLab user "ghost" not found', implode("\n", $outcome->messages));
        $this->assertSame(
            'main',
            trim($this->sandbox->exec(['git', 'branch', '--show-current'])),
            'the working tree is back where it was',
        );
    }

    #[Test]
    public function itTellsWhatItDoesWhileItWorks(): void
    {
        $progress = new class implements Progress {
            /** @var list<string> */
            public array $steps = [];

            public function step(string $message): void
            {
                $this->steps[] = $message;
            }
        };

        $this->sync(progress: $progress);

        $this->assertSame(
            [
                'Looking for an open merge request',
                'Fetching the default branch and the state of security/rustine-yarn-others',
                'Starting from the default branch',
                'Committing the fixes',
                'Opening the merge request',
            ],
            $progress->steps,
        );
    }

    #[Test]
    public function aSecondRunWithTheSameFixChangesNothing(): void
    {
        $this->sync();
        $pushed = $this->sandbox->remoteSha(self::BRANCH);
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Unchanged, $outcome->action);
        $this->assertSame([], $this->gitlab->writes);
        $this->assertSame($pushed, $this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aDifferentFixForcePushesRefreshesAndLeavesANote(): void
    {
        $this->sync();
        $this->applier->lock = "lock v3\n";

        $outcome = $this->sync(plan: Fixtures::plan(Fixtures::fix(target: '4.26.0')));

        $this->assertSame(PlanAction::Updated, $outcome->action);
        $this->assertSame("lock v3\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        $this->assertSame(['update 1', 'note 1'], array_slice($this->gitlab->writes, -2));
        $this->assertStringContainsString('rebuilt by a new Rustine run', $this->gitlab->notes[1][0]);
    }

    #[Test]
    public function theDefaultBranchMovingOnDoesNotRebuildTheBranchForTheSameFixes(): void
    {
        $this->sync();
        $pushed = $this->sandbox->remoteSha(self::BRANCH);
        $this->defaultBranchMovesOn();
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Unchanged, $outcome->action);
        $this->assertSame([], $this->gitlab->writes, 'no note, no update');
        $this->assertSame($pushed, $this->sandbox->remoteSha(self::BRANCH), 'no force-push');
    }

    #[Test]
    public function aKeptBranchKeepsTheDescriptionThatDescribesIt(): void
    {
        $this->applier->lockChanges = [new LockChange('lodash', ['4.17.20'], ['4.17.21'])];
        $this->sync();
        $description = $this->gitlab->open[1]->description;
        $this->assertStringContainsString('`lodash`', $description);

        /*
         * The default branch moves on and the refresh now moves another package: the branch is kept, so is its
         * description.
         */
        $this->defaultBranchMovesOn();
        $this->applier->lockChanges = [new LockChange('left-pad', ['1.2.0'], ['1.3.0'])];
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Unchanged, $outcome->action);
        $this->assertSame([], $this->gitlab->writes);
        $this->assertSame($description, $this->gitlab->open[1]->description);
    }

    #[Test]
    public function aMergeRequestInConflictIsRebuiltEvenForTheSameFixes(): void
    {
        $this->sync();
        $this->defaultBranchMovesOn();
        $mr = $this->gitlab->open[1];
        $this->gitlab->open[1] = new MergeRequest(
            $mr->iid,
            $mr->title,
            $mr->description,
            $mr->webUrl,
            $mr->sourceBranch,
            $mr->labels,
            true,
        );
        $pushed = $this->sandbox->remoteSha(self::BRANCH);

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Updated, $outcome->action);
        $this->assertNotSame($pushed, $this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aMergeRequestWithoutFingerprintIsRebuilt(): void
    {
        $this->sync();
        $this->defaultBranchMovesOn();
        $mr = $this->gitlab->open[1];
        $this->gitlab->open[1] = new MergeRequest(
            $mr->iid,
            $mr->title,
            'Edited by hand',
            $mr->webUrl,
            $mr->sourceBranch,
            $mr->labels,
        );

        $this->assertSame(PlanAction::Updated, $this->sync()->action);
    }

    #[Test]
    public function outOfDateTextsAreRefreshedWithoutANote(): void
    {
        $this->sync();
        $this->gitlab->open[1] = new MergeRequest(
            1,
            'Edited title',
            'Edited by hand',
            'https://gitlab.example.com/mr/1',
            self::BRANCH,
            ['security', 'severity::high'],
        );
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Refreshed, $outcome->action);
        $this->assertSame(['update 1'], $this->gitlab->writes);
        $this->assertSame(
            ['security', 'severity::medium'],
            $this->gitlab->open[1]->labels,
            'the stale severity label is swapped',
        );
        $this->assertSame($outcome->content?->title, $this->gitlab->open[1]->title);
    }

    #[Test]
    public function whenNothingWorksAnymoreTheOpenMergeRequestIsClosed(): void
    {
        $this->sync();
        $this->applier->succeed = false;

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Closed, $outcome->action);
        $this->assertSame([], $this->gitlab->open);
        $this->assertStringContainsString('closing this merge request', $this->gitlab->notes[1][0]);
        $this->assertTrue($outcome->needsManualAction(), 'the failed package is flagged for a human');
    }

    #[Test]
    public function aGroupWithNothingFixableTouchesNeitherGitNorGitLab(): void
    {
        $plan = Fixtures::plan(Fixtures::fix(status: PackageFixStatus::MajorBumpRequired));

        $outcome = $this->sync(plan: $plan);

        $this->assertSame(PlanAction::NoFix, $outcome->action);
        $this->assertSame(0, $this->applier->calls);
        $this->assertSame([], $this->gitlab->writes);
        $this->assertNull($this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aGroupWithNothingFixableClosesItsOpenMergeRequest(): void
    {
        $this->gitlab->addExisting(self::BRANCH);

        $outcome = $this->sync(plan: Fixtures::plan(Fixtures::fix(status: PackageFixStatus::NoFixAvailable)));

        $this->assertSame(PlanAction::Closed, $outcome->action);
        $this->assertSame(['note 1', 'close 1'], $this->gitlab->writes);
    }

    #[Test]
    public function aMergeRequestClosedByHandIsNotOpenedAgainForTheSameFixes(): void
    {
        $this->sync();
        $this->gitlab->closeByHand(1);
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        $this->assertSame(PlanAction::SkippedClosed, $outcome->action);
        $this->assertFalse($outcome->needsManualAction());
        $this->assertSame([], $this->gitlab->writes);
        $this->assertStringContainsString('closed less than 30 days ago', $outcome->messages[0]);
    }

    #[Test]
    public function aMergeRequestClosedByHandIsOpenedAgainForDifferentFixes(): void
    {
        $this->sync();
        $this->gitlab->closeByHand(1);

        $outcome = $this->sync(plan: Fixtures::plan(Fixtures::fix(target: '4.26.0')));

        $this->assertSame(PlanAction::Created, $outcome->action);
    }

    #[Test]
    public function aGraceOfZeroDaysOpensANewMergeRequestAlways(): void
    {
        $this->sync();
        $this->gitlab->closeByHand(1);

        $outcome = $this->sync(settings: new SyncSettings('main', closedGraceDays: 0));

        $this->assertSame(PlanAction::Created, $outcome->action);
    }

    #[Test]
    public function aMergeRequestThatRustineClosedItselfDoesNotCountAsRefused(): void
    {
        $this->sync();
        // Nothing fixable anymore: Rustine closes it with its own note.
        $this->sync(plan: Fixtures::plan(Fixtures::fix(status: PackageFixStatus::NoFixAvailable)));
        $this->assertArrayHasKey(1, $this->gitlab->closed);

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Created, $outcome->action);
    }

    #[Test]
    public function theLimitOnlyPreventsNewMergeRequests(): void
    {
        $this->gitlab->addExisting('security/rustine-composer-others');
        $settings = new SyncSettings('main', ['security'], maxOpenMergeRequests: 1);

        $skipped = $this->sync(settings: $settings);

        $this->assertSame(PlanAction::SkippedLimit, $skipped->action);
        $this->assertTrue($skipped->needsManualAction());
        $this->assertSame(0, $this->applier->calls);
        $this->assertNull($this->sandbox->remoteSha(self::BRANCH));

        // Once the branch has its merge request, the limit no longer applies to it.
        $this->sync();
        $updated = $this->sync($settings);
        $this->assertContains($updated->action, [PlanAction::Created, PlanAction::Unchanged]);
    }

    #[Test]
    public function theLimitDoesNotBlockUpdatingAnExistingMergeRequest(): void
    {
        $this->sync();
        $this->applier->lock = "lock v3\n";

        $outcome = $this->sync(
            settings: new SyncSettings('main', ['security'], maxOpenMergeRequests: 1),
            plan: Fixtures::plan(Fixtures::fix(target: '4.26.0')),
        );

        $this->assertSame(PlanAction::Updated, $outcome->action);
    }

    #[Test]
    public function aBranchWithForeignCommitsIsLeftAloneAndTheNoteIsPostedOnce(): void
    {
        $this->sync();
        $other = $this->sandbox->otherClone();
        $this->sandbox->exec(['git', 'checkout', '--quiet', self::BRANCH], $other);
        file_put_contents($other . '/README.md', "human\n");
        $this->sandbox->exec(['git', 'commit', '--quiet', '-am', 'manual'], $other);
        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', self::BRANCH], $other);

        $theirs = $this->sandbox->remoteSha(self::BRANCH);
        $this->applier->calls = 0;

        $first = $this->sync();
        $second = $this->sync();

        $this->assertSame(PlanAction::SkippedForeign, $first->action);
        $this->assertSame(PlanAction::SkippedForeign, $second->action);
        $this->assertTrue($first->needsManualAction());
        $this->assertSame($theirs, $this->sandbox->remoteSha(self::BRANCH));
        $this->assertSame(0, $this->applier->calls);
        $this->assertCount(1, $this->gitlab->notes[1], 'the same remark is not repeated');
        $this->assertStringContainsString('did not create', $this->gitlab->notes[1][0]);
    }

    #[Test]
    public function aFailedConsistencyCheckPushesNothing(): void
    {
        $this->applier->finalCheckError = 'lockfile would be modified';

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Failed, $outcome->action);
        $this->assertTrue($outcome->isFailure());
        $this->assertStringContainsString('lockfile would be modified', $outcome->messages[0]);
        $this->assertNull($this->sandbox->remoteSha(self::BRANCH));
        $this->assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
        $this->assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
    }

    #[Test]
    public function aGitLabErrorIsReportedAndTheWorkingTreeRestored(): void
    {
        $this->gitlab->failOnCreate = true;

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Failed, $outcome->action);
        $this->assertStringContainsString('HTTP 500', $outcome->messages[0]);
        $this->assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
    }

    #[Test]
    public function aProjectErrorIsReportedAndTheWorkingTreeRestored(): void
    {
        $this->applier->throw = new ToolingException('Yarn is not available');

        $outcome = $this->sync();

        $this->assertSame(PlanAction::Failed, $outcome->action);
        $this->assertSame('Yarn is not available', $outcome->messages[0]);
        $this->assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
        $this->assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
        $this->assertNull($this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function textsAreWrittenInTheRequestedLanguage(): void
    {
        $outcome = $this->sync(locale: 'fr');

        $this->assertSame(
            'Sécurité : corriger les dépendances Yarn (sévérité moyenne, basse et inconnue)',
            $this->gitlab->lastCreated?->title,
        );
        $this->assertSame(PlanAction::Created, $outcome->action);
    }

    private function defaultBranchMovesOn(): void
    {
        $other = $this->sandbox->otherClone();
        file_put_contents($other . '/CHANGELOG.md', "moved on\n");
        $this->sandbox->exec(['git', 'add', '.'], $other);
        $this->sandbox->exec(['git', 'commit', '--quiet', '-m', 'feat: something else'], $other);
        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', 'HEAD:main'], $other);
    }

    private function sync(
        ?SyncSettings $settings = null,
        ?GroupPlan $plan = null,
        string $locale = 'en',
        ?Progress $progress = null,
    ): PlanOutcome {
        $translator = new TranslatorFactory()->create($locale);
        $synchronizer = new MergeRequestSynchronizer(
            $this->gitlab,
            new BranchPublisher($this->sandbox->git(), 'main', new GitIdentity('Rustine Bot', 'bot@example.com')),
            $this->applier,
            new MergeRequestRenderer($translator, 'test'),
            new NoteRenderer($translator),
            $settings ?? new SyncSettings('main'),
            $progress ?? new NullProgress(),
        );

        return $synchronizer->sync($plan ?? Fixtures::plan(Fixtures::fix()), Fixtures::report());
    }
}
