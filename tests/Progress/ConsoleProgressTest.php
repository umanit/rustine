<?php

declare(strict_types=1);

namespace Rustine\Tests\Progress;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Progress\ConsoleProgress;
use Rustine\Progress\NullProgress;
use Symfony\Component\Console\Output\BufferedOutput;

final class ConsoleProgressTest extends TestCase
{
    #[Test]
    public function itWritesOneLineWithTheElapsedSeconds(): void
    {
        $output = new BufferedOutput();
        $progress = new ConsoleProgress($output);

        $progress->step('Fetching the branches');
        $progress->step('Pushing the branch');

        $this->assertMatchesRegularExpression(
            '/^  \[\s*0s\] Fetching the branches\n  \[\s*0s\] Pushing the branch\n$/',
            $output->fetch(),
        );
    }

    #[Test]
    public function theNullProgressWritesNothing(): void
    {
        new NullProgress()->step('anything');

        $this->expectNotToPerformAssertions();
    }
}
