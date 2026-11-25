<?php

declare(strict_types=1);

namespace Rustine\Tests\I18n;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\I18n\TranslatorFactory;

/**
 * Guards the shipped catalogs: every language must stay complete and compatible with Symfony's ICU format.
 */
final class CatalogsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function locales(): iterable
    {
        foreach (new TranslatorFactory()->availableLocales() as $locale) {
            yield $locale => [$locale];
        }
    }

    #[Test]
    public function englishAndFrenchAreShipped(): void
    {
        $this->assertContains('en', new TranslatorFactory()->availableLocales());
        $this->assertContains('fr', new TranslatorFactory()->availableLocales());
    }

    #[Test]
    #[DataProvider('locales')]
    public function everyLocaleHasExactlyTheKeysOfEnglish(string $locale): void
    {
        $english = array_keys($this->messages('en'));
        $other = array_keys($this->messages($locale));

        $this->assertSame([], array_diff($english, $other), sprintf('Keys missing in "%s".', $locale));
        $this->assertSame([], array_diff($other, $english), sprintf('Keys of "%s" unknown in English.', $locale));
    }

    #[Test]
    #[DataProvider('locales')]
    public function everyLocaleKeepsThePlaceholdersOfEnglish(string $locale): void
    {
        $messages = $this->messages($locale);

        foreach ($this->messages('en') as $id => $english) {
            $expected = $this->placeholders($english);
            $actual = $this->placeholders($messages[$id] ?? '');

            $this->assertSame($expected, $actual, sprintf('Placeholders of "%s" differ in "%s".', $id, $locale));
        }
    }

    #[Test]
    #[DataProvider('locales')]
    public function messagesOnlyUseSimpleIcuArguments(string $locale): void
    {
        foreach ($this->messages($locale) as $id => $message) {
            $this->assertDoesNotMatchRegularExpression(
                '/\{[^{}]*,/',
                $message,
                sprintf('"%s" uses plural/select, which is not supported.', $id),
            );
            // In ICU an apostrophe right before a brace starts a quoted section.
            $this->assertDoesNotMatchRegularExpression(
                "/'[{}]/",
                $message,
                sprintf('"%s" has an apostrophe next to a brace.', $id),
            );
            $this->assertNotSame('', trim($message), sprintf('"%s" is empty.', $id));
        }
    }

    /**
     * Flattened messages of the `rustine` domain for a locale (without the English fallback).
     *
     * @return array<string, string>
     */
    private function messages(string $locale): array
    {
        /** @var array<string, string> $messages */
        $messages = new TranslatorFactory()->create($locale)->getCatalogue($locale)->all(TranslatorFactory::DOMAIN);

        return $messages;
    }

    /**
     * @return list<string>
     */
    private function placeholders(string $message): array
    {
        preg_match_all('/\{(\w+)\}/', $message, $matches);
        $names = array_unique($matches[1]);
        sort($names);

        return $names;
    }
}
