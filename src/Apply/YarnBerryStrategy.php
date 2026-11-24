<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;

/**
 * Fix ladder for Yarn Berry (v2+), cheapest and cleanest first.
 */
final readonly class YarnBerryStrategy implements FixStrategy
{
    /**
     * @return list<Attempt>
     */
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

        // `-R` also updates transitive occurrences, within the ranges already declared.
        $attempts = [new Attempt(AttemptKind::LockfileRefresh, [$this->runtime->yarn('up', '-R', $name)])];

        $attempts[] = DependencyKind::Direct === $fix->dependencyKind
            ? new Attempt(AttemptKind::DirectBump, [$this->runtime->yarn('up', $name . '@' . $range)])
            : new Attempt(AttemptKind::Resolution, [$this->runtime->yarn('install')], [$name, $range]);

        return $attempts;
    }

    /**
     * Yarn would otherwise refuse to modify the lockfile on CI, and lifecycle scripts have no business running
     * while the bot resolves dependencies.
     */
    public function mutatingEnv(): array
    {
        return [...$this->runtime->env(), 'YARN_ENABLE_IMMUTABLE_INSTALLS' => 'false', 'YARN_ENABLE_SCRIPTS' => 'false'];
    }

    public function checkCommand(): array
    {
        return $this->runtime->yarn('install', '--immutable');
    }

    public function checkEnv(): array
    {
        return [...$this->runtime->env(), 'YARN_ENABLE_SCRIPTS' => 'false'];
    }
}
