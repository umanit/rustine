<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\CommandResult;
use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\FixApplier;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\FixStrategy;
use Rustine\Apply\LockChange;
use Rustine\Apply\PackageJsonEditor;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Apply\VerifyMode;
use Rustine\Apply\WorkingTree;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Progress\NullProgress;
use Rustine\Progress\Progress;
use Rustine\Tests\Apply\Support\FakeRunner;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Apply\Support\QueueRescanner;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\TrivyReport;

final class FixApplierTest extends TestCase
{
    private const string PACKAGE_JSON = "{\n  \"name\": \"app\"\n}\n";

    private const string LOCK = "ckeditor4@^4.0.0:\n  version \"4.22.1\"\n";

    private const string LOCK_FIXED = "ckeditor4@^4.0.0:\n  version \"4.25.0\"\n";

    private string $dir;

    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-applier-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/package.json', self::PACKAGE_JSON);
        file_put_contents($this->dir . '/yarn.lock', self::LOCK);

        $this->runner = new FakeRunner();
        // By default `yarn up`/`yarn install` succeed and lock the fixed version.
        $this->runner->handler = function (array $command): ?CommandResult {
            file_put_contents($this->dir . '/yarn.lock', self::LOCK_FIXED);

            return null;
        };
    }

    protected function tearDown(): void
    {
        foreach (['package.json', 'yarn.lock'] as $file) {
            @unlink($this->dir . '/' . $file);
        }

        @rmdir($this->dir);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function nonRegistrySpecs(): iterable
    {
        yield 'git shorthand' => ['ckeditor/ckeditor4-releases#full/4.22.1'];
        yield 'git url' => ['git+https://github.com/ckeditor/ckeditor4-releases.git#full/4.22.1'];
        yield 'https tarball' => ['https://example.com/ckeditor4.tgz'];
        yield 'npm alias' => ['npm:other-package@^1.0.0'];
        yield 'local path' => ['file:vendor/ckeditor4'];
        yield 'workspace' => ['workspace:*'];
    }

    #[Test]
    public function itReportsWhatChangedInTheLockfileCollateralChangesIncluded(): void
    {
        file_put_contents(
            $this->dir . '/yarn.lock',
            "ckeditor4@^4.0.0:\n  version \"4.22.1\"\n\nlodash@^4.0.0:\n  version \"4.17.20\"\n",
        );
        $this->runner->handler = function (array $command): ?CommandResult {
            file_put_contents(
                $this->dir . '/yarn.lock',
                "ckeditor4@^4.0.0:\n  version \"4.25.0\"\n\nlodash@^4.0.0:\n  version \"4.17.21\"\n",
            );

            return null;
        };

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(
            [['ckeditor4', ['4.22.1'], ['4.25.0']], ['lodash', ['4.17.20'], ['4.17.21']]],
            array_map(
                static fn(LockChange $c): array => [$c->package, $c->before, $c->after],
                $result->lockChanges,
            ),
        );
    }

    #[Test]
    public function itKeepsTheLockfileRefreshWhenTheCvesDisappear(): void
    {
        $rescanner = new QueueRescanner(Fixtures::report());

        $result = $this->applier($rescanner)->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
        $this->assertSame(["yarn up -R ckeditor4", 'yarn install --immutable'], $this->runner->toolCommands());
        $this->assertSame(self::LOCK_FIXED, file_get_contents($this->dir . '/yarn.lock'));
        $this->assertSame(1, $rescanner->calls, 'Trivy runs once, not after every attempt.');
        $this->assertFalse($result->outcomes[0]->stillReported);
        $this->assertTrue($result->isHealthy());
        $this->assertTrue($result->hasChanges());
    }

    #[Test]
    public function itRunsYarnInAnEnvironmentThatCannotBlockTheLockfileOrRunScripts(): void
    {
        $this->applier(new QueueRescanner(Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $yarnEnvs = array_values(array_filter($this->runner->envs, static fn(array $e): bool => [] !== $e));
        $this->assertSame('false', $yarnEnvs[0]['YARN_ENABLE_IMMUTABLE_INSTALLS']);
        $this->assertSame('false', $yarnEnvs[0]['YARN_ENABLE_SCRIPTS']);
        $this->assertArrayNotHasKey(
            'YARN_ENABLE_IMMUTABLE_INSTALLS',
            $yarnEnvs[1],
            'The final check must stay immutable.',
        );
    }

    #[Test]
    public function itFallsBackToAResolutionAndRestoresFilesBetweenAttempts(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);
        $rescanner = new QueueRescanner(Fixtures::report());
        $this->lock(['braces' => ['3.0.2']]);
        // The refresh leaves braces where it is (its parent pins it): only the resolution moves it.
        $this->runner->handler = function (array $command): ?CommandResult {
            if (str_contains((string) file_get_contents($this->dir . '/package.json'), 'resolutions')) {
                $this->lock(['braces' => ['3.0.3']]);
            }

            return null;
        };

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $vulnerable);

        $this->assertSame(AttemptKind::Resolution, $result->outcomes[0]->strategy);
        $this->assertSame(
            ['lockfile-refresh: the lockfile still installs braces 3.0.2, below 3.0.3', 'resolution: ok'],
            $result->outcomes[0]->log,
        );
        $this->assertSame(1, $rescanner->calls, 'The failed attempt did not cost a Trivy run.');
        $this->assertSame(
            "{\n  \"name\": \"app\",\n  \"resolutions\": {\n    \"braces\": \"^3.0.3\"\n  }\n}\n",
            file_get_contents($this->dir . '/package.json'),
        );
    }

    #[Test]
    public function withYarnClassicItPrunesTheLockfileEntriesBeforeInstalling(): void
    {
        file_put_contents(
            $this->dir . '/yarn.lock',
            "# yarn lockfile v1\n\n\nbraces@^3.0.2:\n  version \"3.0.2\"\n\nother@^1.0.0:\n  version \"1.0.0\"\n",
        );
        $seenByYarn = null;
        $this->runner->handler = function (array $command) use (&$seenByYarn): ?CommandResult {
            $seenByYarn ??= file_get_contents($this->dir . '/yarn.lock');

            return null;
        };
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);

        $result = $this->applier(new QueueRescanner(Fixtures::report()), new YarnClassicStrategy())->apply(
            Fixtures::plan($fix),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        // Yarn rewrites the whole file afterwards: only the remaining entries matter here.
        $this->assertStringNotContainsString('braces', (string) $seenByYarn);
        $this->assertStringContainsString("other@^1.0.0:\n  version \"1.0.0\"\n", (string) $seenByYarn);
        $this->assertSame([
            'yarn install --non-interactive --ignore-scripts',
            'yarn install --frozen-lockfile --non-interactive --ignore-scripts',
        ], $this->runner->toolCommands());
    }

    #[Test]
    public function withYarnClassicAFailedPruneIsRestored(): void
    {
        $original = "# yarn lockfile v1\n\n\nbraces@^3.0.2:\n  version \"3.0.2\"\n";
        file_put_contents($this->dir . '/yarn.lock', $original);
        // Yarn resolves braces again to the very same version.
        $this->runner->handler = function (array $command) use ($original): ?CommandResult {
            file_put_contents($this->dir . '/yarn.lock', $original);

            return null;
        };
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);

        $result = $this->applier(new QueueRescanner(), new YarnClassicStrategy())->apply(
            Fixtures::plan($fix),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertSame($original, file_get_contents($this->dir . '/yarn.lock'));
        $this->assertSame("{\n  \"name\": \"app\"\n}\n", file_get_contents($this->dir . '/package.json'));
    }

    #[Test]
    public function itRefusesAResolutionThatWouldDragAnotherMajorAlong(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        // braces is also installed in 2.x: `resolutions["braces"] = "^3.0.3"` would force it to 3.x too.
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')], ['braces' => ['2.3.2', '3.0.2']]);
        $rescanner = new QueueRescanner();
        $this->lock(['braces' => ['2.3.2', '3.0.2']]);
        $this->runner->handler = null;

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $vulnerable);

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertSame([
            'lockfile-refresh: the lockfile still installs braces 3.0.2, below 3.0.3',
            'resolution: skipped, braces is also installed in other majors (2.3.2) and a resolution would force them'
            . ' all to ^3.0.3',
        ], $result->outcomes[0]->log);
        $this->assertSame(['yarn up -R braces'], $this->runner->toolCommands());
        $this->assertSame(self::PACKAGE_JSON, file_get_contents($this->dir . '/package.json'));
    }

    #[Test]
    public function otherVersionsOfTheSameMajorDoNotPreventAResolution(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')], ['braces' => ['3.0.1', '3.0.2']]);
        $this->lock(['braces' => ['3.0.1', '3.0.2']]);
        $this->runner->handler = function (array $command): ?CommandResult {
            if (str_contains((string) file_get_contents($this->dir . '/package.json'), 'resolutions')) {
                $this->lock(['braces' => ['3.0.3']]);
            }

            return null;
        };

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(Fixtures::plan($fix), $vulnerable);

        $this->assertSame(AttemptKind::Resolution, $result->outcomes[0]->strategy);
    }

    #[Test]
    public function theSameCveOnAnotherMajorDoesNotMakeTheFixFail(): void
    {
        $fix = Fixtures::fix('semver', '5.7.1', '5.7.2', DependencyKind::Transitive);
        $other = Fixtures::vulnerability('semver', '7.5.1');
        // The refresh fixed semver 5.x; semver 7.x still carries the same CVE, as another instance of the plan.
        $rescanner = new QueueRescanner(Fixtures::report([$other]));
        $this->lock(['semver' => ['5.7.1', '7.5.1']]);
        $this->runner->handler = function (array $command): ?CommandResult {
            $this->lock(['semver' => ['5.7.2', '7.5.1']]);

            return null;
        };

        $result = $this->applier($rescanner)->apply(
            Fixtures::plan($fix),
            Fixtures::report([Fixtures::vulnerability('semver', '5.7.1'), $other]),
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
        $this->assertFalse($result->outcomes[0]->stillReported);
    }

    #[Test]
    public function theCveStillReportedOnTheSameMajorIsAFailure(): void
    {
        $fix = Fixtures::fix('semver', '5.7.1', '5.7.2', DependencyKind::Transitive);
        // Neither attempt removes the CVE from semver 5.x: the fix fails.
        $stillVulnerable = Fixtures::report([Fixtures::vulnerability('semver', '5.7.1')]);
        $rescanner = new QueueRescanner();
        $this->lock(['semver' => ['5.7.1']]);
        $this->runner->handler = null;

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $stillVulnerable);

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
    }

    #[Test]
    public function itRestoresEverythingWhenNoAttemptWorks(): void
    {
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);
        $rescanner = new QueueRescanner();
        $this->runner->handler = null;

        $result = $this->applier($rescanner)->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertSame(0, $rescanner->calls);
        $this->assertCount(2, $result->outcomes[0]->log);
        $this->assertFalse($result->isHealthy());
        $this->assertFalse($result->hasChanges());
        $this->assertSame(self::LOCK, file_get_contents($this->dir . '/yarn.lock'));
        $this->assertSame(self::PACKAGE_JSON, file_get_contents($this->dir . '/package.json'));
        $this->assertNotContains(
            'yarn install --immutable',
            $this->runner->toolCommands(),
            'No final check when nothing changed.',
        );
    }

    #[Test]
    public function aFailingPackageDoesNotBlockTheNext(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $before = Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0'), Fixtures::vulnerability('bbb', '2.0.0')]);
        // aaa: both attempts leave it locked; bbb: its first attempt moves it.
        $rescanner = new QueueRescanner(Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0')]));
        $this->lock(['aaa' => ['1.0.0'], 'bbb' => ['2.0.0']]);
        $this->runner->handler = function (array $command): ?CommandResult {
            if (\in_array('bbb', $command, true)) {
                $this->lock(['aaa' => ['1.0.0'], 'bbb' => ['2.0.1']]);
            }

            return null;
        };

        $result = $this->applier($rescanner)->apply(Fixtures::plan($a, $b), $before);

        $this->assertSame(
            [FixOutcomeStatus::Failed, FixOutcomeStatus::Applied],
            array_map(
                static fn(FixOutcome $o): FixOutcomeStatus => $o->status,
                $result->outcomes,
            ),
        );
        $this->assertFalse($result->isHealthy());
        $this->assertTrue($result->hasChanges());
        $this->assertSame(1, $rescanner->calls);
    }

    #[Test]
    public function thePackageCounterAlsoCountsThePackagesThatWereAlreadyFixed(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $before = Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0'), Fixtures::vulnerability('bbb', '2.0.0')]);
        $this->lock(['aaa' => ['1.0.0'], 'bbb' => ['2.0.0']]);
        $this->runner->handler = function (array $command): ?CommandResult {
            $this->lock(['aaa' => ['1.0.1'], 'bbb' => ['2.0.1']]);

            return null;
        };
        $progress = new class implements Progress {
            /** @var list<string> */
            public array $steps = [];

            public function step(string $message): void
            {
                $this->steps[] = $message;
            }
        };

        $this->applier(new QueueRescanner(Fixtures::report()), progress: $progress)->apply(
            Fixtures::plan($a, $b),
            $before,
        );

        $this->assertContains('Fixing aaa 1.0.0 → 1.0.1 (1/2)', $progress->steps);
        $this->assertContains('Skipping bbb 2.0.0 (2/2): already fixed', $progress->steps);
    }

    #[Test]
    public function aFixAlsoFixesWhatAnotherPackageNeeded(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $before = Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0'), Fixtures::vulnerability('bbb', '2.0.0')]);
        $this->lock(['aaa' => ['1.0.0'], 'bbb' => ['2.0.0']]);
        // Refreshing aaa moves bbb along with it.
        $this->runner->handler = function (array $command): ?CommandResult {
            $this->lock(['aaa' => ['1.0.1'], 'bbb' => ['2.0.1']]);

            return null;
        };

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(Fixtures::plan($a, $b), $before);

        $this->assertSame(
            [FixOutcomeStatus::Applied, FixOutcomeStatus::AlreadyFixed],
            array_map(
                static fn(FixOutcome $o): FixOutcomeStatus => $o->status,
                $result->outcomes,
            ),
        );
        $this->assertSame(['yarn up -R aaa', 'yarn install --immutable'], $this->runner->toolCommands());
    }

    #[Test]
    public function itFlagsAFixWhoseCvesTrivyStillReportsAtTheEnd(): void
    {
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);

        $result = $this->applier(new QueueRescanner($vulnerable))->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertTrue($result->outcomes[0]->stillReported);
        $this->assertTrue($result->hasChanges(), 'The change is kept: it is up to a human to check it.');
    }

    #[Test]
    public function withVerifyEachTrivyJudgesEveryAttemptTheLockfileAccepts(): void
    {
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);
        // The lockfile accepts the first attempt, Trivy does not: the next one is tried, and Trivy accepts it.
        $rescanner = new QueueRescanner($vulnerable, Fixtures::report());

        $result = $this->applier($rescanner, verify: VerifyMode::Each)->apply(
            Fixtures::plan(Fixtures::fix()),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(AttemptKind::DirectBump, $result->outcomes[0]->strategy);
        $this->assertSame(
            'lockfile-refresh: the CVEs are still reported after the attempt',
            $result->outcomes[0]->log[0],
        );
        $this->assertSame(2, $rescanner->calls);
        $this->assertSame(VerifyMode::Each, $result->verify);
        $this->assertFalse($result->outcomes[0]->stillReported, 'No final scan: every attempt was already confirmed.');
    }

    #[Test]
    public function withVerifyEachTrivyIsNotAskedAboutWhatTheLockfileRefuses(): void
    {
        $rescanner = new QueueRescanner();
        $this->runner->handler = null;

        $result = $this->applier($rescanner, verify: VerifyMode::Each)->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertSame(0, $rescanner->calls);
    }

    #[Test]
    public function withVerifyNoneTrivyNeverRuns(): void
    {
        $rescanner = new QueueRescanner();

        $result = $this->applier($rescanner, verify: VerifyMode::None)->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(0, $rescanner->calls);
        $this->assertSame(VerifyMode::None, $result->verify);
    }

    #[Test]
    public function anUnreadableLockfileFailsTheAttempt(): void
    {
        $this->runner->handler = function (array $command): ?CommandResult {
            file_put_contents($this->dir . '/yarn.lock', '');

            return null;
        };

        $result = $this->applier(new QueueRescanner())->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertStringContainsString('cannot be read', $result->outcomes[0]->log[0]);
        $this->assertSame(self::LOCK, file_get_contents($this->dir . '/yarn.lock'));
    }

    #[Test]
    #[DataProvider('nonRegistrySpecs')]
    public function itDoesNotTouchDependenciesDeclaredWithANonRegistrySource(string $spec): void
    {
        file_put_contents(
            $this->dir . '/package.json',
            json_encode(['dependencies' => ['ckeditor4' => $spec]], \JSON_THROW_ON_ERROR),
        );
        // A local source that does not exist is refused earlier: here it is there, and simply not to be replaced.
        $local = str_starts_with($spec, 'file:') ? $this->dir . '/' . substr($spec, 5) : null;
        if (null !== $local) {
            mkdir($local, 0o777, true);
        }
        $rescanner = new QueueRescanner();

        try {
            $result = $this->applier($rescanner)->apply(
                Fixtures::plan(Fixtures::fix()),
                Fixtures::report([Fixtures::vulnerability()]),
            );
        } finally {
            if (null !== $local) {
                rmdir($local);
                rmdir($this->dir . '/vendor');
            }
        }

        $this->assertSame(FixOutcomeStatus::UnsupportedSource, $result->outcomes[0]->status);
        $this->assertStringContainsString($spec, $result->outcomes[0]->log[0]);
        $this->assertSame([], $this->runner->toolCommands());
        $this->assertSame(0, $rescanner->calls);
        $this->assertTrue($result->isHealthy(), 'A manual fix is reported, not a failure of the bot.');
        $this->assertFalse($result->hasChanges());
    }

    #[Test]
    public function aRegistryRangeIsStillBumped(): void
    {
        file_put_contents(
            $this->dir . '/package.json',
            json_encode(['devDependencies' => ['ckeditor4' => '^4.22.1']], \JSON_THROW_ON_ERROR),
        );

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
    }

    #[Test]
    public function itSkipsUnfixablePackagesAndThoseAlreadyFixed(): void
    {
        $unfixable = Fixtures::fix('major', '1.0.0', '2.0.0', status: PackageFixStatus::MajorBumpRequired);
        $gone = Fixtures::fix('gone', '1.0.0', '1.0.1');
        $rescanner = new QueueRescanner();

        $result = $this->applier($rescanner)->apply(Fixtures::plan($unfixable, $gone), Fixtures::report());

        $this->assertSame(
            [FixOutcomeStatus::Skipped, FixOutcomeStatus::AlreadyFixed],
            array_map(
                static fn(FixOutcome $o): FixOutcomeStatus => $o->status,
                $result->outcomes,
            ),
        );
        $this->assertSame([], $this->runner->toolCommands());
        $this->assertSame(0, $rescanner->calls);
    }

    #[Test]
    public function itRejectsAFixThatTouchesOtherFiles(): void
    {
        // The project is clean at the start; the first yarn command then changes an unrelated file.
        $this->runner->handler = function (array $command): ?CommandResult {
            $this->runner->status = " M yarn.lock\0 M src/app.js\0";

            return null;
        };
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);

        $result = $this->applier(new QueueRescanner(Fixtures::report(), Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertCount(1, $result->outcomes[0]->log, 'The correction is abandoned, not retried.');
        $this->assertStringContainsString('unexpected files changed: src/app.js', $result->outcomes[0]->log[0]);
        $this->assertSame(self::LOCK, file_get_contents($this->dir . '/yarn.lock'));
    }

    #[Test]
    public function aFailingYarnCommandCountsAsAFailedAttempt(): void
    {
        $this->runner->handler = static fn(array $command): CommandResult => new CommandResult(1, "boom\nYN0035: 404");
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);

        $result = $this->applier(new QueueRescanner())->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertStringContainsString('failed (exit 1): boom | YN0035: 404', $result->outcomes[0]->log[0]);
    }

    #[Test]
    public function itReportsAFinalInstallCheckFailure(): void
    {
        $this->runner->handler = function (array $command): ?CommandResult {
            if (\in_array('--immutable', $command, true)) {
                return new CommandResult(1, 'YN0028: lockfile would be modified');
            }
            file_put_contents($this->dir . '/yarn.lock', self::LOCK_FIXED);

            return null;
        };

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertSame('YN0028: lockfile would be modified', $result->finalCheckError);
        $this->assertFalse($result->isHealthy());
    }

    #[Test]
    public function itRefusesAProjectWithCommittedPnpFiles(): void
    {
        $this->runner->tracked = ".pnp.cjs\0";

        $this->expectException(UnsupportedProjectException::class);
        $this->expectExceptionMessage('.pnp.cjs');

        $this->applier(new QueueRescanner())->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
    }

    #[Test]
    public function itRefusesToModifyAnAlreadyDirtyManifest(): void
    {
        $this->runner->status = " M package.json\0";

        $this->expectException(UnsupportedProjectException::class);
        $this->expectExceptionMessage('package.json');

        $this->applier(new QueueRescanner())->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report());
    }

    #[Test]
    public function withComposerAFailureEndsWithTheWhyNotExplanation(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require":{"twig/twig":"3.4.0"}}');
        file_put_contents($this->dir . '/composer.lock', $this->composerLock('3.4.0'));
        $this->runner->handler = static fn(array $command): ?CommandResult => \in_array('why-not', $command, true)
            ? new CommandResult(1, "twig/twig 3.4.3 requires php >=8.1\nproblem 2")
            : null;
        $fix = Fixtures::fix('twig/twig', '3.4.0', '3.4.3', ecosystem: Ecosystem::Composer);
        $vulnerable = new TrivyReport(
            [Fixtures::vulnerability('twig/twig', '3.4.0', Ecosystem::Composer)],
            [],
        );

        $result = $this->applier(new QueueRescanner(), new ComposerStrategy())->apply(
            Fixtures::plan($fix),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertSame(
            ['lockfile-refresh', 'direct-bump', 'composer why-not twig/twig 3.4.3 --locked'],
            array_map(static fn(string $line): string => explode(':', $line)[0], $result->outcomes[0]->log),
        );
        $this->assertStringContainsString('requires php >=8.1 | problem 2', $result->outcomes[0]->log[2]);
        @unlink($this->dir . '/composer.json');
        @unlink($this->dir . '/composer.lock');
    }

    #[Test]
    public function withComposerAFixIsValidatedWithComposerValidate(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require":{"other/pkg":"^1.0"}}');
        file_put_contents($this->dir . '/composer.lock', $this->composerLock('3.4.0'));
        $this->runner->handler = function (array $command): ?CommandResult {
            file_put_contents($this->dir . '/composer.lock', $this->composerLock('3.4.3'));

            return null;
        };
        $fix = Fixtures::fix('twig/twig', '3.4.0', '3.4.3', DependencyKind::Transitive, ecosystem: Ecosystem::Composer);
        $vulnerable = new TrivyReport(
            [Fixtures::vulnerability('twig/twig', '3.4.0', Ecosystem::Composer)],
            [],
        );

        $result = $this->applier(new QueueRescanner(Fixtures::report()), new ComposerStrategy())->apply(
            Fixtures::plan($fix),
            $vulnerable,
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(
            [
                'composer validate --no-check-publish --no-interaction --no-plugins',
                'composer update twig/twig --with-all-dependencies --no-install --no-interaction --no-scripts'
                . ' --no-progress --no-plugins',
                'composer validate --no-check-publish --no-interaction --no-plugins',
            ],
            $this->runner->toolCommands(),
            'The check also runs before the fixes: it is compared with the one that follows.',
        );
        $this->assertNull($result->finalCheckError);
        $this->assertNull($result->existingCheckFindings);
        @unlink($this->dir . '/composer.json');
        @unlink($this->dir . '/composer.lock');
    }

    #[Test]
    public function findingsThatWereAlreadyThereDoNotFailTheCheck(): void
    {
        $result = $this->composerFixWithChecks(
            before: new CommandResult(2, "# General errors\n- some unrelated error\n"),
            after: new CommandResult(2, "# General errors\n  - some   unrelated error\n"),
        );

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertNull($result->finalCheckError);
        $this->assertSame(
            '# General errors | - some   unrelated error',
            $result->existingCheckFindings,
            'What the final check reported.',
        );
        $this->assertTrue($result->isHealthy());
    }

    #[Test]
    public function aNewFindingFailsTheCheckEvenWhenOthersWereAlreadyThere(): void
    {
        $result = $this->composerFixWithChecks(
            before: new CommandResult(2, "# General errors\n- some unrelated error\n"),
            after: new CommandResult(
                2,
                "# General errors\n- some unrelated error\n# Lock file errors\n- The lock file is not up to date\n",
            ),
        );

        $this->assertStringContainsString('The lock file is not up to date', (string) $result->finalCheckError);
        $this->assertNull($result->existingCheckFindings);
        $this->assertFalse($result->isHealthy());
    }

    #[Test]
    public function aCheckThatWasCleanAndFailsNowIsAFailure(): void
    {
        $result = $this->composerFixWithChecks(
            before: new CommandResult(0, ''),
            after: new CommandResult(
                2,
                "# Lock file errors\n- stale\n",
            ),
        );

        $this->assertNotNull($result->finalCheckError);
    }

    #[Test]
    public function aYarnFixOnlyRunsTheCheckOnce(): void
    {
        $this->applier(new QueueRescanner(Fixtures::report()))->apply(
            Fixtures::plan(Fixtures::fix()),
            Fixtures::report([Fixtures::vulnerability()]),
        );

        $this->assertCount(
            1,
            array_filter(
                $this->runner->toolCommands(),
                static fn(string $c): bool => 'yarn install --immutable' === $c,
            ),
        );
    }

    #[Test]
    public function aYarnProjectWithAMissingLocalSourceIsRefusedBeforeAnyCommand(): void
    {
        file_put_contents(
            $this->dir . '/package.json',
            '{"name":"app","dependencies":{"@symfony/ux":"file:vendor/ux/assets"}}',
        );

        try {
            $this->applier(new QueueRescanner(Fixtures::report()))->apply(
                Fixtures::plan(Fixtures::fix()),
                Fixtures::report([Fixtures::vulnerability()]),
            );
            self::fail('Yarn cannot resolve a project whose local source is missing.');
        } catch (UnsupportedProjectException $unsupportedProjectException) {
            $this->assertStringContainsString(
                '@symfony/ux (file:vendor/ux/assets)',
                $unsupportedProjectException->getMessage(),
            );
            $this->assertStringContainsString(
                'only fetches the packages of composer.lock',
                $unsupportedProjectException->getMessage(),
            );
        }

        $this->assertSame([], $this->runner->toolCommands(), 'Nothing was run.');
    }

    #[Test]
    public function itOnlyHandlesTheEcosystemOfItsStrategy(): void
    {
        $this->expectException(UnsupportedProjectException::class);

        $this->applier(new QueueRescanner())->apply(
            Fixtures::plan(Fixtures::fix(ecosystem: Ecosystem::Composer)),
            Fixtures::report(),
        );
    }

    private function composerFixWithChecks(CommandResult $before, CommandResult $after): ApplyResult
    {
        file_put_contents($this->dir . '/composer.json', '{"require":{"other/pkg":"^1.0"}}');
        file_put_contents($this->dir . '/composer.lock', $this->composerLock('3.4.0'));
        $validations = 0;
        $this->runner->handler = function (array $command) use (&$validations, $before, $after): ?CommandResult {
            if ('validate' === $command[1]) {
                return 0 === $validations++ ? $before : $after;
            }
            file_put_contents($this->dir . '/composer.lock', $this->composerLock('3.4.3'));

            return null;
        };
        $fix = Fixtures::fix('twig/twig', '3.4.0', '3.4.3', DependencyKind::Transitive, ecosystem: Ecosystem::Composer);
        $vulnerable = new TrivyReport(
            [Fixtures::vulnerability('twig/twig', '3.4.0', Ecosystem::Composer)],
            [],
        );

        try {
            return $this->applier(new QueueRescanner(Fixtures::report()), new ComposerStrategy())->apply(
                Fixtures::plan($fix),
                $vulnerable,
            );
        } finally {
            @unlink($this->dir . '/composer.json');
            @unlink($this->dir . '/composer.lock');
        }
    }

    /**
     * @param array<string, list<string>> $versions
     */
    private function lock(array $versions): void
    {
        $content = '';

        foreach ($versions as $name => $list) {
            foreach ($list as $version) {
                $content .= sprintf("%s@^%s:\n  version \"%s\"\n\n", $name, $version, $version);
            }
        }

        file_put_contents($this->dir . '/yarn.lock', $content);
    }

    private function composerLock(string $twigVersion): string
    {
        return json_encode(['packages' => [['name' => 'twig/twig', 'version' => $twigVersion]]], \JSON_THROW_ON_ERROR);
    }

    private function applier(
        QueueRescanner $rescanner,
        ?FixStrategy $strategy = null,
        VerifyMode $verify = VerifyMode::Final,
        ?Progress $progress = null,
    ): FixApplier {
        return new FixApplier(
            $this->runner,
            new WorkingTree($this->runner, $this->dir),
            $rescanner,
            $strategy ?? new YarnBerryStrategy(),
            new PackageJsonEditor(),
            $this->dir,
            $verify,
            $progress ?? new NullProgress(),
        );
    }
}
