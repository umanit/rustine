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
        public ?string $baseSlug = null,
    ) {
    }

    /**
     * The same plan for a base branch other than the default one: its slug ends the name of the Rustine branch, so
     * that every analyzed branch has its own branches and merge requests.
     */
    public function forBase(string $baseBranch): self
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($baseBranch)), '-');

        return new self($this->ecosystem, $this->group, $this->fixes, '' === $slug ? null : $slug);
    }

    /** Stable branch name, the basis of MR idempotency. */
    public function branchName(): string
    {
        $name = null === $this->group
            ? sprintf('security/rustine-%s', $this->ecosystem->value)
            : sprintf('security/rustine-%s-%s', $this->ecosystem->value, $this->group->value);

        return null === $this->baseSlug ? $name : $name . '-' . $this->baseSlug;
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
