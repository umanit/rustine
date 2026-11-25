<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\YarnLocalSources;

final class YarnLocalSourcesTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-local-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (['vendor/ui/assets', 'vendor/ui', 'vendor', 'lib'] as $path) {
            is_dir($this->dir . '/' . $path) ? @rmdir($this->dir . '/' . $path) : @unlink($this->dir . '/' . $path);
        }
        @unlink($this->dir . '/package.json');
        @rmdir($this->dir);
    }

    #[Test]
    public function aLocalSourceWhoseTargetIsMissingIsReported(): void
    {
        $this->packageJson('{"dependencies":{"@symfony/ux":"file:vendor/ui/assets","lodash":"^4.17.0"},"devDependencies":{"tool":"link:../tool"}}');

        self::assertSame(['@symfony/ux (file:vendor/ui/assets)', 'tool (link:../tool)'], (new YarnLocalSources())->missing($this->dir));
    }

    #[Test]
    public function aLocalSourceThatExistsIsFine(): void
    {
        mkdir($this->dir . '/vendor/ui/assets', 0o777, true);
        $this->packageJson('{"dependencies":{"@symfony/ux":"file:vendor/ui/assets","other":"portal:vendor/ui/assets#hash"}}');

        self::assertSame([], (new YarnLocalSources())->missing($this->dir));
    }

    #[Test]
    public function registryRangesWorkspacesAndAMissingPackageJsonAreNotLocalSources(): void
    {
        $this->packageJson('{"dependencies":{"a":"^1.0.0","b":"workspace:*","c":"npm:other@^2","d":"github:user/repo","e":"https://example.com/e.tgz"}}');

        self::assertSame([], (new YarnLocalSources())->missing($this->dir));
        self::assertSame([], (new YarnLocalSources())->missing($this->dir . '/nowhere'));
    }

    private function packageJson(string $json): void
    {
        file_put_contents($this->dir . '/package.json', $json);
    }
}
