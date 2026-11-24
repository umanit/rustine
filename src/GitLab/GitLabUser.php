<?php

declare(strict_types=1);

namespace Rustine\GitLab;

/**
 * The owner of the token, as returned by `GET /user`.
 */
final readonly class GitLabUser
{
    public function __construct(
        public int $id,
        public string $username,
        public string $name,
        public ?string $commitEmail,
    ) {
    }
}
