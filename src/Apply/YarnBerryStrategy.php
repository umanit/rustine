<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFix;

/**
 * Fix ladder for Yarn Berry (v2+), cheapest and cleanest first.
 */
final readonly class YarnBerryStrategy implements FixStrategy
{
    /**
     * @return list<Attempt>
     */
    public function attempts(PackageFix $fix): array
    {
        if (null === $fix->targetVersion) {
            return [];
        }

        $name = $fix->packageName;
        $range = '^' . $fix->targetVersion;

        // `-R` also updates transitive occurrences, within the ranges already declared.
        $attempts = [new Attempt(AttemptKind::LockfileRefresh, [['yarn', 'up', '-R', $name]])];

        $attempts[] = DependencyKind::Direct === $fix->dependencyKind
            ? new Attempt(AttemptKind::DirectBump, [['yarn', 'up', $name . '@' . $range]])
            : new Attempt(AttemptKind::Resolution, [['yarn', 'install']], [$name, $range]);

        return $attempts;
    }

    /**
     * Yarn would otherwise refuse to modify the lockfile on CI, and lifecycle scripts have no business running
     * while the bot resolves dependencies.
     */
    public function mutatingEnv(): array
    {
        return ['YARN_ENABLE_IMMUTABLE_INSTALLS' => 'false', 'YARN_ENABLE_SCRIPTS' => 'false'];
    }

    public function checkCommand(): array
    {
        return ['yarn', 'install', '--immutable'];
    }

    public function checkEnv(): array
    {
        return ['YARN_ENABLE_SCRIPTS' => 'false'];
    }
}
