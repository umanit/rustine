<?php

declare(strict_types=1);

namespace Rustine\Version;

final class FixedVersionSelector
{
    /**
     * Picks, for one CVE, the smallest fixed version strictly greater than the installed one.
     *
     * Preference order: same major (same minor below 1.0), then stable version, then smallest version.
     * Unparsable versions (e.g. `dev-main`) are ignored.
     *
     * @param list<string> $fixedVersions
     */
    public function select(Version $installed, array $fixedVersions): ?FixCandidate
    {
        $candidates = [];
        foreach ($fixedVersions as $raw) {
            $version = Version::tryParse($raw);
            if (null === $version || !$version->isGreaterThan($installed)) {
                continue;
            }

            $candidates[] = new FixCandidate($version, !$version->isSameLineAs($installed));
        }

        if ([] === $candidates) {
            return null;
        }

        usort($candidates, static function (FixCandidate $a, FixCandidate $b): int {
            return [$a->majorBump, $a->version->isPrerelease()] <=> [$b->majorBump, $b->version->isPrerelease()]
                ?: $a->version->compare($b->version);
        });

        return $candidates[0];
    }
}
