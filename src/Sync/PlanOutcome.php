<?php

declare(strict_types=1);

namespace Rustine\Sync;

use Rustine\Report\MergeRequestContent;

/**
 * What happened to the merge request of one plan.
 */
final readonly class PlanOutcome
{
    /**
     * @param list<string> $messages warnings or the error, for the job log and the report
     */
    public function __construct(
        public string $branch,
        public PlanAction $action,
        public ?MergeRequestContent $content = null,
        public ?string $mergeRequestUrl = null,
        public array $messages = [],
    ) {
    }

    public function isFailure(): bool
    {
        return PlanAction::Failed === $this->action;
    }

    /**
     * True when a human has something to do: unfixable packages, a branch to clean up, a limit to free.
     */
    public function needsManualAction(): bool
    {
        return \in_array($this->action, [PlanAction::SkippedLimit, PlanAction::SkippedForeign], true)
            || (null !== $this->content && $this->content->attentionItems > 0);
    }
}
