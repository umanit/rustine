<?php

declare(strict_types=1);

namespace Rustine\Git;

/**
 * What the remote holds for a Rustine branch, as seen when it was inspected.
 */
final readonly class RemoteBranch
{
    /**
     * @param string|null $sha commit the remote branch pointed to (null when absent)
     */
    public function __construct(
        public RemoteBranchState $state,
        public ?string $sha = null,
    ) {
    }
}
