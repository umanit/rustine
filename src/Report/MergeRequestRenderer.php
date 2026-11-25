<?php

declare(strict_types=1);

namespace Rustine\Report;

use Rustine\Apply\ApplyResult;
use Rustine\Apply\AttemptKind;
use Rustine\Apply\FixOutcome;
use Rustine\Apply\FixOutcomeStatus;
use Rustine\Apply\LockChange;
use Rustine\Apply\ToolVersions;
use Rustine\Plan\DependencyKind;
use Rustine\Plan\GroupPlan;
use Rustine\Plan\PackageFix;
use Rustine\Plan\PackageFixStatus;
use Rustine\Trivy\Severity;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Writes the title, description and commit message of a merge request in the language of the translator.
 *
 * The structure lives here; only the sentences come from the catalogs, so every language shows the same sections.
 * Data (package names, versions, CVE identifiers, commands) is never translated. Without an {@see ApplyResult} the
 * content is a preview of the plan: nothing has been executed nor verified.
 */
final readonly class MergeRequestRenderer
{
    /** Marks the descriptions written by Rustine and carries the fingerprint used for idempotency. */
    public const string MARKER = 'rustine:v1';

    /** Rows of the lockfile table: a refresh may move hundreds of packages, GitLab limits a description to 1 MB. */
    private const int MAX_LOCK_ROWS = 200;

    /**
     * @param list<string> $notify usernames (or groups) mentioned in the description, so that GitLab notifies them
     *                             without assigning the merge request to them
     */
    public function __construct(
        private TranslatorInterface $translator,
        private string $version,
        private array $notify = [],
    ) {
    }

    /**
     * Fingerprint written by a previous run in a description, or null when the description is not (or no longer) one
     * of Rustine's.
     */
    public static function fingerprintOf(string $description): ?string
    {
        return 1 === preg_match(
            '/<!-- ' . preg_quote(self::MARKER, '/') . ' fingerprint=([0-9a-f]+) -->/',
            $description,
            $m,
        ) ? $m[1] : null;
    }

    public function render(
        GroupPlan $plan,
        ?ApplyResult $result = null,
        ?ToolVersions $tools = null,
    ): MergeRequestContent {
        $outcomes = $this->outcomesByFix($plan, $result);
        $rows = $this->rows($plan, $result, $outcomes);
        $attention = $this->attention($plan, $outcomes);
        $fingerprint = $this->fingerprint($rows);

        $severity = null;

        foreach ($rows as $row) {
            $severity = null === $severity ? $row['fix']->severity : Severity::max($severity, $row['fix']->severity);
        }

        $params = ['ecosystem' => ucfirst($plan->ecosystem->value)];
        if (null !== $plan->group) {
            $params['group'] = $this->t('group.' . $plan->group->value);
        }

        $sections = [];
        if (!$result instanceof ApplyResult) {
            $sections[] = '> ' . $this->t('preview');
        }
        $sections[] = $this->summary($rows, !$result instanceof ApplyResult);
        if ([] !== $rows) {
            $sections[] = '## ' . $this->t('section.fixed') . "\n\n" . $this->table($rows);
        }
        if ($result instanceof ApplyResult && [] !== $result->lockChanges) {
            $sections[] = '## ' . $this->t('section.lock') . "\n\n"
                . $this->lockChanges(
                    $plan,
                    $rows,
                    $result->lockChanges,
                );
        }
        if ([] !== $attention) {
            $sections[] = '## ' . $this->t('section.attention') . "\n\n" . implode("\n", $attention);
        }
        $debt = $this->debt($rows);
        if ([] !== $debt) {
            $sections[] = '## ' . $this->t('section.debt') . "\n\n" . implode("\n", $debt);
        }
        if ($result instanceof ApplyResult && ([] !== $rows || null !== $result->finalCheckError)) {
            $sections[] = '## ' . $this->t('section.verification') . "\n\n" . $this->verification($result);
        }
        $sections[] = '## ' . $this->t('section.tooling') . "\n\n" . ($tools instanceof ToolVersions
                ? $this->t('tooling.used', ['tools' => '`' . $tools->describe() . '`'])
                : $this->t('tooling.none'));
        if ([] !== $this->notify) {
            $sections[] = $this->t(
                'notify',
                ['users' => implode(' ', array_map(static fn(string $user): string => '@' . $user, $this->notify))],
            );
        }
        $sections[] = sprintf(
            "---\n%s\n<!-- %s fingerprint=%s -->",
            $this->t('footer', ['version' => $this->version]),
            self::MARKER,
            $fingerprint,
        );

        return new MergeRequestContent(
            $this->t(null === $plan->group ? 'title_all' : 'title', $params),
            implode("\n\n", $sections) . "\n",
            $this->commitMessage(
                $rows,
                null === $plan->group
                    ? ['ecosystem' => $params['ecosystem']]
                    : ['ecosystem' => $params['ecosystem'], 'group' => $plan->group->value],
            ),
            $fingerprint,
            $severity,
            \count($rows),
            \count($attention),
        );
    }

    /**
     * One outcome per fix, in plan order; empty without a result.
     *
     * @return array<int, FixOutcome>
     */
    private function outcomesByFix(GroupPlan $plan, ?ApplyResult $result): array
    {
        if (!$result instanceof ApplyResult) {
            return [];
        }

        $outcomes = [];

        foreach ($plan->fixes as $index => $fix) {
            foreach ($result->outcomes as $outcome) {
                if ($outcome->fix === $fix) {
                    $outcomes[$index] = $outcome;

                    break;
                }
            }
        }

        return $outcomes;
    }

    /**
     * Packages of the table of fixes.
     *
     * @param array<int, FixOutcome> $outcomes
     *
     * @return list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}>
     */
    private function rows(GroupPlan $plan, ?ApplyResult $result, array $outcomes): array
    {
        $rows = [];

        foreach ($plan->fixes as $index => $fix) {
            if (!$result instanceof ApplyResult) {
                if ($fix->isFixable()) {
                    $rows[] = ['fix' => $fix, 'strategy' => null, 'already' => false];
                }

                continue;
            }

            $outcome = $outcomes[$index] ?? null;
            if (FixOutcomeStatus::Applied === $outcome?->status) {
                $rows[] = ['fix' => $fix, 'strategy' => $outcome->strategy, 'already' => false];
            } elseif (FixOutcomeStatus::AlreadyFixed === $outcome?->status) {
                $rows[] = ['fix' => $fix, 'strategy' => null, 'already' => true];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     */
    private function summary(array $rows, bool $planned): string
    {
        $cves = 0;

        foreach ($rows as $row) {
            $cves += \count($row['fix']->fixedIds);
        }

        return $this->t($planned ? 'summary.planned' : 'summary.fixed', ['packages' => \count($rows), 'cves' => $cves]);
    }

    /**
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     */
    private function table(array $rows): string
    {
        $lines = [
            sprintf(
                '| %s | %s | %s | %s | %s | %s |',
                $this->t('table.package'),
                $this->t('table.version'),
                $this->t('table.cves'),
                $this->t('table.severity'),
                $this->t('table.dependency'),
                $this->t('table.strategy'),
            ),
            '|---|---|---|---|---|---|',
        ];

        foreach ($rows as $row) {
            $fix = $row['fix'];
            $strategy = $row['already']
                ? $this->t('strategy.already')
                : (null === $row['strategy'] ? $this->t('strategy.planned') : $this->t(
                    'strategy.' . $row['strategy']->value,
                ));

            $lines[] = sprintf(
                '| `%s` | `%s` → `%s` | %s | %s | %s | %s |',
                $fix->packageName,
                $fix->installedVersion,
                $fix->targetVersion ?? '?',
                $this->cves($fix, $fix->fixedIds),
                $fix->severity->value,
                $this->dependency($fix),
                $strategy,
            );
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<string> $ids
     */
    private function cves(PackageFix $fix, array $ids): string
    {
        $links = [];

        foreach ($ids as $id) {
            $url = null;

            foreach ($fix->vulnerabilities as $vulnerability) {
                if ($vulnerability->id === $id) {
                    $url = $vulnerability->url;

                    break;
                }
            }

            $links[] = null === $url ? $id : sprintf('[%s](%s)', $id, $url);
        }

        return implode(', ', $links);
    }

    private function dependency(PackageFix $fix): string
    {
        return match ($fix->dependencyKind) {
            DependencyKind::Direct => $this->t('dependency.direct'),
            DependencyKind::Transitive => $this->t('dependency.transitive', [
                'chain' => implode(
                    ' → ',
                    array_map(
                        static fn(string $id): string => '`' . (strrpos($id, '@') > 0
                                ? substr(
                                    $id,
                                    0,
                                    strrpos($id, '@'),
                                )
                                : $id) . '`',
                        $fix->dependencyChain,
                    ),
                ),
            ]),
            DependencyKind::Unknown => $this->t('dependency.unknown'),
        };
    }

    /**
     * Items that need a manual action.
     *
     * @param array<int, FixOutcome> $outcomes
     *
     * @return list<string>
     */
    private function attention(GroupPlan $plan, array $outcomes): array
    {
        $items = [];

        foreach ($plan->fixes as $index => $fix) {
            $messages = [];
            $outcome = $outcomes[$index] ?? null;

            if (PackageFixStatus::UnparsableVersion === $fix->status) {
                $messages[] = $this->t('attention.unparsable', ['version' => '`' . $fix->installedVersion . '`']);
            }
            if ([] !== $fix->majorBumpIds) {
                $messages[] = $this->t('attention.major_bump', [
                    'cves'     => $this->cves($fix, array_keys($fix->majorBumpIds)),
                    'versions' => '`' . implode('`, `', array_unique(array_values($fix->majorBumpIds))) . '`',
                ]);
            }
            if ([] !== $fix->withoutFixIds) {
                $messages[] = $this->t('attention.no_fix', ['cves' => $this->cves($fix, $fix->withoutFixIds)]);
            }
            if (FixOutcomeStatus::UnsupportedSource === $outcome?->status) {
                $messages[] = $this->t('attention.unsupported_source', ['cves' => $this->cves($fix, $fix->fixedIds)])
                    . $this->details($this->t('attention.technical_details'), $outcome->log);
            }
            if (true === $outcome?->stillReported) {
                $messages[] = $this->t('attention.still_reported', ['cves' => $this->cves($fix, $fix->fixedIds)]);
            }
            if (FixOutcomeStatus::Failed === $outcome?->status) {
                $messages[] = $this->t('attention.failed', ['cves' => $this->cves($fix, $fix->fixedIds)])
                    . $this->details($this->t('attention.technical_details'), $outcome->log);
            }

            foreach ($messages as $message) {
                $items[] = sprintf('- **%s** (`%s`): %s', $fix->packageName, $fix->installedVersion, $message);
            }
        }

        return $items;
    }

    /**
     * What the run changed in the lockfile: the packages of the fixes, and the ones that moved along with them. The
     * table is folded, the summary line tells at a glance how far the changes go.
     *
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     * @param non-empty-list<LockChange>                                          $changes
     */
    private function lockChanges(GroupPlan $plan, array $rows, array $changes): string
    {
        $targeted = array_flip(array_map(static fn(array $row): string => $row['fix']->packageName, $rows));
        $fixed = \count(
            array_filter($changes, static fn(LockChange $change): bool => isset($targeted[$change->package])),
        );

        $lines = [
            sprintf('| %s | %s | %s |', $this->t('table.package'), $this->t('lock.before'), $this->t('lock.after')),
            '|---|---|---|',
        ];

        foreach (\array_slice($changes, 0, self::MAX_LOCK_ROWS) as $change) {
            $name = isset($targeted[$change->package])
                ? '**`' . $change->package . '`**'
                : '`' . $change->package . '`';
            $lines[] = sprintf(
                '| %s | %s | %s |',
                $name,
                $this->versions($change->before),
                $this->versions($change->after),
            );
        }

        if (\count($changes) > self::MAX_LOCK_ROWS) {
            $lines[] = '| ' . $this->t('lock.more', ['count' => \count($changes) - self::MAX_LOCK_ROWS]) . ' | | |';
        }

        return sprintf(
            "%s\n\n<details><summary>%s</summary>\n\n%s\n\n</details>",
            $this->t(
                'lock.summary',
                ['file' => '`' . $plan->ecosystem->lockFile() . '`', 'changed' => \count($changes), 'fixed' => $fixed],
            ),
            $this->t('lock.show'),
            implode("\n", $lines),
        );
    }

    /**
     * @param list<string> $versions
     */
    private function versions(array $versions): string
    {
        return [] === $versions ? '—' : implode(
            ', ',
            array_map(static fn(string $version): string => '`' . $version . '`', $versions),
        );
    }

    /**
     * @param list<string> $lines
     */
    private function details(string $summary, array $lines): string
    {
        if ([] === $lines) {
            return '';
        }

        $body = implode("\n", array_map(static fn(string $line): string => '  ' . $line, $lines));

        return sprintf("\n  <details><summary>%s</summary>\n\n  ```text\n%s\n  ```\n\n  </details>", $summary, $body);
    }

    /**
     * Resolutions are technical debt: flagged so that they get removed once the parent ships the fix.
     *
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     *
     * @return list<string>
     */
    private function debt(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            if (AttemptKind::Resolution === $row['strategy']) {
                $items[] = '- ' . $this->t('debt.resolution', ['package' => '`' . $row['fix']->packageName . '`']);
            }
        }

        return $items;
    }

    private function verification(ApplyResult $result): string
    {
        $lines = ['- ' . $this->t('verification.rescan_' . $result->verify->value)];
        $lines[] = match (true) {
            null !== $result->finalCheckError => '- ' . $this->t('verification.check_failed')
                . "\n\n  ```text\n  " . $result->finalCheckError . "\n  ```",
            null !== $result->existingCheckFindings => '- ' . $this->t('verification.check_existing')
                . "\n\n  ```text\n  " . $result->existingCheckFindings . "\n  ```",
            default => '- ' . $this->t('verification.check_ok'),
        };

        return implode("\n", $lines);
    }

    /**
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     * @param array<string, string>                                               $params
     */
    private function commitMessage(array $rows, array $params): string
    {
        // The type and scope are the Conventional Commits contract: only the sentence is localized. The group is
        // given as its short identifier to keep the subject short.
        $subject = isset($params['group']) ? 'commit.subject' : 'commit.subject_all';
        $message = 'build(deps): ' . $this->t($subject, $params);

        if ([] !== $rows) {
            $message .= "\n\n" . $this->t('commit.intro');

            foreach ($rows as $row) {
                $fix = $row['fix'];
                $message .= sprintf(
                    "\n- %s %s → %s (%s)",
                    $fix->packageName,
                    $fix->installedVersion,
                    $fix->targetVersion ?? '?',
                    implode(', ', $fix->fixedIds),
                );
            }
        }

        return $message;
    }

    /**
     * Stable hash of what the MR changes: same fixes, same versions, same strategies, same fingerprint.
     *
     * @param list<array{fix: PackageFix, strategy: ?AttemptKind, already: bool}> $rows
     */
    private function fingerprint(array $rows): string
    {
        $lines = array_map(
            static fn(array $row): string => implode('|', [
                $row['fix']->ecosystem->value,
                $row['fix']->packageName,
                $row['fix']->installedVersion,
                $row['fix']->targetVersion ?? '',
                $row['strategy']->value ?? ($row['already'] ? 'already' : 'planned'),
            ]),
            $rows,
        );
        sort($lines);

        return substr(hash('sha256', implode("\n", $lines)), 0, 16);
    }

    /**
     * @param array<string, scalar|\Stringable> $parameters
     */
    private function t(string $id, array $parameters = []): string
    {
        return $this->translator->trans($id, $parameters, 'rustine');
    }
}
