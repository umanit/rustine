<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Severity;
use Rustine\Trivy\Vulnerability;

/**
 * Fix to apply to one instance of a package (ecosystem, name, installed version).
 */
final readonly class PackageFix
{
    /**
     * @param list<Vulnerability>  $vulnerabilities all CVEs of this instance
     * @param list<string>         $fixedIds        CVEs fixed by `targetVersion`
     * @param array<string,string> $majorBumpIds    CVEs requiring a major bump => smallest fixed
     *                                              version
     * @param list<string>         $withoutFixIds   CVEs without a fixed version
     * @param list<string>         $dependencyChain from the direct dependency down to the package (Trivy IDs), empty if
     *                                              unknown
     */
    public function __construct(
        public Ecosystem $ecosystem,
        public string $packageName,
        public string $installedVersion,
        public PackageFixStatus $status,
        public ?string $targetVersion,
        public Severity $severity,
        public SeverityGroup $group,
        public DependencyKind $dependencyKind,
        public array $dependencyChain,
        public array $vulnerabilities,
        public array $fixedIds,
        public array $majorBumpIds,
        public array $withoutFixIds,
    ) {
    }

    public function isFixable(): bool
    {
        return PackageFixStatus::Fixable === $this->status;
    }
}
