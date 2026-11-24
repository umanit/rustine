<?php

declare(strict_types=1);

namespace Rustine\I18n;

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
    public const DOMAIN = 'rustine';
    public const FALLBACK_LOCALE = 'en';

    public function __construct(private string $directory = __DIR__ . '/../../resources/translations')
    {
    }

    /**
     * @throws \Symfony\Component\Translation\Exception\InvalidArgumentException for a malformed locale
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
        $locales = [];
        foreach (glob(sprintf('%s/%s+intl-icu.*.yaml', $this->directory, self::DOMAIN)) ?: [] as $file) {
            if (1 === preg_match('/\.([A-Za-z_-]+)\.yaml$/', $file, $m)) {
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
