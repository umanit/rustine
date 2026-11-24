<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\CommandResult;
use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\FixApplier;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\FixStrategy;
use Rustine\Apply\PackageJsonEditor;
use Rustine\Apply\ToolEnvironment;
use Rustine\Apply\UnsupportedProjectException;
use Rustine\Apply\WorkingTree;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Apply\YarnClassicStrategy;
use Rustine\Pinning\PinningSource;
use Rustine\Pinning\ToolPinning;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Tests\Apply\Support\FakeRunner;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Apply\Support\QueueRescanner;
use Rustine\Trivy\Ecosystem;

final class FixApplierTest extends TestCase
{
    private const PACKAGE_JSON = "{\n  \"name\": \"app\"\n}\n";
    private const LOCK = "# lock\n";

    private string $dir;
    private FakeRunner $runner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-applier-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents($this->dir . '/package.json', self::PACKAGE_JSON);
        file_put_contents($this->dir . '/yarn.lock', self::LOCK);

        $this->runner = new FakeRunner();
        // By default `yarn up`/`yarn install` succeed and rewrite the lockfile.
        $this->runner->handler = function (array $command): ?CommandResult {
            file_put_contents($this->dir . '/yarn.lock', "# changed by yarn\n");

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

    #[Test]
    public function itKeepsTheLockfileRefreshWhenTheCvesDisappear(): void
    {
        $rescanner = new QueueRescanner(Fixtures::report());

        $result = $this->applier($rescanner)->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report([Fixtures::vulnerability()]));

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        self::assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
        self::assertSame(["yarn up -R ckeditor4", 'yarn install --immutable'], $this->runner->yarnCommands());
        self::assertSame("# changed by yarn\n", file_get_contents($this->dir . '/yarn.lock'));
        self::assertTrue($result->isHealthy());
        self::assertTrue($result->hasChanges());
    }

    #[Test]
    public function itRunsYarnInAnEnvironmentThatCannotBlockTheLockfileOrRunScripts(): void
    {
        $this->applier(new QueueRescanner(Fixtures::report()))->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report([Fixtures::vulnerability()]));

        $yarnEnvs = array_values(array_filter($this->runner->envs, static fn (array $e): bool => [] !== $e));
        self::assertSame('false', $yarnEnvs[0]['YARN_ENABLE_IMMUTABLE_INSTALLS']);
        self::assertSame('false', $yarnEnvs[0]['YARN_ENABLE_SCRIPTS']);
        self::assertSame($this->dir, $yarnEnvs[0]['MISE_TRUSTED_CONFIG_PATHS']);
        self::assertArrayNotHasKey('YARN_ENABLE_IMMUTABLE_INSTALLS', $yarnEnvs[1], 'The final check must stay immutable.');
    }

    #[Test]
    public function itFallsBackToAResolutionAndRestoresFilesBetweenAttempts(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);
        $rescanner = new QueueRescanner($vulnerable, Fixtures::report());

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(AttemptKind::Resolution, $result->outcomes[0]->strategy);
        self::assertSame(
            ['lockfile-refresh: the CVEs are still reported after the attempt', 'resolution: ok'],
            $result->outcomes[0]->log,
        );
        self::assertSame(
            "{\n  \"name\": \"app\",\n  \"resolutions\": {\n    \"braces\": \"^3.0.3\"\n  }\n}\n",
            file_get_contents($this->dir . '/package.json'),
        );
    }

    #[Test]
    public function withYarnClassicItPrunesTheLockfileEntriesBeforeInstalling(): void
    {
        file_put_contents($this->dir . '/yarn.lock', "# yarn lockfile v1\n\n\nbraces@^3.0.2:\n  version \"3.0.2\"\n\nother@^1.0.0:\n  version \"1.0.0\"\n");
        $seenByYarn = null;
        $this->runner->handler = function (array $command) use (&$seenByYarn): ?CommandResult {
            $seenByYarn ??= file_get_contents($this->dir . '/yarn.lock');

            return null;
        };
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);

        $result = $this->applier(new QueueRescanner(Fixtures::report()), new YarnClassicStrategy())->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        // Yarn rewrites the whole file afterwards: only the remaining entries matter here.
        self::assertStringNotContainsString('braces', (string) $seenByYarn);
        self::assertStringContainsString("other@^1.0.0:\n  version \"1.0.0\"\n", (string) $seenByYarn);
        self::assertSame(
            ['yarn install --non-interactive --ignore-scripts', 'yarn install --frozen-lockfile --non-interactive --ignore-scripts'],
            $this->runner->yarnCommands(),
        );
    }

    #[Test]
    public function withYarnClassicAFailedPruneIsRestored(): void
    {
        $original = "# yarn lockfile v1\n\n\nbraces@^3.0.2:\n  version \"3.0.2\"\n";
        file_put_contents($this->dir . '/yarn.lock', $original);
        $this->runner->handler = null;
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')]);

        $result = $this->applier(new QueueRescanner($vulnerable, $vulnerable), new YarnClassicStrategy())->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertSame($original, file_get_contents($this->dir . '/yarn.lock'));
        self::assertSame(
            "{\n  \"name\": \"app\"\n}\n",
            file_get_contents($this->dir . '/package.json'),
        );
    }

    #[Test]
    public function itRefusesAResolutionThatWouldDragAnotherMajorAlong(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        // braces is also installed in 2.x: `resolutions["braces"] = "^3.0.3"` would force it to 3.x too.
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')], ['braces' => ['2.3.2', '3.0.2']]);
        $rescanner = new QueueRescanner($vulnerable);

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertSame(
            [
                'lockfile-refresh: the CVEs are still reported after the attempt',
                'resolution: skipped, braces is also installed in other majors (2.3.2) and a resolution would force them all to ^3.0.3',
            ],
            $result->outcomes[0]->log,
        );
        self::assertSame(['yarn up -R braces'], $this->runner->yarnCommands());
        self::assertSame(self::PACKAGE_JSON, file_get_contents($this->dir . '/package.json'));
    }

    #[Test]
    public function otherVersionsOfTheSameMajorDoNotPreventAResolution(): void
    {
        $fix = Fixtures::fix('braces', '3.0.2', '3.0.3', DependencyKind::Transitive);
        $vulnerable = Fixtures::report([Fixtures::vulnerability('braces', '3.0.2')], ['braces' => ['3.0.1', '3.0.2']]);

        $result = $this->applier(new QueueRescanner($vulnerable, Fixtures::report()))->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(AttemptKind::Resolution, $result->outcomes[0]->strategy);
    }

    #[Test]
    public function theSameCveOnAnotherMajorDoesNotMakeTheFixFail(): void
    {
        $fix = Fixtures::fix('semver', '5.7.1', '5.7.2', DependencyKind::Transitive);
        $other = Fixtures::vulnerability('semver', '7.5.1');
        // The refresh fixed semver 5.x; semver 7.x still carries the same CVE, as another instance of the plan.
        $rescanner = new QueueRescanner(Fixtures::report([$other]));

        $result = $this->applier($rescanner)->apply(
            Fixtures::plan($fix),
            Fixtures::report([Fixtures::vulnerability('semver', '5.7.1'), $other]),
        );

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        self::assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
    }

    #[Test]
    public function theCveStillReportedOnTheSameMajorIsAFailure(): void
    {
        $fix = Fixtures::fix('semver', '5.7.1', '5.7.2', DependencyKind::Transitive);
        // Neither attempt removes the CVE from semver 5.x: the fix fails.
        $stillVulnerable = Fixtures::report([Fixtures::vulnerability('semver', '5.7.1')]);
        $rescanner = new QueueRescanner($stillVulnerable, $stillVulnerable);

        $result = $this->applier($rescanner)->apply(Fixtures::plan($fix), $stillVulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
    }

    #[Test]
    public function itRestoresEverythingWhenNoAttemptWorks(): void
    {
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);
        $rescanner = new QueueRescanner($vulnerable, $vulnerable);

        $result = $this->applier($rescanner)->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertCount(2, $result->outcomes[0]->log);
        self::assertFalse($result->isHealthy());
        self::assertFalse($result->hasChanges());
        self::assertSame(self::LOCK, file_get_contents($this->dir . '/yarn.lock'));
        self::assertSame(self::PACKAGE_JSON, file_get_contents($this->dir . '/package.json'));
        self::assertNotContains('yarn install --immutable', $this->runner->yarnCommands(), 'No final check when nothing changed.');
    }

    #[Test]
    public function aFailingPackageDoesNotBlockTheNext(): void
    {
        $a = Fixtures::fix('aaa', '1.0.0', '1.0.1');
        $b = Fixtures::fix('bbb', '2.0.0', '2.0.1');
        $before = Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0'), Fixtures::vulnerability('bbb', '2.0.0')]);
        // aaa: both attempts leave the CVE; bbb: its first attempt clears its own CVE (aaa is still reported).
        $rescanner = new QueueRescanner($before, $before, Fixtures::report([Fixtures::vulnerability('aaa', '1.0.0')]));

        $result = $this->applier($rescanner)->apply(Fixtures::plan($a, $b), $before);

        self::assertSame(
            [FixOutcomeStatus::Failed, FixOutcomeStatus::Applied],
            array_map(static fn ($o) => $o->status, $result->outcomes),
        );
        self::assertFalse($result->isHealthy());
        self::assertTrue($result->hasChanges());
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('nonRegistrySpecs')]
    public function itDoesNotTouchDependenciesDeclaredWithANonRegistrySource(string $spec): void
    {
        file_put_contents($this->dir . '/package.json', json_encode(['dependencies' => ['ckeditor4' => $spec]], \JSON_THROW_ON_ERROR));
        $rescanner = new QueueRescanner();

        $result = $this->applier($rescanner)->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report([Fixtures::vulnerability()]));

        self::assertSame(FixOutcomeStatus::UnsupportedSource, $result->outcomes[0]->status);
        self::assertStringContainsString($spec, $result->outcomes[0]->log[0]);
        self::assertSame([], $this->runner->yarnCommands());
        self::assertSame(0, $rescanner->calls);
        self::assertTrue($result->isHealthy(), 'A manual fix is reported, not a failure of the bot.');
        self::assertFalse($result->hasChanges());
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
        yield 'local path' => ['file:../ckeditor4'];
        yield 'workspace' => ['workspace:*'];
    }

    #[Test]
    public function aRegistryRangeIsStillBumped(): void
    {
        file_put_contents($this->dir . '/package.json', json_encode(['devDependencies' => ['ckeditor4' => '^4.22.1']], \JSON_THROW_ON_ERROR));

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report([Fixtures::vulnerability()]));

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
    }

    #[Test]
    public function itSkipsUnfixablePackagesAndThoseAlreadyFixed(): void
    {
        $unfixable = Fixtures::fix('major', '1.0.0', '2.0.0', status: PackageFixStatus::MajorBumpRequired);
        $gone = Fixtures::fix('gone', '1.0.0', '1.0.1');
        $rescanner = new QueueRescanner();

        $result = $this->applier($rescanner)->apply(Fixtures::plan($unfixable, $gone), Fixtures::report());

        self::assertSame(
            [FixOutcomeStatus::Skipped, FixOutcomeStatus::AlreadyFixed],
            array_map(static fn ($o) => $o->status, $result->outcomes),
        );
        self::assertSame([], $this->runner->yarnCommands());
        self::assertSame(0, $rescanner->calls);
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

        $result = $this->applier(new QueueRescanner(Fixtures::report(), Fixtures::report()))->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertCount(1, $result->outcomes[0]->log, 'The correction is abandoned, not retried.');
        self::assertStringContainsString('unexpected files changed: src/app.js', $result->outcomes[0]->log[0]);
        self::assertSame(self::LOCK, file_get_contents($this->dir . '/yarn.lock'));
    }

    #[Test]
    public function aFailingYarnCommandCountsAsAFailedAttempt(): void
    {
        $this->runner->handler = static fn (array $command): CommandResult => new CommandResult(1, "boom\nYN0035: 404");
        $vulnerable = Fixtures::report([Fixtures::vulnerability()]);

        $result = $this->applier(new QueueRescanner())->apply(Fixtures::plan(Fixtures::fix()), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertStringContainsString('failed (exit 1): boom | YN0035: 404', $result->outcomes[0]->log[0]);
    }

    #[Test]
    public function itReportsAFinalInstallCheckFailure(): void
    {
        $this->runner->handler = function (array $command): ?CommandResult {
            if (\in_array('--immutable', $command, true)) {
                return new CommandResult(1, 'YN0028: lockfile would be modified');
            }

            return null;
        };

        $result = $this->applier(new QueueRescanner(Fixtures::report()))->apply(Fixtures::plan(Fixtures::fix()), Fixtures::report([Fixtures::vulnerability()]));

        self::assertSame('YN0028: lockfile would be modified', $result->finalCheckError);
        self::assertFalse($result->isHealthy());
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
        file_put_contents($this->dir . '/composer.lock', '{}');
        $this->runner->handler = static fn (array $command): ?CommandResult => \in_array('why-not', $command, true)
            ? new CommandResult(1, "twig/twig 3.4.3 requires php >=8.1\nproblem 2")
            : null;
        $fix = Fixtures::fix('twig/twig', '3.4.0', '3.4.3', ecosystem: Ecosystem::Composer);
        $vulnerable = new \Rustine\Trivy\TrivyReport([Fixtures::vulnerability('twig/twig', '3.4.0', Ecosystem::Composer)], []);

        $result = $this->applier(new QueueRescanner($vulnerable, $vulnerable), new ComposerStrategy())->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        self::assertSame(
            ['lockfile-refresh', 'direct-bump', 'composer why-not twig/twig 3.4.3 --locked'],
            array_map(static fn (string $line): string => explode(':', $line)[0], $result->outcomes[0]->log),
        );
        self::assertStringContainsString('requires php >=8.1 | problem 2', $result->outcomes[0]->log[2]);
        @unlink($this->dir . '/composer.json');
        @unlink($this->dir . '/composer.lock');
    }

    #[Test]
    public function withComposerAFixIsValidatedWithComposerValidate(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"require":{"other/pkg":"^1.0"}}');
        file_put_contents($this->dir . '/composer.lock', '{}');
        $fix = Fixtures::fix('twig/twig', '3.4.0', '3.4.3', DependencyKind::Transitive, ecosystem: Ecosystem::Composer);
        $vulnerable = new \Rustine\Trivy\TrivyReport([Fixtures::vulnerability('twig/twig', '3.4.0', Ecosystem::Composer)], []);

        $result = $this->applier(new QueueRescanner(Fixtures::report()), new ComposerStrategy())->apply(Fixtures::plan($fix), $vulnerable);

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        self::assertSame(
            ['composer update twig/twig --with-all-dependencies --no-install --no-interaction --no-scripts --no-plugins --no-progress', 'composer validate --no-check-publish --no-interaction'],
            $this->runner->yarnCommands(),
        );
        @unlink($this->dir . '/composer.json');
        @unlink($this->dir . '/composer.lock');
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

    private function applier(QueueRescanner $rescanner, ?FixStrategy $strategy = null): FixApplier
    {
        return new FixApplier(
            $this->runner,
            new ToolEnvironment(new ToolPinning(PinningSource::Mise, ['yarn' => '4.2.2'], 'mise.toml'), $this->dir),
            new WorkingTree($this->runner, $this->dir),
            $rescanner,
            $strategy ?? new YarnBerryStrategy(),
            new PackageJsonEditor(),
            $this->dir,
        );
    }
}
