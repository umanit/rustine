<?php

declare(strict_types=1);

namespace Rustine\Tests\I18n;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\I18n\TranslatorFactory;
use Symfony\Component\Translation\Exception\InvalidArgumentException;

final class TranslatorFactoryTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-i18n-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
        $this->catalog(
            'en',
            "greeting: \"Hello {name}\"\nonly_en: \"Only in English\"\nnested:\n  key: \"Nested {a} {b}\"\n",
        );
        $this->catalog('fr', "greeting: \"Bonjour {name}\"\n");
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir . '/*') ?: []);
        rmdir($this->dir);
    }

    #[Test]
    public function itReplacesNamedParametersAndFlattensNestedKeys(): void
    {
        $translator = $this->factory()->create('en');

        $this->assertSame('Hello Ada', $translator->trans('greeting', ['name' => 'Ada'], 'rustine'));
        $this->assertSame('Nested 1 x', $translator->trans('nested.key', ['a' => 1, 'b' => 'x'], 'rustine'));
    }

    #[Test]
    public function itUsesTheRequestedLocaleThenFallsBackToEnglishPerKey(): void
    {
        $translator = $this->factory()->create('fr');

        $this->assertSame('Bonjour Ada', $translator->trans('greeting', ['name' => 'Ada'], 'rustine'));
        $this->assertSame(
            'Only in English',
            $translator->trans('only_en', [], 'rustine'),
            'A missing key falls back to English.',
        );
        $this->assertSame(
            'Hello Ada',
            $translator->trans('greeting', ['name' => 'Ada'], 'rustine', 'en'),
            'The locale argument wins.',
        );
    }

    #[Test]
    public function aRegionalLocaleFallsBackToItsLanguage(): void
    {
        $this->assertSame(
            'Bonjour Ada',
            $this->factory()->create('fr_CA')->trans('greeting', ['name' => 'Ada'], 'rustine'),
        );
    }

    #[Test]
    public function anUnknownLocaleFallsBackToEnglishAndAnUnknownKeyReturnsItsId(): void
    {
        $translator = $this->factory()->create('de');

        $this->assertSame('Hello Ada', $translator->trans('greeting', ['name' => 'Ada'], 'rustine'));
        $this->assertSame('no.such.key', $translator->trans('no.such.key', [], 'rustine'));
    }

    #[Test]
    public function aMalformedLocaleIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory()->create('../../etc/passwd');
    }

    #[Test]
    public function itListsTheAvailableLocales(): void
    {
        $this->assertSame(['en', 'fr'], $this->factory()->availableLocales());
    }

    private function factory(): TranslatorFactory
    {
        return new TranslatorFactory($this->dir);
    }

    private function catalog(string $locale, string $yaml): void
    {
        file_put_contents(sprintf('%s/rustine+intl-icu.%s.yaml', $this->dir, $locale), $yaml);
    }
}
