<?php

declare(strict_types=1);

namespace Rustine\Tests\Sync;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Report\MergeRequestContent;
use Rustine\Sync\PlanAction;
use Rustine\Sync\PlanOutcome;
use Rustine\Sync\RunSummary;

final class RunSummaryTest extends TestCase
{
    #[Test]
    public function aCleanRunExitsWithZero(): void
    {
        $summary = new RunSummary([
            new PlanOutcome('security/rustine-yarn-others', PlanAction::Created, $this->content(0), 'https://gitlab.example.com/mr/1'),
            new PlanOutcome('security/rustine-composer-others', PlanAction::Unchanged, $this->content(0)),
        ]);

        self::assertSame(RunSummary::EXIT_OK, $summary->exitCode());
    }

    #[Test]
    public function remainingManualItemsExitWithTwo(): void
    {
        $summary = new RunSummary([
            new PlanOutcome('security/rustine-yarn-others', PlanAction::Created, $this->content(0)),
            new PlanOutcome('security/rustine-composer-others', PlanAction::NoFix, $this->content(2)),
        ]);

        self::assertSame(RunSummary::EXIT_MANUAL_ACTION, $summary->exitCode());
    }

    #[Test]
    public function aSkippedGroupNeedsAHumanEvenWithoutManualItems(): void
    {
        foreach ([PlanAction::SkippedLimit, PlanAction::SkippedForeign] as $action) {
            self::assertSame(RunSummary::EXIT_MANUAL_ACTION, (new RunSummary([new PlanOutcome('b', $action)]))->exitCode());
        }
    }

    #[Test]
    public function aFailureWinsOverManualItems(): void
    {
        $summary = new RunSummary([
            new PlanOutcome('a', PlanAction::NoFix, $this->content(3)),
            new PlanOutcome('b', PlanAction::Failed, null, null, ['boom']),
        ]);

        self::assertSame(RunSummary::EXIT_FAILURE, $summary->exitCode());
    }

    #[Test]
    public function theReportCarriesStatusMessagesAndDescriptions(): void
    {
        $summary = new RunSummary([
            new PlanOutcome('security/rustine-yarn-others', PlanAction::Created, $this->content(1), 'https://gitlab.example.com/mr/1', ['GitLab user "ghost" not found: ignored.']),
            new PlanOutcome('security/rustine-composer-others', PlanAction::Failed, null, null, ['boom']),
        ]);

        $markdown = $summary->toMarkdown();

        self::assertStringStartsWith('<!-- security/rustine-yarn-others -->', $markdown);
        self::assertStringContainsString('merge request opened https://gitlab.example.com/mr/1 (manual action required)', $markdown);
        self::assertStringContainsString('> GitLab user "ghost" not found', $markdown);
        self::assertStringContainsString('DESCRIPTION', $markdown);
        self::assertStringContainsString('security/rustine-composer-others: FAILED', $markdown);
        self::assertStringContainsString('> boom', $markdown);
    }

    private function content(int $attention): MergeRequestContent
    {
        return new MergeRequestContent('T', 'DESCRIPTION', 'build(deps): x', 'abc', null, 0, $attention);
    }
}
