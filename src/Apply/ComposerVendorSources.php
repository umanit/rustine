<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Symfony\Component\Filesystem\Exception\IOExceptionInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Fetches, from `composer.lock`, only the Composer packages that `package.json` points into, so that Yarn can resolve
 * a `file:vendor/symfony/ux-turbo/assets` without a whole `composer install`.
 *
 * Composer still does the download (dist, cache, mirrors, credentials), in a throwaway project whose lockfile holds
 * nothing but these packages, at their locked version, without their requirements: Yarn only reads the files of the
 * package, never its PHP dependencies. Plugins and scripts are disabled, no autoloader is dumped, and only the
 * package directories are copied into the `vendor/` of the project: `vendor/composer/` is never touched.
 *
 * What is not under the vendor directory, or is not in `composer.lock`, is left alone: {@see FixApplier} then refuses
 * the project with an explicit error.
 */
final readonly class ComposerVendorSources
{
    /** What the throwaway lockfile drops from each package: the package is installed alone. */
    private const array DROPPED = ['require', 'require-dev', 'conflict', 'replace', 'provide', 'suggest'];

    /**
     * @param string $composerCommand command that runs Composer (see {@see ComposerStrategy::fromCommandLine()}), only
     *                                split when something has to be fetched
     */
    public function __construct(
        private CommandRunner $runner,
        private string $projectDir,
        private string $composerCommand = 'composer',
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Packages of `composer.lock` that hold a missing local source of `package.json`.
     *
     * @return list<string> package names, in the order of `package.json`
     */
    public function needed(): array
    {
        $vendor = $this->vendorDir();
        $locked = $this->lockedPackages();
        $names = [];

        foreach (new YarnLocalSources()->missingPaths($this->projectDir) as $path) {
            $name = $this->packageOf($path, $vendor);
            $package = null === $name ? null : ($locked[$name] ?? null);
            if (null !== $package && \is_string($package['name'] ?? null)) {
                $names[$package['name']] = true;
            }
        }

        return array_map(strval(...), array_keys($names));
    }

    /**
     * Fetches the {@see needed()} packages into the vendor directory of the project.
     *
     * @return list<string> the directories created, to give to {@see remove()}; empty when nothing was needed
     *
     * @throws ToolingException         when Composer is missing or cannot fetch the packages
     * @throws \InvalidArgumentException when the Composer command is empty
     */
    public function provide(): array
    {
        $names = $this->needed();
        if ([] === $names) {
            return [];
        }

        $composer = ComposerStrategy::fromCommandLine($this->composerCommand)->command();
        new ToolProbe($this->runner, $this->projectDir)->composer($composer);

        $tmp = sys_get_temp_dir() . '/rustine-vendor-' . bin2hex(random_bytes(6));

        try {
            $this->filesystem->mkdir($tmp, 0o700);
        } catch (IOExceptionInterface $ioException) {
            throw new ToolingException(
                sprintf('Cannot create the temporary directory "%s".', $tmp),
                0,
                $ioException,
            );
        }

        try {
            $this->writeProject($tmp, $names);
            $result = $this->runner->run(
                [
                    ...$composer,
                    'install',
                    '--no-interaction',
                    '--no-scripts',
                    '--no-plugins',
                    '--no-autoloader',
                    '--no-progress',
                    '--no-ansi',
                ],
                $tmp,
                [
                    'COMPOSER'                => 'composer.json',
                    'COMPOSER_VENDOR_DIR'     => $tmp . '/vendor',
                    'COMPOSER_BIN_DIR'        => $tmp . '/bin',
                    'COMPOSER_NO_INTERACTION' => '1',
                ],
            );
            if (!$result->isSuccessful()) {
                throw new ToolingException(
                    sprintf(
                        'Composer could not fetch %s, which package.json points into: `composer install` failed'
                        . ' (exit %d): %s',
                        implode(', ', $names),
                        $result->exitCode,
                        FixApplier::maskSecrets($this->tail($result->output)),
                    ),
                );
            }

            return $this->copyInto($tmp . '/vendor', $names);
        } finally {
            $this->filesystem->remove($tmp);
        }
    }

    /**
     * Removes what {@see provide()} created, deepest first.
     *
     * @param list<string> $created
     */
    public function remove(array $created): void
    {
        $this->filesystem->remove(array_reverse($created));
    }

    /**
     * @param list<string> $names
     */
    private function writeProject(string $tmp, array $names): void
    {
        $lock = $this->lockedPackages();
        $packages = [];

        foreach ($names as $name) {
            $package = $lock[strtolower($name)];

            foreach (self::DROPPED as $key) {
                unset($package[$key]);
            }

            // A `path` dist is relative to the project, not to the throwaway one.
            if (
                \is_array($package['dist'] ?? null)
                && 'path' === ($package['dist']['type'] ?? null)
                && \is_string($package['dist']['url'] ?? null)
                && !str_starts_with($package['dist']['url'], '/')
            ) {
                $package['dist']['url'] = $this->projectDir . '/' . $package['dist']['url'];
            }

            $packages[] = $package;
        }

        // The settings that change how a package is downloaded (domains, secure-http, cafile, preferred-install…),
        // never those that would load code or write elsewhere.
        $config = $this->composerJson()['config'] ?? [];
        $config = \is_array($config) ? $config : [];
        unset($config['vendor-dir'], $config['bin-dir'], $config['allow-plugins']);
        $config['allow-plugins'] = false;

        $this->writeJson($tmp . '/composer.json', ['name' => 'rustine/vendor-sources', 'config' => $config]);
        $this->writeJson(
            $tmp . '/composer.lock',
            [
                'content-hash'      => '',
                'packages'          => $packages,
                'packages-dev'      => [],
                'aliases'           => [],
                'minimum-stability' => 'dev',
                'stability-flags'   => new \stdClass(),
                'prefer-stable'     => false,
                'prefer-lowest'     => false,
                'platform'          => new \stdClass(),
                'platform-dev'      => new \stdClass(),
            ],
        );

        // Credentials of the project for private dists (the global ones and COMPOSER_AUTH apply anyway).
        if (is_file($this->projectDir . '/auth.json')) {
            $this->filesystem->copy($this->projectDir . '/auth.json', $tmp . '/auth.json');
        }
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     *
     * @throws ToolingException
     */
    private function copyInto(string $fetched, array $names): array
    {
        $vendor = $this->vendorDir();
        $created = [];

        foreach ($names as $name) {
            $source = $fetched . '/' . $name;
            $target = $vendor . '/' . $name;
            if (!is_dir($source)) {
                throw new ToolingException(sprintf('Composer did not install %s where expected.', $name));
            }

            if (file_exists($target)) {
                continue;
            }

            // Remember the first missing level only: removing it removes the rest.
            $levels = [$vendor, $vendor . '/' . strtok($name, '/'), $target];

            foreach ($levels as $level) {
                if (!file_exists($level)) {
                    $created[] = $level;

                    break;
                }
            }

            try {
                $this->filesystem->mirror($source, $target);
            } catch (IOExceptionInterface $ioException) {
                // The caller never receives $created: leave nothing half-copied behind.
                $this->remove($created);

                throw new ToolingException(
                    sprintf('Cannot copy "%s" to "%s": %s', $source, $target, $ioException->getMessage()),
                    0,
                    $ioException,
                );
            }
        }

        return $created;
    }

    /**
     * `vendor/<vendor>/<package>/…` → `<vendor>/<package>`, null for a path outside the vendor directory.
     */
    private function packageOf(string $path, string $vendor): ?string
    {
        $path = (string) preg_replace('#/(?:\./)+#', '/', $path);
        if (!str_starts_with($path, $vendor . '/') || str_contains($path, '/../')) {
            return null;
        }

        $segments = explode('/', substr($path, \strlen($vendor) + 1));

        return \count($segments) >= 2 && '' !== $segments[0] && '' !== $segments[1]
            ? strtolower($segments[0] . '/' . $segments[1])
            : null;
    }

    private function vendorDir(): string
    {
        $vendor = rtrim(ComposerManifest::read($this->projectDir . '/composer.json')->vendorDir(), '/');
        $vendor = str_starts_with($vendor, '/') ? $vendor : $this->projectDir . '/' . $vendor;

        return (string) preg_replace('#/(?:\./)+#', '/', $vendor);
    }

    /**
     * @return array<string, array<mixed>> lock entries by lowercase name, `packages` and `packages-dev`
     */
    private function lockedPackages(): array
    {
        $content = @file_get_contents($this->projectDir . '/composer.lock');
        $data = false === $content ? null : json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $packages = [];

        foreach (['packages', 'packages-dev'] as $field) {
            foreach (\is_array($data[$field] ?? null) ? $data[$field] : [] as $package) {
                if (\is_array($package) && \is_string($package['name'] ?? null)) {
                    $packages[strtolower($package['name'])] ??= $package;
                }
            }
        }

        return $packages;
    }

    /**
     * @return array<mixed>
     */
    private function composerJson(): array
    {
        $content = @file_get_contents($this->projectDir . '/composer.json');
        $data = false === $content ? null : json_decode($content, true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<mixed> $data
     */
    private function writeJson(string $path, array $data): void
    {
        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR);

        try {
            $this->filesystem->dumpFile($path, $json . "\n");
        } catch (IOExceptionInterface $ioException) {
            throw new ToolingException(sprintf('Cannot write "%s".', $path), 0, $ioException);
        }
    }

    private function tail(string $output): string
    {
        $lines = array_slice(preg_split('/\R/', trim($output)) ?: [], -15);

        return implode("\n", $lines);
    }
}
