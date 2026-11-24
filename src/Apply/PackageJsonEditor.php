<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Minimal edits of `package.json` that keep its formatting (indentation, final newline, key order).
 */
final class PackageJsonEditor
{
    /**
     * Adds or updates `resolutions[$package]`. An existing entry for the package is updated, never duplicated.
     */
    public function setResolution(string $path, string $package, string $range): void
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

        if (!($data->resolutions ?? null) instanceof \stdClass) {
            $data->resolutions = new \stdClass();
        }
        $data->resolutions->{$package} = $range;

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

        foreach (['dependencies', 'devDependencies', 'optionalDependencies', 'peerDependencies'] as $field) {
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
