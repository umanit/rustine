<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;

/**
 * Fix ladder for Yarn Classic (v1), cheapest and cleanest first.
 */
final readonly class YarnClassicStrategy implements FixStrategy
{
    /** Classic has no environment switch for scripts: the flag goes on each command. */
    private const FLAGS = ['--non-interactive', '--ignore-scripts'];

    public function __construct(
        private YarnRuntime $runtime = new YarnRuntime(),
        private YarnSourceGuard $sourceGuard = new YarnSourceGuard(),
    )
    {
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Yarn;
    }

    public function files(): array
    {
        return ['package.json', 'yarn.lock'];
    }

    public function unsupportedReason(PackageFix $fix, string $projectDir): ?string
    {
        return $this->sourceGuard->reason($fix, $projectDir);
    }

    public function diagnosis(PackageFix $fix): ?array
    {
        return null;
    }

    public function attempts(PackageFix $fix, string $projectDir = ''): array
    {
        if (null === $fix->targetVersion) {
            return [];
        }

        $name = $fix->packageName;
        $range = '^' . $fix->targetVersion;

        // `yarn upgrade <pkg>` skips transitive occurrences: drop the lockfile entries and let Yarn resolve again.
        $attempts = [new Attempt(AttemptKind::LockfileRefresh, [$this->runtime->yarn('install', ...self::FLAGS)], null, $name)];

        $attempts[] = DependencyKind::Direct === $fix->dependencyKind
            // `yarn upgrade pkg@range` would also re-sort `package.json`: edit the range in place instead.
            ? new Attempt(AttemptKind::DirectBump, [$this->runtime->yarn('install', ...self::FLAGS)], null, null, [$name, $range])
            : new Attempt(AttemptKind::Resolution, [$this->runtime->yarn('install', ...self::FLAGS)], ['**/' . $name, $range]);

        return $attempts;
    }

    public function mutatingEnv(): array
    {
        return $this->runtime->env();
    }

    public function checkCommand(): array
    {
        return $this->runtime->yarn('install', '--frozen-lockfile', ...self::FLAGS);
    }

    public function checkEnv(): array
    {
        return $this->runtime->env();
    }
}
