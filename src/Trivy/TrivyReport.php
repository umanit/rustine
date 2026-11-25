<?php

declare(strict_types=1);

namespace Rustine\Trivy;

final readonly class TrivyReport
{
    /**
     * @param list<Vulnerability>                        $vulnerabilities
     * @param array<string, array<string, TrivyPackage>> $packages       by ecosystem value, then by package ID
     * @param list<string>                               $ignoredTargets targets whose type is not supported, or
     *                                                                   lockfiles outside the project root
     */
    public function __construct(
        public array $vulnerabilities,
        public array $packages,
        public array $ignoredTargets = [],
    ) {
    }

    /**
     * @return array<string, TrivyPackage>
     */
    public function packagesOf(Ecosystem $ecosystem): array
    {
        return $this->packages[$ecosystem->value] ?? [];
    }

    /**
     * True if Trivy was run with `--list-all-pkgs` (relationships available).
     */
    public function hasPackageList(Ecosystem $ecosystem): bool
    {
        return [] !== $this->packagesOf($ecosystem);
    }
}
