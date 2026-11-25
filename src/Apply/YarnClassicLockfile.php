<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Edits a Yarn Classic (v1) `yarn.lock`.
 *
 * Yarn Classic cannot refresh a transitive dependency (`yarn upgrade <pkg>` ignores them), but it resolves again any
 * entry missing from the lockfile to the highest version each declared range allows. Removing the entries is thus
 * the way to refresh. The lockfile is parsed, never patched with a regex over the whole file.
 *
 * Format: blocks separated by a blank line, each starting with an unindented header listing the specifiers
 * (`"@scope/pkg@^1.0.0", pkg@^2.0.0:`) followed by indented fields.
 */
final class YarnClassicLockfile
{
    public function withoutPackage(string $content, string $package): string
    {
        // A lockfile checked out with CRLF line endings: edit it as LF, then give the endings back.
        if (str_contains($content, "\r\n")) {
            return str_replace("\n", "\r\n", $this->withoutPackage(str_replace("\r\n", "\n", $content), $package));
        }

        $kept = [];

        foreach (explode("\n\n", $content) as $block) {
            if (!$this->isBlockOf($block, $package)) {
                $kept[] = $block;
            }
        }

        $result = implode("\n\n", $kept);
        if (str_ends_with($content, "\n") && !str_ends_with($result, "\n")) {
            $result .= "\n";
        }

        return $result;
    }

    private function isBlockOf(string $block, string $package): bool
    {
        $header = strtok(ltrim($block, "\n"), "\n");
        if (false === $header || '#' === $header[0] || ' ' === $header[0] || !str_ends_with($header, ':')) {
            return false;
        }

        foreach (explode(',', substr($header, 0, -1)) as $specifier) {
            $specifier = trim(trim($specifier), '"');
            // The name ends at the last `@` that is not the scope marker.
            $at = strrpos($specifier, '@');
            if (false !== $at && $at > 0 && substr($specifier, 0, $at) === $package) {
                return true;
            }
        }

        return false;
    }
}
