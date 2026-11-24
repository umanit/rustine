<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Minimal edits of `package.json` that keep its formatting (indentation, final newline, key order).
 */
final class PackageJsonEditor
{
    private const DEPENDENCY_FIELDS = ['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'];

    /**
     * Adds or updates a `resolutions` entry. An existing entry for the same package is updated, never duplicated:
     * `pkg` and `**\/pkg` (Yarn Classic) designate the same package, so either one is reused if present.
     */
    public function setResolution(string $path, string $key, string $range): void
    {
        $this->edit($path, static function (\stdClass $data) use ($key, $range): void {
            if (!($data->resolutions ?? null) instanceof \stdClass) {
                $data->resolutions = new \stdClass();
            }

            $base = str_starts_with($key, '**/') ? substr($key, 3) : $key;
            foreach ([$key, $base, '**/' . $base] as $candidate) {
                if (isset($data->resolutions->{$candidate})) {
                    $key = $candidate;
                    break;
                }
            }

            $data->resolutions->{$key} = $range;
        });
    }

    /**
     * Changes the range of a dependency in place, in the field(s) where it is declared (order and formatting kept).
     */
    public function setDependencyRange(string $path, string $package, string $range): void
    {
        $this->edit($path, static function (\stdClass $data) use ($package, $range): void {
            foreach (self::DEPENDENCY_FIELDS as $field) {
                $block = $data->{$field} ?? null;
                if ($block instanceof \stdClass && isset($block->{$package})) {
                    $block->{$package} = $range;
                }
            }
        });
    }

    /**
     * @param \Closure(\stdClass): void $change
     */
    private function edit(string $path, \Closure $change): void
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        try {
            $data = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(sprintf('"%s" is not valid JSON: %s', $path, $e->getMessage()), 0, $e);
        }
        if (!$data instanceof \stdClass) {
            throw new \RuntimeException(sprintf('"%s" must contain a JSON object.', $path));
        }

        $change($data);

        $json = json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $indent = $this->detectIndent($content);
        if ('    ' !== $indent) {
            // PHP always indents with 4 spaces; JSON strings cannot contain raw newlines, so only structure matches.
            $json = (string) preg_replace_callback(
                '/^( {4})+/m',
                static fn (array $m): string => str_repeat($indent, intdiv(\strlen($m[0]), 4)),
                $json,
            );
        }

        file_put_contents($path, $json . (str_ends_with($content, "\n") ? "\n" : ''));
    }

    /**
     * Specification declared for a package in `dependencies`, `devDependencies`, `optionalDependencies` or
     * `peerDependencies` (first match), or null if the package is not declared directly.
     */
    public function declaredSpec(string $path, string $package): ?string
    {
        $content = @file_get_contents($path);
        $data = false === $content ? null : json_decode($content, false);
        if (!$data instanceof \stdClass) {
            return null;
        }

        foreach (self::DEPENDENCY_FIELDS as $field) {
            $block = $data->{$field} ?? null;
            if ($block instanceof \stdClass && isset($block->{$package}) && \is_string($block->{$package})) {
                return $block->{$package};
            }
        }

        return null;
    }

    private function detectIndent(string $content): string
    {
        if (1 === preg_match('/^([ \t]+)"/m', $content, $m)) {
            return $m[1];
        }

        return '  ';
    }
}
