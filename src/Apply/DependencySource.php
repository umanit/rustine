<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Tells whether a `package.json` specification points at the npm registry.
 *
 * Git/URL/alias/local specifications are not bumped automatically: replacing them with a registry range would
 * silently change where the package comes from (e.g. a `full` preset published in a git releases repository).
 */
final class DependencySource
{
    public static function isRegistryRange(string $spec): bool
    {
        // Registry ranges and dist-tags only use these characters; `/`, `:`, `#` and `@` mark every other source.
        return 1 === preg_match('/^[\^~<>=*xX0-9a-zA-Z.\-+| ]+$/', $spec);
    }
}
