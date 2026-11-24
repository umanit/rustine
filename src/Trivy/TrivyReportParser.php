<?php

declare(strict_types=1);

namespace Rustine\Trivy;

final class TrivyReportParser
{
    public function parseFile(string $path): TrivyReport
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new InvalidTrivyReportException(sprintf('Unable to read the Trivy report "%s".', $path));
        }

        return $this->parse($content);
    }

    public function parse(string $json): TrivyReport
    {
        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidTrivyReportException(
                'The Trivy report is not valid JSON: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        if (!\is_array($data)) {
            throw new InvalidTrivyReportException('The Trivy report must be a JSON object.');
        }

        // `Results` is absent when Trivy finds nothing to analyze.
        $results = $data['Results'] ?? [];
        if (!\is_array($results)) {
            throw new InvalidTrivyReportException('The "Results" field of the Trivy report must be an array.');
        }

        $vulnerabilities = [];
        $packages = [];
        $ignored = [];

        foreach ($results as $result) {
            if (!\is_array($result)) {
                continue;
            }

            $target = \is_string($result['Target'] ?? null) ? $result['Target'] : '?';
            $ecosystem = Ecosystem::fromTrivyType(\is_string($result['Type'] ?? null) ? $result['Type'] : '');
            // Only the lockfile at the root of the project is handled: a lockfile found deeper (`tools/*/`,
            // `vendor-bin/`, a sub-project, a package of `node_modules/`…) is not the one the fixes would change.
            if (null === $ecosystem || !$this->isRootLockfile($target, $ecosystem)) {
                $ignored[] = $target;

                continue;
            }

            foreach ($this->list($result['Packages'] ?? null) as $rawPackage) {
                $package = $this->parsePackage($rawPackage);
                if (null !== $package) {
                    $packages[$ecosystem->value][$package->id] = $package;
                }
            }

            foreach ($this->list($result['Vulnerabilities'] ?? null) as $rawVuln) {
                $vulnerability = $this->parseVulnerability($rawVuln, $ecosystem);
                if (null !== $vulnerability) {
                    // Deduplication: the same CVE may appear several times for one package.
                    $vulnerabilities[$vulnerability->packageId . "\0" . $vulnerability->id] = $vulnerability;
                }
            }
        }

        return new TrivyReport(array_values($vulnerabilities), $packages, array_values(array_unique($ignored)));
    }

    private function isRootLockfile(string $target, Ecosystem $ecosystem): bool
    {
        return $ecosystem->lockFile() === (str_starts_with($target, './') ? substr($target, 2) : $target);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                /** @var array<string, mixed> $item */
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function parseVulnerability(array $raw, Ecosystem $ecosystem): ?Vulnerability
    {
        $id = $raw['VulnerabilityID'] ?? null;
        $name = $raw['PkgName'] ?? null;
        $installed = $raw['InstalledVersion'] ?? null;
        if (!\is_string($id) || !\is_string($name) || !\is_string($installed)) {
            return null;
        }

        $packageId = \is_string($raw['PkgID'] ?? null) ? $raw['PkgID'] : $name . '@' . $installed;

        return new Vulnerability(
            $id,
            $ecosystem,
            $name,
            $packageId,
            $installed,
            $this->splitFixedVersions($raw['FixedVersion'] ?? null),
            Severity::fromTrivy(\is_string($raw['Severity'] ?? null) ? $raw['Severity'] : null),
            \is_string($raw['PrimaryURL'] ?? null) ? $raw['PrimaryURL'] : null,
            \is_string($raw['Title'] ?? null) ? $raw['Title'] : null,
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function parsePackage(array $raw): ?TrivyPackage
    {
        $name = $raw['Name'] ?? null;
        if (!\is_string($name)) {
            return null;
        }

        $version = \is_string($raw['Version'] ?? null) ? $raw['Version'] : '';
        $id = \is_string($raw['ID'] ?? null) ? $raw['ID'] : $name . '@' . $version;
        $dependsOn = array_values(
            array_filter(
                \is_array($raw['DependsOn'] ?? null) ? $raw['DependsOn'] : [],
                'is_string',
            ),
        );

        return new TrivyPackage(
            $id,
            $name,
            $version,
            Relationship::fromTrivy(\is_string($raw['Relationship'] ?? null) ? $raw['Relationship'] : null),
            $dependsOn,
        );
    }

    /**
     * @return list<string>
     */
    private function splitFixedVersions(mixed $value): array
    {
        if (!\is_string($value)) {
            return [];
        }

        return array_values(
            array_filter(
                array_map('trim', explode(',', $value)),
                static fn(string $v): bool => '' !== $v,
            ),
        );
    }
}
