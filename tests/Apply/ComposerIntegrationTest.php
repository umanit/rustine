<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\CommandResult;
use Rustine\Apply\CommandRunner;
use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\FixApplier;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\LockfileVersions;
use Rustine\Apply\PackageJsonEditor;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\VerifyMode;
use Rustine\Apply\WorkingTree;
use Rustine\Plan\DependencyKind;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Tests\Apply\Support\QueueRescanner;
use Rustine\Trivy\Ecosystem;

/**
 * Real Composer and real git, no network: the packages come from an inline `package` repository (Packagist is
 * disabled) and `--no-install` never downloads them. Only Trivy is replaced. Skipped without Composer.
 */
final class ComposerIntegrationTest extends TestCase
{
    private string $dir;

    private string $home;

    protected function setUp(): void
    {
        $probe = new ProcessCommandRunner(30.0)->run(['composer', '--version'], sys_get_temp_dir());
        if (!$probe->isSuccessful()) {
            self::markTestSkipped('Composer is not available.');
        }

        $this->dir = sys_get_temp_dir() . '/rustine-composer-' . bin2hex(random_bytes(4));
        $this->home = $this->dir . '-home';
        mkdir($this->dir);
        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        if (isset($this->dir)) {
            new ProcessCommandRunner()->run(['rm', '-rf', '--', $this->dir, $this->home], sys_get_temp_dir());
        }
    }

    #[Test]
    public function aCaretConstraintIsRefreshedWithoutTouchingComposerJson(): void
    {
        $this->project('^1.0', locked: '1.0.0');

        $result = $this->apply(target: '1.0.5');

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(AttemptKind::LockfileRefresh, $result->outcomes[0]->strategy);
        // The refresh takes the highest version the constraint allows, never a new major.
        $this->assertSame('1.1.0', $this->lockedVersion());
        $this->assertStringContainsString('"^1.0"', (string) file_get_contents($this->dir . '/composer.json'));
        $this->assertSame([' M composer.lock'], $this->changes(), 'only the lockfile changed');
    }

    #[Test]
    public function aConstraintThatForbidsTheFixIsRaisedInItsOwnStyle(): void
    {
        $this->project('1.0.*', locked: '1.0.0');

        $result = $this->apply(target: '1.1.0');

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame(AttemptKind::DirectBump, $result->outcomes[0]->strategy);
        $this->assertSame('1.1.0', $this->lockedVersion());
        $this->assertStringContainsString(
            '"vendor/lib": "1.1.*"',
            (string) file_get_contents($this->dir . '/composer.json'),
        );
    }

    #[Test]
    public function aCacheFilledInAnAlreadyUntrackedDirectoryDoesNotAbandonTheFix(): void
    {
        $this->project('^1.0', locked: '1.0.0');
        mkdir($this->dir . '/.cache');
        file_put_contents($this->dir . '/.cache/db', 'old');

        $result = $this->apply(target: '1.0.5', afterCommand: function (): void {
            file_put_contents($this->dir . '/.cache/more', 'new');
        });

        $this->assertSame(FixOutcomeStatus::Applied, $result->outcomes[0]->status);
        $this->assertSame('1.1.0', $this->lockedVersion());
    }

    #[Test]
    public function aNewStrayFileAbandonsTheFixAndRestoresTheProject(): void
    {
        $this->project('^1.0', locked: '1.0.0');
        $lockBefore = (string) file_get_contents($this->dir . '/composer.lock');

        $result = $this->apply(target: '1.0.5', afterCommand: function (): void {
            file_put_contents($this->dir . '/stray.txt', 'x');
        });

        $this->assertSame(FixOutcomeStatus::Failed, $result->outcomes[0]->status);
        $this->assertStringContainsString('unexpected files changed: stray.txt', $result->outcomes[0]->log[0]);
        $this->assertSame(
            $lockBefore,
            file_get_contents($this->dir . '/composer.lock'),
            'the lockfile is back as it was',
        );
    }

    /**
     * @param (\Closure(): void)|null $afterCommand run after every Composer command that changes the project
     */
    private function apply(string $target, ?\Closure $afterCommand = null): ApplyResult
    {
        $runner = new readonly class ($this->home, $afterCommand) implements CommandRunner {
            public function __construct(private string $home, private ?\Closure $after)
            {
            }

            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                $result = new ProcessCommandRunner(120.0)->run($command, $cwd, [
                    ...$env,
                    'COMPOSER_HOME'           => $this->home,
                    'COMPOSER_CACHE_DIR'      => $this->home . '/cache',
                    'COMPOSER_NO_INTERACTION' => '1',
                ]);
                if (
                    $this->after instanceof \Closure
                    && 'composer' === $command[0]
                    && \in_array($command[1] ?? '', ['update', 'require'], true)
                ) {
                    ($this->after)();
                }

                return $result;
            }
        };

        $fix = Fixtures::fix('vendor/lib', '1.0.0', $target, DependencyKind::Direct, ecosystem: Ecosystem::Composer);
        $initial = Fixtures::report([Fixtures::vulnerability('vendor/lib', '1.0.0', Ecosystem::Composer)]);

        return new FixApplier(
            $runner,
            new WorkingTree($runner, $this->dir),
            new QueueRescanner(),
            new ComposerStrategy(),
            new PackageJsonEditor(),
            $this->dir,
            VerifyMode::None,
        )->apply(Fixtures::plan($fix), $initial);
    }

    private function lockedVersion(): string
    {
        $lock = new LockfileVersions()->parse(
            Ecosystem::Composer,
            (string) file_get_contents($this->dir . '/composer.lock'),
        );

        return $lock['vendor/lib'][0] ?? self::fail('vendor/lib is not locked.');
    }

    /**
     * @return list<string>
     */
    private function changes(): array
    {
        $output = $this->exec(['git', 'status', '--porcelain', '--untracked-files=normal']);

        return array_values(array_filter(explode("\n", $output)));
    }

    /**
     * A project whose only requirement is `vendor/lib` (versions 1.0.0, 1.0.5, 1.1.0 and 2.0.0), locked at `$locked`
     * and committed.
     */
    private function project(string $constraint, string $locked): void
    {
        $versions = array_map(
            static fn(string $v): array => [
                'name'    => 'vendor/lib',
                'version' => $v,
                'dist'    => [
                    'type' => 'zip',
                    'url'  => 'https://example.invalid/lib-' . $v . '.zip',
                ],
            ],
            ['1.0.0', '1.0.5', '1.1.0', '2.0.0'],
        );
        $manifest = static fn(string $require): string => json_encode([
                'name'         => 'acme/app',
                'repositories' => [['type' => 'package', 'package' => $versions], ['packagist.org' => false]],
                'require'      => ['vendor/lib' => $require],
            ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR) . "\n";

        // Lock the old version first, then give the manifest its real constraint and refresh the hash only.
        file_put_contents($this->dir . '/composer.json', $manifest($locked));
        $this->exec(['composer', 'update', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction']);
        file_put_contents($this->dir . '/composer.json', $manifest($constraint));
        $this->exec(
            ['composer', 'update', '--lock', '--no-install', '--no-scripts', '--no-plugins', '--no-interaction'],
        );
        $this->assertSame($locked, $this->lockedVersion());

        $this->exec(['git', 'init', '--quiet']);
        $this->exec(['git', 'add', '.']);
        $this->exec(['git', '-c', 'user.name=t', '-c', 'user.email=t@example.com', 'commit', '--quiet', '-m', 'init']);
    }

    /**
     * @param list<string> $command
     */
    private function exec(array $command): string
    {
        $result = new ProcessCommandRunner(120.0)->run($command, $this->dir, [
            'COMPOSER_HOME'      => $this->home,
            'COMPOSER_CACHE_DIR' => $this->home . '/cache',
        ]);
        $this->assertTrue($result->isSuccessful(), implode(' ', $command) . ': ' . $result->output);

        return $result->output;
    }
}
