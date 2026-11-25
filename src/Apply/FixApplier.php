<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\TrivyReport;
use Rustine\Version\Version;

/**
 * Applies the fixes of one plan, with the strategy of its ecosystem (Yarn Berry/Classic, Composer), package by package.
 *
 * After each attempt the lockfile is read back: a fix is kept only if the package is no longer locked at a vulnerable
 * version, otherwise the manifest and the lockfile are restored and the next attempt is tried. A package that cannot
 * be fixed never blocks the others. By default Trivy is run once, at the end, to confirm that the CVEs are gone: a scan
 * after every attempt would cost minutes per package (see {@see VerifyMode}).
 */
final class FixApplier
{
    public function __construct(
        private readonly CommandRunner $runner,
        private readonly WorkingTree $tree,
        private readonly VulnerabilityRescanner $rescanner,
        private readonly FixStrategy $strategy,
        private readonly PackageJsonEditor $editor,
        private readonly string $projectDir,
        private readonly VerifyMode $verify = VerifyMode::Final,
    ) {
    }

    /**
     * @throws UnsupportedProjectException when the project cannot be handled safely
     */
    public function apply(GroupPlan $plan, TrivyReport $initialReport): ApplyResult
    {
        $this->assertSupported($plan);

        $baseline = $this->tree->dirtyPaths();
        $baselineCheck = $this->strategy->checkIsRelativeToBaseline()
            ? $this->runner->run($this->strategy->checkCommand(), $this->projectDir, $this->strategy->checkEnv())
            : null;
        $lockfile = $this->projectDir . '/' . $plan->ecosystem->lockFile();
        $lockBefore = @file_get_contents($lockfile);
        $locked = $this->lockedVersions($lockfile);
        $changed = false;
        $outcomes = [];

        foreach ($plan->fixes as $fix) {
            if (!$fix->isFixable()) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::Skipped);
                continue;
            }

            // The report tells what is vulnerable at the start, then the lockfile follows what the fixes changed.
            if (!$this->stillReported($initialReport, $fix) || ($changed && !$this->vulnerableInLock($locked ?? [], $fix))) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::AlreadyFixed);
                continue;
            }

            $unsupported = $this->strategy->unsupportedReason($fix, $this->projectDir);
            if (null !== $unsupported) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::UnsupportedSource, null, [$unsupported]);
                continue;
            }

            [$outcome, $after] = $this->fixPackage($fix, $locked ?? [], $baseline);
            $outcomes[] = $outcome;
            if (null !== $after) {
                $locked = $after;
                $changed = true;
            }
        }

        $result = new ApplyResult($outcomes, verify: $this->verify);
        if (!$result->hasChanges()) {
            return $result;
        }

        if (VerifyMode::Final === $this->verify) {
            $outcomes = $this->confirmWithTrivy($outcomes);
        }

        // The final lockfile must be consistent with the manifest, whatever the sequence of fixes.
        $check = $this->runner->run($this->strategy->checkCommand(), $this->projectDir, $this->strategy->checkEnv());

        $lockAfter = @file_get_contents($lockfile);
        $lockChanges = false === $lockBefore || false === $lockAfter
            ? []
            : (new LockfileVersions())->changes($plan->ecosystem, $lockBefore, $lockAfter);

        // Findings that were already there before the fixes, and nothing new, are not the fixes' doing.
        $existed = !$check->isSuccessful()
            && null !== $baselineCheck
            && !$baselineCheck->isSuccessful()
            && $check->exitCode === $baselineCheck->exitCode
            && $this->normalize($check->output) === $this->normalize($baselineCheck->output);

        return new ApplyResult(
            $outcomes,
            $check->isSuccessful() || $existed ? null : $this->tail($check->output),
            $lockChanges,
            $this->verify,
            $existed ? $this->tail($check->output) : null,
        );
    }

    private function assertSupported(GroupPlan $plan): void
    {
        if ($this->strategy->ecosystem() !== $plan->ecosystem) {
            throw new UnsupportedProjectException(sprintf('The strategy does not handle "%s" plans.', $plan->ecosystem->value));
        }

        if (Ecosystem::Yarn === $plan->ecosystem) {
            $artifacts = $this->tree->trackedYarnArtifacts();
            if ([] !== $artifacts) {
                throw new UnsupportedProjectException(
                    'Committed Yarn PnP/cache files are not supported yet: ' . implode(', ', $artifacts) . '.',
                );
            }

            $missing = (new YarnLocalSources())->missing($this->projectDir);
            if ([] !== $missing) {
                throw new UnsupportedProjectException(sprintf(
                    'package.json depends on local sources that do not exist (%s): Yarn cannot resolve the project without them. '
                    . 'Rustine installs nothing: make them available first, e.g. with `composer install --no-scripts` for a path in vendor/.',
                    implode(', ', $missing),
                ));
            }
        }

        foreach ($this->strategy->files() as $file) {
            if ($this->tree->isDirty($file)) {
                throw new UnsupportedProjectException(sprintf('"%s" has uncommitted changes; refusing to modify it.', $file));
            }
        }
    }

    /**
     * @param array<string, list<string>> $locked   versions the lockfile installs before the fix
     * @param list<string>                $baseline
     *
     * @return array{FixOutcome, array<string, list<string>>|null} the versions locked after the fix, null when nothing was kept
     */
    private function fixPackage(PackageFix $fix, array $locked, array &$baseline): array
    {
        $snapshot = $this->snapshot();
        $log = [];

        foreach ($this->strategy->attempts($fix, $this->projectDir) as $attempt) {
            // A resolution applies to every instance of the package: it must not drag another major along.
            if (AttemptKind::Resolution === $attempt->kind) {
                $others = $this->otherMajorVersions($locked, $fix);
                if ([] !== $others) {
                    $log[] = sprintf(
                        '%s: skipped, %s is also installed in other majors (%s) and a resolution would force them all to ^%s',
                        $attempt->kind->value,
                        $fix->packageName,
                        implode(', ', $others),
                        $fix->targetVersion,
                    );
                    continue;
                }
            }

            $after = null;
            $error = null;
            $abandon = false;

            try {
                $error = $this->run($attempt);

                if (null === $error) {
                    $forbidden = $this->tree->forbiddenChanges($baseline);
                    if ([] !== $forbidden) {
                        // Cannot be undone safely: do not flag the same files again for the next packages.
                        $baseline = [...$baseline, ...$forbidden];
                        $abandon = true;
                        $error = 'unexpected files changed: ' . implode(', ', $forbidden);
                    }
                }

                if (null === $error) {
                    $after = $this->lockedVersions($this->projectDir . '/' . $this->strategy->ecosystem()->lockFile());
                    $error = null === $after
                        ? 'the versions installed by the lockfile cannot be read to check the fix'
                        : $this->vulnerableReason($after, $fix);
                }

                // The lockfile accepted the fix: Trivy, the slow judge, is only asked about what is left.
                if (null === $error && VerifyMode::Each === $this->verify && $this->stillReported($this->rescanner->rescan(), $fix)) {
                    $error = 'the CVEs are still reported after the attempt';
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            if (null === $error && null !== $after) {
                $log[] = sprintf('%s: ok', $attempt->kind->value);

                return [new FixOutcome($fix, FixOutcomeStatus::Applied, $attempt->kind, $log), $after];
            }

            $log[] = sprintf('%s: %s', $attempt->kind->value, $error);
            $this->restore($snapshot);

            // Unexpected files changed: the correction is abandoned, no other attempt for this package.
            if ($abandon) {
                break;
            }
        }

        $diagnosis = $this->strategy->diagnosis($fix);
        if (null !== $diagnosis) {
            $result = $this->runner->run($diagnosis, $this->projectDir);
            $log[] = sprintf('%s: %s', implode(' ', $diagnosis), $this->tail($result->output, 12));
        }

        return [new FixOutcome($fix, FixOutcomeStatus::Failed, null, $log), null];
    }

    private function run(Attempt $attempt): ?string
    {
        if (null !== $attempt->pruneLockfile) {
            $lockfile = $this->projectDir . '/yarn.lock';
            file_put_contents($lockfile, (new YarnClassicLockfile())->withoutPackage((string) file_get_contents($lockfile), $attempt->pruneLockfile));
        }

        if (null !== $attempt->rangeEdit) {
            $this->editor->setDependencyRange($this->projectDir . '/package.json', ...$attempt->rangeEdit);
        }

        if (null !== $attempt->resolutionEdit) {
            $this->editor->setResolution($this->projectDir . '/package.json', ...$attempt->resolutionEdit);
        }

        foreach ($attempt->commands as $command) {
            $result = $this->runner->run($command, $this->projectDir, $this->strategy->mutatingEnv());
            if (!$result->isSuccessful()) {
                return sprintf('`%s` failed (exit %d): %s', implode(' ', $command), $result->exitCode, $this->tail($result->output));
            }
        }

        return null;
    }

    /**
     * Locked versions of the package whose line differs from the fix target.
     *
     * @param array<string, list<string>> $locked
     *
     * @return list<string>
     */
    private function otherMajorVersions(array $locked, PackageFix $fix): array
    {
        $target = null === $fix->targetVersion ? null : Version::tryParse($fix->targetVersion);
        if (null === $target) {
            return [];
        }

        $others = [];
        foreach ($locked[$fix->packageName] ?? [] as $version) {
            $parsed = Version::tryParse($version);
            if (null !== $parsed && !$parsed->isSameLineAs($target)) {
                $others[] = $version;
            }
        }

        return $others;
    }

    /**
     * Versions installed by the lockfile, by package; null when it cannot be read or holds no package.
     *
     * @return array<string, list<string>>|null
     */
    private function lockedVersions(string $lockfile): ?array
    {
        $content = @file_get_contents($lockfile);
        if (false === $content) {
            return null;
        }

        $versions = (new LockfileVersions())->parse($this->strategy->ecosystem(), $content);

        return [] === $versions ? null : $versions;
    }

    /**
     * @param array<string, list<string>> $locked
     */
    private function vulnerableReason(array $locked, PackageFix $fix): ?string
    {
        $remaining = $this->vulnerableVersions($locked, $fix);

        return [] === $remaining
            ? null
            : sprintf('the lockfile still installs %s %s, below %s', $fix->packageName, implode(', ', $remaining), $fix->targetVersion);
    }

    /**
     * @param array<string, list<string>> $locked
     */
    private function vulnerableInLock(array $locked, PackageFix $fix): bool
    {
        return [] !== $this->vulnerableVersions($locked, $fix);
    }

    /**
     * Locked versions that the fix is meant to replace: on the line of the fixed instance (same major, same minor below
     * 1.0), from the installed version up, and below the target. Another major of the same package is another
     * instance, with its own fix, and an older version on the same line was not reported.
     *
     * @param array<string, list<string>> $locked
     *
     * @return list<string>
     */
    private function vulnerableVersions(array $locked, PackageFix $fix): array
    {
        $versions = $locked[$fix->packageName] ?? [];
        $installed = Version::tryParse($fix->installedVersion);
        $target = null === $fix->targetVersion ? null : Version::tryParse($fix->targetVersion);

        // Versions that cannot be compared: the only safe check is that the reported one is gone.
        if (null === $installed || null === $target) {
            return \in_array($fix->installedVersion, $versions, true) ? [$fix->installedVersion] : [];
        }

        return array_values(array_filter($versions, static function (string $raw) use ($installed, $target): bool {
            $version = Version::tryParse($raw);

            return null !== $version
                && $version->isSameLineAs($installed)
                && $version->compare($installed) >= 0
                && $target->isGreaterThan($version);
        }));
    }

    /**
     * One Trivy run for the whole plan: a fix whose CVEs are still reported is flagged for a manual check.
     *
     * @param list<FixOutcome> $outcomes
     *
     * @return list<FixOutcome>
     */
    private function confirmWithTrivy(array $outcomes): array
    {
        $report = $this->rescanner->rescan();

        return array_map(
            fn (FixOutcome $outcome): FixOutcome => FixOutcomeStatus::Applied === $outcome->status && $this->stillReported($report, $outcome->fix)
                ? new FixOutcome($outcome->fix, $outcome->status, $outcome->strategy, $outcome->log, stillReported: true)
                : $outcome,
            $outcomes,
        );
    }

    /**
     * True while one of the CVEs the fix targets is still reported on the package, on the line of the fixed instance
     * (same major, same minor below 1.0). Another major of the same package may carry the same CVE (e.g. semver 5.x
     * and 7.x): it is another instance, with its own fix, and must not make this one look like a failure.
     */
    private function stillReported(TrivyReport $report, PackageFix $fix): bool
    {
        $installed = Version::tryParse($fix->installedVersion);

        foreach ($report->vulnerabilities as $vulnerability) {
            if (
                $vulnerability->ecosystem !== $fix->ecosystem
                || $vulnerability->packageName !== $fix->packageName
                || !\in_array($vulnerability->id, $fix->fixedIds, true)
            ) {
                continue;
            }

            $reported = Version::tryParse($vulnerability->installedVersion);
            if (null === $installed || null === $reported || $reported->isSameLineAs($installed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function snapshot(): array
    {
        $snapshot = [];
        foreach ($this->strategy->files() as $file) {
            $content = @file_get_contents($this->projectDir . '/' . $file);
            if (false === $content) {
                throw new UnsupportedProjectException(sprintf('Unable to read "%s".', $file));
            }
            $snapshot[$file] = $content;
        }

        return $snapshot;
    }

    /**
     * @param array<string, string> $snapshot
     */
    private function restore(array $snapshot): void
    {
        foreach ($snapshot as $file => $content) {
            file_put_contents($this->projectDir . '/' . $file, $content);
        }
    }

    private function normalize(string $output): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $output));
    }

    private function tail(string $output, int $count = 5): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $l): bool => '' !== $l));

        return implode(' | ', \array_slice($lines, -$count));
    }
}
