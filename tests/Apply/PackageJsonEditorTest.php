<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\PackageJsonEditor;

final class PackageJsonEditorTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'rustine-pkg-') ?: throw new \RuntimeException('tempnam failed');
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    #[Test]
    public function itCreatesTheResolutionsBlockKeepingTwoSpaceIndentAndFinalNewline(): void
    {
        file_put_contents($this->file, "{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"x\"\n  }\n}\n");

        (new PackageJsonEditor())->setResolution($this->file, '@scope/pkg', '^1.2.3');

        self::assertSame(
            "{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"x\"\n  },\n  \"resolutions\": {\n    \"@scope/pkg\": \"^1.2.3\"\n  }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itUpdatesAnExistingResolutionInsteadOfAddingASecondOne(): void
    {
        file_put_contents($this->file, "{\n    \"resolutions\": {\n        \"foo\": \"^1.0.0\",\n        \"bar\": \"^2.0.0\"\n    }\n}\n");

        (new PackageJsonEditor())->setResolution($this->file, 'foo', '^1.5.0');

        self::assertSame(
            "{\n    \"resolutions\": {\n        \"foo\": \"^1.5.0\",\n        \"bar\": \"^2.0.0\"\n    }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itKeepsTabsEmptyObjectsAndTheAbsenceOfFinalNewline(): void
    {
        file_put_contents($this->file, "{\n\t\"dependencies\": {},\n\t\"name\": \"é/x\"\n}");

        (new PackageJsonEditor())->setResolution($this->file, 'foo', '^1.0.0');

        self::assertSame(
            "{\n\t\"dependencies\": {},\n\t\"name\": \"é/x\",\n\t\"resolutions\": {\n\t\t\"foo\": \"^1.0.0\"\n\t}\n}",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        file_put_contents($this->file, '{nope');

        $this->expectException(\RuntimeException::class);

        (new PackageJsonEditor())->setResolution($this->file, 'foo', '^1.0.0');
    }
}
