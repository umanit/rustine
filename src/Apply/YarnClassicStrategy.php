<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFix;

/**
 * Fix ladder for Yarn Classic (v1), cheapest and cleanest first.
 */
final readonly class YarnClassicStrategy implements FixStrategy
{
    /** Classic has no environment switch for scripts: the flag goes on each command. */
    private const FLAGS = ['--non-interactive', '--ignore-scripts'];

    public function attempts(PackageFix $fix): array
    {
        if (null === $fix->targetVersion) {
            return [];
        }

        $name = $fix->packageName;
        $range = '^' . $fix->targetVersion;

        // `yarn upgrade <pkg>` skips transitive occurrences: drop the lockfile entries and let Yarn resolve again.
        $attempts = [new Attempt(AttemptKind::LockfileRefresh, [['yarn', 'install', ...self::FLAGS]], null, $name)];

        $attempts[] = DependencyKind::Direct === $fix->dependencyKind
            // `yarn upgrade pkg@range` would also re-sort `package.json`: edit the range in place instead.
            ? new Attempt(AttemptKind::DirectBump, [['yarn', 'install', ...self::FLAGS]], null, null, [$name, $range])
            : new Attempt(AttemptKind::Resolution, [['yarn', 'install', ...self::FLAGS]], ['**/' . $name, $range]);

        return $attempts;
    }

    public function mutatingEnv(): array
    {
        return [];
    }

    public function checkCommand(): array
    {
        return ['yarn', 'install', '--frozen-lockfile', ...self::FLAGS];
    }

    public function checkEnv(): array
    {
        return [];
    }
}
