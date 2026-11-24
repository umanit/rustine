<?php

declare(strict_types=1);

namespace Rustine\Version;

final readonly class FixCandidate
{
    public function __construct(
        public Version $version,
        public bool $majorBump,
    ) {
    }
}
