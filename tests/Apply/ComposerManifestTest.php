<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ComposerManifest;

final class ComposerManifestTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'rustine-composer-') ?: throw new \RuntimeException('tempnam failed');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function itFindsRequirementsInBothBlocks(): void
    {
        file_put_contents($this->file, '{"require":{"php":"^8.2","twig/twig":"^3.4"},"require-dev":{"phpunit/phpunit":"^11"}}');
        $manifest = ComposerManifest::read($this->file);

        self::assertSame(['field' => 'require', 'constraint' => '^3.4'], $manifest->declaration('twig/twig'));
        self::assertSame(['field' => 'require-dev', 'constraint' => '^11'], $manifest->declaration('phpunit/phpunit'));
        self::assertNull($manifest->declaration('symfony/yaml'));
    }

    #[Test]
    public function aPackageInBothBlocksIsReportedAsRequire(): void
    {
        file_put_contents($this->file, '{"require":{"a/b":"^1.0"},"require-dev":{"a/b":"^1.0"}}');

        self::assertSame('require', ComposerManifest::read($this->file)->declaration('a/b')['field'] ?? null);
    }

    #[Test]
    public function aMissingOrBrokenFileDeclaresNothing(): void
    {
        self::assertNull(ComposerManifest::read('/nonexistent/composer.json')->declaration('a/b'));

        file_put_contents($this->file, '{nope');
        self::assertNull(ComposerManifest::read($this->file)->declaration('a/b'));
    }
}
