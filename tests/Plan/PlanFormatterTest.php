<?php

declare(strict_types=1);

namespace Rustine\Tests\Plan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PlanFormatter;
use Rustine\Plan\Planner;
use Rustine\Trivy\TrivyReportParser;

final class PlanFormatterTest extends TestCase
{
    #[Test]
    public function itRendersATextPlan(): void
    {
        $text = new PlanFormatter()->toText($this->plans());

        $this->assertStringContainsString('security/rustine-yarn-critical-high — 4 fixable, 1 to report', $text);
        $this->assertStringContainsString('✔ brace-expansion@1.1.12 → 1.1.21 [HIGH, transitive, 7 CVE]', $text);
        $this->assertStringContainsString('✘ braces@3.0.3 [HIGH, transitive, 1 CVE] — no fix available', $text);
        $this->assertStringContainsString('security/rustine-yarn-others', $text);
    }

    #[Test]
    public function itRendersAnEmptyPlan(): void
    {
        $this->assertSame("No vulnerability to fix.\n", new PlanFormatter()->toText([]));
    }

    #[Test]
    public function itExportsAJsonSerializablePlan(): void
    {
        $data = new PlanFormatter()->toArray($this->plans());

        $this->assertSame('security/rustine-yarn-critical-high', $data[0]['branch']);
        $this->assertSame('1.1.21', $data[0]['fixes'][1]['target'] ?? null);
        $this->assertNotFalse(json_encode($data));
    }

    /**
     * @return list<GroupPlan>
     */
    private function plans(): array
    {
        return new Planner()->plan(new TrivyReportParser()->parseFile(__DIR__ . '/../fixtures/trivy/yarn-report.json'));
    }
}
