<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Read-only view of `composer.json`: which packages the project requires itself, and the few settings that change how
 * a resolution behaves.
 */
final readonly class ComposerManifest
{
    private const array FIELDS = ['require', 'require-dev'];

    /**
     * @param array<string, array{field: string, constraint: string}> $declared
     */
    private function __construct(
        private array $declared,
        private ?string $symfonyRequire = null,
        private string $vendorDir = 'vendor',
    ) {
    }

    public static function read(string $path): self
    {
        $content = @file_get_contents($path);
        $data = false === $content ? null : json_decode($content, true);
        if (!\is_array($data)) {
            return new self([]);
        }

        $declared = [];

        foreach (self::FIELDS as $field) {
            $block = $data[$field] ?? null;
            if (!\is_array($block)) {
                continue;
            }

            foreach ($block as $name => $constraint) {
                if (\is_string($name) && \is_string($constraint) && !isset($declared[$name])) {
                    $declared[$name] = ['field' => $field, 'constraint' => $constraint];
                }
            }
        }

        $symfony = \is_array($data['extra'] ?? null) && \is_array(
            $data['extra']['symfony'] ?? null,
        ) ? $data['extra']['symfony'] : [];
        $config = \is_array($data['config'] ?? null) ? $data['config'] : [];

        return new self(
            $declared,
            \is_string($symfony['require'] ?? null) && '' !== $symfony['require'] ? $symfony['require'] : null,
            \is_string(
                $config['vendor-dir'] ?? null,
            ) && '' !== $config['vendor-dir'] ? $config['vendor-dir'] : 'vendor',
        );
    }

    /**
     * `extra.symfony.require`: the Symfony version every `symfony/*` component must follow. Only Symfony Flex enforces
     * it, while it resolves.
     */
    public function symfonyRequire(): ?string
    {
        return $this->symfonyRequire;
    }

    /**
     * `config.vendor-dir`, relative to the project unless absolute.
     */
    public function vendorDir(): string
    {
        return $this->vendorDir;
    }

    /**
     * @return array{field: string, constraint: string}|null null if the project does not require the package itself
     */
    public function declaration(string $package): ?array
    {
        return $this->declared[$package] ?? null;
    }
}
