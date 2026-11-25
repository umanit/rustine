<?php

declare(strict_types=1);

namespace Rustine\Progress;

/**
 * Tells what a long operation is doing. A run spends most of its time in Composer, Yarn, git and Trivy, which print
 * nothing of their own: this is what the job log shows with `-v`.
 */
interface Progress
{
    public function step(string $message): void;
}
