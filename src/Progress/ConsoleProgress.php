<?php

declare(strict_types=1);

namespace Rustine\Progress;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One line per step, with the seconds elapsed since the start of the run, so that a slow step is easy to spot.
 */
final readonly class ConsoleProgress implements Progress
{
    private int $start;

    public function __construct(private OutputInterface $output)
    {
        $this->start = hrtime(true);
    }

    public function step(string $message): void
    {
        $seconds = intdiv(hrtime(true) - $this->start, 1000000000);

        $this->output->writeln(sprintf('  [%3ds] %s', $seconds, $message));
    }
}
