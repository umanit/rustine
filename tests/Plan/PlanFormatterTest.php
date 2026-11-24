<?php

declare(strict_types=1);

namespace Rustine\Tests\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Plan\PlanFormatter;
use Rustine\Plan\Planner;
use Rustine\Trivy\TrivyReportParser;

final class PlanFormatterTest extends TestCase
{
    #[Test]
    public function itRendersATextPlan(): void
    {
        $text = (new PlanFormatter())->toText($this->plans());

        self::assertStringContainsString('security/rustine-yarn-critical-high — 4 fixable, 1 to report', $text);
        self::assertStringContainsString('✔ brace-expansion@1.1.12 → 1.1.21 [HIGH, transitive, 7 CVE]', $text);
        self::assertStringContainsString('✘ braces@3.0.3 [HIGH, transitive, 1 CVE] — no fix available', $text);
        self::assertStringContainsString('security/rustine-yarn-others', $text);
    }

    #[Test]
    public function itRendersAnEmptyPlan(): void
    {
        self::assertSame("No vulnerability to fix.\n", (new PlanFormatter())->toText([]));
    }

    #[Test]
    public function itExportsAJsonSerializablePlan(): void
    {
        $data = (new PlanFormatter())->toArray($this->plans());

        self::assertSame('security/rustine-yarn-critical-high', $data[0]['branch']);
        self::assertSame('1.1.21', $data[0]['fixes'][1]['target'] ?? null);
        self::assertNotFalse(json_encode($data));
    }

    /**
     * @return list<\Rustine\Plan\GroupPlan>
     */
    private function plans(): array
    {
        return (new Planner())->plan((new TrivyReportParser())->parseFile(__DIR__ . '/../fixtures/trivy/yarn-report.json'));
    }
}
