<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Symfony\Component\Filesystem\Filesystem;

/**
 * Minimal edits of `package.json`: the text around the edited value is left byte for byte as it was (inline arrays,
 * spacing, key order, final newline). A shape the text edit does not handle falls back to re-encoding the JSON, which
 * keeps the indentation, the final newline and the key order only.
 */
final readonly class PackageJsonEditor
{
    private const array DEPENDENCY_FIELDS = [
        'dependencies',
        'devDependencies',
        'optionalDependencies',
        'peerDependencies',
    ];

    public function __construct(
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * Adds or updates a `resolutions` entry. An existing entry for the same package is updated, never duplicated:
     * `pkg` and `**\/pkg` (Yarn Classic) designate the same package, so either one is reused if present.
     */
    public function setResolution(string $path, string $key, string $range): void
    {
        $this->edit($path, function (string $content, \stdClass $data) use ($key, $range): ?string {
            $base = str_starts_with($key, '**/') ? substr($key, 3) : $key;
            $resolutions = $data->resolutions ?? null;
            if ($resolutions instanceof \stdClass) {
                foreach ([$key, $base, '**/' . $base] as $candidate) {
                    if (isset($resolutions->{$candidate})) {
                        $key = $candidate;

                        break;
                    }
                }
            }

            return $this->setMember($content, ['resolutions'], $key, $range);
        }, static function (\stdClass $data) use ($key, $range): void {
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
        $this->edit($path, function (string $content, \stdClass $data) use ($package, $range): ?string {
            foreach (self::DEPENDENCY_FIELDS as $field) {
                $block = $data->{$field} ?? null;
                if ($block instanceof \stdClass && isset($block->{$package})) {
                    $content = $this->setMember($content, [$field], $package, $range);
                    if (null === $content) {
                        return null;
                    }
                }
            }

            return $content;
        }, static function (\stdClass $data) use ($package, $range): void {
            foreach (self::DEPENDENCY_FIELDS as $field) {
                $block = $data->{$field} ?? null;
                if ($block instanceof \stdClass && isset($block->{$package})) {
                    $block->{$package} = $range;
                }
            }
        });
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

    /**
     * @param \Closure(string, \stdClass): ?string $textEdit new text, or null when the text cannot be edited in place
     * @param \Closure(\stdClass): void            $change   the same change, on the decoded data (fallback)
     */
    private function edit(string $path, \Closure $textEdit, \Closure $change): void
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new \RuntimeException(sprintf('Unable to read "%s".', $path));
        }

        try {
            $data = json_decode($content, false, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $jsonException) {
            throw new \RuntimeException(
                sprintf('"%s" is not valid JSON: %s', $path, $jsonException->getMessage()),
                0,
                $jsonException,
            );
        }

        if (!$data instanceof \stdClass) {
            throw new \RuntimeException(sprintf('"%s" must contain a JSON object.', $path));
        }

        $edited = $textEdit($content, clone $data);
        if (null !== $edited && JsonSpans::parse($edited) instanceof JsonObject) {
            $this->filesystem->dumpFile($path, $edited);

            return;
        }

        $change($data);

        $json = json_encode(
            $data,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );
        $indent = $this->detectIndent($content);
        if ('    ' !== $indent) {
            // PHP always indents with 4 spaces; JSON strings cannot contain raw newlines, so only structure matches.
            $json = (string) preg_replace_callback(
                '/^( {4})+/m',
                static fn(array $m): string => str_repeat($indent, intdiv(\strlen($m[0]), 4)),
                $json,
            );
        }

        $this->filesystem->dumpFile($path, $json . (str_ends_with($content, "\n") ? "\n" : ''));
    }

    /**
     * Sets `$object[$key] = $value` (a string) inside the object found at `$objectPath` from the root, in the text
     * itself. The object is created at the end of the root when it is missing. Null when the layout is not one the
     * edit understands (inline objects, a value that is not a string…).
     *
     * @param list<string> $objectPath
     */
    private function setMember(string $content, array $objectPath, string $key, string $value): ?string
    {
        $root = JsonSpans::parse($content);
        if (!$root instanceof JsonObject) {
            return null;
        }

        $eol = str_contains($content, "\r\n") ? "\r\n" : "\n";
        $encoded = json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $encodedKey = json_encode($key, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);

        $object = $root;

        foreach ($objectPath as $name) {
            $next = $object->member($name);
            if (!$next instanceof JsonMember) {
                return $this->addMember(
                    $content,
                    $root,
                    $name,
                    $eol,
                    static fn(
                        string $indent,
                        string $unit,
                        string $colon,
                    ): string => '{' . $eol . $indent . $unit . $encodedKey . $colon . $encoded . $eol . $indent . '}',
                );
            }
            if (!$next->value instanceof JsonObject) {
                return null;
            }
            $object = $next->value;
        }

        $existing = $object->member($key);
        if ($existing instanceof JsonMember) {
            return $existing->value instanceof JsonScalar && $existing->value->isString
                ? substr_replace($content, $encoded, $existing->valueStart, $existing->valueEnd - $existing->valueStart)
                : null;
        }

        return $this->addMember($content, $object, $key, $eol, static fn(): string => $encoded);
    }

    /**
     * Appends a member after the last one of `$object`, laid out like it. `$render(indent, unit, colon)` gives the
     * value.
     *
     * @param \Closure(string, string, string): string $render
     */
    private function addMember(string $content, JsonObject $object, string $key, string $eol, \Closure $render): ?string
    {
        $last = $object->members[array_key_last($object->members)] ?? null;
        if (null === $last) {
            return null;
        }

        $lineStart = strrpos(substr($content, 0, $last->keyStart), "\n");
        $indent = false === $lineStart ? '' : substr($content, $lineStart + 1, $last->keyStart - $lineStart - 1);
        if (false === $lineStart || '' !== trim($indent)) {
            return null; // the members share a line
        }

        $colon = $this->colonStyle($content, $last);
        $unit = $this->detectIndent($content);
        $encodedKey = json_encode($key, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $text = ',' . $eol . $indent . $encodedKey . $colon . $render($indent, $unit, $colon);

        return substr_replace($content, $text, $last->valueEnd, 0);
    }

    /**
     * The text between a key and its value (`: ` or ` : `), to write the new member the same way.
     */
    private function colonStyle(string $content, JsonMember $member): string
    {
        return substr($content, $member->keyEnd, $member->valueStart - $member->keyEnd);
    }

    private function detectIndent(string $content): string
    {
        if (1 === preg_match('/^([ \t]+)"/m', $content, $m)) {
            return $m[1];
        }

        return '  ';
    }
}
