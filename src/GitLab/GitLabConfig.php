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
    /** Environment variables that may hold the token, in order of precedence. */
    public const array TOKEN_VARIABLES = ['RUSTINE_GITLAB_TOKEN', 'GITLAB_TOKEN'];

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
    public static function resolve(
        array $env,
        ?string $apiUrl = null,
        ?string $projectId = null,
        ?string $defaultBranch = null,
    ): self {
        $apiUrl = self::first($apiUrl, $env['CI_API_V4_URL'] ?? null);
        $projectId = self::first($projectId, $env['CI_PROJECT_ID'] ?? null);
        $defaultBranch = self::first($defaultBranch, $env['CI_DEFAULT_BRANCH'] ?? null);
        $token = self::first(
            ...array_map(static fn(string $name): string|false|null => $env[$name] ?? null, self::TOKEN_VARIABLES),
        );

        if (in_array(null, [$apiUrl, $projectId, $defaultBranch, $token], true)) {
            $missing = [];
            if (null === $apiUrl) {
                $missing[] = 'the GitLab API URL (--gitlab-url or CI_API_V4_URL)';
            }
            if (null === $projectId) {
                $missing[] = 'the project (--project-id or CI_PROJECT_ID)';
            }
            if (null === $defaultBranch) {
                $missing[] = 'the default branch (--default-branch or CI_DEFAULT_BRANCH)';
            }
            if (null === $token) {
                $missing[] = 'the access token (RUSTINE_GITLAB_TOKEN or GITLAB_TOKEN)';
            }

            throw new GitLabConfigException('Missing ' . implode(', ', $missing) . '.');
        }

        $apiUrl = trim($apiUrl);
        if (
            false === filter_var($apiUrl, \FILTER_VALIDATE_URL)
            || !\in_array(parse_url($apiUrl, \PHP_URL_SCHEME), ['http', 'https'], true)
        ) {
            throw new GitLabConfigException(sprintf('"%s" is not a valid GitLab API URL.', $apiUrl));
        }

        return new self(self::apiRoot($apiUrl), $projectId, $token, $defaultBranch);
    }

    /**
     * Environment that removes the token from what a child process inherits (Symfony Process unsets `false` values).
     *
     * @return array<string, false>
     */
    public static function tokenUnsetEnv(): array
    {
        return array_fill_keys(self::TOKEN_VARIABLES, false);
    }

    /**
     * The API root, without a trailing slash. `/api/v4` is added when the URL is only the address of the GitLab
     * instance (`https://gitlab.example.com`, with or without a trailing slash), as `CI_API_V4_URL` always carries it.
     */
    private static function apiRoot(string $url): string
    {
        $root = rtrim($url, '/');

        return 1 === preg_match('#/api/v\d+$#', $root) ? $root : $root . '/api/v4';
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

    public function host(): string
    {
        return (string) parse_url($this->apiUrl, \PHP_URL_HOST);
    }
}
