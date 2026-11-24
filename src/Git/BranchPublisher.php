<?php

declare(strict_types=1);

namespace Rustine\Git;

use Rustine\Apply\WorkingTree;

/**
 * Prepares, commits and pushes the branch of one merge request.
 *
 * Expected sequence: {@see inspect()} → {@see start()} → (fixes are applied to the working tree) →
 * {@see commit()} → {@see push()} → {@see finish()}. The branch always restarts from the default branch, so a push
 * is a force-push guarded by a lease on the commit seen by {@see inspect()}; it is skipped when the tree of the new
 * commit equals the tree of the remote branch. The default branch is never pushed.
 *
 * Only {@see WorkingTree::ALLOWED_FILES} are committed: anything else the working tree contains is left alone.
 */
final class BranchPublisher
{
    public const BRANCH_PREFIX = 'security/rustine-';

    /** Trailer added to every commit, so that commits made by someone else on the branch can be recognized. */
    public const TRAILER = 'Generated-by: Rustine';

    /** Where the working tree was before {@see start()}: a branch name or a commit. */
    private ?string $origin = null;

    public function __construct(
        private readonly Git $git,
        private readonly string $defaultBranch,
        private readonly GitIdentity $identity,
        private readonly string $remote = 'origin',
    ) {
    }

    /**
     * Fetches the default branch and the Rustine branch, and tells whether the latter can be overwritten.
     *
     * @throws GitException
     */
    public function inspect(string $branch): RemoteBranch
    {
        $this->assertRustineBranch($branch);

        $this->git->run(['fetch', '--no-tags', $this->remote, $this->refspec($this->defaultBranch)], true);

        $listing = $this->git->run(['ls-remote', '--heads', $this->remote, 'refs/heads/' . $branch], true);
        if ('' === trim($listing)) {
            return new RemoteBranch(RemoteBranchState::Absent);
        }

        // The ownership check walks the commits between the default branch and the Rustine branch: a shallow clone
        // would make it list unrelated history.
        if ('true' === trim($this->git->run(['rev-parse', '--is-shallow-repository']))) {
            $this->git->run(['fetch', '--no-tags', '--unshallow', $this->remote], true);
        }
        $this->git->run(['fetch', '--no-tags', $this->remote, $this->refspec($branch)], true);

        $sha = trim($this->git->run(['rev-parse', $this->remoteRef($branch)]));

        return new RemoteBranch($this->onlyRustineCommits($branch) ? RemoteBranchState::Rustine : RemoteBranchState::Foreign, $sha);
    }

    /**
     * Checks out the branch, (re)created from the remote default branch.
     *
     * @throws GitException when a file Rustine may change is already modified, or when something is staged
     */
    public function start(string $branch): void
    {
        $this->assertRustineBranch($branch);

        if ([] !== $this->changedAllowedFiles()) {
            throw new GitException(sprintf('%s already modified in the working tree: refusing to mix them with the fixes.', implode(', ', $this->changedAllowedFiles())));
        }
        if (!$this->git->attempt(['diff', '--cached', '--quiet'])->isSuccessful()) {
            throw new GitException('Changes are already staged in the working tree: refusing to commit them in the fix.');
        }

        $this->origin ??= $this->currentPosition();

        $this->git->run(['checkout', '--quiet', '--no-track', '-B', $branch, $this->remoteRef($this->defaultBranch)]);
    }

    /**
     * Commits the changed manifests and lockfiles.
     *
     * @return bool false when there was nothing to commit
     *
     * @throws GitException
     */
    public function commit(string $message): bool
    {
        $files = $this->changedAllowedFiles();
        if ([] === $files) {
            return false;
        }

        $this->git->run(['add', '--', ...$files]);
        $this->git->run([
            '-c', 'user.name=' . $this->identity->name,
            '-c', 'user.email=' . $this->identity->email,
            '-c', 'commit.gpgsign=false',
            'commit', '--quiet', '--no-verify', '-m', rtrim($message) . "\n\n" . self::TRAILER,
        ]);

        return true;
    }

    /**
     * Pushes HEAD to the branch, unless the remote branch already holds the same tree.
     *
     * @param RemoteBranch $remote what {@see inspect()} found: the push fails if the remote moved since
     *
     * @throws GitException
     */
    public function push(string $branch, RemoteBranch $remote): PushResult
    {
        $this->assertRustineBranch($branch);
        if (RemoteBranchState::Foreign === $remote->state) {
            throw new GitException(sprintf('"%s" carries commits that Rustine did not create: refusing to overwrite it.', $branch));
        }

        if (null !== $remote->sha) {
            $remoteTree = trim($this->git->run(['rev-parse', $remote->sha . '^{tree}']));
            if ($remoteTree === trim($this->git->run(['rev-parse', 'HEAD^{tree}']))) {
                return PushResult::Unchanged;
            }
        }

        $this->git->run([
            'push', '--quiet',
            sprintf('--force-with-lease=refs/heads/%s:%s', $branch, $remote->sha ?? ''),
            $this->remote,
            'HEAD:refs/heads/' . $branch,
        ], true);

        return PushResult::Pushed;
    }

    /**
     * Drops what the fixes left uncommitted and puts the working tree back where it was before {@see start()}.
     *
     * @throws GitException
     */
    public function finish(): void
    {
        $tracked = array_values(array_filter(explode("\0", $this->git->run(['ls-files', '-z', '--', ...WorkingTree::ALLOWED_FILES])), static fn (string $p): bool => '' !== $p));
        if ([] !== $tracked) {
            $this->git->run(['restore', '--source=HEAD', '--staged', '--worktree', '--', ...$tracked]);
        }

        if (null !== $this->origin) {
            $this->git->run(['checkout', '--quiet', $this->origin]);
            $this->origin = null;
        }
    }

    /**
     * @return list<string> changed manifests and lockfiles (relative to the project)
     */
    private function changedAllowedFiles(): array
    {
        $status = $this->git->run(['status', '--porcelain', '-z', '--untracked-files=all', '--', ...WorkingTree::ALLOWED_FILES]);

        $paths = [];
        foreach (explode("\0", $status) as $entry) {
            if (\strlen($entry) > 3) {
                $paths[] = substr($entry, 3);
            }
        }

        return $paths;
    }

    private function onlyRustineCommits(string $branch): bool
    {
        $shas = array_filter(explode("\n", $this->git->run(['rev-list', $this->remoteRef($this->defaultBranch) . '..' . $this->remoteRef($branch)])));
        foreach ($shas as $sha) {
            $trailers = $this->git->run(['show', '--quiet', '--format=%(trailers:key=Generated-by,valueonly)', $sha]);
            if ('Rustine' !== trim($trailers)) {
                return false;
            }
        }

        // An empty range means the branch holds nothing of its own: nobody's work is at stake.
        return true;
    }

    private function currentPosition(): string
    {
        $branch = $this->git->attempt(['symbolic-ref', '--quiet', '--short', 'HEAD']);

        return $branch->isSuccessful() ? trim($branch->output) : trim($this->git->run(['rev-parse', 'HEAD']));
    }

    private function assertRustineBranch(string $branch): void
    {
        if ($branch === $this->defaultBranch || !str_starts_with($branch, self::BRANCH_PREFIX)) {
            throw new GitException(sprintf('Refusing to touch "%s": only branches named "%s*" may be written.', $branch, self::BRANCH_PREFIX));
        }
    }

    private function refspec(string $branch): string
    {
        return sprintf('+refs/heads/%s:refs/remotes/%s/%s', $branch, $this->remote, $branch);
    }

    private function remoteRef(string $branch): string
    {
        return sprintf('refs/remotes/%s/%s', $this->remote, $branch);
    }
}
