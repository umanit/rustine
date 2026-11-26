<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\Vulnerability;
use Rustine\Version\FixCandidate;
use Rustine\Version\FixedVersionSelector;
use Rustine\Version\Version;

final readonly class Planner
{
    public function __construct(
        private FixedVersionSelector $selector = new FixedVersionSelector(),
        private DependencyChainResolver $chainResolver = new DependencyChainResolver(),
    ) {
    }

    /**
     * Builds one plan per (ecosystem × severity group), or one plan per ecosystem holding every group when `$groups`
     * is null.
     *
     * A package's group depends on the highest severity across all its instances: a package thus only appears
     * in a single MR, whichever groups the run processes.
     *
     * @param list<SeverityGroup>|null $groups    groups to keep, each one in its own plan (null = every group, in a
     *                                            single plan per ecosystem)
     * @param Ecosystem|null           $ecosystem ecosystem to keep (null = all)
     *
     * @return list<GroupPlan> sorted by ecosystem then group, without empty plans
     */
    public function plan(TrivyReport $report, ?array $groups = null, ?Ecosystem $ecosystem = null): array
    {
        /** @var array<string, non-empty-list<Vulnerability>> $byInstance */
        $byInstance = [];
        /** @var array<string, Severity> $maxSeverityByPackage */
        $maxSeverityByPackage = [];

        foreach ($report->vulnerabilities as $vulnerability) {
            $key = $this->instanceKey($vulnerability);
            $byInstance[$key] = isset($byInstance[$key]) ? [...$byInstance[$key], $vulnerability] : [$vulnerability];

            $packageKey = $vulnerability->ecosystem->value . "\0" . $vulnerability->packageName;
            $maxSeverityByPackage[$packageKey] = Severity::max(
                $maxSeverityByPackage[$packageKey] ?? Severity::Unknown,
                $vulnerability->severity,
            );
        }

        /** @var array<string, list<PackageFix>> $planned */
        $planned = [];

        foreach ($byInstance as $vulnerabilities) {
            $first = $vulnerabilities[0];
            $group = SeverityGroup::forSeverity(
                $maxSeverityByPackage[$first->ecosystem->value . "\0" . $first->packageName],
            );
            if (null !== $groups && !\in_array($group, $groups, true)) {
                continue;
            }
            if (null !== $ecosystem && $first->ecosystem !== $ecosystem) {
                continue;
            }

            $planned[$first->ecosystem->value . "\0" . (null === $groups ? '' : $group->value)][] = $this->planInstance(
                $report,
                $vulnerabilities,
                $group,
            );
        }

        ksort($planned);

        $plans = [];

        foreach ($planned as $key => $fixes) {
            [$ecosystem, $group] = explode("\0", $key);
            usort(
                $fixes,
                static fn(PackageFix $a, PackageFix $b): int => [
                        $a->packageName,
                        $a->installedVersion,
                    ] <=> [$b->packageName, $b->installedVersion],
            );
            $plans[] = new GroupPlan(Ecosystem::from($ecosystem), SeverityGroup::tryFrom($group), $fixes);
        }

        return $plans;
    }

    /**
     * Adds an empty plan for every (ecosystem × group) a run processes that has none, in the order of {@see plan()}.
     * An empty plan carries no fix: it only lets the run find the merge request of a group whose last vulnerabilities
     * are gone, and close it. Without it a project whose scan comes back clean would keep its stale merge requests.
     *
     * @param list<GroupPlan>          $plans
     * @param list<SeverityGroup>|null $groups    as given to {@see plan()}
     * @param Ecosystem|null           $ecosystem as given to {@see plan()}
     *
     * @return list<GroupPlan>
     */
    public function withEmptyPlans(array $plans, ?array $groups = null, ?Ecosystem $ecosystem = null): array
    {
        $completed = $plans;

        foreach (null === $ecosystem ? Ecosystem::cases() : [$ecosystem] as $candidate) {
            foreach ($groups ?? [null] as $group) {
                $present = array_any(
                    $plans,
                    static fn(GroupPlan $plan): bool => $plan->ecosystem === $candidate && $plan->group === $group,
                );
                if (!$present) {
                    $completed[] = new GroupPlan($candidate, $group, []);
                }
            }
        }

        usort(
            $completed,
            static fn(GroupPlan $a, GroupPlan $b): int => [$a->ecosystem->value, $a->group->value ?? '']
                <=> [$b->ecosystem->value, $b->group->value ?? ''],
        );

        return $completed;
    }

    private function instanceKey(Vulnerability $v): string
    {
        return $v->ecosystem->value . "\0" . $v->packageName . "\0" . $v->installedVersion;
    }

    /**
     * @param non-empty-list<Vulnerability> $vulnerabilities CVEs of a single package instance
     */
    private function planInstance(TrivyReport $report, array $vulnerabilities, SeverityGroup $group): PackageFix
    {
        $first = $vulnerabilities[0];
        $severity = array_reduce(
            $vulnerabilities,
            static fn(Severity $carry, Vulnerability $v): Severity => Severity::max($carry, $v->severity),
            Severity::Unknown,
        );

        [$kind, $chain] = $this->dependency($report, $first);

        $installed = Version::tryParse($first->installedVersion);
        if (!$installed instanceof Version) {
            return new PackageFix(
                $first->ecosystem,
                $first->packageName,
                $first->installedVersion,
                PackageFixStatus::UnparsableVersion,
                null,
                $severity,
                $group,
                $kind,
                $chain,
                $vulnerabilities,
                [],
                [],
                [],
            );
        }

        $target = null;
        $fixedIds = [];
        $majorBumpIds = [];
        $withoutFixIds = [];

        foreach ($vulnerabilities as $vulnerability) {
            $candidate = $this->selector->select($installed, $vulnerability->fixedVersions);

            if (!$candidate instanceof FixCandidate) {
                $withoutFixIds[] = $vulnerability->id;
            } elseif ($candidate->majorBump) {
                $majorBumpIds[$vulnerability->id] = $candidate->version->raw;
            } else {
                $fixedIds[] = $vulnerability->id;
                // The version covering all CVEs of the same major is the highest of the per-CVE minimums.
                if (!$target instanceof Version || $candidate->version->isGreaterThan($target)) {
                    $target = $candidate->version;
                }
            }
        }

        $status = match (true) {
            $target instanceof Version => PackageFixStatus::Fixable,
            [] !== $majorBumpIds => PackageFixStatus::MajorBumpRequired,
            default => PackageFixStatus::NoFixAvailable,
        };

        return new PackageFix(
            $first->ecosystem,
            $first->packageName,
            $first->installedVersion,
            $status,
            $target?->raw,
            $severity,
            $group,
            $kind,
            $chain,
            $vulnerabilities,
            $fixedIds,
            $majorBumpIds,
            $withoutFixIds,
        );
    }

    /**
     * @return array{DependencyKind, list<string>}
     */
    private function dependency(TrivyReport $report, Vulnerability $vulnerability): array
    {
        $packages = $report->packagesOf($vulnerability->ecosystem);
        $chain = $this->chainResolver->resolve($packages, $vulnerability->packageId);
        if (null === $chain) {
            return [DependencyKind::Unknown, []];
        }

        return [1 === \count($chain) ? DependencyKind::Direct : DependencyKind::Transitive, $chain];
    }
}
