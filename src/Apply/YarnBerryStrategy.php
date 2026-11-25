<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;

/**
 * Fix ladder for Yarn Berry (v2+), cheapest and cleanest first.
 *
 * With `$lockfileOnly`, every attempt runs with `--mode=update-lockfile`: Yarn resolves and fetches what the lockfile
 * lacks but skips the link step, and `node_modules` stays as it is. Trivy only reads `yarn.lock`, so linking after every
 * attempt of every package is wasted time. It is not combinable with `--immutable`, hence the final check is a normal
 * install.
 */
final readonly class YarnBerryStrategy implements FixStrategy
{
    /** First Yarn major version `--mode=update-lockfile` was checked with (`install` and `up`). */
    public const LOCKFILE_ONLY_MIN_MAJOR = 4;

    public function __construct(
        private YarnRuntime $runtime = new YarnRuntime(),
        private YarnSourceGuard $sourceGuard = new YarnSourceGuard(),
        private bool $lockfileOnly = false,
    ) {
    }

    /**
     * @param string|null $yarnVersion version printed by `yarn --version`
     */
    public static function supportsLockfileOnly(?string $yarnVersion): bool
    {
        return null !== $yarnVersion
            && 1 === preg_match('/^v?(\d+)\./', $yarnVersion, $m)
            && (int) $m[1] >= self::LOCKFILE_ONLY_MIN_MAJOR;
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
        $attempts = [new Attempt(AttemptKind::LockfileRefresh, [$this->yarn('up', '-R', $name)])];

        $attempts[] = DependencyKind::Direct === $fix->dependencyKind
            ? new Attempt(AttemptKind::DirectBump, [$this->yarn('up', $name . '@' . $range)])
            : new Attempt(AttemptKind::Resolution, [$this->yarn('install')], [$name, $range]);

        return $attempts;
    }

    /**
     * @return non-empty-list<string>
     */
    private function yarn(string ...$arguments): array
    {
        return $this->runtime->yarn(...$arguments, ...($this->lockfileOnly ? ['--mode=update-lockfile'] : []));
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

    public function checkIsRelativeToBaseline(): bool
    {
        // A full install: too slow to run twice, and it only fails for a reason that comes from the lockfile.
        return false;
    }
}
