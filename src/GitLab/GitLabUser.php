<?php

declare(strict_types=1);

namespace Rustine\GitLab;

use Rustine\Git\GitIdentity;

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

    /**
     * Identity of the commits: explicit options win, then the commit email GitLab exposes for the user, then the
     * user's noreply address (git needs an author even though API calls are attributed to the token owner).
     */
    public function gitIdentity(string $host, ?string $name = null, ?string $email = null): GitIdentity
    {
        return new GitIdentity(
            $name ?? $this->name,
            $email ?? $this->commitEmail ?? sprintf('%s@noreply.%s', $this->username, $host),
        );
    }
}
