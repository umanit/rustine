<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\SkippedWithMessageException;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\CommandResult;
use Rustine\Apply\CommandRunner;
use Rustine\Apply\FixApplier;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\PackageJsonEditor;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\VerifyMode;
use Rustine\Apply\WorkingTree;
use Rustine\Apply\YarnBerryStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Apply\Support\LocalNpmRegistry;
use Rustine\Tests\Apply\Support\QueueRescanner;
use Rustine\Trivy\Ecosystem;

/**
 * Real Yarn Berry (4+) and real git, no network: the packages come from a registry served by PHP's built-in web
 * server. Only Trivy is replaced. Skipped without Yarn 4+, `tar` or a free local port (see {@see LocalNpmRegistry}).
 */
final class YarnBerryIntegrationTest extends TestCase
{
    private string $root;
    private string $dir;
    private LocalNpmRegistry $registry;

    protected function setUp(): void
    {
        $version = (new ProcessCommandRunner(30.0))->run(['yarn', '--version'], sys_get_temp_dir());
        if (!$version->isSuccessful() || !YarnBerryStrategy::supportsLockfileOnly(trim($version->output))) {
            self::markTestSkipped('Yarn 4 or later is not available.');
        }

        $this->root = sys_get_temp_dir() . '/rustine-yarn-' . bin2hex(random_bytes(4));
        $this->dir = $this->root . '/project';
        mkdir($this->dir, 0777, true);
        mkdir($this->root . '/home');
        $this->registry = new LocalNpmRegistry();
        try {
            $this->registry->start($this->root);
        } catch (SkippedWithMessageException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->registry)) {
            $this->registry->stop();
        }
        if (isset($this->root)) {
            (new ProcessCommandRunner())->run(['rm', '-rf', '--', $this->root], sys_get_temp_dir());
        }
    }

    #[Test]
    public function aRangeIsRefreshedWithoutTouchingPackageJson(): void
    {
        $this->project('^1.0.0', locked: '1.0.0');

        $result = $this->apply(target: '1.0.5');

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status, implode("\n", $result->outcomes[0]->log));
        self::assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
        self::assertStringContainsString('version: 1.1.0', $this->lockBlock());
        self::assertSame(['M yarn.lock'], $this->changes(), 'only the lockfile changed, no node_modules was linked');
    }

    #[Test]
    public function aRangeThatForbidsTheFixIsBumped(): void
    {
        $this->project('~1.0.0', locked: '1.0.0');

        $result = $this->apply(target: '1.1.0');

        self::assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status, implode("\n", $result->outcomes[0]->log));
        self::assertSame(AttemptKind::DirectBump, $result->outcomes[0]->strategy);
        self::assertStringContainsString('version: 1.1.0', $this->lockBlock());
        self::assertStringContainsString('"lib": "^1.1.0"', (string) file_get_contents($this->dir . '/package.json'));
        self::assertSame(['M package.json', 'M yarn.lock'], $this->changes());
    }

    private function apply(string $target): ApplyResult
    {
        $runner = $this->runner();
        $fix = Fixtures::fix('lib', '1.0.0', $target, DependencyKind::Direct);
        $initial = Fixtures::report([Fixtures::vulnerability('lib', '1.0.0')]);

        return (new FixApplier(
            $runner,
            new WorkingTree($runner, $this->dir),
            new QueueRescanner(),
            new YarnBerryStrategy(lockfileOnly: true),
            new PackageJsonEditor(),
            $this->dir,
            VerifyMode::None,
        ))->apply(Fixtures::plan($fix), $initial);
    }

    private function runner(): CommandRunner
    {
        $home = $this->root . '/home';

        // The sandboxed HOME hides the Yarn activated through Corepack (kept in its cache): point back to the real one.
        $corepackHome = getenv('COREPACK_HOME') ?: (getenv('XDG_CACHE_HOME') ?: (string) getenv('HOME') . '/.cache') . '/node/corepack';

        return new class($home, $corepackHome) implements CommandRunner {
            public function __construct(private readonly string $home, private readonly string $corepackHome)
            {
            }

            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return (new ProcessCommandRunner(120.0))->run($command, $cwd, [
                    ...$env,
                    'HOME' => $this->home,
                    'COREPACK_HOME' => $this->corepackHome,
                    'YARN_GLOBAL_FOLDER' => $this->home . '/yarn',
                    'YARN_ENABLE_TELEMETRY' => '0',
                    'YARN_ENABLE_GLOBAL_CACHE' => '0',
                ]);
            }
        };
    }

    /**
     * Locks `lib` at `$locked`, then gives `package.json` its real range and renames the lock entry to match, so that
     * the project starts consistent with the old version locked under the new range. Committed.
     */
    private function project(string $range, string $locked): void
    {
        file_put_contents($this->dir . '/.yarnrc.yml', sprintf(
            "nodeLinker: node-modules\nnpmRegistryServer: \"%s\"\nunsafeHttpWhitelist: [\"127.0.0.1\"]\nenableTelemetry: false\n",
            $this->registry->url(),
        ));
        file_put_contents($this->dir . '/.gitignore', "node_modules/\n.yarn/\n");
        file_put_contents($this->dir . '/yarn.lock', '');
        file_put_contents($this->dir . '/package.json', json_encode(['name' => 'app', 'private' => true, 'dependencies' => ['lib' => $locked]], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
        $this->exec(['yarn', 'install', '--mode=update-lockfile']);

        file_put_contents($this->dir . '/package.json', json_encode(['name' => 'app', 'private' => true, 'dependencies' => ['lib' => $range]], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR) . "\n");
        $lock = (string) file_get_contents($this->dir . '/yarn.lock');
        file_put_contents($this->dir . '/yarn.lock', str_replace(
            ['"lib@npm:' . $locked . '":', 'lib: "npm:' . $locked . '"'],
            ['"lib@npm:' . $range . '":', 'lib: "npm:' . $range . '"'],
            $lock,
        ));

        self::assertStringContainsString('version: ' . $locked, $this->lockBlock(), 'the project starts on the old version');

        $this->exec(['git', 'init', '--quiet']);
        $this->exec(['git', 'add', '.']);
        $this->exec(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'init']);
    }

    private function lockBlock(): string
    {
        $lock = (string) file_get_contents($this->dir . '/yarn.lock');

        return 1 === preg_match('/^"lib@npm:[^"]*":\n(?:  .*\n)+/m', $lock, $m) ? $m[0] : self::fail('lib is not locked: ' . $lock);
    }

    /**
     * @return list<string> `git status --short` entries, without the leading column padding
     */
    private function changes(): array
    {
        return array_values(array_filter(array_map('trim', explode("\n", $this->exec(['git', 'status', '--porcelain', '--untracked-files=normal'])))));
    }

    /**
     * @param list<string> $command
     */
    private function exec(array $command): string
    {
        $result = $this->runner()->run($command, $this->dir);
        self::assertTrue($result->isSuccessful(), implode(' ', $command) . ': ' . $result->output);

        return $result->output;
    }
}
