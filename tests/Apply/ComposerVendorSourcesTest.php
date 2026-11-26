<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
use Rustine\Apply\CommandRunner;
use Rustine\Apply\ComposerVendorSources;
use Rustine\Apply\ProcessCommandRunner;
use Rustine\Apply\ToolingException;
use Rustine\Tests\Apply\Support\FakeRunner;

/**
 * `needed()` reads files only. `provide()` runs the real Composer, without network: the dists are a local zip and a
 * `path` directory. Those tests are skipped without Composer.
 */
final class ComposerVendorSourcesTest extends TestCase
{
    private string $dir;

    private string $home;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-vendor-src-' . bin2hex(random_bytes(4));
        $this->home = $this->dir . '-home';
        mkdir($this->dir);
        mkdir($this->home);
    }

    protected function tearDown(): void
    {
        new ProcessCommandRunner()->run(['rm', '-rf', '--', $this->dir, $this->home], sys_get_temp_dir());
    }

    #[Test]
    public function onlyTheLockedPackagesThatPackageJsonPointsIntoAreNeeded(): void
    {
        $this->packageJson([
            '@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets',
            '@symfony/stimulus' => 'file:./vendor/symfony/stimulus-bundle/assets',
            'twice'             => 'file:vendor/symfony/ux-turbo/assets',
            'not-locked'        => 'file:vendor/acme/gone/assets',
            'outside'           => 'link:../tool',
            'escape'            => 'file:vendor/../lib',
            'lodash'            => '^4.17.0',
        ]);
        $this->lock(['symfony/ux-turbo', 'symfony/stimulus-bundle', 'symfony/http-kernel']);

        $this->assertSame(
            ['symfony/ux-turbo', 'symfony/stimulus-bundle'],
            new ComposerVendorSources(new FakeRunner(), $this->dir)->needed(),
        );
    }

    #[Test]
    public function linkedPackagesAreFoundWhetherOrNotTheyAreInVendor(): void
    {
        mkdir($this->dir . '/vendor/symfony/ux-turbo/assets', recursive: true);
        $this->packageJson([
            '@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets',
            'again'             => 'portal:./vendor/symfony/ux-turbo/assets',
            'other'             => 'file:vendor/symfony/stimulus-bundle/assets',
            'outside'           => 'link:../tool',
            'lodash'            => '^4.17.0',
        ]);

        $this->assertSame(
            [
                'symfony/ux-turbo'        => [
                    '@symfony/ux-turbo (file:vendor/symfony/ux-turbo/assets)',
                    'again (portal:./vendor/symfony/ux-turbo/assets)',
                ],
                'symfony/stimulus-bundle' => ['other (file:vendor/symfony/stimulus-bundle/assets)'],
            ],
            new ComposerVendorSources(new FakeRunner(), $this->dir)->linked(),
        );
    }

    #[Test]
    public function aPackageAlreadyInVendorOrAnotherVendorDirectoryIsTakenIntoAccount(): void
    {
        file_put_contents($this->dir . '/composer.json', '{"config":{"vendor-dir":"libs/php"}}');
        mkdir($this->dir . '/libs/php/acme/present/assets', 0o777, true);
        $this->packageJson([
            'present' => 'file:libs/php/acme/present/assets',
            'missing' => 'file:libs/php/acme/missing/assets',
            'default' => 'file:vendor/acme/default/assets',
        ]);
        $this->lock(['acme/present', 'acme/missing', 'acme/default']);

        $this->assertSame(['acme/missing'], new ComposerVendorSources(new FakeRunner(), $this->dir)->needed());
    }

    #[Test]
    public function nothingIsRunWhenNothingIsNeeded(): void
    {
        $runner = new FakeRunner();
        $this->packageJson(['lodash' => '^4.17.0']);

        $this->assertSame([], new ComposerVendorSources($runner, $this->dir)->provide());
        $this->assertSame([], $runner->commands);
    }

    #[Test]
    public function onlyThePackagesNeededAreFetchedAndRemovedAfterwards(): void
    {
        $this->requireComposer();
        mkdir($this->dir . '/vendor/symfony/other', 0o777, true);
        mkdir($this->dir . '/packages/stimulus/assets', 0o777, true);
        file_put_contents($this->dir . '/packages/stimulus/assets/package.json', '{"name":"@symfony/stimulus"}');
        $this->packageJson([
            '@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets',
            '@symfony/stimulus' => 'file:vendor/symfony/stimulus-bundle/assets',
        ]);
        $this->lock(
            ['symfony/ux-turbo', 'symfony/stimulus-bundle', 'symfony/http-kernel'],
            [
                'symfony/ux-turbo'        => [
                    'type' => 'zip',
                    'url'  => $this->zip('ux-turbo', ['assets/package.json' => '{"name":"@symfony/ux-turbo"}']),
                ],
                'symfony/stimulus-bundle' => ['type' => 'path', 'url' => 'packages/stimulus'],
                // Never downloaded: neither needed nor required by the packages that are.
                'symfony/http-kernel'     => ['type' => 'zip', 'url' => 'https://invalid.example/kernel.zip'],
            ],
        );

        $sources = new ComposerVendorSources($this->runner(), $this->dir);
        $created = $sources->provide();

        $this->assertSame(
            [$this->dir . '/vendor/symfony/ux-turbo', $this->dir . '/vendor/symfony/stimulus-bundle'],
            $created,
        );
        $this->assertStringContainsString(
            '@symfony/ux-turbo',
            (string) file_get_contents($this->dir . '/vendor/symfony/ux-turbo/assets/package.json'),
        );
        $this->assertFileExists($this->dir . '/vendor/symfony/stimulus-bundle/assets/package.json');
        $this->assertDirectoryDoesNotExist($this->dir . '/vendor/symfony/http-kernel');
        $this->assertDirectoryDoesNotExist($this->dir . '/vendor/composer', 'the install state is never written');

        $sources->remove($created);

        $this->assertDirectoryDoesNotExist($this->dir . '/vendor/symfony/ux-turbo');
        $this->assertDirectoryDoesNotExist($this->dir . '/vendor/symfony/stimulus-bundle');
        $this->assertDirectoryExists($this->dir . '/vendor/symfony/other', 'what was there is kept');
        $this->assertFileExists(
            $this->dir . '/packages/stimulus/assets/package.json',
            'the source of a path dist is not removed through a symlink',
        );
    }

    #[Test]
    public function aVendorDirectoryThatDidNotExistIsRemovedAsAWhole(): void
    {
        $this->requireComposer();
        $this->packageJson(['@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets']);
        $this->lock(
            ['symfony/ux-turbo'],
            ['symfony/ux-turbo' => ['type' => 'zip', 'url' => $this->zip('ux-turbo', ['assets/index.js' => ''])]],
        );

        $sources = new ComposerVendorSources($this->runner(), $this->dir);
        $created = $sources->provide();

        $this->assertSame([$this->dir . '/vendor'], $created);
        $this->assertFileExists($this->dir . '/vendor/symfony/ux-turbo/assets/index.js');

        $sources->remove($created);

        $this->assertDirectoryDoesNotExist($this->dir . '/vendor');
    }

    #[Test]
    public function aDistThatCannotBeFetchedStopsTheRunWithComposerOutput(): void
    {
        $this->requireComposer();
        $this->packageJson(['@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets']);
        $this->lock(
            ['symfony/ux-turbo'],
            ['symfony/ux-turbo' => ['type' => 'zip', 'url' => 'file://' . $this->dir . '/nowhere.zip']],
        );

        try {
            new ComposerVendorSources($this->runner(), $this->dir)->provide();
            self::fail('A missing dist must stop the run.');
        } catch (ToolingException $toolingException) {
            $this->assertStringContainsString(
                'Composer could not fetch symfony/ux-turbo',
                $toolingException->getMessage(),
            );
        }

        $this->assertDirectoryDoesNotExist($this->dir . '/vendor');
    }

    #[Test]
    public function aCopyThatFailsHalfwayLeavesNothingBehind(): void
    {
        $this->requireComposer();
        if (0 === posix_geteuid()) {
            self::markTestSkipped('Read-only directories are not enforced for root.');
        }

        mkdir($this->dir . '/vendor/zeta', 0o777, true);
        chmod($this->dir . '/vendor/zeta', 0o555);
        $this->packageJson([
            '@symfony/ux-turbo' => 'file:vendor/symfony/ux-turbo/assets',
            '@zeta/ui'          => 'file:vendor/zeta/ui/assets',
        ]);
        $this->lock(
            ['symfony/ux-turbo', 'zeta/ui'],
            [
                'symfony/ux-turbo' => ['type' => 'zip', 'url' => $this->zip('ux-turbo', ['assets/index.js' => ''])],
                'zeta/ui'          => ['type' => 'zip', 'url' => $this->zip('ui', ['assets/index.js' => ''])],
            ],
        );

        try {
            new ComposerVendorSources($this->runner(), $this->dir)->provide();
            self::fail('A copy into a read-only directory must fail.');
        } catch (ToolingException) {
            $this->assertDirectoryDoesNotExist($this->dir . '/vendor/symfony');
        } finally {
            chmod($this->dir . '/vendor/zeta', 0o755);
        }
    }

    private function requireComposer(): void
    {
        if (!new ProcessCommandRunner(30.0)->run(['composer', '--version'], sys_get_temp_dir())->isSuccessful()) {
            self::markTestSkipped('Composer is not available.');
        }
    }

    private function runner(): CommandRunner
    {
        return new readonly class ($this->home) implements CommandRunner {
            public function __construct(private string $home)
            {
            }

            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return new ProcessCommandRunner(120.0)->run($command, $cwd, [
                    ...$env,
                    'COMPOSER_HOME'      => $this->home,
                    'COMPOSER_CACHE_DIR' => $this->home . '/cache',
                ]);
            }
        };
    }

    /**
     * @param array<string, string> $dependencies
     */
    private function packageJson(array $dependencies): void
    {
        file_put_contents(
            $this->dir . '/package.json',
            json_encode(['dependencies' => $dependencies], \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param list<string>                                    $names
     * @param array<string, array{type: string, url: string}> $dists
     */
    private function lock(array $names, array $dists = []): void
    {
        $packages = [];

        foreach ($names as $name) {
            $packages[] = [
                'name'    => $name,
                'version' => 'v2.36.3',
                'dist'    => $dists[$name] ?? ['type' => 'zip', 'url' => 'https://invalid.example/x.zip'],
                // Not installable here: the throwaway lockfile must drop it.
                'require' => ['php' => '>=99.0', 'symfony/http-kernel' => '^9.0'],
                'type'    => 'symfony-bundle',
            ];
        }

        file_put_contents(
            $this->dir . '/composer.lock',
            json_encode(
                ['packages' => \array_slice($packages, 0, 1), 'packages-dev' => \array_slice($packages, 1)],
                \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            ),
        );
    }

    /**
     * @param array<string, string> $files
     */
    private function zip(string $name, array $files): string
    {
        if (!class_exists(\ZipArchive::class)) {
            self::markTestSkipped('ext-zip is not available.');
        }

        $path = $this->dir . '/' . $name . '.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);

        foreach ($files as $file => $content) {
            // GitHub dists hold one top directory, which Composer strips.
            $zip->addFromString($name . '-abc123/' . $file, $content);
        }

        $zip->close();

        return 'file://' . $path;
    }
}
