<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Pinning\PinningSource;
use Rustine\Pinning\ToolPinning;

/**
 * Wraps commands so that they run with the tool versions pinned by the project.
 *
 * - `mise.toml`: `mise exec -- <command>` (mise reads the file itself);
 * - `volta` key: mise does not read it, so the pinned Node/Yarn versions are passed explicitly.
 */
final readonly class ToolEnvironment
{
    private const VOLTA_TOOLS = ['node', 'yarn'];

    public function __construct(
        private ToolPinning $pinning,
        private string $projectDir,
    ) {
    }

    /**
     * Environment for wrapped commands. The project is the one the CI is already running the code of, so its mise
     * configuration is trusted explicitly (mise otherwise refuses an untrusted `mise.toml` in non-interactive runs).
     *
     * @return array<string, string>
     */
    public function env(): array
    {
        return ['MISE_TRUSTED_CONFIG_PATHS' => $this->projectDir];
    }

    /**
     * @param list<string> $command
     *
     * @return list<string>
     */
    public function wrap(array $command): array
    {
        $specs = [];
        if (PinningSource::Volta === $this->pinning->source) {
            foreach (self::VOLTA_TOOLS as $tool) {
                $version = $this->pinning->version($tool);
                if (null !== $version) {
                    $specs[] = $tool . '@' . $version;
                }
            }
        }

        return ['mise', 'exec', ...$specs, '--', ...$command];
    }
}
