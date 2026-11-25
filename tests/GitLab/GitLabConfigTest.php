<?php

declare(strict_types=1);

namespace Rustine\Tests\GitLab;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\GitLab\GitLabConfig;
use Rustine\GitLab\GitLabConfigException;
use Rustine\GitLab\GitLabUser;

final class GitLabConfigTest extends TestCase
{
    private const array ENV = [
        'CI_API_V4_URL'     => 'https://gitlab.example.com/api/v4/',
        'CI_PROJECT_ID'     => '42',
        'CI_DEFAULT_BRANCH' => 'main',
        'GITLAB_TOKEN'      => 'glpat-x',
        'PATH'              => '/usr/bin',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function instanceAddresses(): iterable
    {
        yield 'bare address' => ['https://gitlab.example.com', 'https://gitlab.example.com/api/v4'];
        yield 'bare address, trailing slash' => ['https://gitlab.example.com/', 'https://gitlab.example.com/api/v4'];
        yield 'complete' => ['https://gitlab.example.com/api/v4', 'https://gitlab.example.com/api/v4'];
        yield 'complete, trailing slash' => ['https://gitlab.example.com/api/v4/', 'https://gitlab.example.com/api/v4'];
        yield 'under a path' => ['https://example.com/gitlab', 'https://example.com/gitlab/api/v4'];
        yield 'under a path, complete' => ['https://example.com/gitlab/api/v4/', 'https://example.com/gitlab/api/v4'];
        yield 'surrounded by spaces' => [' https://gitlab.example.com/ ', 'https://gitlab.example.com/api/v4'];
    }

    #[Test]
    public function itReadsTheGitLabCiVariables(): void
    {
        $config = GitLabConfig::resolve(self::ENV);

        $this->assertSame('https://gitlab.example.com/api/v4', $config->apiUrl);
        $this->assertSame('42', $config->projectId);
        $this->assertSame('main', $config->defaultBranch);
        $this->assertSame('glpat-x', $config->token);
        $this->assertSame('gitlab.example.com', $config->host());
    }

    #[Test]
    #[DataProvider('instanceAddresses')]
    public function theApiPathIsAddedWhenOnlyTheInstanceAddressIsGiven(string $given, string $expected): void
    {
        $this->assertSame($expected, GitLabConfig::resolve(self::ENV, $given)->apiUrl);
    }

    #[Test]
    public function optionsWinOverTheVariablesAndTheDedicatedTokenWins(): void
    {
        $config = GitLabConfig::resolve(
            [...self::ENV, 'RUSTINE_GITLAB_TOKEN' => 'glpat-rustine'],
            'https://other.example.org/api/v4',
            '7',
            'develop',
        );

        $this->assertSame('https://other.example.org/api/v4', $config->apiUrl);
        $this->assertSame('7', $config->projectId);
        $this->assertSame('develop', $config->defaultBranch);
        $this->assertSame('glpat-rustine', $config->token);
    }

    #[Test]
    public function itListsEverythingThatIsMissing(): void
    {
        try {
            GitLabConfig::resolve(['CI_PROJECT_ID' => '']);
            self::fail('A GitLabConfigException was expected.');
        } catch (GitLabConfigException $gitLabConfigException) {
            $this->assertStringContainsString('API URL', $gitLabConfigException->getMessage());
            $this->assertStringContainsString('project', $gitLabConfigException->getMessage());
            $this->assertStringContainsString('default branch', $gitLabConfigException->getMessage());
            $this->assertStringContainsString('RUSTINE_GITLAB_TOKEN', $gitLabConfigException->getMessage());
        }
    }

    #[Test]
    public function itRejectsAnApiUrlThatIsNotHttp(): void
    {
        $this->expectException(GitLabConfigException::class);

        GitLabConfig::resolve(self::ENV, 'file:///etc/passwd');
    }

    #[Test]
    public function theCommitIdentityPrefersOptionsThenTheCommitEmailThenThePrivateCommitEmail(): void
    {
        $withEmail = new GitLabUser(1, 'rustine-bot', 'Rustine Bot', 'bot@example.com');
        $withoutEmail = new GitLabUser(1, 'rustine-bot', 'Rustine Bot', null);

        $this->assertSame('bot@example.com', $withEmail->gitIdentity('gitlab.example.com')->email);
        $this->assertSame(
            '1-rustine-bot@users.noreply.gitlab.example.com',
            $withoutEmail->gitIdentity('gitlab.example.com')->email,
        );
        $explicit = $withEmail->gitIdentity('gitlab.example.com', 'Security Bot', 'security@example.com');
        $this->assertSame(['Security Bot', 'security@example.com'], [$explicit->name, $explicit->email]);
        $this->assertSame('Rustine Bot', $withoutEmail->gitIdentity('gitlab.example.com')->name);
    }
}
