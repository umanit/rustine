<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\TrivyReport;
use Rustine\Version\Version;

/**
 * Applies the fixes of one Yarn plan (Berry or Classic, depending on the strategy), package by package.
 *
 * After each package Trivy is run again: a fix is kept only if its CVEs are gone, otherwise the manifest and the
 * lockfile are restored and the next attempt is tried. A package that cannot be fixed never blocks the others.
 */
final class FixApplier
{
    /** Files restored when an attempt fails. */
    private const RESTORABLE = ['package.json', 'yarn.lock'];

    public function __construct(
        private readonly CommandRunner $runner,
        private readonly ToolEnvironment $tools,
        private readonly WorkingTree $tree,
        private readonly VulnerabilityRescanner $rescanner,
        private readonly FixStrategy $strategy,
        private readonly PackageJsonEditor $editor,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @throws UnsupportedProjectException when the project cannot be handled safely
     */
    public function apply(GroupPlan $plan, TrivyReport $initialReport): ApplyResult
    {
        $this->assertSupported($plan);

        $baseline = $this->tree->dirtyPaths();
        $current = $initialReport;
        $outcomes = [];

        foreach ($plan->fixes as $fix) {
            if (!$fix->isFixable()) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::Skipped);
                continue;
            }

            if (!$this->stillVulnerable($current, $fix)) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::AlreadyFixed);
                continue;
            }

            $unsupported = $this->unsupportedSource($fix);
            if (null !== $unsupported) {
                $outcomes[] = new FixOutcome($fix, FixOutcomeStatus::UnsupportedSource, null, [$unsupported]);
                continue;
            }

            [$outcome, $report] = $this->fixPackage($fix, $current, $baseline);
            $outcomes[] = $outcome;
            $current = $report ?? $current;
        }

        $result = new ApplyResult($outcomes);
        if (!$result->hasChanges()) {
            return $result;
        }

        // The final lockfile must be consistent with the manifest, whatever the sequence of fixes.
        $check = $this->runner->run($this->tools->wrap($this->strategy->checkCommand()), $this->projectDir, [...$this->tools->env(), ...$this->strategy->checkEnv()]);

        return new ApplyResult($outcomes, $check->isSuccessful() ? null : $this->tail($check->output));
    }

    private function assertSupported(GroupPlan $plan): void
    {
        if (Ecosystem::Yarn !== $plan->ecosystem) {
            throw new UnsupportedProjectException(sprintf('Applying fixes is not implemented for "%s" yet.', $plan->ecosystem->value));
        }

        $artifacts = $this->tree->trackedYarnArtifacts();
        if ([] !== $artifacts) {
            throw new UnsupportedProjectException(
                'Committed Yarn PnP/cache files are not supported yet: ' . implode(', ', $artifacts) . '.',
            );
        }

        foreach (self::RESTORABLE as $file) {
            if ($this->tree->isDirty($file)) {
                throw new UnsupportedProjectException(sprintf('"%s" has uncommitted changes; refusing to modify it.', $file));
            }
        }
    }

    /**
     * @param list<string> $baseline
     *
     * @return array{FixOutcome, TrivyReport|null} the new report is null when nothing was kept
     */
    private function fixPackage(PackageFix $fix, TrivyReport $current, array &$baseline): array
    {
        $snapshot = $this->snapshot();
        $log = [];

        foreach ($this->strategy->attempts($fix) as $attempt) {
            // A resolution applies to every instance of the package: it must not drag another major along.
            if (AttemptKind::Resolution === $attempt->kind) {
                $others = $this->otherMajorVersions($current, $fix);
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

            $report = null;
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
                    $report = $this->rescanner->rescan();
                    if ($this->stillVulnerable($report, $fix)) {
                        $error = 'the CVEs are still reported after the attempt';
                    }
                }
            } catch (\Throwable $e) {
                $error = $e->getMessage();
            }

            if (null === $error && null !== $report) {
                $log[] = sprintf('%s: ok', $attempt->kind->value);

                return [new FixOutcome($fix, FixOutcomeStatus::Applied, $attempt->kind, $log), $report];
            }

            $log[] = sprintf('%s: %s', $attempt->kind->value, $error);
            $this->restore($snapshot);

            // Unexpected files changed: the correction is abandoned, no other attempt for this package.
            if ($abandon) {
                break;
            }
        }

        return [new FixOutcome($fix, FixOutcomeStatus::Failed, null, $log), null];
    }

    private function run(Attempt $attempt): ?string
    {
        if (null !== $attempt->pruneLockfile) {
            $lockfile = $this->projectDir . '/yarn.lock';
            file_put_contents($lockfile, (new YarnClassicLockfile())->withoutPackage((string) file_get_contents($lockfile), $attempt->pruneLockfile));
        }

        if (null !== $attempt->resolutionEdit) {
            $this->editor->setResolution($this->projectDir . '/package.json', ...$attempt->resolutionEdit);
        }

        foreach ($attempt->commands as $command) {
            $result = $this->runner->run($this->tools->wrap($command), $this->projectDir, [...$this->tools->env(), ...$this->strategy->mutatingEnv()]);
            if (!$result->isSuccessful()) {
                return sprintf('`%s` failed (exit %d): %s', implode(' ', $command), $result->exitCode, $this->tail($result->output));
            }
        }

        return null;
    }

    /**
     * Installed versions of the package whose major differs from the fix target.
     *
     * @return list<string>
     */
    private function otherMajorVersions(TrivyReport $report, PackageFix $fix): array
    {
        $target = null === $fix->targetVersion ? null : Version::tryParse($fix->targetVersion);
        if (null === $target) {
            return [];
        }

        $versions = [];
        foreach ($report->packagesOf($fix->ecosystem) as $package) {
            if ($package->name === $fix->packageName) {
                $versions[$package->version] = true;
            }
        }
        foreach ($report->vulnerabilities as $vulnerability) {
            if ($vulnerability->ecosystem === $fix->ecosystem && $vulnerability->packageName === $fix->packageName) {
                $versions[$vulnerability->installedVersion] = true;
            }
        }

        $others = [];
        foreach (array_keys($versions) as $version) {
            $parsed = Version::tryParse((string) $version);
            if (null !== $parsed && $parsed->major() !== $target->major()) {
                $others[] = (string) $version;
            }
        }
        sort($others);

        return $others;
    }

    private function unsupportedSource(PackageFix $fix): ?string
    {
        $spec = $this->editor->declaredSpec($this->projectDir . '/package.json', $fix->packageName);
        if (null === $spec || DependencySource::isRegistryRange($spec)) {
            return null;
        }

        return sprintf(
            'declared as "%s" in package.json, not an npm registry range: update this reference by hand to a version that fixes %s',
            $spec,
            implode(', ', $fix->fixedIds),
        );
    }

    private function stillVulnerable(TrivyReport $report, PackageFix $fix): bool
    {
        foreach ($report->vulnerabilities as $vulnerability) {
            if (
                $vulnerability->ecosystem === $fix->ecosystem
                && $vulnerability->packageName === $fix->packageName
                && \in_array($vulnerability->id, $fix->fixedIds, true)
            ) {
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
        foreach (self::RESTORABLE as $file) {
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

    private function tail(string $output): string
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $output)), static fn (string $l): bool => '' !== $l));

        return implode(' | ', \array_slice($lines, -5));
    }
}
