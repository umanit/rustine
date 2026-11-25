<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function equivalentKeys(): iterable
    {
        yield 'glob form' => ['**/minimist'];
        yield 'bare form' => ['minimist'];
    }

    #[Test]
    public function itCreatesTheResolutionsBlockKeepingTwoSpaceIndentAndFinalNewline(): void
    {
        file_put_contents($this->file, "{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"x\"\n  }\n}\n");

        new PackageJsonEditor()->setResolution($this->file, '@scope/pkg', '^1.2.3');

        $this->assertSame(
            "{\n  \"name\": \"app\",\n  \"scripts\": {\n    \"build\": \"x\"\n  },\n  \"resolutions\": {\n"
            . "    \"@scope/pkg\": \"^1.2.3\"\n  }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itUpdatesAnExistingResolutionInsteadOfAddingASecondOne(): void
    {
        file_put_contents(
            $this->file,
            "{\n    \"resolutions\": {\n        \"foo\": \"^1.0.0\",\n        \"bar\": \"^2.0.0\"\n    }\n}\n",
        );

        new PackageJsonEditor()->setResolution($this->file, 'foo', '^1.5.0');

        $this->assertSame(
            "{\n    \"resolutions\": {\n        \"foo\": \"^1.5.0\",\n        \"bar\": \"^2.0.0\"\n    }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itKeepsTabsEmptyObjectsAndTheAbsenceOfFinalNewline(): void
    {
        file_put_contents($this->file, "{\n\t\"dependencies\": {},\n\t\"name\": \"é/x\"\n}");

        new PackageJsonEditor()->setResolution($this->file, 'foo', '^1.0.0');

        $this->assertSame(
            "{\n\t\"dependencies\": {},\n\t\"name\": \"é/x\",\n\t\"resolutions\": {\n\t\t\"foo\": \"^1.0.0\"\n\t}\n}",
            file_get_contents($this->file),
        );
    }

    #[Test]
    #[DataProvider('equivalentKeys')]
    public function itReusesTheEquivalentExistingKeyOfAGlobResolution(string $existing): void
    {
        file_put_contents($this->file, sprintf("{\n  \"resolutions\": {\n    \"%s\": \"^0.1.0\"\n  }\n}\n", $existing));

        new PackageJsonEditor()->setResolution($this->file, '**/minimist', '^0.2.4');

        $this->assertSame(
            sprintf("{\n  \"resolutions\": {\n    \"%s\": \"^0.2.4\"\n  }\n}\n", $existing),
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function aScopedOrParentSpecificKeyIsNotMistakenForTheGlobalOne(): void
    {
        file_put_contents($this->file, "{\n  \"resolutions\": {\n    \"mkdirp/minimist\": \"^0.1.0\"\n  }\n}\n");

        new PackageJsonEditor()->setResolution($this->file, '**/minimist', '^0.2.4');

        $this->assertStringContainsString('"mkdirp/minimist": "^0.1.0"', (string) file_get_contents($this->file));
        $this->assertStringContainsString('"**/minimist": "^0.2.4"', (string) file_get_contents($this->file));
    }

    #[Test]
    public function itChangesADependencyRangeInPlaceWithoutReorderingKeys(): void
    {
        file_put_contents(
            $this->file,
            "{\n  \"dependencies\": {\n    \"zlib\": \"1.0.0\",\n    \"ckeditor4\": \"4.22.1\",\n"
            . "    \"app\": \"2.0.0\"\n  }\n}\n",
        );

        new PackageJsonEditor()->setDependencyRange($this->file, 'ckeditor4', '^4.25.0');

        $this->assertSame(
            "{\n  \"dependencies\": {\n    \"zlib\": \"1.0.0\",\n    \"ckeditor4\": \"^4.25.0\",\n"
            . "    \"app\": \"2.0.0\"\n  }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function itRejectsInvalidJson(): void
    {
        file_put_contents($this->file, '{nope');

        $this->expectException(\RuntimeException::class);

        new PackageJsonEditor()->setResolution($this->file, 'foo', '^1.0.0');
    }

    #[Test]
    public function theRestOfTheFileIsLeftByteForByte(): void
    {
        $original = "{\n  \"name\": \"app\",\n  \"files\": [\"dist\", \"src\"],\n  \"browserslist\": [ \"> 1%\","
            . " \"last 2 versions\" ],\n  \"dependencies\": {\n    \"foo\" :   \"1.0.0\",\n    \"bar\":"
            . " \"^2.0.0\"\n  }\n}\n";
        file_put_contents($this->file, $original);

        new PackageJsonEditor()->setDependencyRange($this->file, 'foo', '^1.5.0');
        new PackageJsonEditor()->setResolution($this->file, '**/baz', '^3.0.0');

        $this->assertSame(
            "{\n  \"name\": \"app\",\n  \"files\": [\"dist\", \"src\"],\n  \"browserslist\": [ \"> 1%\","
            . " \"last 2 versions\" ],\n  \"dependencies\": {\n    \"foo\" :   \"^1.5.0\",\n    \"bar\": \"^2.0.0\"\n"
            . "  },\n  \"resolutions\": {\n    \"**/baz\": \"^3.0.0\"\n  }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function aNewResolutionIsAppendedToTheExistingBlockInItsStyle(): void
    {
        file_put_contents(
            $this->file,
            "{\r\n\t\"resolutions\": {\r\n\t\t\"a\": \"^1.0.0\"\r\n\t},\r\n\t\"files\": [\"x\"]\r\n}\r\n",
        );

        new PackageJsonEditor()->setResolution($this->file, 'b', '^2.0.0');

        $this->assertSame(
            "{\r\n\t\"resolutions\": {\r\n\t\t\"a\": \"^1.0.0\",\r\n\t\t\"b\": \"^2.0.0\"\r\n\t},\r\n\t"
            . "\"files\": [\"x\"]\r\n}\r\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function aKeyContainingBracesAndColonsDoesNotConfuseTheEdit(): void
    {
        file_put_contents(
            $this->file,
            "{\n  \"scripts\": {\"a:b\": \"echo }\\\" {\"},\n  \"dependencies\": {\n    \"foo\": \"1.0.0\"\n  }\n}\n",
        );

        new PackageJsonEditor()->setDependencyRange($this->file, 'foo', '^1.1.0');

        $this->assertSame(
            "{\n  \"scripts\": {\"a:b\": \"echo }\\\" {\"},\n  \"dependencies\": {\n    \"foo\": \"^1.1.0\"\n  }\n}\n",
            file_get_contents($this->file),
        );
    }

    #[Test]
    public function anInlineObjectFallsBackToReEncoding(): void
    {
        file_put_contents($this->file, "{\"resolutions\": {\"a\": \"1.0.0\"}}\n");

        new PackageJsonEditor()->setResolution($this->file, 'b', '^2.0.0');

        $this->assertSame(
            '{"resolutions":{"a":"1.0.0","b":"^2.0.0"}}',
            json_encode(json_decode((string) file_get_contents($this->file))),
        );
    }
}
