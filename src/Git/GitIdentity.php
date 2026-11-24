<?php

declare(strict_types=1);

namespace Rustine\Git;

/**
 * Author and committer of the commits Rustine creates (git needs them, unlike GitLab API calls).
 */
final readonly class GitIdentity
{
    public function __construct(
        public string $name,
        public string $email,
    ) {
    }
}
