<?php

declare(strict_types=1);

namespace Rustine\GitLab;

final readonly class MergeRequest
{
    /**
     * @param list<string> $labels
     */
    public function __construct(
        public int $iid,
        public string $title,
        public string $description,
        public string $webUrl,
        public string $sourceBranch,
        public array $labels,
        public bool $hasConflicts = false,
    ) {
    }
}
