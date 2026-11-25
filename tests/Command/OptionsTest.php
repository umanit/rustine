<?php

declare(strict_types=1);

namespace Rustine\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\VerifyMode;
use Rustine\Command\InvalidOptionException;
use Rustine\Command\Options;
use Rustine\Plan\SeverityGroup;
use Rustine\Trivy\Ecosystem;

final class OptionsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('RUSTINE_VERIFY');
    }

    #[Test]
    public function itParsesSeverityGroups(): void
    {
        $this->assertSame([], Options::groups([]));
        $this->assertSame(
            [SeverityGroup::CriticalHigh, SeverityGroup::Others],
            Options::groups(['critical-high', 'others']),
        );
    }

    #[Test]
    public function itRejectsAnUnknownGroup(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown group "nope" (expected: critical-high, others).');

        Options::groups(['nope']);
    }

    #[Test]
    public function itRequiresExactlyOneGroup(): void
    {
        $this->assertSame([SeverityGroup::Others], Options::exactlyOneGroup(['others']));

        foreach ([[], ['others', 'critical-high']] as $names) {
            try {
                Options::exactlyOneGroup($names);
                $this->fail('An InvalidOptionException is expected.');
            } catch (InvalidOptionException $e) {
                $this->assertStringContainsString('exactly one --group', $e->getMessage());
            }
        }
    }

    #[Test]
    public function theVerificationFallsBackToTheVariableThenToFinal(): void
    {
        $this->assertSame(VerifyMode::Final, Options::verifyMode(null));

        putenv('RUSTINE_VERIFY=none');
        $this->assertSame(VerifyMode::None, Options::verifyMode(null));
        $this->assertSame(VerifyMode::Each, Options::verifyMode('each'));
    }

    #[Test]
    public function itRejectsAnUnknownVerification(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown verification "sometimes"');

        Options::verifyMode('sometimes');
    }

    #[Test]
    public function itParsesTheEcosystem(): void
    {
        $this->assertNull(Options::ecosystem(null));
        $this->assertSame(Ecosystem::Composer, Options::ecosystem('composer'));
    }

    #[Test]
    public function itRejectsAnUnknownEcosystem(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Unknown ecosystem "pip" (expected: yarn, composer).');

        Options::ecosystem('pip');
    }

    #[Test]
    public function itNormalizesTheMentions(): void
    {
        $this->assertSame(['alice', 'team/sec'], Options::mentions(['@alice', ' alice ', 'team/sec']));
        $this->assertSame([], Options::mentions([]));
    }

    #[Test]
    public function itRejectsAnInvalidMention(): void
    {
        $this->expectException(InvalidOptionException::class);
        $this->expectExceptionMessage('Invalid username "bob smith" for --notify.');

        Options::mentions(['bob smith']);
    }
}
