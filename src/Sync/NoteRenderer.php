<?php

declare(strict_types=1);

namespace Rustine\Sync;

use Rustine\I18n\TranslatorFactory;
use Rustine\Report\MergeRequestContent;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Localized notes Rustine leaves on merge requests. Each carries an invisible marker, so that a remark that was
 * already posted is not repeated at every run.
 */
final readonly class NoteRenderer
{
    public const string CLOSED_MARKER = '<!-- rustine:note:closed -->';

    public const string FOREIGN_MARKER = '<!-- rustine:note:foreign -->';

    public function __construct(private TranslatorInterface $translator)
    {
    }

    public function updated(MergeRequestContent $content): string
    {
        return $this->translator->trans(
            'note.updated',
            ['packages' => $content->fixedPackages],
            TranslatorFactory::DOMAIN,
        );
    }

    public function closed(): string
    {
        return $this->translator->trans('note.closed', [], TranslatorFactory::DOMAIN) . "\n\n" . self::CLOSED_MARKER;
    }

    public function foreign(string $branch): string
    {
        return $this->translator->trans('note.foreign', ['branch' => '`' . $branch . '`'], TranslatorFactory::DOMAIN)
            . "\n\n" . self::FOREIGN_MARKER;
    }
}
