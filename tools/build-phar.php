<?php

declare(strict_types=1);

/**
 * Builds build/rustine.phar.
 *
 *   php -d phar.readonly=0 tools/build-phar.php [--version=1.2.3] [--output=build/rustine.phar]
 *
 * The PHAR is built from a clean copy of the sources with production dependencies only (`composer install --no-dev`),
 * so the development vendor directory is never touched. It embeds `resources/translations/`, and is smoke-tested
 * (version, locales, dry-run preview) before the script succeeds.
 */

$root = dirname(__DIR__);
$options = getopt('', ['version::', 'output::']);
$version = is_string($options['version'] ?? null) ? $options['version'] : null;
$output = is_string($options['output'] ?? null) ? $options['output'] : $root . '/build/rustine.phar';
if (!str_starts_with($output, '/')) {
    $output = getcwd() . '/' . $output;
}

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "phar.readonly is on: run `php -d phar.readonly=0 tools/build-phar.php`.\n");
    exit(1);
}

function run(array $command, string $cwd): void
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot run ' . $command[0]);
    }
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    if (0 !== proc_close($process)) {
        throw new RuntimeException(sprintf("%s failed:\n%s", implode(' ', $command), $out));
    }
}

function removeTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        ) as $item
    ) {
        $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

function copyTree(string $from, string $to): void
{
    mkdir($to, 0777, true);
    foreach (
        new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        ) as $item
    ) {
        $target = $to . '/' . substr($item->getPathname(), strlen($from) + 1);
        $item->isDir() ? @mkdir($target, 0777, true) : copy($item->getPathname(), $target);
    }
}

$stage = $root . '/build/stage';
removeTree($stage);
mkdir($stage, 0777, true);

echo "Staging sources and production dependencies…\n";
copyTree($root . '/src', $stage . '/src');
copyTree($root . '/resources', $stage . '/resources');
mkdir($stage . '/bin');
copy($root . '/composer.json', $stage . '/composer.json');
copy($root . '/composer.lock', $stage . '/composer.lock');
run(
    [
        'composer',
        'install',
        '--no-dev',
        '--no-interaction',
        '--no-progress',
        '--no-scripts',
        '--optimize-autoloader',
        '--classmap-authoritative',
    ],
    $stage,
);

// The entry point inside the PHAR: no shebang (the stub carries it), the autoloader is always the embedded one.
file_put_contents(
    $stage . '/bin/rustine',
    <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

exit((new Rustine\Application())->run());
PHP,
);

if (null !== $version) {
    $file = $stage . '/src/Application.php';
    $patched = preg_replace(
        "/(public const VERSION = ')[^']*(';)/",
        '${1}' . $version . '${2}',
        (string) file_get_contents($file),
        1,
        $count,
    );
    if (1 !== $count || null === $patched) {
        throw new RuntimeException('Cannot set the version in Application.php.');
    }
    file_put_contents($file, $patched);
}

echo "Building the PHAR…\n";
@mkdir(dirname($output), 0777, true);
@unlink($output);

$phar = new Phar($output, 0, 'rustine.phar');
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->startBuffering();

$skippedDirectories = ['tests', 'Tests', 'test', 'Test', 'docs', 'doc', 'examples', '.git', '.github'];
$skippedFiles = '/^(README|CHANGELOG|UPGRADE|CONTRIBUTING|SECURITY)(\.[a-z]+)?$|^phpunit\.xml(\.dist)?$|^phpstan(\.dist)?\.neon$|^\.|^composer\.(json|lock)$/i';
$count = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($stage, FilesystemIterator::SKIP_DOTS),
        static function (SplFileInfo $file) use ($skippedDirectories, $skippedFiles, $stage): bool {
            if ($file->isDir()) {
                return !in_array($file->getFilename(), $skippedDirectories, true) || !str_contains(
                        $file->getPathname(),
                        '/vendor/',
                    );
            }

            // Package manifests of dependencies are useless at runtime; the project's own composer.json is not embedded.
            return 1 !== preg_match($skippedFiles, $file->getFilename()) || str_ends_with(
                    $file->getPathname(),
                    '/vendor/composer/installed.json',
                );
        },
    ),
);
foreach ($iterator as $file) {
    /** @var SplFileInfo $file */
    $phar->addFile($file->getPathname(), substr($file->getPathname(), strlen($stage) + 1));
    ++$count;
}

$phar->setStub(
    <<<'STUB'
#!/usr/bin/env php
<?php
Phar::mapPhar('rustine.phar');
require 'phar://rustine.phar/bin/rustine';
__HALT_COMPILER();
STUB. "\n",
);
$phar->stopBuffering();
unset($phar);
chmod($output, 0755);
removeTree($stage);

printf("%d files, %.1f MB: %s\n", $count, filesize($output) / 1048576, $output);

echo "Smoke test…\n";
$php = PHP_BINARY;
$check = static function (array $arguments, string $expected) use ($php, $output): void {
    $process = proc_open([$php, $output, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    $code = proc_close($process);
    if (0 !== $code || !str_contains($out, $expected)) {
        fwrite(STDERR, sprintf("Smoke test failed: %s (exit %d)\n%s\n", implode(' ', $arguments), $code, $out));
        exit(1);
    }
};
$check(['--version'], 'Rustine');
$check(['list'], 'apply');
foreach (
    [
        'en' => 'Security: fix Yarn dependencies',
        'fr' => 'Sécurité : corriger les dépendances Yarn',
    ] as $locale => $title
) {
    $check(
        [
            'run',
            '--dry-run',
            '--group=others',
            '--locale=' . $locale,
            '--report=' . $root . '/tests/fixtures/trivy/yarn-report.json',
        ],
        $title,
    );
}
echo "OK\n";
