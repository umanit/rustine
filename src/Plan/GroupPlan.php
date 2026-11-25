<?php

declare(strict_types=1);

namespace Rustine\Plan;

use Rustine\Trivy\Ecosystem;

/**
 * Content of one MR: one ecosystem × one severity group, or one ecosystem with every group when no group was asked
 * for (`$group` is null).
 */
final readonly class GroupPlan
{
    /**
     * @param list<PackageFix> $fixes
     */
    public function __construct(
        public Ecosystem $ecosystem,
        public ?SeverityGroup $group,
        public array $fixes,
    ) {
    }

    /** Stable branch name, the basis of MR idempotency. */
    public function branchName(): string
    {
        return null === $this->group
            ? sprintf('security/rustine-%s', $this->ecosystem->value)
            : sprintf('security/rustine-%s-%s', $this->ecosystem->value, $this->group->value);
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
