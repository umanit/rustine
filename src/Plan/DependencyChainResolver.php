<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Relationship;
use Rustine\Trivy\TrivyPackage;

final class DependencyChainResolver
{
    /**
     * Shortest path from a direct dependency to the target package (included).
     *
     * @param array<string, TrivyPackage> $packages packages of one ecosystem, indexed by ID
     *
     * @return list<string>|null null if the package is missing or unreachable from a direct dependency
     */
    public function resolve(array $packages, string $targetId): ?array
    {
        if (!isset($packages[$targetId])) {
            return null;
        }

        $queue = [];
        $parent = [];

        foreach ($this->starts($packages) as $id) {
            $queue[] = $id;
            $parent[$id] = null;
        }

        for ($i = 0; $i < \count($queue); ++$i) {
            $current = $queue[$i];
            if ($current === $targetId) {
                $chain = [];

                for ($node = $current; null !== $node; $node = $parent[$node]) {
                    $chain[] = $node;
                }

                return array_reverse($chain);
            }

            foreach ($packages[$current]->dependsOn as $child) {
                if (!\array_key_exists($child, $parent) && isset($packages[$child])) {
                    $parent[$child] = $current;
                    $queue[] = $child;
                }
            }
        }

        return null;
    }

    /**
     * Direct dependencies of the project.
     *
     * For Composer, Trivy may list the project itself (`acme/app@dev-main`) as a `direct` package whose `DependsOn`
     * are the real direct dependencies, some of them flagged `indirect`. That root is never a dependency: it is
     * dropped and its own dependencies are the starting points instead.
     *
     * @param array<string, TrivyPackage> $packages
     *
     * @return list<string>
     */
    private function starts(array $packages): array
    {
        $dependedOn = [];

        foreach ($packages as $package) {
            foreach ($package->dependsOn as $child) {
                $dependedOn[$child] = true;
            }
        }

        $starts = [];

        foreach ($packages as $package) {
            if ($this->isRoot($package, $dependedOn)) {
                foreach ($package->dependsOn as $child) {
                    $starts[$child] = true;
                }
            } elseif (Relationship::Direct === $package->relationship) {
                $starts[$package->id] = true;
            }
        }

        return array_map(strval(...), array_keys($starts));
    }

    /**
     * @param array<string, true> $dependedOn IDs depended on by at least one package
     */
    private function isRoot(TrivyPackage $package, array $dependedOn): bool
    {
        if (Relationship::Root === $package->relationship) {
            return true;
        }

        // The project is installed from a branch (`dev-*`) and nothing depends on it.
        return Relationship::Direct === $package->relationship
            && str_starts_with($package->version, 'dev-')
            && !isset($dependedOn[$package->id]);
    }
}
