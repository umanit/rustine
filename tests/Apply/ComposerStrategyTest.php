<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\ComposerStrategy;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\PackageFixStatus;
use Rustine\Tests\Apply\Support\Fixtures;
use Rustine\Trivy\Ecosystem;

final class ComposerStrategyTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/rustine-composer-' . bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/composer.json');
        @rmdir($this->dir);
    }

    #[Test]
    public function composerCanBeRunThroughACustomCommand(): void
    {
        $this->manifest('{"require":{"twig/twig":"^3.0"}}');
        $strategy = ComposerStrategy::fromCommandLine('  /usr/bin/php8.1   /usr/local/bin/composer2 ');
        $fix = $this->fix(DependencyKind::Direct);

        $commands = [];
        foreach ($strategy->attempts($fix, $this->dir) as $attempt) {
            foreach ($attempt->commands as $command) {
                $commands[] = \array_slice($command, 0, 3);
            }
        }

        self::assertSame([
            ['/usr/bin/php8.1', '/usr/local/bin/composer2', 'update'],
            ['/usr/bin/php8.1', '/usr/local/bin/composer2', 'require'],
        ], $commands);
        self::assertSame(['/usr/bin/php8.1', '/usr/local/bin/composer2', 'why-not'], \array_slice($strategy->diagnosis($fix) ?? [], 0, 3));
        self::assertSame(['/usr/bin/php8.1', '/usr/local/bin/composer2', 'validate'], \array_slice($strategy->checkCommand(), 0, 3));
    }

    #[Test]
    public function anEmptyComposerCommandIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ComposerStrategy::fromCommandLine('   ');
    }

    #[Test]
    public function aTransitiveDependencyOnlyGetsAnUpdate(): void
    {
        $this->manifest('{"require":{"other/pkg":"^1.0"}}');

        $attempts = (new ComposerStrategy())->attempts($this->fix(DependencyKind::Transitive), $this->dir);

        self::assertCount(1, $attempts);
        self::assertSame(AttemptKind::LockfileRefresh, $attempts[0]->kind);
        self::assertSame(
            [['composer', 'update', 'twig/twig', '--with-all-dependencies', '--no-install', '--no-interaction', '--no-scripts', '--no-progress', '--no-plugins']],
            $attempts[0]->commands,
        );
    }

    #[Test]
    public function pluginsAreOnlyEnabledForAProjectThatNeedsFlex(): void
    {
        $this->manifest('{"require":{"twig/twig":"3.4.0"},"extra":{"symfony":{"require":"6.4.*"}}}');

        $attempts = (new ComposerStrategy())->attempts($this->fix(DependencyKind::Direct, 'v3.4.3'), $this->dir);

        foreach ($attempts as $attempt) {
            self::assertNotContains('--no-plugins', $attempt->commands[0]);
            self::assertContains('--no-scripts', $attempt->commands[0], 'Scripts stay disabled.');
            self::assertContains('--no-install', $attempt->commands[0]);
        }
    }

    #[Test]
    public function aRequiredPackageThenGetsItsConstraintRaised(): void
    {
        $this->manifest('{"require":{"twig/twig":"3.4.0"}}');

        $attempts = (new ComposerStrategy())->attempts($this->fix(DependencyKind::Direct, 'v3.4.3'), $this->dir);

        self::assertSame([AttemptKind::LockfileRefresh, AttemptKind::DirectBump], array_map(static fn ($a) => $a->kind, $attempts));
        self::assertSame(
            ['composer', 'require', 'twig/twig:^3.4.3', '--update-with-all-dependencies', '--no-install', '--no-interaction', '--no-scripts', '--no-progress', '--no-plugins'],
            $attempts[1]->commands[0],
            'The leading v of the version is dropped from the constraint.',
        );
    }

    #[Test]
    public function aDevRequirementIsRequiredWithTheDevFlag(): void
    {
        $this->manifest('{"require-dev":{"twig/twig":"3.4.0"}}');

        $attempts = (new ComposerStrategy())->attempts($this->fix(DependencyKind::Direct), $this->dir);

        self::assertContains('--dev', $attempts[1]->commands[0]);
    }

    #[Test]
    public function theManifestDecidesWhetherAPackageIsDirectNotTheReport(): void
    {
        $this->manifest('{"require":{"twig/twig":"^3.0"}}');

        // Trivy said transitive: the project requires it itself, so the bump is offered.
        $attempts = (new ComposerStrategy())->attempts($this->fix(DependencyKind::Transitive), $this->dir);

        self::assertCount(2, $attempts);
    }

    #[Test]
    #[DataProvider('branchConstraints')]
    public function aBranchOrAliasConstraintNeedsAManualFix(string $constraint): void
    {
        $this->manifest(sprintf('{"require":{"twig/twig":"%s"}}', $constraint));

        $reason = (new ComposerStrategy())->unsupportedReason($this->fix(DependencyKind::Direct), $this->dir);

        self::assertNotNull($reason);
        self::assertStringContainsString($constraint, $reason);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function branchConstraints(): iterable
    {
        yield 'dev branch' => ['dev-main'];
        yield 'pinned commit' => ['dev-main#abc1234'];
        yield 'alias' => ['dev-main as 3.4.x-dev'];
        yield 'stability flag' => ['^3.4@dev'];
    }

    #[Test]
    public function aVersionRangeIsNotAnUnsupportedSource(): void
    {
        $this->manifest('{"require":{"twig/twig":"^3.4 || ~4.0"}}');

        self::assertNull((new ComposerStrategy())->unsupportedReason($this->fix(DependencyKind::Direct), $this->dir));
    }

    #[Test]
    public function itExplainsFailuresWithWhyNotAndValidatesTheResult(): void
    {
        $strategy = new ComposerStrategy();

        self::assertSame(['composer', 'why-not', 'twig/twig', '3.4.3', '--locked'], $strategy->diagnosis($this->fix(DependencyKind::Direct)));
        self::assertSame(['composer', 'validate', '--no-check-publish', '--no-interaction', '--no-plugins'], $strategy->checkCommand());
        self::assertTrue($strategy->checkIsRelativeToBaseline());
        self::assertSame(Ecosystem::Composer, $strategy->ecosystem());
        self::assertSame(['composer.json', 'composer.lock'], $strategy->files());
    }

    #[Test]
    public function anUnfixablePackageHasNeitherAttemptNorDiagnosis(): void
    {
        $fix = Fixtures::fix('twig/twig', status: PackageFixStatus::NoFixAvailable, ecosystem: Ecosystem::Composer);

        self::assertSame([], (new ComposerStrategy())->attempts($fix, $this->dir));
        self::assertNull((new ComposerStrategy())->diagnosis($fix));
    }

    private function manifest(string $json): void
    {
        file_put_contents($this->dir . '/composer.json', $json);
    }

    private function fix(DependencyKind $kind, string $target = '3.4.3'): \Rustine\Plan\PackageFix
    {
        return Fixtures::fix('twig/twig', '3.4.0', $target, $kind, ecosystem: Ecosystem::Composer);
    }
}
