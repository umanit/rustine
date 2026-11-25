<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply\Support;

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\SkippedWithMessageException;
use Rustine\Apply\ProcessCommandRunner;
use Symfony\Component\Process\Process;

/**
 * A npm registry on a local port, served by PHP's built-in web server from a temporary directory. It knows one package,
 * `lib`, in the versions 1.0.0, 1.0.5, 1.1.0 and 2.0.0 (the latest one). No network is involved.
 */
final class LocalNpmRegistry
{
    private ?Process $server = null;

    private int $port = 0;

    /**
     * @param string $root a directory that exists and is removed by the caller
     *
     * @throws SkippedWithMessageException when `tar` or a local port is missing
     */
    public function start(string $root): int
    {
        if (!new ProcessCommandRunner(30.0)->run(['tar', '--version'], sys_get_temp_dir())->isSuccessful()) {
            throw new SkippedWithMessageException('tar is not available.');
        }

        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        if (false === $socket) {
            throw new SkippedWithMessageException('No local port available: ' . $error);
        }
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        $registry = $root . '/registry';
        mkdir($registry . '/lib/-', 0777, true);
        $versions = [];

        foreach (['1.0.0', '1.0.5', '1.1.0', '2.0.0'] as $version) {
            $build = $root . '/build';
            mkdir($build . '/package', 0777, true);
            file_put_contents(
                $build . '/package/package.json',
                json_encode(['name' => 'lib', 'version' => $version], \JSON_THROW_ON_ERROR),
            );
            $tarball = $registry . '/lib/-/lib-' . $version . '.tgz';
            $made = new ProcessCommandRunner(30.0)->run(['tar', '-czf', $tarball, '-C', $build, 'package'], $root);
            Assert::assertTrue($made->isSuccessful(), $made->output);
            new ProcessCommandRunner(30.0)->run(['rm', '-rf', '--', $build], $root);
            $versions[$version] = [
                'name'    => 'lib',
                'version' => $version,
                'dist'    => [
                    'tarball' => sprintf('http://127.0.0.1:%d/lib/-/lib-%s.tgz', $port, $version),
                    'shasum'  => sha1_file($tarball),
                ],
            ];
        }

        file_put_contents(
            $registry . '/lib.json',
            json_encode(
                ['name' => 'lib', 'dist-tags' => ['latest' => '2.0.0'], 'versions' => $versions],
                \JSON_THROW_ON_ERROR,
            ),
        );
        file_put_contents(
            $root . '/router.php',
            <<<'PHP_WRAP'
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
        PHP_WRAP,
        );

        $this->server = new Process([\PHP_BINARY, '-S', '127.0.0.1:' . $port, $root . '/router.php'], $root);
        $this->server->start();

        for ($i = 0; $i < 50; ++$i) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if (false !== $connection) {
                fclose($connection);

                return $this->port = $port;
            }
            usleep(100000);
        }

        throw new SkippedWithMessageException('The test registry did not start.');
    }

    public function stop(): void
    {
        $this->server?->stop(1);
        $this->server = null;
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }
}
