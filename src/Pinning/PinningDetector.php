<?php

declare(strict_types=1);

namespace Rustine\Pinning;

use Devium\Toml\Toml;

/**
 * Determines the tool versions pinned by a project.
 *
 * `mise.toml` (or `.mise.toml`) is authoritative when present; otherwise the `volta` key of `package.json`
 * (with `volta.extends` support); otherwise an explicit error.
 */
final class PinningDetector
{
    private const MISE_FILES = ['mise.toml', '.mise.toml'];

    public function detect(string $projectDir): ToolPinning
    {
        $projectDir = rtrim($projectDir, '/');

        foreach (self::MISE_FILES as $file) {
            if (is_file($projectDir . '/' . $file)) {
                return new ToolPinning(PinningSource::Mise, $this->readMise($projectDir . '/' . $file), $file);
            }
        }

        $packageJson = $projectDir . '/package.json';
        if (is_file($packageJson)) {
            $tools = $this->readVolta($packageJson, []);
            if (null !== $tools) {
                return new ToolPinning(PinningSource::Volta, $tools, 'package.json');
            }
        }

        throw new PinningException(
            sprintf(
                'No tool pinning found in "%s": neither mise.toml nor a "volta" key in package.json. '
                . 'The bot refuses to guess Node/Yarn versions.',
                $projectDir,
            ),
        );
    }

    /**
     * @return array<string, string>
     */
    private function readMise(string $path): array
    {
        try {
            $data = Toml::decode((string) file_get_contents($path), true);
        } catch (\Throwable $e) {
            throw new PinningException(sprintf('Unable to read "%s": %s', $path, $e->getMessage()), 0, $e);
        }

        $tools = [];
        $raw = \is_array($data) && \is_array($data['tools'] ?? null) ? $data['tools'] : [];
        foreach ($raw as $name => $spec) {
            // Forms accepted by mise: "20", ["20", "18"] (the first one wins) or { version = "20", ... }.
            if (\is_array($spec) && \array_key_exists('version', $spec)) {
                $spec = $spec['version'];
            } elseif (\is_array($spec)) {
                $spec = $spec[0] ?? null;
            }

            if (\is_string($spec) || \is_int($spec) || \is_float($spec)) {
                $tools[(string) $name] = (string) $spec;
            }
        }

        return $tools;
    }

    /**
     * @param list<string> $visited paths already read, to detect `extends` cycles
     *
     * @return array<string, string>|null null if the file has no `volta` key
     */
    private function readVolta(string $packageJson, array $visited): ?array
    {
        $real = realpath($packageJson);
        if (false === $real) {
            throw new PinningException(sprintf('Fichier "%s" introuvable (volta.extends)', $packageJson));
        }
        if (\in_array($real, $visited, true)) {
            throw new PinningException(sprintf('Cycle detected in volta.extends around "%s".', $real));
        }

        try {
            $data = json_decode((string) file_get_contents($real), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PinningException(sprintf('"%s" is not valid JSON: %s', $real, $e->getMessage()), 0, $e);
        }

        $volta = \is_array($data) ? ($data['volta'] ?? null) : null;
        if (!\is_array($volta)) {
            return null;
        }

        $tools = [];
        if (\is_string($volta['extends'] ?? null)) {
            $parentPath = $this->resolveExtends(\dirname($real), $volta['extends']);
            $tools = $this->readVolta($parentPath, [...$visited, $real])
                ?? throw new PinningException(
                    sprintf('"%s" (volta.extends) has no "volta" key.', $parentPath),
                );
        }

        // Values from the current file override those from the parent.
        foreach ($volta as $tool => $version) {
            if ('extends' !== $tool && \is_string($version)) {
                $tools[(string) $tool] = $version;
            }
        }

        return $tools;
    }

    private function resolveExtends(string $baseDir, string $extends): string
    {
        $path = str_starts_with($extends, '/') ? $extends : $baseDir . '/' . $extends;

        return is_dir($path) ? $path . '/package.json' : $path;
    }
}
