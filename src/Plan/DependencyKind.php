<?php

declare(strict_types=1);

namespace Rustine\Plan;

enum DependencyKind: string
{
    case Direct = 'direct';
    case Transitive = 'transitive';
    /** Trivy was not run with `--list-all-pkgs` or the package is missing from the list. */
    case Unknown = 'unknown';
}
