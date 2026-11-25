<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Git view of the project: guard against fixes that touch files other than the manifests and lockfiles.
 */
final readonly class WorkingTree
{
    /** The only files a fix may change. */
    public const array ALLOWED_FILES = ['composer.json', 'composer.lock', 'package.json', 'yarn.lock'];

    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
    ) {
    }

    /**
     * Paths (relative to the project) that differ from HEAD or are untracked and not ignored.
     *
     * An untracked directory counts as one path (`--untracked-files=normal`): what appears inside a directory that was
     * already untracked before the fix (a cache that Trivy or Yarn fills, for instance) is not a change, while a new
     * untracked file or directory is.
     *
     * @return list<string>
     */
    public function dirtyPaths(): array
    {
        $prefix = trim($this->git(['rev-parse', '--show-prefix']));
        $status = $this->git(['status', '--porcelain', '-z', '--untracked-files=normal', '--', '.']);

        $paths = [];
        $entries = explode("\0", $status);

        for ($i = 0, $count = \count($entries); $i < $count; ++$i) {
            $entry = $entries[$i];
            if (\strlen($entry) < 4) {
                continue;
            }
            // A rename/copy entry is followed by the original path.
            if (\in_array($entry[0], ['R', 'C'], true)) {
                ++$i;
            }
            $path = substr($entry, 3);
            $paths[] = '' !== $prefix && str_starts_with($path, $prefix) ? substr($path, \strlen($prefix)) : $path;
        }

        return $paths;
    }

    /**
     * Files changed since `$baseline` that a fix is not allowed to touch.
     *
     * @param list<string> $baseline result of {@see dirtyPaths()} before the fix
     *
     * @return list<string>
     */
    public function forbiddenChanges(array $baseline): array
    {
        return array_values(array_diff($this->dirtyPaths(), $baseline, self::ALLOWED_FILES));
    }

    /**
     * True if `$path` is dirty (modified or untracked).
     */
    public function isDirty(string $path): bool
    {
        return \in_array($path, $this->dirtyPaths(), true);
    }

    /**
     * Tracked files that Yarn Berry would also rewrite (PnP loader, committed cache): not handled yet.
     *
     * @return list<string>
     */
    public function trackedYarnArtifacts(): array
    {
        $output = $this->git(['ls-files', '-z', '--', '.pnp.cjs', '.pnp.loader.mjs', '.yarn/cache']);

        return array_values(array_filter(explode("\0", $output), static fn(string $p): bool => '' !== $p));
    }

    /**
     * @param list<string> $args
     */
    private function git(array $args): string
    {
        $result = $this->runner->run(['git', ...$args], $this->projectDir);
        if (!$result->isSuccessful()) {
            throw new \RuntimeException(sprintf('"git %s" failed: %s', implode(' ', $args), trim($result->output)));
        }

        return $result->output;
    }
}
