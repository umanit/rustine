<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
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
}
