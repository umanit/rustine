<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply\Support;

use Rustine\Apply\VulnerabilityRescanner;
use Rustine\Trivy\TrivyReport;

final class QueueRescanner implements VulnerabilityRescanner
{
    /** @var list<TrivyReport> */
    private array $reports;
    public int $calls = 0;

    public function __construct(TrivyReport ...$reports)
    {
        $this->reports = array_values($reports);
    }

    public function rescan(): TrivyReport
    {
        ++$this->calls;

        return array_shift($this->reports) ?? throw new \LogicException('No more fake reports.');
    }
}
