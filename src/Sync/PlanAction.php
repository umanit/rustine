<?php

declare(strict_types=1);

namespace Rustine\Sync;

enum PlanAction: string
{
    /** A merge request was opened. */
    case Created = 'created';

    /** The branch changed: it was force-pushed and the merge request refreshed. */
    case Updated = 'updated';

    /** Same branch content, but the merge request texts or labels were out of date. */
    case Refreshed = 'refreshed';

    /** Nothing to do: the merge request already matches. */
    case Unchanged = 'unchanged';

    /** Nothing is fixable anymore: the open merge request was closed. */
    case Closed = 'closed';

    /** Nothing is fixable and there is no merge request: only the report mentions the manual items. */
    case NoFix = 'no-fix';

    /** A new merge request was needed but the limit of open merge requests is reached. */
    case SkippedLimit = 'skipped-limit';

    /** The same fixes were already proposed in a merge request that someone closed: not opened again. */
    case SkippedClosed = 'skipped-closed';

    /** The branch carries someone else's commits: it was left alone. */
    case SkippedForeign = 'skipped-foreign';

    /** The group could not be processed (git, GitLab, project or consistency error). */
    case Failed = 'failed';
}
