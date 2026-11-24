<?php

declare(strict_types=1);

namespace Rustine\Command;

use Rustine\Plan\PlanFormatter;
use Rustine\Plan\Planner;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\InvalidTrivyReportException;
use Rustine\Trivy\TrivyReportParser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'plan', description: 'Shows which packages would be fixed, grouped by merge request')]
final class PlanCommand
{
    /**
     * @param list<string> $group
     */
    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Trivy JSON report')]
        string $report = 'trivy-report.json',
        #[Option(description: 'Severity group to process: critical-high or others (repeatable, default: all)')]
        array $group = [],
        #[Option(description: 'Output format: text or json')]
        string $format = 'text',
    ): int {
        $groups = [];
        foreach ($group as $name) {
            $parsed = SeverityGroup::tryFrom($name);
            if (null === $parsed) {
                $io->error(sprintf('Unknown group "%s" (expected: %s).', $name, implode(', ', array_column(SeverityGroup::cases(), 'value'))));

                return Command::INVALID;
            }
            $groups[] = $parsed;
        }

        if (!\in_array($format, ['text', 'json'], true)) {
            $io->error(sprintf('Unknown format "%s" (expected: text, json).', $format));

            return Command::INVALID;
        }

        try {
            $parsedReport = (new TrivyReportParser())->parseFile($report);
        } catch (InvalidTrivyReportException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        $plans = (new Planner())->plan($parsedReport, [] === $groups ? null : $groups);
        $formatter = new PlanFormatter();

        if ('json' === $format) {
            $io->writeln(json_encode($formatter->toArray($plans), \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
        } else {
            $io->write($formatter->toText($plans));
        }

        return Command::SUCCESS;
    }
}
