<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply\Support;

use Rustine\Plan\DependencyKind;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Plan\PackageFixStatus;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;
use Rustine\Trivy\Relationship;
use Rustine\Trivy\Severity;
use Rustine\Trivy\TrivyPackage;
use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\Vulnerability;

final class Fixtures
{
    public static function fix(
        string $name = 'ckeditor4',
        string $installed = '4.22.1',
        string $target = '4.25.0',
        DependencyKind $kind = DependencyKind::Direct,
        PackageFixStatus $status = PackageFixStatus::Fixable,
        Ecosystem $ecosystem = Ecosystem::Yarn,
    ): PackageFix {
        $vulnerability = self::vulnerability($name, $installed);

        return new PackageFix(
            $ecosystem, $name, $installed, $status,
            PackageFixStatus::Fixable === $status ? $target : null,
            Severity::Medium, SeverityGroup::Others, $kind, [], [$vulnerability],
            PackageFixStatus::Fixable === $status ? [$vulnerability->id] : [], [], [],
        );
    }

    public static function vulnerability(string $name = 'ckeditor4', string $installed = '4.22.1'): Vulnerability
    {
        return new Vulnerability(
            'CVE-2024-0001', Ecosystem::Yarn, $name, $name . '@' . $installed, $installed,
            ['4.25.0'], Severity::Medium, null, null,
        );
    }

    public static function plan(PackageFix ...$fixes): GroupPlan
    {
        return new GroupPlan($fixes[0]->ecosystem, SeverityGroup::Others, array_values($fixes));
    }

    /**
     * @param list<Vulnerability>        $vulnerabilities
     * @param array<string, list<string>> $installedVersions every installed version per package name
     */
    public static function report(array $vulnerabilities = [], array $installedVersions = []): TrivyReport
    {
        $packages = [];
        foreach ($installedVersions as $name => $versions) {
            foreach ($versions as $version) {
                $id = $name . '@' . $version;
                $packages[$id] = new TrivyPackage($id, $name, $version, Relationship::Indirect, []);
            }
        }

        return new TrivyReport($vulnerabilities, [] === $packages ? [] : [Ecosystem::Yarn->value => $packages]);
    }
}

/**
 * Records every command; `$handler` can override the result of a command (and fake side effects).
 */
