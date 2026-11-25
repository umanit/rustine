<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\WorkingTree;
use Rustine\Tests\Apply\Support\FakeRunner;

final class WorkingTreeTest extends TestCase
{
    #[Test]
    public function itParsesDirtyPathsIncludingRenamesAndUntrackedFiles(): void
    {
        $runner = new FakeRunner();
        $runner->status = " M yarn.lock\0?? notes.txt\0R  new.js\0old.js\0 M package.json\0";

        self::assertSame(
            ['yarn.lock', 'notes.txt', 'new.js', 'package.json'],
            (new WorkingTree($runner, '/p'))->dirtyPaths(),
        );
    }

    #[Test]
    public function onlyNewChangesOutsideTheAllowedFilesAreForbidden(): void
    {
        $runner = new FakeRunner();
        $runner->status = " M .env\0 M yarn.lock\0 M package.json\0?? src/new.js\0";

        $forbidden = (new WorkingTree($runner, '/p'))->forbiddenChanges(['.env']);

        self::assertSame(['src/new.js'], $forbidden);
    }

    #[Test]
    public function itListsTrackedYarnArtifacts(): void
    {
        $runner = new FakeRunner();
        $runner->tracked = ".pnp.cjs\0";

        self::assertSame(['.pnp.cjs'], (new WorkingTree($runner, '/p'))->trackedYarnArtifacts());
    }

    #[Test]
    public function itFailsWhenGitFails(): void
    {
        $runner = new class() extends FakeRunner {
            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return new CommandResult(128, 'fatal: not a git repository');
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a git repository');

        (new WorkingTree($runner, '/p'))->dirtyPaths();
    }

    #[Test]
    public function aFileInsideADirectoryThatWasAlreadyUntrackedIsNotAChange(): void
    {
        $dir = $this->repository();
        mkdir($dir . '/.cache');
        file_put_contents($dir . '/.cache/db', 'old');
        $tree = new WorkingTree(new ProcessCommandRunner(), $dir);
        $baseline = $tree->dirtyPaths();

        // What a re-scan or an install does: it fills a cache that was already there.
        file_put_contents($dir . '/.cache/more', 'new');
        mkdir($dir . '/.cache/sub');
        file_put_contents($dir . '/.cache/sub/deep', 'new');

        self::assertSame(['.cache/'], $baseline);
        self::assertSame([], $tree->forbiddenChanges($baseline));
        $this->remove($dir);
    }

    #[Test]
    public function aNewUntrackedFileOrDirectoryIsStillAChange(): void
    {
        $dir = $this->repository();
        $tree = new WorkingTree(new ProcessCommandRunner(), $dir);
        $baseline = $tree->dirtyPaths();

        mkdir($dir . '/.mise');
        file_put_contents($dir . '/.mise/node', 'x');
        file_put_contents($dir . '/stray.txt', 'x');

        self::assertEqualsCanonicalizing(['.mise/', 'stray.txt'], $tree->forbiddenChanges($baseline));
        $this->remove($dir);
    }

    #[Test]
    public function aTrackedFileOutsideTheAllowedOnesStillCountsWhenItIsModified(): void
    {
        $dir = $this->repository();
        $tree = new WorkingTree(new ProcessCommandRunner(), $dir);
        $baseline = $tree->dirtyPaths();

        file_put_contents($dir . '/symfony.lock', '{"changed":true}');
        file_put_contents($dir . '/yarn.lock', 'allowed');

        self::assertSame(['symfony.lock'], $tree->forbiddenChanges($baseline));
        $this->remove($dir);
    }

    /**
     * A real git repository: what `git status` reports is the point of these tests.
     */
    private function repository(): string
    {
        $dir = sys_get_temp_dir() . '/rustine-tree-' . bin2hex(random_bytes(4));
        mkdir($dir);
        $git = static function (string ...$args) use ($dir): void {
            $result = (new ProcessCommandRunner())->run(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', ...array_values($args)], $dir);
            self::assertTrue($result->isSuccessful(), $result->output);
        };
        $git('init', '--quiet');
        file_put_contents($dir . '/symfony.lock', '{}');
        file_put_contents($dir . '/yarn.lock', 'lock');
        $git('add', '.');
        $git('commit', '--quiet', '-m', 'init');

        return $dir;
    }

    private function remove(string $dir): void
    {
        (new ProcessCommandRunner())->run(['rm', '-rf', '--', $dir], sys_get_temp_dir());
    }
}
