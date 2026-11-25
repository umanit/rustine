<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Raises a `composer.json` constraint to let a fixed version in, keeping the style the project chose: `6.4.*` becomes
 * `6.5.*`, `~6.3.0` becomes `~6.5.0`, `1.2.3` stays an exact version, `^1.2` stays a caret.
 *
 * A constraint with several parts (`||`, `,`, spaces, `<`) is not guessed: it becomes `^<version>`.
 */
final class ComposerConstraint
{
    public static function raise(string $constraint, string $target): string
    {
        $target = ltrim(trim($target), 'vV');
        if (1 !== preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $target, $t)) {
            return '^' . $target;
        }
        $major = $t[1];
        $minor = $t[2] ?? '0';
        $patch = $t[3] ?? '0';

        $constraint = trim($constraint);

        // `6.*`, `6.4.*`
        if (1 === preg_match('/^v?(\d+)(\.\d+)?\.\*$/', $constraint, $m)) {
            return '' === ($m[2] ?? '') ? $major . '.*' : $major . '.' . $minor . '.*';
        }

        if (1 === preg_match('/^(\^|~|>=|=)?\s*v?(\d+(?:\.\d+){0,2})$/', $constraint, $m)) {
            $prefix = $m[1];
            $parts = substr_count($m[2], '.') + 1;

            return match ($prefix) {
                '~' => '~' . implode('.', \array_slice([$major, $minor, '0'], 0, $parts)),
                '^', '>=', '=', '' => $prefix . $major . '.' . $minor . '.' . $patch,
            };
        }

        return '^' . $major . '.' . $minor . '.' . $patch;
    }
}
