<?php

declare(strict_types=1);

namespace Rustine\GitLab;

final readonly class NewMergeRequest
{
    /**
     * @param list<string> $labels
     * @param list<int>    $assigneeIds
     * @param list<int>    $reviewerIds
     */
    public function __construct(
        public string $sourceBranch,
        public string $targetBranch,
        public string $title,
        public string $description,
        public array $labels = [],
        public array $assigneeIds = [],
        public array $reviewerIds = [],
    ) {
    }
}
