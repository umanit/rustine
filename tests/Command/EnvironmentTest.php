<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Command\Environment;

final class EnvironmentTest extends TestCase
{
    private const string VARIABLE = 'RUSTINE_TEST_ENVIRONMENT_OPTION';

    protected function tearDown(): void
    {
        putenv(self::VARIABLE);
    }

    #[Test]
    public function theOptionWinsOverTheVariable(): void
    {
        putenv(self::VARIABLE . '=from-env');

        $this->assertSame('from-option', Environment::string('from-option', self::VARIABLE, 'default'));
    }

    #[Test]
    public function theVariableWinsOverTheDefault(): void
    {
        putenv(self::VARIABLE . '=from-env');

        $this->assertSame('from-env', Environment::string(null, self::VARIABLE, 'default'));
    }

    #[Test]
    public function aSwitchIsSetByItsOptionOrByATruthyVariable(): void
    {
        $this->assertFalse(Environment::flag(false, 'RUSTINE_TEST_UNSET_VARIABLE'));
        $this->assertTrue(Environment::flag(true, 'RUSTINE_TEST_UNSET_VARIABLE'));

        foreach (['1', 'true', 'TRUE', ' yes ', 'on'] as $value) {
            putenv(self::VARIABLE . '=' . $value);
            $this->assertTrue(Environment::flag(false, self::VARIABLE), $value);
        }

        foreach (['0', 'false', 'no', '', 'maybe'] as $value) {
            putenv(self::VARIABLE . '=' . $value);
            $this->assertFalse(Environment::flag(false, self::VARIABLE), $value);
        }
    }

    #[Test]
    public function blankValuesFallBackToTheDefault(): void
    {
        putenv(self::VARIABLE . '=');

        $this->assertSame('default', Environment::string('', self::VARIABLE, 'default'));
        $this->assertSame('default', Environment::string(null, 'RUSTINE_TEST_UNSET_VARIABLE', 'default'));
    }
}
