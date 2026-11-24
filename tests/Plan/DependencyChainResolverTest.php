<?php

declare(strict_types=1);

namespace Rustine\Tests\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Plan\DependencyChainResolver;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Relationship;
use Rustine\Trivy\TrivyPackage;
use Rustine\Trivy\TrivyReportParser;

final class DependencyChainResolverTest extends TestCase
{
    #[Test]
    public function theProjectItselfListedAsDirectByTrivyIsNotADependency(): void
    {
        $packages = $this->packages(
            new TrivyPackage('acme/app@dev-main', 'acme/app', 'dev-feature/x', Relationship::Direct, ['lib/a@1.0.0', 'lib/b@1.0.0']),
            new TrivyPackage('lib/a@1.0.0', 'lib/a', '1.0.0', Relationship::Indirect, ['lib/c@1.0.0']),
            new TrivyPackage('lib/b@1.0.0', 'lib/b', '1.0.0', Relationship::Direct, []),
            new TrivyPackage('lib/c@1.0.0', 'lib/c', '1.0.0', Relationship::Indirect, []),
        );
        $resolver = new DependencyChainResolver();

        // `lib/a` is flagged indirect by Trivy but is required by the project.
        self::assertSame(['lib/a@1.0.0'], $resolver->resolve($packages, 'lib/a@1.0.0'));
        self::assertSame(['lib/a@1.0.0', 'lib/c@1.0.0'], $resolver->resolve($packages, 'lib/c@1.0.0'));
        self::assertSame(['lib/b@1.0.0'], $resolver->resolve($packages, 'lib/b@1.0.0'));
        self::assertNull($resolver->resolve($packages, 'acme/app@dev-main'), 'The project is not a dependency of itself.');
    }

    #[Test]
    public function aDirectDependencyInstalledFromABranchIsKeptWhenSomethingDependsOnIt(): void
    {
        $packages = $this->packages(
            new TrivyPackage('lib/a@dev-main', 'lib/a', 'dev-main', Relationship::Direct, []),
            new TrivyPackage('lib/b@1.0.0', 'lib/b', '1.0.0', Relationship::Direct, ['lib/a@dev-main']),
        );

        self::assertSame(['lib/a@dev-main'], (new DependencyChainResolver())->resolve($packages, 'lib/a@dev-main'));
    }

    #[Test]
    public function aRootRelationshipIsAlsoDropped(): void
    {
        $packages = $this->packages(
            new TrivyPackage('app@1', 'app', '1.0.0', Relationship::Root, ['lib/a@1.0.0']),
            new TrivyPackage('lib/a@1.0.0', 'lib/a', '1.0.0', Relationship::Indirect, []),
        );

        self::assertSame(['lib/a@1.0.0'], (new DependencyChainResolver())->resolve($packages, 'lib/a@1.0.0'));
    }

    #[Test]
    public function itClassifiesTheDirectDependenciesOfTheRealComposerReport(): void
    {
        $report = (new TrivyReportParser())->parseFile(__DIR__ . '/../fixtures/trivy/composer-report.json');
        $packages = $report->packagesOf(Ecosystem::Composer);
        $resolver = new DependencyChainResolver();

        // Required by the project (`acme/app` depends on them), although Trivy flags `acme-core/core` as indirect.
        self::assertSame(['acme-core/core@4.3.16'], $resolver->resolve($packages, 'acme-core/core@4.3.16'));
        self::assertSame(['symfony/framework-bundle@v6.4.33'], $resolver->resolve($packages, 'symfony/framework-bundle@v6.4.33'));
        // phpspreadsheet is only reachable through the core.
        self::assertSame(
            ['acme-core/core@4.3.16', 'phpoffice/phpspreadsheet@1.30.0'],
            $resolver->resolve($packages, 'phpoffice/phpspreadsheet@1.30.0'),
        );
    }

    /**
     * @return array<string, TrivyPackage>
     */
    private function packages(TrivyPackage ...$packages): array
    {
        $indexed = [];
        foreach ($packages as $package) {
            $indexed[$package->id] = $package;
        }

        return $indexed;
    }
}
