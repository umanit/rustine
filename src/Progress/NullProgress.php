<?php

declare(strict_types=1);

namespace Rustine\Progress;

final readonly class NullProgress implements Progress
{
    public function step(string $message): void
    {
    }
}
