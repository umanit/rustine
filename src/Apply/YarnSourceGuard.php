<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;

/**
 * Yarn strategies do not bump dependencies declared with a git/URL/alias/local source.
 */
final readonly class YarnSourceGuard
{
    public function __construct(private PackageJsonEditor $editor = new PackageJsonEditor())
    {
    }

    public function reason(PackageFix $fix, string $projectDir): ?string
    {
        $spec = $this->editor->declaredSpec($projectDir . '/package.json', $fix->packageName);
        if (null === $spec || DependencySource::isRegistryRange($spec)) {
            return null;
        }

        return sprintf(
            'declared as "%s" in package.json, not an npm registry range: update this reference by hand to a version that fixes %s',
            $spec,
            implode(', ', $fix->fixedIds),
        );
    }
}
