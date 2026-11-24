<?php

declare(strict_types=1);

namespace Rustine\Sync;

use Rustine\Trivy\Severity;

/**
 * How merge requests are opened: where they go, how they are labelled, who they are given to.
 */
final readonly class SyncSettings
{
    /**
     * @param list<string> $labels              always set, never translated: filters and boards depend on them. They also
     *                                          scope the count of open merge requests
     * @param string       $severityLabelFormat label of the highest severity; `{severity}` is replaced by the lowercase
     *                                          severity (`critical`, `high`, …)
     * @param list<string> $assignees           usernames, applied when a merge request is created
     * @param list<string> $reviewers           usernames, applied when a merge request is created
     * @param int|null     $maxOpenMergeRequests only prevents opening new merge requests; null = no limit
     */
    public function __construct(
        public string $defaultBranch,
        public array $labels = ['security'],
        public string $severityLabelFormat = 'severity::{severity}',
        public array $assignees = [],
        public array $reviewers = [],
        public ?int $maxOpenMergeRequests = null,
    ) {
    }

    public function severityLabel(Severity $severity): string
    {
        return str_replace('{severity}', strtolower($severity->value), $this->severityLabelFormat);
    }

    /**
     * @return list<string> labels of every other severity: they are removed when the severity of a merge request changes
     */
    public function otherSeverityLabels(Severity $severity): array
    {
        $labels = [];
        foreach (Severity::cases() as $case) {
            if ($case !== $severity) {
                $labels[] = $this->severityLabel($case);
            }
        }

        return $labels;
    }
}
