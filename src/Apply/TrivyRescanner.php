<?php

declare(strict_types=1);

namespace Rustine\Apply;

use Rustine\Trivy\TrivyReport;
use Rustine\Trivy\TrivyReportParser;
use Rustine\Trivy\TrivyScanner;

final readonly class TrivyRescanner implements VulnerabilityRescanner
{
    /**
     * @param list<string> $trivyArgs
     */
    public function __construct(
        private TrivyScanner $scanner,
        private TrivyReportParser $parser,
        private string $projectDir,
        private array $trivyArgs = [],
    ) {
    }

    public function rescan(): TrivyReport
    {
        $output = tempnam(sys_get_temp_dir(), 'rustine-rescan-');
        if (false === $output) {
            throw new \RuntimeException('Unable to create a temporary file for the Trivy report.');
        }

        try {
            $this->scanner->scan($this->projectDir, $output, $this->trivyArgs);

            return $this->parser->parseFile($output);
        } finally {
            @unlink($output);
        }
    }
}
