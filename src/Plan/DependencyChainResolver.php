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
        foreach ($packages as $package) {
            if (Relationship::Direct === $package->relationship) {
                $queue[] = $package->id;
                $parent[$package->id] = null;
            }
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
}
