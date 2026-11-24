<?php

declare(strict_types=1);

namespace Rustine\Version;

/**
 * Lenient version, good enough to compare the Composer and npm versions published by Trivy
 * (`v` prefix, 1 to 4 numeric segments, pre-release suffix such as `-rc.6` or `-lts`).
 */
final readonly class Version
{
    /**
     * @param array{int, int, int, int} $numbers
     */
    private function __construct(
        public string $raw,
        private array $numbers,
        private string $suffix,
    ) {
    }

    public static function tryParse(string $raw): ?self
    {
        $clean = ltrim(trim($raw), 'vV');
        // Build metadata (`+...`) does not take part in ordering.
        $clean = (string) preg_replace('/\+.*$/', '', $clean);

        if (1 !== preg_match(
                '/^(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:[-._]?([0-9A-Za-z][0-9A-Za-z.\-]*))?$/',
                $clean,
                $m,
            )) {
            return null;
        }

        return new self(
            $raw,
            [(int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)],
            $m[5] ?? '',
        );
    }

    public function major(): int
    {
        return $this->numbers[0];
    }

    /**
     * Versions on the same line are compatible according to semver: same major, or same minor below 1.0
     * (`^0.3.5` does not allow 0.4.0, a breaking change).
     */
    public function isSameLineAs(self $other): bool
    {
        return $this->major() === $other->major()
            && (0 !== $this->major() || $this->numbers[1] === $other->numbers[1]);
    }

    public function isPrerelease(): bool
    {
        return '' !== $this->suffix;
    }

    public function compare(self $other): int
    {
        foreach ($this->numbers as $i => $n) {
            if ($n !== $other->numbers[$i]) {
                return $n <=> $other->numbers[$i];
            }
        }

        if ($this->suffix === $other->suffix) {
            return 0;
        }

        // A stable version is greater than any pre-release with the same number.
        if ('' === $this->suffix) {
            return 1;
        }
        if ('' === $other->suffix) {
            return -1;
        }

        return strnatcasecmp($this->suffix, $other->suffix) <=> 0;
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->compare($other) > 0;
    }
}
