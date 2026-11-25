<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
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
use Rustine\Tests\Apply\Support\QueueRescanner;
use Rustine\Trivy\Ecosystem;
use Symfony\Component\Process\Process;

/**
 * Real Yarn Berry (4+) and real git, no network: the packages come from a registry served by PHP's built-in web server
 * from a temporary directory. Only Trivy is replaced. Skipped without Yarn 4+, `tar` or a free local port.
 */
final class YarnBerryIntegrationTest extends TestCase
{
    private string $root;
    private string $dir;
    private ?Process $server = null;

    protected function setUp(): void
    {
        $version = (new ProcessCommandRunner(30.0))->run(['yarn', '--version'], sys_get_temp_dir());
        if (!$version->isSuccessful() || !YarnBerryStrategy::supportsLockfileOnly(trim($version->output))) {
            self::markTestSkipped('Yarn 4 or later is not available.');
        }
        if (!(new ProcessCommandRunner(30.0))->run(['tar', '--version'], sys_get_temp_dir())->isSuccessful()) {
            self::markTestSkipped('tar is not available.');
        }

        $this->root = sys_get_temp_dir() . '/rustine-yarn-' . bin2hex(random_bytes(4));
        $this->dir = $this->root . '/project';
        mkdir($this->dir, 0777, true);
        mkdir($this->root . '/home');
        $this->startRegistry();
    }

    protected function tearDown(): void
    {
        $this->server?->stop(1);
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

        return new class($home) implements CommandRunner {
            public function __construct(private readonly string $home)
            {
            }

            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return (new ProcessCommandRunner(120.0))->run($command, $cwd, [
                    ...$env,
                    'HOME' => $this->home,
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
        $port = $this->port();
        file_put_contents($this->dir . '/.yarnrc.yml', sprintf(
            "nodeLinker: node-modules\nnpmRegistryServer: \"http://127.0.0.1:%d\"\nunsafeHttpWhitelist: [\"127.0.0.1\"]\nenableTelemetry: false\n",
            $port,
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

    private ?int $port = null;

    private function port(): int
    {
        return $this->port ?? self::fail('The registry is not started.');
    }

    private function startRegistry(): void
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            self::markTestSkipped('No local port available: ' . $error);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $registry = $this->root . '/registry';
        mkdir($registry . '/lib/-', 0777, true);
        $versions = [];
        foreach (['1.0.0', '1.0.5', '1.1.0', '2.0.0'] as $version) {
            $build = $this->root . '/build';
            mkdir($build . '/package', 0777, true);
            file_put_contents($build . '/package/package.json', json_encode(['name' => 'lib', 'version' => $version], \JSON_THROW_ON_ERROR));
            $tarball = $registry . '/lib/-/lib-' . $version . '.tgz';
            $made = (new ProcessCommandRunner(30.0))->run(['tar', '-czf', $tarball, '-C', $build, 'package'], $this->root);
            self::assertTrue($made->isSuccessful(), $made->output);
            (new ProcessCommandRunner(30.0))->run(['rm', '-rf', '--', $build], $this->root);
            $versions[$version] = [
                'name' => 'lib',
                'version' => $version,
                'dist' => ['tarball' => sprintf('http://127.0.0.1:%d/lib/-/lib-%s.tgz', $port, $version), 'shasum' => sha1_file($tarball)],
            ];
        }
        file_put_contents($registry . '/lib.json', json_encode(['name' => 'lib', 'dist-tags' => ['latest' => '2.0.0'], 'versions' => $versions], \JSON_THROW_ON_ERROR));
        file_put_contents($this->root . '/router.php', <<<'PHP'
            <?php
            $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            $root = __DIR__ . '/registry';
            if ('/lib' === $path) {
                header('Content-Type: application/json');
                readfile($root . '/lib.json');

                return;
            }
            if (is_file($root . $path)) {
                header('Content-Type: application/octet-stream');
                readfile($root . $path);

                return;
            }
            http_response_code(404);
            PHP);

        $this->server = new Process([\PHP_BINARY, '-S', '127.0.0.1:' . $port, $this->root . '/router.php'], $this->root);
        $this->server->start();
        for ($i = 0; $i < 50; ++$i) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (false !== $connection) {
                fclose($connection);
                $this->port = $port;

                return;
            }
            usleep(100_000);
        }

        self::markTestSkipped('The test registry did not start.');
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
