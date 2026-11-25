<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Apply\ComposerStrategy;
use Rustine\Apply\YarnRuntime;

/**
 * The commands that run Composer, Yarn and Node: each one from its option, then its environment variable.
 */
final readonly class ToolCommands
{
    public function __construct(
        public string $composer,
        public string $yarn,
        public string $node,
    ) {
    }

    /**
     * @throws \InvalidArgumentException when a command line cannot be used
     */
    public static function resolve(?string $composerBin, ?string $yarnBin, ?string $nodeBin): self
    {
        $tools = new self(
            Environment::string($composerBin, 'RUSTINE_COMPOSER_BIN', 'composer'),
            Environment::string($yarnBin, 'RUSTINE_YARN_BIN', 'yarn'),
            Environment::string($nodeBin, 'RUSTINE_NODE_BIN', 'node'),
        );

        ComposerStrategy::fromCommandLine($tools->composer);
        YarnRuntime::fromCommandLines($tools->yarn, $tools->node);

        return $tools;
    }
}
