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
    private const TOKEN = 'glpat-s3cr3t-t0ken';

    #[Test]
    public function theTokenTravelsInTheEnvironmentAsABasicAuthHeader(): void
    {
        $env = (new GitAccess(self::TOKEN, inheritedConfigCount: 0))->environment();

        self::assertSame('http.extraHeader', $env['GIT_CONFIG_KEY_0']);
        self::assertSame('Authorization: Basic ' . base64_encode('oauth2:' . self::TOKEN), $env['GIT_CONFIG_VALUE_0']);
        self::assertSame('1', $env['GIT_CONFIG_COUNT']);
    }

    #[Test]
    public function theHeaderIsAddedAfterTheConfigurationTheRunnerAlreadyInjects(): void
    {
        $env = (new GitAccess(self::TOKEN, inheritedConfigCount: 2))->environment();

        self::assertSame('3', $env['GIT_CONFIG_COUNT']);
        self::assertSame('http.extraHeader', $env['GIT_CONFIG_KEY_2']);
        self::assertArrayNotHasKey('GIT_CONFIG_KEY_0', $env, 'the inherited pairs are left alone');
        self::assertArrayNotHasKey('GIT_CONFIG_KEY_1', $env);
    }

    #[Test]
    public function theInheritedCountIsReadFromTheEnvironment(): void
    {
        $previous = getenv('GIT_CONFIG_COUNT');
        putenv('GIT_CONFIG_COUNT=1');
        try {
            $env = (new GitAccess(self::TOKEN))->environment();
        } finally {
            false === $previous ? putenv('GIT_CONFIG_COUNT') : putenv('GIT_CONFIG_COUNT=' . $previous);
        }

        self::assertSame('2', $env['GIT_CONFIG_COUNT']);
        self::assertArrayHasKey('GIT_CONFIG_VALUE_1', $env);
    }

    #[Test]
    public function itMasksTheTokenInAnyForm(): void
    {
        $access = new GitAccess(self::TOKEN);
        $text = sprintf('fatal: %s and %s', self::TOKEN, base64_encode('oauth2:' . self::TOKEN));

        self::assertSame('fatal: *** and ***', $access->redact($text));
    }

    #[Test]
    public function remoteCommandsGetTheCredentialsButNeverOnTheCommandLine(): void
    {
        $runner = new FakeRunner();
        $git = new Git($runner, '/p', new GitAccess(self::TOKEN));

        $git->run(['fetch', 'origin'], true);
        $git->run(['status']);

        self::assertStringNotContainsString(self::TOKEN, implode(' ', $runner->commands[0]));
        self::assertArrayHasKey('GIT_CONFIG_VALUE_0', $runner->envs[0]);
        self::assertSame(['GIT_TERMINAL_PROMPT' => '0'], $runner->envs[1], 'local commands do not get the token');
    }

    #[Test]
    public function errorsDoNotLeakTheToken(): void
    {
        $runner = new class() extends FakeRunner {
            public function run(array $command, string $cwd, array $env = []): CommandResult
            {
                return new CommandResult(128, 'fatal: could not read from https://oauth2:glpat-s3cr3t-t0ken@host/x.git');
            }
        };

        try {
            (new Git($runner, '/p', new GitAccess(self::TOKEN)))->run(['fetch', 'origin'], true);
            self::fail('A GitException was expected.');
        } catch (GitException $e) {
            self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
            self::assertStringContainsString('***', $e->getMessage());
        }
    }
}
