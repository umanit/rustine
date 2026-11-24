<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Command\Environment;

final class EnvironmentTest extends TestCase
{
    private const VARIABLE = 'RUSTINE_TEST_ENVIRONMENT_OPTION';

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);
    }

    #[Test]
    public function theOptionWinsOverTheVariable(): void
    {
        putenv(self::VARIABLE . '=from-env');

        self::assertSame('from-option', Environment::string('from-option', self::VARIABLE, 'default'));
    }

    #[Test]
    public function theVariableWinsOverTheDefault(): void
    {
        putenv(self::VARIABLE . '=from-env');

        self::assertSame('from-env', Environment::string(null, self::VARIABLE, 'default'));
    }

    #[Test]
    public function blankValuesFallBackToTheDefault(): void
    {
        putenv(self::VARIABLE . '=');

        self::assertSame('default', Environment::string('', self::VARIABLE, 'default'));
        self::assertSame('default', Environment::string(null, 'RUSTINE_TEST_UNSET_VARIABLE', 'default'));
    }
}
