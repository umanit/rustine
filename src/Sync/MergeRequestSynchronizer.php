<?php

declare(strict_types=1);

namespace Rustine\Sync;

use Rustine\Apply\ApplyResult;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\PlanApplier;
use Rustine\Apply\ToolingException;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Git\BranchPublisher;
use Rustine\Git\GitException;
use Rustine\Git\PushResult;
use Rustine\Git\RemoteBranch;
use Rustine\Git\RemoteBranchState;
use Rustine\GitLab\GitLabClient;
use Rustine\GitLab\GitLabException;
use Rustine\GitLab\MergeRequest;
use Rustine\GitLab\NewMergeRequest;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Progress\NullProgress;
use Rustine\Progress\Progress;
use Rustine\Report\MergeRequestContent;
use Rustine\Report\MergeRequestRenderer;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyReport;

/**
 * Brings the branch and the merge request of one plan to the state the plan calls for.
 *
 * A merge request exists if and only if at least one fix was applied:
 *  - no open merge request and a fix → push the branch, open the merge request;
 *  - an open merge request and the same tree, or the same fingerprint without conflicts → nothing (the texts are
 *    refreshed if they are out of date);
 *  - an open merge request and a different tree → force-push, refresh the texts, leave a note;
 *  - an open merge request and no fix anymore → close it with a note;
 *  - no open merge request, but one closed by hand recently for the same fingerprint → nothing (the "no thanks" holds).
 * The limit of open merge requests only prevents opening new ones.
 */
final readonly class MergeRequestSynchronizer
{
    public function __construct(
        private GitLabClient $gitlab,
        private BranchPublisher $git,
        private PlanApplier $applier,
        private MergeRequestRenderer $renderer,
        private NoteRenderer $notes,
        private SyncSettings $settings,
        private Progress $progress = new NullProgress(),
    ) {
    }

    /**
     * Never throws for a problem of this plan: it is reported as {@see PlanAction::Failed}, so that the other plans
     * of the run still get processed.
     */
    public function sync(GroupPlan $plan, TrivyReport $report): PlanOutcome
    {
        try {
            return $this->process($plan, $report);
        } catch (GitException | GitLabException | UnsupportedProjectException | ToolingException $e) {
            return new PlanOutcome($plan->branchName(), PlanAction::Failed, null, null, [$e->getMessage()]);
        }
    }

    private function process(GroupPlan $plan, TrivyReport $report): PlanOutcome
    {
        $branch = $plan->branchName();
        $this->progress->step('Looking for an open merge request');
        $existing = $this->gitlab->findOpenMergeRequest($branch);

        if ([] === $plan->fixes) {
            return $this->nothingLeft($plan, $existing);
        }

        if ([] === $plan->fixable()) {
            return $this->nothingToFix($plan, $existing, $this->withoutFixes($plan));
        }

        if (!$existing instanceof MergeRequest && $this->limitReached()) {
            return new PlanOutcome($branch, PlanAction::SkippedLimit, $this->renderer->render($plan), null, [
                sprintf(
                    '%d open Rustine merge requests: the limit is reached, no new merge request is opened.',
                    $this->settings->maxOpenMergeRequests ?? 0,
                ),
            ]);
        }

        $this->progress->step('Fetching the default branch and the state of ' . $branch);
        $remote = $this->git->inspect($branch);
        if (RemoteBranchState::Foreign === $remote->state) {
            if (
                $existing instanceof MergeRequest
                && !$this->alreadyNoted($existing, NoteRenderer::FOREIGN_MARKER)
            ) {
                $this->gitlab->addNote($existing->iid, $this->notes->foreign($branch));
            }

            return new PlanOutcome(
                $branch,
                PlanAction::SkippedForeign,
                $this->renderer->render($plan),
                $existing?->webUrl,
                [
                    sprintf('The branch %s carries commits that Rustine did not create: left untouched.', $branch),
                ],
            );
        }

        $this->progress->step('Starting from the default branch');
        $this->git->start($branch);

        try {
            $applied = $this->applier->apply($plan, $report);
            $content = $this->renderer->render($plan, $applied->result, $applied->tools);

            if (null !== $applied->result->finalCheckError) {
                return new PlanOutcome($branch, PlanAction::Failed, $content, $existing?->webUrl, [
                    'The lockfile consistency check failed, nothing was pushed: ' . $applied->result->finalCheckError,
                ]);
            }

            if (
                !$existing instanceof MergeRequest
                && $applied->result->hasChanges()
                && $this->dismissedByHand($branch, $content)
            ) {
                return new PlanOutcome($branch, PlanAction::SkippedClosed, $content, null, [
                    sprintf(
                        'A merge request for these very fixes was closed less than %d days ago: not opened again.',
                        $this->settings->closedGraceDays,
                    ),
                ]);
            }

            if ($applied->result->hasChanges()) {
                $this->progress->step('Committing the fixes');
            }

            $push = $applied->result->hasChanges() && $this->git->commit($content->commitMessage)
                ? ($this->canKeepBranch($existing, $remote, $content) ? PushResult::Kept : $this->git->push(
                    $branch,
                    $remote,
                ))
                : null;
        } finally {
            $this->git->finish();
        }

        return $push instanceof PushResult
            ? $this->syncMergeRequest($branch, $existing, $content, $push)
            : $this->nothingToFix($plan, $existing, $content);
    }

    /**
     * The branch is kept as it is when the open merge request was written for the very same fixes and still merges:
     * the default branch moving on changes the tree of a rebuilt branch, but not what the merge request does, and
     * rebuilding it every day would restart its pipeline and may reset its approvals.
     */
    private function canKeepBranch(?MergeRequest $existing, RemoteBranch $remote, MergeRequestContent $content): bool
    {
        return $existing instanceof MergeRequest
            && null !== $remote->sha
            && !$existing->hasConflicts
            && $content->fingerprint === MergeRequestRenderer::fingerprintOf($existing->description);
    }

    /**
     * "No thanks" must stick: a merge request closed by a person, for the same fixes, is not opened again each morning.
     * The ones Rustine closed itself (nothing fixable anymore) carry its note and do not count.
     */
    private function dismissedByHand(string $branch, MergeRequestContent $content): bool
    {
        if ($this->settings->closedGraceDays <= 0) {
            return false;
        }

        $closed = $this->gitlab->findClosedMergeRequest(
            $branch,
            new \DateTimeImmutable(sprintf('-%d days', $this->settings->closedGraceDays)),
        );

        return $closed instanceof MergeRequest
            && $content->fingerprint === MergeRequestRenderer::fingerprintOf($closed->description)
            && !$this->alreadyNoted($closed, NoteRenderer::CLOSED_MARKER);
    }

    private function syncMergeRequest(
        string $branch,
        ?MergeRequest $existing,
        MergeRequestContent $content,
        PushResult $push,
    ): PlanOutcome {
        $this->progress->step(null === $existing ? 'Opening the merge request' : 'Synchronizing the merge request');
        $severity = $content->severity;
        $wanted = [
            ...$this->settings->labels,
            ...($severity instanceof Severity ? [$this->settings->severityLabel($severity)] : []),
        ];

        if (!$existing instanceof MergeRequest) {
            $messages = [];
            $created = $this->gitlab->createMergeRequest(
                new NewMergeRequest(
                    $branch,
                    $this->settings->defaultBranch,
                    $content->title,
                    $content->description,
                    $wanted,
                    $this->userIds($this->settings->assignees, $messages),
                    $this->userIds($this->settings->reviewers, $messages),
                ),
            );

            return new PlanOutcome($branch, PlanAction::Created, $content, $created->webUrl, $messages);
        }

        $add = array_values(array_diff($wanted, $existing->labels));
        $remove = $severity instanceof Severity ? array_values(
            array_intersect($this->settings->otherSeverityLabels($severity), $existing->labels),
        ) : [];
        $stale = trim($existing->title) !== trim($content->title)
            || trim($existing->description) !== trim($content->description)
            || [] !== $add
            || [] !== $remove;

        if (PushResult::Pushed === $push) {
            $this->gitlab->updateMergeRequest($existing->iid, $content->title, $content->description, $add, $remove);
            $this->gitlab->addNote($existing->iid, $this->notes->updated($content));

            return new PlanOutcome($branch, PlanAction::Updated, $content, $existing->webUrl);
        }

        // A kept branch was not rebuilt: its description keeps describing it (the lockfile changes in particular).
        if (PushResult::Kept === $push) {
            $stale = trim($existing->title) !== trim($content->title) || [] !== $add || [] !== $remove;
            $content = $content->withDescription($existing->description);
        }

        if ($stale) {
            $this->gitlab->updateMergeRequest($existing->iid, $content->title, $content->description, $add, $remove);

            return new PlanOutcome($branch, PlanAction::Refreshed, $content, $existing->webUrl);
        }

        return new PlanOutcome($branch, PlanAction::Unchanged, $content, $existing->webUrl);
    }

    /**
     * The scan found nothing at all for the group: its open merge request, if any, has no purpose anymore.
     */
    private function nothingLeft(GroupPlan $plan, ?MergeRequest $existing): PlanOutcome
    {
        if (!$existing instanceof MergeRequest) {
            return new PlanOutcome($plan->branchName(), PlanAction::Clear);
        }

        $this->gitlab->addNote($existing->iid, $this->notes->closed());
        $this->gitlab->closeMergeRequest($existing->iid);

        return new PlanOutcome($plan->branchName(), PlanAction::Closed, null, $existing->webUrl);
    }

    private function nothingToFix(GroupPlan $plan, ?MergeRequest $existing, MergeRequestContent $content): PlanOutcome
    {
        if (!$existing instanceof MergeRequest) {
            return new PlanOutcome($plan->branchName(), PlanAction::NoFix, $content);
        }

        $this->gitlab->addNote($existing->iid, $this->notes->closed());
        $this->gitlab->closeMergeRequest($existing->iid);

        return new PlanOutcome($plan->branchName(), PlanAction::Closed, $content, $existing->webUrl);
    }

    /**
     * Texts of a group on which nothing could be applied: only the manual items are of interest.
     */
    private function withoutFixes(GroupPlan $plan): MergeRequestContent
    {
        return $this->renderer->render(
            $plan,
            new ApplyResult(
                array_map(
                    static fn(PackageFix $fix): FixOutcome => new FixOutcome(
                        $fix,
                        FixOutcomeStatus::Skipped,
                    ),
                    $plan->fixes,
                ),
            ),
        );
    }

    private function limitReached(): bool
    {
        $max = $this->settings->maxOpenMergeRequests;
        if (null === $max) {
            return false;
        }

        $open = array_filter(
            $this->gitlab->listOpenMergeRequests($this->settings->labels),
            static fn(MergeRequest $mr): bool => str_starts_with($mr->sourceBranch, BranchPublisher::BRANCH_PREFIX),
        );

        return \count($open) >= $max;
    }

    private function alreadyNoted(MergeRequest $mr, string $marker): bool
    {
        return array_any(
            $this->gitlab->listNoteBodies($mr->iid),
            static fn(string $body): bool => str_contains($body, $marker),
        );
    }

    /**
     * @param list<string> $usernames
     * @param list<string> $messages receives a warning for each unknown user
     *
     * @return list<int>
     */
    private function userIds(array $usernames, array &$messages): array
    {
        $ids = [];

        foreach ($usernames as $username) {
            $id = $this->gitlab->findUserId($username);
            if (null === $id) {
                $messages[] = sprintf('GitLab user "%s" not found: ignored.', $username);

                continue;
            }
            $ids[] = $id;
        }

        return $ids;
    }
}
