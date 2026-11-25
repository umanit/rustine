<?php

declare(strict_types=1);

namespace Rustine\Report;

use Rustine\Trivy\Severity;

/**
 * Everything written to GitLab for one merge request, already localized.
 */
final readonly class MergeRequestContent
{
    /**
     * @param string        $title          MR title
     * @param string        $description    Markdown description, ends with the idempotency marker
     * @param string        $commitMessage  `build(deps): …` subject and body
     * @param string        $fingerprint    hash of what the MR changes (package, versions, strategy)
     * @param Severity|null $severity       highest severity among the fixed packages (null if nothing is fixed)
     * @param int           $fixedPackages  number of packages in the table of fixes
     * @param int           $attentionItems number of items needing a manual action
     */
    public function __construct(
        public string $title,
        public string $description,
        public string $commitMessage,
        public string $fingerprint,
        public ?Severity $severity,
        public int $fixedPackages,
        public int $attentionItems,
    ) {
    }

    public function withDescription(string $description): self
    {
        return new self(
            $this->title,
            $description,
            $this->commitMessage,
            $this->fingerprint,
            $this->severity,
            $this->fixedPackages,
            $this->attentionItems,
        );
    }
}
