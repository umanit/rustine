<?php

declare(strict_types=1);

namespace Rustine\GitLab;

/**
 * The slice of the GitLab REST API (v4) Rustine needs, for one project.
 */
interface GitLabClient
{
    /**
     * The owner of the token. Also proves that the token is valid.
     *
     * @throws GitLabException
     */
    public function currentUser(): GitLabUser;

    /**
     * The open merge request whose source branch is `$sourceBranch`, if any.
     *
     * @throws GitLabException
     */
    public function findOpenMergeRequest(string $sourceBranch): ?MergeRequest;

    /**
     * The most recently closed (not merged) merge request whose source branch is `$sourceBranch` and that was updated
     * since `$since`, if any.
     *
     * @throws GitLabException
     */
    public function findClosedMergeRequest(string $sourceBranch, \DateTimeImmutable $since): ?MergeRequest;

    /**
     * All the open merge requests of the project carrying every label of `$labels`.
     *
     * @param list<string> $labels
     *
     * @return list<MergeRequest>
     *
     * @throws GitLabException
     */
    public function listOpenMergeRequests(array $labels): array;

    /**
     * @throws GitLabException
     */
    public function createMergeRequest(NewMergeRequest $request): MergeRequest;

    /**
     * Updates the texts and adjusts the labels without touching the ones other people added.
     *
     * @param list<string> $addLabels
     * @param list<string> $removeLabels
     *
     * @throws GitLabException
     */
    public function updateMergeRequest(int $iid, string $title, string $description, array $addLabels = [], array $removeLabels = []): MergeRequest;

    /**
     * @throws GitLabException
     */
    public function closeMergeRequest(int $iid): void;

    /**
     * @throws GitLabException
     */
    public function addNote(int $iid, string $body): void;

    /**
     * Bodies of the notes of a merge request (used to avoid posting the same remark twice).
     *
     * @return list<string>
     *
     * @throws GitLabException
     */
    public function listNoteBodies(int $iid): array;

    /**
     * Id of the user with this username, null if there is none.
     *
     * @throws GitLabException
     */
    public function findUserId(string $username): ?int;
}
