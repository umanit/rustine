<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\ComposerConstraint;

final class ComposerConstraintTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cases(): iterable
    {
        yield 'wildcard minor' => ['6.4.*', '6.5.2', '6.5.*'];
        yield 'wildcard major' => ['6.*', '6.5.2', '6.*'];
        yield 'tilde patch' => ['~6.3.0', '6.5.2', '~6.5.0'];
        yield 'tilde minor' => ['~6.3', '6.5.2', '~6.5'];
        yield 'caret' => ['^3.0', '3.4.3', '^3.4.3'];
        yield 'caret with v' => ['^v3.0', 'v3.4.3', '^3.4.3'];
        yield 'exact stays exact' => ['3.4.0', '3.4.3', '3.4.3'];
        yield 'at least' => ['>=3.4', '3.4.3', '>=3.4.3'];
        yield 'equals' => ['=3.4.0', '3.4.3', '=3.4.3'];
        yield 'or' => ['^3.4 || ~4.0', '4.1.2', '^4.1.2'];
        yield 'upper bound' => ['>=3.0 <3.4', '3.4.3', '^3.4.3'];
        yield 'short target' => ['^3.0', '3.5', '^3.5.0'];
        yield 'not a version' => ['^1.0', 'weird', '^weird'];
    }

    #[Test]
    #[DataProvider('cases')]
    public function itKeepsTheStyleOfTheConstraint(string $constraint, string $target, string $expected): void
    {
        $this->assertSame($expected, ComposerConstraint::raise($constraint, $target));
    }
}
