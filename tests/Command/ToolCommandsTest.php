<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Command\ToolCommands;

final class ToolCommandsTest extends TestCase
{
    private const array VARIABLES = ['RUSTINE_COMPOSER_BIN', 'RUSTINE_YARN_BIN', 'RUSTINE_NODE_BIN'];

    protected function tearDown(): void
    {
        foreach (self::VARIABLES as $variable) {
            putenv($variable);
        }
    }

    #[Test]
    public function itDefaultsToTheToolsOfThePath(): void
    {
        $tools = ToolCommands::resolve(null, null, null);

        $this->assertSame('composer', $tools->composer);
        $this->assertSame('yarn', $tools->yarn);
        $this->assertSame('node', $tools->node);
    }

    #[Test]
    public function theOptionWinsOverTheVariable(): void
    {
        putenv('RUSTINE_COMPOSER_BIN=/env/composer');
        putenv('RUSTINE_YARN_BIN=/env/yarn');

        $tools = ToolCommands::resolve('/opt/composer2', null, '/opt/node20/bin/node');

        $this->assertSame('/opt/composer2', $tools->composer);
        $this->assertSame('/env/yarn', $tools->yarn);
        $this->assertSame('/opt/node20/bin/node', $tools->node);
    }

    #[Test]
    public function itRejectsACommandLineThatCannotBeUsed(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ToolCommands::resolve('   ', null, null);
    }
}
