<?php

declare(strict_types=1);

namespace Rustine\Plan;

enum PackageFixStatus: string
{
    /** A fixed version exists in the same major: the fix can be applied automatically. */
    case Fixable = 'fixable';
    /** Only a major bump fixes it: reported, never applied. */
    case MajorBumpRequired = 'major_bump_required';
    /** No fixed version published. */
    case NoFixAvailable = 'no_fix_available';
    /** The installed version is not comparable (e.g. `dev-main`). */
    case UnparsableVersion = 'unparsable_version';
}
