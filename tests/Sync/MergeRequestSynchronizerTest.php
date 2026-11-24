<?php

declare(strict_types=1);

namespace Rustine\Tests\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Git\BranchPublisher;
use Rustine\Git\GitIdentity;
use Rustine\I18n\TranslatorFactory;
use Rustine\Pinning\PinningException;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Sync\MergeRequestSynchronizer;
use Rustine\Sync\NoteRenderer;
use Rustine\Sync\PlanAction;
use Rustine\Sync\SyncSettings;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Git\Support\GitSandbox;
use Rustine\Tests\Sync\Support\FakeGitLab;
use Rustine\Tests\Sync\Support\FakePlanApplier;
use Rustine\Plan\PackageFixStatus;

final class MergeRequestSynchronizerTest extends TestCase
{
    private const BRANCH = 'security/rustine-yarn-others';

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

        $outcome = $this->sync(settings: new SyncSettings('main', ['security'], 'severity::{severity}', ['alice', 'ghost'], ['alice']));

        self::assertSame(PlanAction::Created, $outcome->action);
        self::assertSame('https://gitlab.example.com/mr/1', $outcome->mergeRequestUrl);
        self::assertSame("lock v2\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        $created = $this->gitlab->lastCreated;
        self::assertSame(self::BRANCH, $created?->sourceBranch);
        self::assertSame('main', $created->targetBranch);
        self::assertSame(['security', 'severity::medium'], $created->labels);
        self::assertSame([11], $created->assigneeIds);
        self::assertSame([11], $created->reviewerIds);
        self::assertStringContainsString('GitLab user "ghost" not found', implode("\n", $outcome->messages));
        self::assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])), 'the working tree is back where it was');
    }

    #[Test]
    public function aSecondRunWithTheSameFixChangesNothing(): void
    {
        $this->sync();
        $pushed = $this->sandbox->remoteSha(self::BRANCH);
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        self::assertSame(PlanAction::Unchanged, $outcome->action);
        self::assertSame([], $this->gitlab->writes);
        self::assertSame($pushed, $this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aDifferentFixForcePushesRefreshesAndLeavesANote(): void
    {
        $this->sync();
        $this->applier->lock = "lock v3\n";

        $outcome = $this->sync();

        self::assertSame(PlanAction::Updated, $outcome->action);
        self::assertSame("lock v3\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        self::assertSame(['update 1', 'note 1'], array_slice($this->gitlab->writes, -2));
        self::assertStringContainsString('rebuilt by a new Rustine run', $this->gitlab->notes[1][0]);
    }

    #[Test]
    public function outOfDateTextsAreRefreshedWithoutANote(): void
    {
        $this->sync();
        $this->gitlab->open[1] = new \Rustine\GitLab\MergeRequest(1, 'Edited title', 'Edited by hand', 'https://gitlab.example.com/mr/1', self::BRANCH, ['security', 'severity::high']);
        $this->gitlab->writes = [];

        $outcome = $this->sync();

        self::assertSame(PlanAction::Refreshed, $outcome->action);
        self::assertSame(['update 1'], $this->gitlab->writes);
        self::assertSame(['security', 'severity::medium'], $this->gitlab->open[1]->labels, 'the stale severity label is swapped');
        self::assertSame($outcome->content?->title, $this->gitlab->open[1]->title);
    }

    #[Test]
    public function whenNothingWorksAnymoreTheOpenMergeRequestIsClosed(): void
    {
        $this->sync();
        $this->applier->succeed = false;

        $outcome = $this->sync();

        self::assertSame(PlanAction::Closed, $outcome->action);
        self::assertSame([], $this->gitlab->open);
        self::assertStringContainsString('closing this merge request', $this->gitlab->notes[1][0]);
        self::assertTrue($outcome->needsManualAction(), 'the failed package is flagged for a human');
    }

    #[Test]
    public function aGroupWithNothingFixableTouchesNeitherGitNorGitLab(): void
    {
        $plan = Fixtures::plan(Fixtures::fix(status: PackageFixStatus::MajorBumpRequired));

        $outcome = $this->sync(plan: $plan);

        self::assertSame(PlanAction::NoFix, $outcome->action);
        self::assertSame(0, $this->applier->calls);
        self::assertSame([], $this->gitlab->writes);
        self::assertNull($this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aGroupWithNothingFixableClosesItsOpenMergeRequest(): void
    {
        $this->gitlab->addExisting(self::BRANCH);

        $outcome = $this->sync(plan: Fixtures::plan(Fixtures::fix(status: PackageFixStatus::NoFixAvailable)));

        self::assertSame(PlanAction::Closed, $outcome->action);
        self::assertSame(['note 1', 'close 1'], $this->gitlab->writes);
    }

    #[Test]
    public function theLimitOnlyPreventsNewMergeRequests(): void
    {
        $this->gitlab->addExisting('security/rustine-composer-others');
        $settings = new SyncSettings('main', ['security'], maxOpenMergeRequests: 1);

        $skipped = $this->sync(settings: $settings);

        self::assertSame(PlanAction::SkippedLimit, $skipped->action);
        self::assertTrue($skipped->needsManualAction());
        self::assertSame(0, $this->applier->calls);
        self::assertNull($this->sandbox->remoteSha(self::BRANCH));

        // Once the branch has its merge request, the limit no longer applies to it.
        $this->sync();
        $updated = $this->sync($settings);
        self::assertContains($updated->action, [PlanAction::Created, PlanAction::Unchanged]);
    }

    #[Test]
    public function theLimitDoesNotBlockUpdatingAnExistingMergeRequest(): void
    {
        $this->sync();
        $this->applier->lock = "lock v3\n";

        $outcome = $this->sync(settings: new SyncSettings('main', ['security'], maxOpenMergeRequests: 1));

        self::assertSame(PlanAction::Updated, $outcome->action);
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

        self::assertSame(PlanAction::SkippedForeign, $first->action);
        self::assertSame(PlanAction::SkippedForeign, $second->action);
        self::assertTrue($first->needsManualAction());
        self::assertSame($theirs, $this->sandbox->remoteSha(self::BRANCH));
        self::assertSame(0, $this->applier->calls);
        self::assertCount(1, $this->gitlab->notes[1], 'the same remark is not repeated');
        self::assertStringContainsString('did not create', $this->gitlab->notes[1][0]);
    }

    #[Test]
    public function aFailedConsistencyCheckPushesNothing(): void
    {
        $this->applier->finalCheckError = 'lockfile would be modified';

        $outcome = $this->sync();

        self::assertSame(PlanAction::Failed, $outcome->action);
        self::assertTrue($outcome->isFailure());
        self::assertStringContainsString('lockfile would be modified', $outcome->messages[0]);
        self::assertNull($this->sandbox->remoteSha(self::BRANCH));
        self::assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
        self::assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
    }

    #[Test]
    public function aGitLabErrorIsReportedAndTheWorkingTreeRestored(): void
    {
        $this->gitlab->failOnCreate = true;

        $outcome = $this->sync();

        self::assertSame(PlanAction::Failed, $outcome->action);
        self::assertStringContainsString('HTTP 500', $outcome->messages[0]);
        self::assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
    }

    #[Test]
    public function aProjectErrorIsReportedAndTheWorkingTreeRestored(): void
    {
        $this->applier->throw = new PinningException('Yarn is not pinned');

        $outcome = $this->sync();

        self::assertSame(PlanAction::Failed, $outcome->action);
        self::assertSame('Yarn is not pinned', $outcome->messages[0]);
        self::assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
        self::assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
        self::assertNull($this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function textsAreWrittenInTheRequestedLanguage(): void
    {
        $outcome = $this->sync(locale: 'fr');

        self::assertSame('Sécurité : corriger les dépendances Yarn (sévérité moyenne, basse et inconnue)', $this->gitlab->lastCreated?->title);
        self::assertSame(PlanAction::Created, $outcome->action);
    }

    private function sync(?SyncSettings $settings = null, ?\Rustine\Plan\GroupPlan $plan = null, string $locale = 'en'): \Rustine\Sync\PlanOutcome
    {
        $translator = (new TranslatorFactory())->create($locale);
        $synchronizer = new MergeRequestSynchronizer(
            $this->gitlab,
            new BranchPublisher($this->sandbox->git(), 'main', new GitIdentity('Rustine Bot', 'bot@example.com')),
            $this->applier,
            new MergeRequestRenderer($translator, 'test'),
            new NoteRenderer($translator),
            $settings ?? new SyncSettings('main'),
        );

        return $synchronizer->sync($plan ?? Fixtures::plan(Fixtures::fix()), Fixtures::report());
    }
}
