<?php

declare(strict_types=1);

namespace Rustine\Git;

/**
 * Credentials used to talk to the remote (fetch, ls-remote, push).
 *
 * The token travels in an `http.extraHeader` handed to git through `GIT_CONFIG_*` environment variables: it never
 * appears in the remote URL, in the command line (visible in `ps`) nor in the git configuration on disk, and
 * {@see redact()} removes it from any text that could reach a log.
 */
final readonly class GitAccess
{
    /**
     * @param int|null $inheritedConfigCount number of `GIT_CONFIG_KEY_n` / `GIT_CONFIG_VALUE_n` pairs already set by
     *                                       the runner; null reads `GIT_CONFIG_COUNT` from the environment
     */
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
        private string $username = 'oauth2',
        private ?int $inheritedConfigCount = null,
    ) {
    }

    /**
     * The header is added after the configuration the runner may already inject through `GIT_CONFIG_*` (proxy, CA
     * bundle, `safe.directory`…): those pairs are inherited as they are, only the count grows.
     *
     * @return array<string, string>
     */
    public function environment(): array
    {
        $inherited = $this->inheritedConfigCount ?? $this->readInheritedCount();

        return [
            'GIT_CONFIG_COUNT'               => (string) ($inherited + 1),
            'GIT_CONFIG_KEY_' . $inherited   => 'http.extraHeader',
            'GIT_CONFIG_VALUE_' . $inherited => 'Authorization: Basic ' . $this->encodedCredentials(),
        ];
    }

    /**
     * Masks the token, and its Basic-auth encoding, in a text.
     */
    public function redact(string $text): string
    {
        if ('' === $this->token) {
            return $text;
        }

        return str_replace([$this->encodedCredentials(), $this->token], '***', $text);
    }

    private function readInheritedCount(): int
    {
        $count = getenv('GIT_CONFIG_COUNT');

        return \is_string($count) && 1 === preg_match('/^\d{1,4}$/', $count) ? (int) $count : 0;
    }

    private function encodedCredentials(): string
    {
        return base64_encode($this->username . ':' . $this->token);
    }
}
