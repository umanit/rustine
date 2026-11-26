<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Dependencies of `package.json` that point to a local directory or archive (`file:`, `link:`, `portal:`).
 *
 * Yarn cannot resolve the project without them, and they are often not part of the repository: a Symfony UX package
 * is declared as `file:vendor/symfony/ux-live-component/assets`, a path that only `composer install` fills.
 */
final class YarnLocalSources
{
    private const array FIELDS = ['dependencies', 'devDependencies', 'optionalDependencies', 'resolutions'];

    /**
     * What `package.json` declares with a local source whose target does not exist.
     *
     * @return list<string> `name (spec)`, in the order of `package.json`
     */
    public function missing(string $projectDir): array
    {
        return array_keys($this->scan($projectDir));
    }

    /**
     * Targets of the local sources that do not exist, made absolute.
     *
     * @return list<string> in the order of `package.json`, without duplicates
     */
    public function missingPaths(string $projectDir): array
    {
        return array_values(array_unique(array_values($this->scan($projectDir))));
    }

    /**
     * Targets of every local source, whether they exist or not, made absolute.
     *
     * @return array<string, string> absolute target by `name (spec)`
     */
    public function all(string $projectDir): array
    {
        return $this->scan($projectDir, false);
    }

    /**
     * @return array<string, string> absolute target by `name (spec)`
     */
    private function scan(string $projectDir, bool $onlyMissing = true): array
    {
        $content = @file_get_contents($projectDir . '/package.json');
        $data = false === $content ? null : json_decode($content, true);
        if (!\is_array($data)) {
            return [];
        }

        $missing = [];

        foreach (self::FIELDS as $field) {
            foreach (\is_array($data[$field] ?? null) ? $data[$field] : [] as $name => $spec) {
                if (
                    !\is_string($name)
                    || !\is_string($spec)
                    || 1 !== preg_match('/^(?:file|link|portal):([^#]+)/', $spec, $m)
                ) {
                    continue;
                }

                $path = str_starts_with($m[1], '/') ? $m[1] : $projectDir . '/' . $m[1];
                if (!$onlyMissing || !file_exists($path)) {
                    $missing[$name . ' (' . $spec . ')'] ??= $path;
                }
            }
        }

        return $missing;
    }
}
