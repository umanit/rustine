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
    public function __construct(
        #[\SensitiveParameter]
        private string $token,
        private string $username = 'oauth2',
    ) {
    }

    /**
     * @return array<string, string>
     */
    public function environment(): array
    {
        return [
            'GIT_CONFIG_COUNT' => '1',
            'GIT_CONFIG_KEY_0' => 'http.extraHeader',
            'GIT_CONFIG_VALUE_0' => 'Authorization: Basic ' . $this->encodedCredentials(),
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

    private function encodedCredentials(): string
    {
        return base64_encode($this->username . ':' . $this->token);
    }
}
