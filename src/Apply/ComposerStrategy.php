<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Plan\PackageFix;
use Rustine\Trivy\Ecosystem;

/**
 * Fix ladder for Composer, cheapest and cleanest first.
 *
 * Only `composer.lock` and `composer.json` are touched: nothing is installed (`--no-install`) and neither scripts nor
 * plugins run (Symfony Flex would otherwise apply recipes and change files that are not ours).
 */
final readonly class ComposerStrategy implements FixStrategy
{
    private const FLAGS = ['--no-install', '--no-interaction', '--no-scripts', '--no-plugins', '--no-progress'];

    /**
     * @param non-empty-list<string> $composer command that runs Composer, e.g. `['composer']` or
     *                                         `['/usr/bin/php8.1', '/usr/local/bin/composer2']`
     */
    public function __construct(private array $composer = ['composer'])
    {
    }

    /**
     * @param string $commandLine words separated by spaces (no quoting: paths with spaces are not supported)
     *
     * @throws \InvalidArgumentException when empty
     */
    public static function fromCommandLine(string $commandLine): self
    {
        return new self(CommandLine::split($commandLine, 'Composer'));
    }

    /**
     * @return non-empty-list<string>
     */
    public function command(): array
    {
        return $this->composer;
    }

    public function ecosystem(): Ecosystem
    {
        return Ecosystem::Composer;
    }

    public function files(): array
    {
        return ['composer.json', 'composer.lock'];
    }

    public function unsupportedReason(PackageFix $fix, string $projectDir): ?string
    {
        $declaration = ComposerManifest::read($projectDir . '/composer.json')->declaration($fix->packageName);
        if (null === $declaration || !$this->isBranchOrAliasConstraint($declaration['constraint'])) {
            return null;
        }

        return sprintf(
            'required as "%s" in composer.json, a branch/alias constraint rather than a version range: '
            . 'update this requirement by hand to a version that fixes %s',
            $declaration['constraint'],
            implode(', ', $fix->fixedIds),
        );
    }

    public function attempts(PackageFix $fix, string $projectDir): array
    {
        if (null === $fix->targetVersion) {
            return [];
        }

        $name = $fix->packageName;
        $attempts = [
            new Attempt(AttemptKind::LockfileRefresh, [[...$this->composer, 'update', $name, '--with-all-dependencies', ...self::FLAGS]]),
        ];

        // The constraint of a direct requirement may forbid the fixed version: raise it, within the same major.
        $declaration = ComposerManifest::read($projectDir . '/composer.json')->declaration($name);
        if (null !== $declaration) {
            $attempts[] = new Attempt(AttemptKind::DirectBump, [[
                ...$this->composer, 'require', $name . ':^' . ltrim($fix->targetVersion, 'v'),
                ...('require-dev' === $declaration['field'] ? ['--dev'] : []),
                '--update-with-all-dependencies', ...self::FLAGS,
            ]]);
        }

        return $attempts;
    }

    public function diagnosis(PackageFix $fix): ?array
    {
        return null === $fix->targetVersion ? null : [...$this->composer, 'why-not', $fix->packageName, $fix->targetVersion, '--locked'];
    }

    public function mutatingEnv(): array
    {
        return ['COMPOSER_NO_INTERACTION' => '1'];
    }

    public function checkCommand(): array
    {
        // Also fails when `composer.lock` is out of date with `composer.json`.
        return [...$this->composer, 'validate', '--no-check-publish', '--no-interaction'];
    }

    public function checkEnv(): array
    {
        return [];
    }

    private function isBranchOrAliasConstraint(string $constraint): bool
    {
        return 1 === preg_match('/^dev-|#|\sas\s|@dev/i', $constraint);
    }
}
