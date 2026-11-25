<?php

declare(strict_types=1);

namespace Rustine\Apply;

enum FixOutcomeStatus: string
{
    /** The CVEs are gone after the fix. */
    case Applied = 'applied';

    /** The CVEs had already disappeared (e.g. fixed by a previous package of the same run). */
    case AlreadyFixed = 'already_fixed';

    /** Every attempt failed or left the CVEs in place; the project is left untouched for this package. */
    case Failed = 'failed';

    /** The package is declared with a git/URL/alias/local source: bumping it would change where it comes from. */
    case UnsupportedSource = 'unsupported_source';

    /** Not automatically fixable (major bump, no fix…): reported only. */
    case Skipped = 'skipped';
}
