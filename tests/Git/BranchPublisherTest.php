<?php

declare(strict_types=1);

namespace Rustine\Tests\Git;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Git\BranchPublisher;
use Rustine\Git\GitException;
use Rustine\Git\GitIdentity;
use Rustine\Git\PushResult;
use Rustine\Git\RemoteBranchState;
use Rustine\Tests\Git\Support\GitSandbox;

final class BranchPublisherTest extends TestCase
{
    private const string BRANCH = 'security/rustine-yarn-critical-high';

    private GitSandbox $sandbox;

    protected function setUp(): void
    {
        $this->sandbox = new GitSandbox();
    }

    protected function tearDown(): void
    {
        $this->sandbox->remove();
    }

    #[Test]
    public function itCreatesTheBranchOnTheRemoteWithOnlyTheAllowedFiles(): void
    {
        $publisher = $this->publisher();

        $remote = $publisher->inspect(self::BRANCH);
        $this->assertSame(RemoteBranchState::Absent, $remote->state);

        $publisher->start(self::BRANCH);
        $this->fix("lock v2\n");
        file_put_contents($this->sandbox->project . '/README.md', "touched\n");
        file_put_contents($this->sandbox->project . '/stray.txt', "stray\n");

        $this->assertTrue($publisher->commit("build(deps): fix a CVE\n\nBody."));
        $this->assertSame(PushResult::Pushed, $publisher->push(self::BRANCH, $remote));
        $publisher->finish();

        $this->assertSame("lock v2\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        $this->assertSame(
            "hello\n",
            $this->sandbox->remoteFile(self::BRANCH, 'README.md'),
            'files outside the allowed list are not committed',
        );
        $message = $this->sandbox->exec(
            ['git', 'log', '-1', '--format=%an <%ae>%n%B', 'refs/heads/' . self::BRANCH],
            $this->sandbox->remote,
        );
        $this->assertStringContainsString('Rustine Bot <bot@example.com>', $message);
        $this->assertStringContainsString('build(deps): fix a CVE', $message);
        $this->assertStringContainsString(BranchPublisher::TRAILER, $message);
    }

    #[Test]
    public function itLeavesTheOtherChangesAndTheStartingBranchAlone(): void
    {
        $publisher = $this->publisher();
        file_put_contents($this->sandbox->project . '/README.md', "local edit\n");
        file_put_contents($this->sandbox->project . '/trivy-report.json', '{}');

        $remote = $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix("lock v2\n");
        $publisher->commit('build(deps): fix');
        $publisher->push(self::BRANCH, $remote);
        $publisher->finish();

        $this->assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
        $this->assertSame("local edit\n", file_get_contents($this->sandbox->project . '/README.md'));
        $this->assertFileExists($this->sandbox->project . '/trivy-report.json');
        $this->assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
    }

    #[Test]
    public function aRunProducingTheSameTreeDoesNotPushAgain(): void
    {
        $this->publishOnce("lock v2\n");
        $before = $this->sandbox->remoteSha(self::BRANCH);

        $publisher = $this->publisher();
        $remote = $publisher->inspect(self::BRANCH);
        $this->assertSame(RemoteBranchState::Rustine, $remote->state);
        $publisher->start(self::BRANCH);
        $this->fix("lock v2\n");
        $publisher->commit('build(deps): fix');

        $this->assertSame(PushResult::Unchanged, $publisher->push(self::BRANCH, $remote));
        $publisher->finish();
        $this->assertSame($before, $this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aDifferentTreeForceUpdatesTheBranch(): void
    {
        $this->publishOnce("lock v2\n");
        $before = $this->sandbox->remoteSha(self::BRANCH);

        $publisher = $this->publisher();
        $remote = $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix("lock v3\n");
        $publisher->commit('build(deps): fix more');

        $this->assertSame(PushResult::Pushed, $publisher->push(self::BRANCH, $remote));
        $publisher->finish();

        $this->assertNotSame($before, $this->sandbox->remoteSha(self::BRANCH));
        $this->assertSame("lock v3\n", $this->sandbox->remoteFile(self::BRANCH, 'yarn.lock'));
        $this->assertSame(
            '1',
            trim($this->sandbox->exec(['git', 'rev-list', '--count', 'main..' . self::BRANCH], $this->sandbox->remote)),
            'the branch restarts from the default branch',
        );
    }

    #[Test]
    public function theBranchRestartsFromTheLatestDefaultBranch(): void
    {
        $this->publishOnce("lock v2\n");
        $other = $this->sandbox->otherClone();
        file_put_contents($other . '/README.md', "newer\n");
        $this->sandbox->exec(['git', 'commit', '--quiet', '-am', 'docs'], $other);
        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', 'HEAD:main'], $other);

        $publisher = $this->publisher();
        $remote = $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix("lock v2\n");
        $publisher->commit('build(deps): fix');

        $this->assertSame(PushResult::Pushed, $publisher->push(self::BRANCH, $remote), 'same fix, but on a newer base');
        $publisher->finish();
        $this->assertSame("newer\n", $this->sandbox->remoteFile(self::BRANCH, 'README.md'));
    }

    #[Test]
    public function aBranchWithSomeoneElsesCommitsIsNotOverwritten(): void
    {
        $this->publishOnce("lock v2\n");
        $other = $this->sandbox->otherClone();
        $this->sandbox->exec(['git', 'checkout', '--quiet', self::BRANCH], $other);
        file_put_contents($other . '/README.md', "human work\n");
        $this->sandbox->exec(['git', 'commit', '--quiet', '-am', 'manual tweak'], $other);
        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', self::BRANCH], $other);

        $before = $this->sandbox->remoteSha(self::BRANCH);

        $remote = $this->publisher()->inspect(self::BRANCH);

        $this->assertSame(RemoteBranchState::Foreign, $remote->state);

        try {
            $this->publisher()->push(self::BRANCH, $remote);
            self::fail('A GitException was expected.');
        } catch (GitException $gitException) {
            $this->assertStringContainsString('did not create', $gitException->getMessage());
        }

        $this->assertSame($before, $this->sandbox->remoteSha(self::BRANCH));
    }

    #[Test]
    public function aShallowCloneStillRecognizesTheBranchOwner(): void
    {
        $this->publishOnce("lock v2\n");
        $shallow = $this->sandbox->root . '/shallow';
        $this->sandbox->exec(
            [
                'git',
                'clone',
                '--quiet',
                '--depth=1',
                '--no-single-branch',
                'file://' . $this->sandbox->remote,
                $shallow,
            ],
            $this->sandbox->root,
        );

        $remote = new BranchPublisher(
            $this->sandbox->git($shallow),
            'main',
            new GitIdentity('Rustine Bot', 'bot@example.com'),
        )->inspect(self::BRANCH);

        $this->assertSame(RemoteBranchState::Rustine, $remote->state);
    }

    #[Test]
    public function aShallowCloneIsNotFullyDownloadedToCheckTheOwner(): void
    {
        $this->publishOnce("lock v2\n");
        $other = $this->sandbox->otherClone();

        foreach (['one', 'two', 'three'] as $name) {
            file_put_contents($other . '/' . $name . '.txt', $name);
            $this->sandbox->exec(['git', 'add', '.'], $other);
            $this->sandbox->exec(['git', 'commit', '--quiet', '-m', 'feat: ' . $name], $other);
        }

        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', 'HEAD:main'], $other);
        $shallow = $this->sandbox->root . '/shallow-bounded';
        $this->sandbox->exec(
            [
                'git',
                'clone',
                '--quiet',
                '--depth=1',
                '--no-single-branch',
                'file://' . $this->sandbox->remote,
                $shallow,
            ],
            $this->sandbox->root,
        );
        $git = $this->sandbox->git($shallow);

        $remote = new BranchPublisher($git, 'main', new GitIdentity('Rustine Bot', 'bot@example.com'))->inspect(
            self::BRANCH,
        );

        $this->assertSame(RemoteBranchState::Rustine, $remote->state);
        $this->assertSame(
            'true',
            trim($git->run(['rev-parse', '--is-shallow-repository'])),
            'the history was not downloaded in full',
        );
    }

    #[Test]
    public function nothingIsCommittedWhenTheFixesChangedNothing(): void
    {
        $publisher = $this->publisher();
        $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);

        $this->assertFalse($publisher->commit('build(deps): fix'));

        $publisher->finish();
        $this->assertSame('main', trim($this->sandbox->exec(['git', 'branch', '--show-current'])));
    }

    #[Test]
    public function finishDropsWhatWasNotCommitted(): void
    {
        $publisher = $this->publisher();
        $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix("half-applied\n");

        $publisher->finish();

        $this->assertSame("# lock v1\n", file_get_contents($this->sandbox->project . '/yarn.lock'));
    }

    #[Test]
    public function itRefusesToStartWhenAManifestIsAlreadyModified(): void
    {
        $this->fix("manual change\n");

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('already modified');

        $this->publisher()->start(self::BRANCH);
    }

    #[Test]
    public function itRefusesToStartWhenSomethingIsStaged(): void
    {
        file_put_contents($this->sandbox->project . '/README.md', "staged\n");
        $this->sandbox->exec(['git', 'add', 'README.md']);

        $this->expectException(GitException::class);
        $this->expectExceptionMessage('staged');

        $this->publisher()->start(self::BRANCH);
    }

    #[Test]
    public function theLeaseProtectsAgainstAPushThatLandedMeanwhile(): void
    {
        $publisher = $this->publisher();
        $remote = $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix("lock v2\n");
        $publisher->commit('build(deps): fix');

        // Someone creates the branch between the inspection and the push.
        $other = $this->sandbox->otherClone();
        $this->sandbox->exec(['git', 'checkout', '--quiet', '-b', self::BRANCH], $other);
        $this->sandbox->exec(['git', 'commit', '--quiet', '--allow-empty', '-m', 'someone was here'], $other);
        $this->sandbox->exec(['git', 'push', '--quiet', 'origin', self::BRANCH], $other);

        $theirs = $this->sandbox->remoteSha(self::BRANCH);

        try {
            $publisher->push(self::BRANCH, $remote);
            self::fail('A GitException was expected.');
        } catch (GitException) {
            $this->assertSame($theirs, $this->sandbox->remoteSha(self::BRANCH));
        }
    }

    #[Test]
    public function itNeverWritesTheDefaultBranchNorForeignNames(): void
    {
        $publisher = $this->publisher();

        foreach (['main', 'feature/x'] as $branch) {
            try {
                $publisher->start($branch);
                self::fail('A GitException was expected for ' . $branch);
            } catch (GitException $e) {
                $this->assertStringContainsString('Refusing to touch', $e->getMessage());
            }
        }

        $this->assertNull($this->sandbox->remoteSha('feature/x'));
    }

    private function publisher(): BranchPublisher
    {
        return new BranchPublisher($this->sandbox->git(), 'main', new GitIdentity('Rustine Bot', 'bot@example.com'));
    }

    private function fix(string $lock): void
    {
        file_put_contents($this->sandbox->project . '/yarn.lock', $lock);
    }

    private function publishOnce(string $lock): void
    {
        $publisher = $this->publisher();
        $remote = $publisher->inspect(self::BRANCH);
        $publisher->start(self::BRANCH);
        $this->fix($lock);
        $publisher->commit('build(deps): fix');
        $publisher->push(self::BRANCH, $remote);
        $publisher->finish();
    }
}
