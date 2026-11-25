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
     * private commit email of the user (git needs an author even though API calls are attributed to the token owner).
     *
     * That address is `<id>-<username>@users.noreply.<host>`, the one GitLab itself generates and recognizes as the
     * user's: a made-up address would be refused by the "commit author must be a GitLab user" push rule.
     */
    public function gitIdentity(string $host, ?string $name = null, ?string $email = null): GitIdentity
    {
        return new GitIdentity(
            $name ?? $this->name,
            $email ?? $this->commitEmail ?? sprintf('%d-%s@users.noreply.%s', $this->id, $this->username, $host),
        );
    }
}
