<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Trivy\Ecosystem;

/**
 * Reads the versions a lockfile installs, to tell what a fix really changed in it (a refresh moves every dependency
 * within its range, not only the vulnerable package).
 */
final class LockfileVersions
{
    /**
     * @return array<string, list<string>> versions (sorted, unique) by package name; empty when the lockfile is
     *                       unreadable
     */
    public function parse(Ecosystem $ecosystem, string $content): array
    {
        $versions = Ecosystem::Composer === $ecosystem ? $this->composer($content) : $this->yarn($content);

        ksort($versions, \SORT_STRING);

        foreach ($versions as $name => $list) {
            $list = array_values(array_unique($list));
            usort($list, static fn(string $a, string $b): int => version_compare($a, $b));
            $versions[$name] = $list;
        }

        return $versions;
    }

    /**
     * Packages whose versions differ between two lockfiles, by name.
     *
     * @return list<LockChange>
     */
    public function changes(Ecosystem $ecosystem, string $before, string $after): array
    {
        $old = $this->parse($ecosystem, $before);
        $new = $this->parse($ecosystem, $after);

        $changes = [];

        foreach (array_keys($old + $new) as $name) {
            $name = (string) $name;
            $gone = array_values(array_diff($old[$name] ?? [], $new[$name] ?? []));
            $appeared = array_values(array_diff($new[$name] ?? [], $old[$name] ?? []));
            if ([] !== $gone || [] !== $appeared) {
                $changes[] = new LockChange($name, $gone, $appeared);
            }
        }

        usort($changes, static fn(LockChange $a, LockChange $b): int => strcmp($a->package, $b->package));

        return $changes;
    }

    /**
     * @return array<string, list<string>>
     */
    private function composer(string $content): array
    {
        $data = json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $versions = [];

        foreach (['packages', 'packages-dev'] as $section) {
            foreach (\is_array($data[$section] ?? null) ? $data[$section] : [] as $package) {
                if (
                    \is_array($package)
                    && \is_string($package['name'] ?? null)
                    && \is_string($package['version'] ?? null)
                ) {
                    $versions[$package['name']][] = $package['version'];
                }
            }
        }

        return $versions;
    }

    /**
     * Yarn Classic (`version "1.2.3"`) and Berry (`version: 1.2.3`): blocks starting with an unindented header that
     * lists the specifiers (`"@scope/pkg@npm:^1.0.0, pkg@npm:^2.0.0":`), the version being a field of the block.
     *
     * @return array<string, list<string>>
     */
    private function yarn(string $content): array
    {
        $versions = [];
        $name = null;

        foreach (explode("\n", $content) as $line) {
            $line = rtrim($line, "\r");
            if ('' === $line || '#' === $line[0]) {
                continue;
            }

            if (' ' !== $line[0]) {
                $name = $this->yarnPackageName($line);

                continue;
            }

            if (null !== $name && 1 === preg_match('/^ {2}version:? "?([^"\s]+)"?$/', $line, $m)) {
                $versions[$name][] = $m[1];
                $name = null;
            }
        }

        return $versions;
    }

    /**
     * Name of the package a block header describes, or null for what is not a package (`__metadata`, the workspaces).
     */
    private function yarnPackageName(string $header): ?string
    {
        if (
            !str_ends_with($header, ':')
            || str_starts_with($header, '__metadata')
            || str_contains($header, '@workspace:')
        ) {
            return null;
        }

        $specifier = trim(explode(',', substr($header, 0, -1))[0], " \t\"");
        // The name ends at the first `@` that is not the scope marker.
        $at = strpos($specifier, '@', 1);
        if (false === $at) {
            return null;
        }

        // An alias (`string-width-cjs@npm:string-width@^4.2.0`) installs another package: its real name is the one
        // after `npm:`.
        $range = substr($specifier, $at + 1);
        if (str_starts_with($range, 'npm:')) {
            $target = substr($range, 4);
            $targetAt = strpos($target, '@', 1);
            if (false !== $targetAt) {
                return substr($target, 0, $targetAt);
            }
        }

        return substr($specifier, 0, $at);
    }
}
