<?php

declare(strict_types=1);

namespace Rustine\Tests\Git;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\CommandResult;
use Rustine\Git\Git;
use Rustine\Git\GitAccess;
use Rustine\Git\GitException;
use Rustine\Tests\Apply\Support\FakeRunner;

final class GitAccessTest extends TestCase
{
    private const string TOKEN = 'glpat-s3cr3t-t0ken';

    #[Test]
    public function theTokenTravelsInTheEnvironmentAsABasicAuthHeader(): void
    {
        $env = new GitAccess(self::TOKEN, inheritedConfigCount: 0)->environment();

        $this->assertSame('http.extraHeader', $env['GIT_CONFIG_KEY_0']);
        $this->assertSame('Authorization: Basic ' . base64_encode('oauth2:' . self::TOKEN), $env['GIT_CONFIG_VALUE_0']);
        $this->assertSame('1', $env['GIT_CONFIG_COUNT']);
    }

    #[Test]
    public function theHeaderIsAddedAfterTheConfigurationTheRunnerAlreadyInjects(): void
    {
        $env = new GitAccess(self::TOKEN, inheritedConfigCount: 2)->environment();

        $this->assertSame('3', $env['GIT_CONFIG_COUNT']);
        $this->assertSame('http.extraHeader', $env['GIT_CONFIG_KEY_2']);
        $this->assertArrayNotHasKey('GIT_CONFIG_KEY_0', $env, 'the inherited pairs are left alone');
        $this->assertArrayNotHasKey('GIT_CONFIG_KEY_1', $env);
    }

    #[Test]
    public function theInheritedCountIsReadFromTheEnvironment(): void
    {
        $previous = getenv('GIT_CONFIG_COUNT');
        putenv('GIT_CONFIG_COUNT=1');

        try {
            $env = new GitAccess(self::TOKEN)->environment();
        } finally {
            false === $previous ? putenv('GIT_CONFIG_COUNT') : putenv('GIT_CONFIG_COUNT=' . $previous);
        }

        $this->assertSame('2', $env['GIT_CONFIG_COUNT']);
        $this->assertArrayHasKey('GIT_CONFIG_VALUE_1', $env);
    }

    #[Test]
    public function itMasksTheTokenInAnyForm(): void
    {
        $access = new GitAccess(self::TOKEN);
        $text = sprintf('fatal: %s and %s', self::TOKEN, base64_encode('oauth2:' . self::TOKEN));

        $this->assertSame('fatal: *** and ***', $access->redact($text));
    }

    #[Test]
    public function remoteCommandsGetTheCredentialsButNeverOnTheCommandLine(): void
    {
        $runner = new FakeRunner();
        $git = new Git($runner, '/p', new GitAccess(self::TOKEN));

        $git->run(['fetch', 'origin'], true);
        $git->run(['status']);

        $this->assertStringNotContainsString(self::TOKEN, implode(' ', $runner->commands[0]));
        $this->assertArrayHasKey('GIT_CONFIG_VALUE_0', $runner->envs[0]);
        $this->assertSame(['GIT_TERMINAL_PROMPT' => '0'], $runner->envs[1], 'local commands do not get the token');
    }

    #[Test]
    public function errorsDoNotLeakTheToken(): void
    {
        $runner = new class () extends FakeRunner {
            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return new CommandResult(
                    128,
                    'fatal: could not read from https://oauth2:glpat-s3cr3t-t0ken@host/x.git',
                );
            }
        };

        try {
            new Git($runner, '/p', new GitAccess(self::TOKEN))->run(['fetch', 'origin'], true);
            self::fail('A GitException was expected.');
        } catch (GitException $gitException) {
            $this->assertStringNotContainsString(self::TOKEN, $gitException->getMessage());
            $this->assertStringContainsString('***', $gitException->getMessage());
        }
    }
}
