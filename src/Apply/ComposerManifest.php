<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Read-only view of `composer.json`: which packages the project requires itself.
 */
final readonly class ComposerManifest
{
    private const FIELDS = ['require', 'require-dev'];

    /**
     * @param array<string, array{field: string, constraint: string}> $declared
     */
    private function __construct(private array $declared)
    {
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

        return new self($declared);
    }

    /**
     * @return array{field: string, constraint: string}|null null if the project does not require the package itself
     */
    public function declaration(string $package): ?array
    {
        return $this->declared[$package] ?? null;
    }
}
