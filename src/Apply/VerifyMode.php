<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * When Trivy confirms a fix. The lockfile is always read back after an attempt: it is instant, and Trivy only reads it.
 */
enum VerifyMode: string
{
    /** Trivy runs after every attempt that the lockfile accepts: the slowest, the most certain. */
    case Each = 'each';

    /** Trivy runs once, when every package has been handled; a fix it still reports is flagged for a manual check. */
    case Final = 'final';

    /** Trivy does not run again: only the lockfile tells whether a fix worked. */
    case None = 'none';
}
