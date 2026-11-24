<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Ecosystem;

/**
 * Content of one MR: one ecosystem × one severity group.
 */
final readonly class GroupPlan
{
    /**
     * @param list<PackageFix> $fixes
     */
    public function __construct(
        public Ecosystem $ecosystem,
        public SeverityGroup $group,
        public array $fixes,
    ) {
    }

    /** Stable branch name, the basis of MR idempotency. */
    public function branchName(): string
    {
        return sprintf('security/%s/%s', $this->ecosystem->value, $this->group->value);
    }

    /**
     * @return list<PackageFix>
     */
    public function fixable(): array
    {
        return array_values(array_filter($this->fixes, static fn(PackageFix $f): bool => $f->isFixable()));
    }

    /**
     * @return list<PackageFix>
     */
    public function unfixable(): array
    {
        return array_values(array_filter($this->fixes, static fn(PackageFix $f): bool => !$f->isFixable()));
    }
}
