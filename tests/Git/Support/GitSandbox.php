<?php

declare(strict_types=1);

namespace Rustine\Tests\Git\Support;

use Rustine\Apply\ProcessCommandRunner;
use Rustine\Git\Git;
use Symfony\Component\Process\Process;

/**
 * A bare "remote" and a clone of it, in a temporary directory: no network involved.
 */
final readonly class GitSandbox
{
    public string $root;

    public string $remote;

    public string $project;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/rustine-git-' . bin2hex(random_bytes(6));
        $this->remote = $this->root . '/remote.git';
        $this->project = $this->root . '/project';
        mkdir($this->root);

        $this->exec(['git', 'init', '--quiet', '--bare', '--initial-branch=main', $this->remote], $this->root);
        $this->exec(['git', 'clone', '--quiet', $this->remote, $this->project], $this->root);
        $this->configure($this->project);

        file_put_contents($this->project . '/package.json', "{\"name\":\"acme\"}\n");
        file_put_contents($this->project . '/yarn.lock', "# lock v1\n");
        file_put_contents($this->project . '/README.md', "hello\n");
        $this->exec(['git', 'add', '.'], $this->project);
        $this->exec(['git', 'commit', '--quiet', '-m', 'initial'], $this->project);
        $this->exec(['git', 'push', '--quiet', 'origin', 'HEAD:main'], $this->project);
        $this->exec(['git', 'branch', '--set-upstream-to=origin/main'], $this->project);
    }

    public function git(?string $cwd = null): Git
    {
        return new Git(new ProcessCommandRunner(), $cwd ?? $this->project);
    }

    /**
     * @param list<string> $command
     */
    public function exec(array $command, ?string $cwd = null): string
    {
        $process = new Process($command, $cwd ?? $this->project);
        $process->mustRun();

        return $process->getOutput();
    }

    /**
     * A second clone, to play someone else pushing to the remote.
     */
    public function otherClone(): string
    {
        $dir = $this->root . '/other-' . bin2hex(random_bytes(3));
        $this->exec(['git', 'clone', '--quiet', $this->remote, $dir], $this->root);
        $this->configure($dir);

        return $dir;
    }

    public function remoteSha(string $branch): ?string
    {
        $process = new Process(['git', 'rev-parse', '--verify', '--quiet', 'refs/heads/' . $branch], $this->remote);
        $process->run();

        return $process->isSuccessful() ? trim($process->getOutput()) : null;
    }

    public function remoteFile(string $branch, string $file): string
    {
        return $this->exec(['git', 'show', 'refs/heads/' . $branch . ':' . $file], $this->remote);
    }

    public function remove(): void
    {
        new Process(['rm', '-rf', $this->root])->mustRun();
    }

    private function configure(string $dir): void
    {
        $this->exec(['git', 'config', 'user.name', 'Someone'], $dir);
        $this->exec(['git', 'config', 'user.email', 'someone@example.com'], $dir);
        $this->exec(['git', 'config', 'commit.gpgsign', 'false'], $dir);
    }
}
