<?php

declare(strict_types=1);

namespace Rustine\I18n;

use Symfony\Component\Translation\Exception\InvalidArgumentException;
use Symfony\Component\Translation\Loader\YamlFileLoader;
use Symfony\Component\Translation\Translator;

/**
 * Builds the Symfony translator for the texts Rustine writes in merge requests and in commits.
 *
 * Catalogs live in `resources/translations/rustine+intl-icu.<locale>.yaml` (ICU message format, `{name}` arguments);
 * a missing key falls back to English. Callers use the `rustine` domain.
 */
final readonly class TranslatorFactory
{
    public const string DOMAIN = 'rustine';

    public const string FALLBACK_LOCALE = 'en';

    public function __construct(private string $directory = __DIR__ . '/../../resources/translations')
    {
    }

    /**
     * @throws InvalidArgumentException for a malformed locale
     */
    public function create(string $locale = self::FALLBACK_LOCALE): Translator
    {
        $translator = new Translator($locale);
        $translator->setFallbackLocales([self::FALLBACK_LOCALE]);
        $translator->addLoader('yaml', new YamlFileLoader());

        foreach ($this->availableLocales() as $available) {
            $translator->addResource('yaml', $this->file($available), $available, self::DOMAIN . '+intl-icu');
        }

        return $translator;
    }

    /**
     * Locales for which a catalog exists.
     *
     * @return list<string>
     */
    public function availableLocales(): array
    {
        // Not glob(): it does not work on phar:// paths, where the catalogs live in the distributed PHAR.
        $locales = [];
        if (!is_dir($this->directory)) {
            return [];
        }

        foreach (new \DirectoryIterator($this->directory) as $file) {
            if (
                $file->isFile()
                && 1 === preg_match(
                    sprintf('/^%s\+intl-icu\.([A-Za-z_-]+)\.yaml$/', preg_quote(self::DOMAIN, '/')),
                    $file->getFilename(),
                    $m,
                )
            ) {
                $locales[] = $m[1];
            }
        }

        sort($locales);

        return $locales;
    }

    private function file(string $locale): string
    {
        return sprintf('%s/%s+intl-icu.%s.yaml', $this->directory, self::DOMAIN, $locale);
    }
}
