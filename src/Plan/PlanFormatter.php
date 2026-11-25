<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Vulnerability;

final class PlanFormatter
{
    /**
     * @param list<GroupPlan> $plans
     *
     * @return list<array{branch: string, ecosystem: string, group: string|null, fixes: list<array<string, mixed>>}>
     */
    public function toArray(array $plans): array
    {
        return array_map(static fn(GroupPlan $plan): array => [
            'branch'    => $plan->branchName(),
            'ecosystem' => $plan->ecosystem->value,
            'group'     => $plan->group?->value,
            'fixes'     => array_map(static fn(PackageFix $fix): array => [
                'package'         => $fix->packageName,
                'installed'       => $fix->installedVersion,
                'target'          => $fix->targetVersion,
                'status'          => $fix->status->value,
                'severity'        => $fix->severity->value,
                'dependency'      => $fix->dependencyKind->value,
                'chain'           => $fix->dependencyChain,
                'vulnerabilities' => array_map(static fn(Vulnerability $v): array => [
                    'id'            => $v->id,
                    'severity'      => $v->severity->value,
                    'url'           => $v->url,
                    'fixedVersions' => $v->fixedVersions,
                ], $fix->vulnerabilities),
                'fixed'           => $fix->fixedIds,
                'majorBump'       => $fix->majorBumpIds,
                'withoutFix'      => $fix->withoutFixIds,
            ], $plan->fixes),
        ], $plans);
    }

    /**
     * @param list<GroupPlan> $plans
     */
    public function toText(array $plans): string
    {
        if ([] === $plans) {
            return "No vulnerability to fix.\n";
        }

        $out = '';

        foreach ($plans as $plan) {
            $out .= sprintf(
                "%s — %d fixable, %d to report\n",
                $plan->branchName(),
                \count($plan->fixable()),
                \count($plan->unfixable()),
            );

            foreach ($plan->fixes as $fix) {
                $out .= sprintf(
                    "  %s %s@%s%s [%s, %s, %d CVE]%s\n",
                    $fix->isFixable() ? '✔' : '✘',
                    $fix->packageName,
                    $fix->installedVersion,
                    $fix->isFixable() ? ' → ' . $fix->targetVersion : '',
                    $fix->severity->value,
                    $fix->dependencyKind->value,
                    \count($fix->vulnerabilities),
                    $this->note($fix),
                );
            }

            $out .= "\n";
        }

        return $out;
    }

    private function note(PackageFix $fix): string
    {
        $notes = [];
        if (PackageFixStatus::Fixable !== $fix->status) {
            $notes[] = str_replace('_', ' ', $fix->status->value);
        }
        if ([] !== $fix->majorBumpIds) {
            $notes[] = sprintf('%d CVE need a major bump', \count($fix->majorBumpIds));
        }
        if ([] !== $fix->withoutFixIds && PackageFixStatus::NoFixAvailable !== $fix->status) {
            $notes[] = sprintf('%d CVE without fix', \count($fix->withoutFixIds));
        }

        return [] === $notes ? '' : ' — ' . implode('; ', $notes);
    }
}
