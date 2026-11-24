<?php

declare(strict_types=1);

namespace Rustine\GitLab;

/**
 * Where and how to talk to GitLab: options first, then the variables GitLab CI provides.
 *
 * The token is only read from the environment (never from an option, which would end up in the process list and the
 * shell history): `RUSTINE_GITLAB_TOKEN`, then `GITLAB_TOKEN`.
 */
final readonly class GitLabConfig
{
    public function __construct(
        public string $apiUrl,
        public string $projectId,
        #[\SensitiveParameter]
        public string $token,
        public string $defaultBranch,
    ) {
    }

    /**
     * @param array<string, string|false> $env
     *
     * @throws GitLabConfigException listing everything that is missing
     */
    public static function resolve(array $env, ?string $apiUrl = null, ?string $projectId = null, ?string $defaultBranch = null): self
    {
        $apiUrl = self::first($apiUrl, $env['CI_API_V4_URL'] ?? null);
        $projectId = self::first($projectId, $env['CI_PROJECT_ID'] ?? null);
        $defaultBranch = self::first($defaultBranch, $env['CI_DEFAULT_BRANCH'] ?? null);
        $token = self::first($env['RUSTINE_GITLAB_TOKEN'] ?? null, $env['GITLAB_TOKEN'] ?? null);

        if (null === $apiUrl || null === $projectId || null === $defaultBranch || null === $token) {
            $missing = [];
            null === $apiUrl && $missing[] = 'the GitLab API URL (--gitlab-url or CI_API_V4_URL)';
            null === $projectId && $missing[] = 'the project (--project-id or CI_PROJECT_ID)';
            null === $defaultBranch && $missing[] = 'the default branch (--default-branch or CI_DEFAULT_BRANCH)';
            null === $token && $missing[] = 'the access token (RUSTINE_GITLAB_TOKEN or GITLAB_TOKEN)';

            throw new GitLabConfigException('Missing ' . implode(', ', $missing) . '.');
        }

        if (false === filter_var($apiUrl, \FILTER_VALIDATE_URL) || !\in_array(parse_url($apiUrl, \PHP_URL_SCHEME), ['http', 'https'], true)) {
            throw new GitLabConfigException(sprintf('"%s" is not a valid GitLab API URL.', $apiUrl));
        }

        return new self(rtrim($apiUrl, '/'), $projectId, $token, $defaultBranch);
    }

    public function host(): string
    {
        return (string) parse_url($this->apiUrl, \PHP_URL_HOST);
    }

    private static function first(string|false|null ...$values): ?string
    {
        foreach ($values as $value) {
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return null;
    }
}
