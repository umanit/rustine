<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Command\Environment;
use Rustine\Command\InvalidOptionException;

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

    #[Test]
    public function aListComesFromItsOptionThenFromTheCommaSeparatedVariable(): void
    {
        $this->assertSame(['a'], Environment::list(['a'], self::VARIABLE));
        $this->assertSame([], Environment::list([], 'RUSTINE_TEST_UNSET_VARIABLE'));

        putenv(self::VARIABLE . '=alice, bob ,,carol');
        $this->assertSame(['alice', 'bob', 'carol'], Environment::list([], self::VARIABLE));
        $this->assertSame(['x'], Environment::list(['x'], self::VARIABLE));
    }

    #[Test]
    public function aListCanBeSplitOnAnotherSeparator(): void
    {
        putenv(self::VARIABLE . '=--severity=HIGH,CRITICAL   --skip-db-update');

        $this->assertSame(
            ['--severity=HIGH,CRITICAL', '--skip-db-update'],
            Environment::list([], self::VARIABLE, '\s+'),
        );
    }

    #[Test]
    public function anOptionalStringIsNullWhenNothingIsSet(): void
    {
        $this->assertNull(Environment::optionalString(null, 'RUSTINE_TEST_UNSET_VARIABLE'));
        $this->assertSame('opt', Environment::optionalString('opt', self::VARIABLE));

        putenv(self::VARIABLE . '=from-env');
        $this->assertSame('from-env', Environment::optionalString(null, self::VARIABLE));
    }

    #[Test]
    public function anIntegerComesFromItsOptionThenItsVariableThenItsDefault(): void
    {
        $this->assertSame(5, Environment::int(5, self::VARIABLE, 30));
        $this->assertSame(30, Environment::int(null, 'RUSTINE_TEST_UNSET_VARIABLE', 30));
        $this->assertNull(Environment::int(null, 'RUSTINE_TEST_UNSET_VARIABLE', null));

        putenv(self::VARIABLE . '=0');
        $this->assertSame(0, Environment::int(null, self::VARIABLE, 30));
    }

    #[Test]
    public function anIntegerVariableMustBeANonNegativeInteger(): void
    {
        putenv(self::VARIABLE . '=soon');

        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage(self::VARIABLE);

        Environment::int(null, self::VARIABLE, 30);
    }

    #[Test]
    public function aListOfTheFileWinsOverTheVariableButNotOverTheOption(): void
    {
        putenv(self::VARIABLE . '=from-env');

        $this->assertSame(['from-file'], Environment::list([], self::VARIABLE, ',', ['from-file']));
        $this->assertSame([], Environment::list([], self::VARIABLE, ',', []));
        $this->assertSame(['from-option'], Environment::list(['from-option'], self::VARIABLE, ',', ['from-file']));
    }

    #[Test]
    public function aSwitchOfTheFileWinsOverTheVariableButNotOverTheOption(): void
    {
        putenv(self::VARIABLE . '=1');

        $this->assertFalse(Environment::flag(false, self::VARIABLE, false));
        $this->assertTrue(Environment::flag(true, self::VARIABLE, false));
        $this->assertTrue(Environment::flag(false, self::VARIABLE));
    }
}
