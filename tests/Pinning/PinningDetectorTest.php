<?php

declare(strict_types=1);

namespace Rustine\Tests\Pinning;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Pinning\PinningDetector;
use Rustine\Pinning\PinningException;
use Rustine\Pinning\PinningSource;
use Rustine\Pinning\YarnFlavor;

final class PinningDetectorTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/pinning-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->dir);
    }

    #[Test]
    public function itReadsMiseTomlAlone(): void
    {
        $this->write('mise.toml', "[tools]\nnode = \"20.11.0\"\nyarn = \"1.22.19\"\nphp = \"8.4\"\n");

        $pinning = (new PinningDetector())->detect($this->dir);

        self::assertSame(PinningSource::Mise, $pinning->source);
        self::assertSame(['node' => '20.11.0', 'yarn' => '1.22.19', 'php' => '8.4'], $pinning->tools);
        self::assertSame('mise.toml', $pinning->file);
        self::assertSame(YarnFlavor::Classic, $pinning->yarnFlavor());
    }

    #[Test]
    public function itAcceptsMiseTableAndArrayForms(): void
    {
        $this->write(
            '.mise.toml',
            "[tools]\nnode = { version = \"22\", postinstall = \"x\" }\nyarn = [\"4.5.0\", \"1.22.0\"]\n",
        );

        $pinning = (new PinningDetector())->detect($this->dir);

        self::assertSame(['node' => '22', 'yarn' => '4.5.0'], $pinning->tools);
        self::assertSame(YarnFlavor::Berry, $pinning->yarnFlavor());
    }

    #[Test]
    public function itReadsVoltaAlone(): void
    {
        $this->write('package.json', '{"name":"x","volta":{"node":"20.11.0","yarn":"1.22.19"}}');

        $pinning = (new PinningDetector())->detect($this->dir);

        self::assertSame(PinningSource::Volta, $pinning->source);
        self::assertSame(['node' => '20.11.0', 'yarn' => '1.22.19'], $pinning->tools);
        self::assertSame('package.json', $pinning->file);
    }

    #[Test]
    public function miseTomlWinsOverVolta(): void
    {
        $this->write('mise.toml', "[tools]\nnode = \"22.1.0\"\n");
        $this->write('package.json', '{"volta":{"node":"18.0.0","yarn":"1.22.19"}}');

        $pinning = (new PinningDetector())->detect($this->dir);

        self::assertSame(PinningSource::Mise, $pinning->source);
        self::assertSame(['node' => '22.1.0'], $pinning->tools, 'No merge with the volta key');
    }

    #[Test]
    public function itFailsWithoutAnyPinning(): void
    {
        $this->write('package.json', '{"name":"x"}');

        $this->expectException(PinningException::class);
        $this->expectExceptionMessage('No tool pinning found');

        (new PinningDetector())->detect($this->dir);
    }

    #[Test]
    public function itFailsInAnEmptyDirectory(): void
    {
        $this->expectException(PinningException::class);

        (new PinningDetector())->detect($this->dir);
    }

    #[Test]
    public function itFollowsVoltaExtendsAndLetsTheChildOverride(): void
    {
        $this->write('package.json', '{"volta":{"node":"20.11.0","yarn":"1.22.19"}}');
        $this->write('packages/app/package.json', '{"volta":{"extends":"../../package.json","node":"22.0.0"}}');

        $pinning = (new PinningDetector())->detect($this->dir . '/packages/app');

        self::assertSame(PinningSource::Volta, $pinning->source);
        self::assertSame(['node' => '22.0.0', 'yarn' => '1.22.19'], $pinning->tools);
    }

    #[Test]
    public function itFollowsChainedVoltaExtends(): void
    {
        $this->write('package.json', '{"volta":{"node":"20.0.0","yarn":"1.22.19"}}');
        $this->write('a/package.json', '{"volta":{"extends":"../package.json","yarn":"1.22.22"}}');
        $this->write('a/b/package.json', '{"volta":{"extends":"../package.json"}}');

        $pinning = (new PinningDetector())->detect($this->dir . '/a/b');

        self::assertSame(['node' => '20.0.0', 'yarn' => '1.22.22'], $pinning->tools);
    }

    #[Test]
    public function itDetectsVoltaExtendsCycles(): void
    {
        $this->write('a/package.json', '{"volta":{"extends":"../b/package.json"}}');
        $this->write('b/package.json', '{"volta":{"extends":"../a/package.json"}}');

        $this->expectException(PinningException::class);
        $this->expectExceptionMessage('Cycle');

        (new PinningDetector())->detect($this->dir . '/a');
    }

    #[Test]
    public function itFailsWhenVoltaExtendsTargetIsMissing(): void
    {
        $this->write('package.json', '{"volta":{"extends":"../nope/package.json"}}');

        $this->expectException(PinningException::class);
        $this->expectExceptionMessage('not found (volta.extends)');

        (new PinningDetector())->detect($this->dir);
    }

    #[Test]
    public function itRejectsInvalidMiseToml(): void
    {
        $this->write('mise.toml', "[tools\nnode = ");

        $this->expectException(PinningException::class);

        (new PinningDetector())->detect($this->dir);
    }

    #[Test]
    public function itDescribesThePinningForLogs(): void
    {
        $this->write('mise.toml', "[tools]\nnode = \"20\"\nyarn = \"4.1.0\"\n");

        self::assertSame('mise.toml (node@20, yarn@4.1.0)', (new PinningDetector())->detect($this->dir)->describe());
    }

    #[Test]
    public function yarnFlavorIsNullWhenYarnIsNotPinned(): void
    {
        $this->write('mise.toml', "[tools]\nnode = \"20\"\n");

        self::assertNull((new PinningDetector())->detect($this->dir)->yarnFlavor());
    }

    private function write(string $relative, string $content): void
    {
        $path = $this->dir . '/' . $relative;
        if (!is_dir(\dirname($path))) {
            mkdir(\dirname($path), 0o777, true);
        }
        file_put_contents($path, $content);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                $this->remove($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
