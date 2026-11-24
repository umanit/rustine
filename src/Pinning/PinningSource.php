<?php

declare(strict_types=1);

namespace Rustine\Pinning;

enum PinningSource: string
{
    case Mise = 'mise.toml';
    case Volta = 'volta';
}
