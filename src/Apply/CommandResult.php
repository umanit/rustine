<?php

declare(strict_types=1);

namespace Rustine\Apply;

final readonly class CommandResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {
    }

    public function isSuccessful(): bool
    {
        return 0 === $this->exitCode;
    }
}
