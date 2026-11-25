<?php

declare(strict_types=1);

namespace Rustine\Tests\Sync\Support;

use Rustine\GitLab\GitLabClient;
use Rustine\GitLab\GitLabException;
use Rustine\GitLab\GitLabUser;
use Rustine\GitLab\MergeRequest;
use Rustine\GitLab\NewMergeRequest;

/**
 * In-memory GitLab: keeps merge requests and notes, and records every write.
 */
final class FakeGitLab implements GitLabClient
{
    /** @var array<int, MergeRequest> */
    public array $open = [];
    /** @var array<int, MergeRequest> */
    public array $closed = [];
    /** @var array<int, list<string>> */
    public array $notes = [];
    /** @var list<string> */
    public array $writes = [];
    /** @var array<string, int> */
    public array $users = [];
    public ?NewMergeRequest $lastCreated = null;
    public bool $failOnCreate = false;
    private int $nextIid = 1;

    public function currentUser(): GitLabUser
    {
        return new GitLabUser(1, 'rustine-bot', 'Rustine Bot', null);
    }

    public function findOpenMergeRequest(string $sourceBranch): ?MergeRequest
    {
        foreach ($this->open as $mr) {
            if ($mr->sourceBranch === $sourceBranch) {
                return $mr;
            }
        }

        return null;
    }

    public function findClosedMergeRequest(string $sourceBranch, \DateTimeImmutable $since): ?MergeRequest
    {
        foreach ($this->closed as $mr) {
            if ($mr->sourceBranch === $sourceBranch) {
                return $mr;
            }
        }

        return null;
    }

    public function listOpenMergeRequests(array $labels): array
    {
        return array_values(array_filter($this->open, static fn (MergeRequest $mr): bool => [] === array_diff($labels, $mr->labels)));
    }

    public function createMergeRequest(NewMergeRequest $request): MergeRequest
    {
        if ($this->failOnCreate) {
            throw new GitLabException('GitLab answered HTTP 500');
        }
        $this->writes[] = 'create ' . $request->sourceBranch;
        $this->lastCreated = $request;
        $mr = new MergeRequest($this->nextIid, $request->title, $request->description, 'https://gitlab.example.com/mr/' . $this->nextIid, $request->sourceBranch, $request->labels);
        $this->open[$this->nextIid++] = $mr;

        return $mr;
    }

    public function updateMergeRequest(int $iid, string $title, string $description, array $addLabels = [], array $removeLabels = []): MergeRequest
    {
        $this->writes[] = 'update ' . $iid;
        $old = $this->open[$iid];
        $labels = array_values(array_diff([...$old->labels, ...$addLabels], $removeLabels));

        return $this->open[$iid] = new MergeRequest($iid, $title, $description, $old->webUrl, $old->sourceBranch, $labels, $old->hasConflicts);
    }

    public function closeMergeRequest(int $iid): void
    {
        $this->writes[] = 'close ' . $iid;
        $this->closed[$iid] = $this->open[$iid];
        unset($this->open[$iid]);
    }

    public function addNote(int $iid, string $body): void
    {
        $this->writes[] = 'note ' . $iid;
        $this->notes[$iid][] = $body;
    }

    public function listNoteBodies(int $iid): array
    {
        return $this->notes[$iid] ?? [];
    }

    public function findUserId(string $username): ?int
    {
        return $this->users[$username] ?? null;
    }

    /**
     * @param list<string> $labels
     */
    public function addExisting(string $branch, string $title = 'old', string $description = 'old', array $labels = ['security']): MergeRequest
    {
        return $this->open[$this->nextIid] = new MergeRequest($this->nextIid++, $title, $description, 'https://gitlab.example.com/mr/' . ($this->nextIid - 1), $branch, $labels);
    }

    public function closeByHand(int $iid): void
    {
        $this->closed[$iid] = $this->open[$iid];
        unset($this->open[$iid]);
    }
}
