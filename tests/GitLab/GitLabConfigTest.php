<?php

declare(strict_types=1);

namespace Rustine\Tests\GitLab;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\GitLab\GitLabConfig;
use Rustine\GitLab\GitLabConfigException;
use Rustine\GitLab\GitLabUser;

final class GitLabConfigTest extends TestCase
{
    private const ENV = [
        'CI_API_V4_URL' => 'https://gitlab.example.com/api/v4/',
        'CI_PROJECT_ID' => '42',
        'CI_DEFAULT_BRANCH' => 'main',
        'GITLAB_TOKEN' => 'glpat-x',
        'PATH' => '/usr/bin',
    ];

    #[Test]
    public function itReadsTheGitLabCiVariables(): void
    {
        $config = GitLabConfig::resolve(self::ENV);

        self::assertSame('https://gitlab.example.com/api/v4', $config->apiUrl);
        self::assertSame('42', $config->projectId);
        self::assertSame('main', $config->defaultBranch);
        self::assertSame('glpat-x', $config->token);
        self::assertSame('gitlab.example.com', $config->host());
    }

    #[Test]
    public function optionsWinOverTheVariablesAndTheDedicatedTokenWins(): void
    {
        $config = GitLabConfig::resolve([...self::ENV, 'RUSTINE_GITLAB_TOKEN' => 'glpat-rustine'], 'https://other.example.org/api/v4', '7', 'develop');

        self::assertSame('https://other.example.org/api/v4', $config->apiUrl);
        self::assertSame('7', $config->projectId);
        self::assertSame('develop', $config->defaultBranch);
        self::assertSame('glpat-rustine', $config->token);
    }

    #[Test]
    public function itListsEverythingThatIsMissing(): void
    {
        try {
            GitLabConfig::resolve(['CI_PROJECT_ID' => '']);
            self::fail('A GitLabConfigException was expected.');
        } catch (GitLabConfigException $e) {
            self::assertStringContainsString('API URL', $e->getMessage());
            self::assertStringContainsString('project', $e->getMessage());
            self::assertStringContainsString('default branch', $e->getMessage());
            self::assertStringContainsString('RUSTINE_GITLAB_TOKEN', $e->getMessage());
        }
    }

    #[Test]
    public function itRejectsAnApiUrlThatIsNotHttp(): void
    {
        $this->expectException(GitLabConfigException::class);

        GitLabConfig::resolve(self::ENV, 'file:///etc/passwd');
    }

    #[Test]
    public function theCommitIdentityPrefersOptionsThenTheCommitEmailThenTheNoreplyAddress(): void
    {
        $withEmail = new GitLabUser(1, 'rustine-bot', 'Rustine Bot', 'bot@example.com');
        $withoutEmail = new GitLabUser(1, 'rustine-bot', 'Rustine Bot', null);

        self::assertSame('bot@example.com', $withEmail->gitIdentity('gitlab.example.com')->email);
        self::assertSame('rustine-bot@noreply.gitlab.example.com', $withoutEmail->gitIdentity('gitlab.example.com')->email);
        $explicit = $withEmail->gitIdentity('gitlab.example.com', 'Security Bot', 'security@example.com');
        self::assertSame(['Security Bot', 'security@example.com'], [$explicit->name, $explicit->email]);
        self::assertSame('Rustine Bot', $withoutEmail->gitIdentity('gitlab.example.com')->name);
    }
}
