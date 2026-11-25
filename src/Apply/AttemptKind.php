<?php

declare(strict_types=1);

namespace Rustine\Apply;

enum AttemptKind: string
{
    /** Re-resolve the package within the ranges already declared (no manifest change). */
    case LockfileRefresh = 'lockfile-refresh';

    /** Raise the range of a direct dependency in `package.json`. */
    case DirectBump = 'direct-bump';

    /** Pin a transitive dependency through `resolutions` (technical debt, flagged in the MR). */
    case Resolution = 'resolution';
}
