<?php

declare(strict_types=1);

namespace Rustine\Sync;

/**
 * Result of a whole run: console lines, job report and exit code.
 *
 * The exit code tells the CI what happened: 0 = nothing left to do for a human, 1 = something failed, 2 = some
 * packages need a manual action (the job template turns 2 into a warning with `allow_failure: exit_codes: [2]`).
 */
final readonly class RunSummary
{
    public const int EXIT_OK = 0;

    public const int EXIT_FAILURE = 1;

    public const int EXIT_MANUAL_ACTION = 2;

    /**
     * @param list<PlanOutcome> $outcomes
     */
    public function __construct(public array $outcomes)
    {
    }

    public static function line(PlanOutcome $outcome): string
    {
        $label = match ($outcome->action) {
            PlanAction::Created => 'merge request opened',
            PlanAction::Updated => 'branch rebuilt, merge request updated',
            PlanAction::Refreshed => 'merge request texts refreshed',
            PlanAction::Unchanged => 'merge request already up to date',
            PlanAction::Closed => 'nothing fixable anymore, merge request closed',
            PlanAction::Clear => 'nothing to fix, no merge request',
            PlanAction::NoFix => 'nothing fixable automatically, no merge request',
            PlanAction::SkippedLimit => 'skipped, too many open merge requests',
            PlanAction::SkippedClosed => 'skipped, the same merge request was closed recently',
            PlanAction::SkippedForeign => "skipped, the branch has commits that are not Rustine's",
            PlanAction::Failed => 'FAILED',
        };

        return sprintf(
            '%s: %s%s%s',
            $outcome->branch,
            $label,
            null === $outcome->mergeRequestUrl ? '' : ' ' . $outcome->mergeRequestUrl,
            $outcome->needsManualAction() ? ' (manual action required)' : '',
        );
    }

    public function exitCode(): int
    {
        foreach ($this->outcomes as $outcome) {
            if ($outcome->isFailure()) {
                return self::EXIT_FAILURE;
            }
        }

        foreach ($this->outcomes as $outcome) {
            if ($outcome->needsManualAction()) {
                return self::EXIT_MANUAL_ACTION;
            }
        }

        return self::EXIT_OK;
    }

    /**
     * Markdown report, published as a job artifact: it also carries the manual items of the groups that have no
     * merge request.
     */
    public function toMarkdown(): string
    {
        $sections = [];

        foreach ($this->outcomes as $outcome) {
            $section = sprintf("<!-- %s -->\n**%s**", $outcome->branch, self::line($outcome));

            foreach ($outcome->messages as $message) {
                $section .= "\n\n> " . str_replace("\n", "\n> ", $message);
            }

            if (null !== $outcome->content) {
                $section .= "\n\n" . $outcome->content->description;
            }
            $sections[] = $section;
        }

        return implode("\n", $sections);
    }
}
