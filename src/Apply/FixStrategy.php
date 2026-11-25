<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;

interface FixStrategy
{
    public function ecosystem(): Ecosystem;

    /**
     * Files (relative to the project) restored when an attempt fails: the manifest and the lockfile.
     *
     * @return list<string>
     */
    public function files(): array;

    /**
     * Why the package must be fixed by hand even though a fixed version exists (e.g. a git source), or null.
     */
    public function unsupportedReason(PackageFix $fix, string $projectDir): ?string;

    /**
     * Ways of fixing the package, cheapest first. Empty when the package is not fixable.
     *
     * @return list<Attempt>
     */
    public function attempts(PackageFix $fix, string $projectDir): array;

    /**
     * Command whose output explains why nothing worked, run after the last attempt failed (read-only).
     *
     * @return list<string>|null
     */
    public function diagnosis(PackageFix $fix): ?array;

    /**
     * Environment of the commands that rewrite the lockfile.
     *
     * @return array<string, string>
     */
    public function mutatingEnv(): array;

    /**
     * Final check: the lockfile must be consistent with the manifest without being modified.
     *
     * @return list<string>
     */
    public function checkCommand(): array;

    /**
     * @return array<string, string>
     */
    public function checkEnv(): array;

    /**
     * True when the check is cheap and also reports problems unrelated to the fixes (e.g. `composer validate` on a
     * `composer.json` that already had findings): it is then run before the fixes too, and only what it reports
     * afterwards that it did not before fails the final check.
     */
    public function checkIsRelativeToBaseline(): bool;
}
