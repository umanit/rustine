<?php

declare(strict_types=1);

namespace Rustine\Git;

enum PushResult: string
{
    /** The branch was created or force-updated on the remote. */
    case Pushed = 'pushed';
    /** The remote branch already holds exactly the same tree: nothing was pushed. */
    case Unchanged = 'unchanged';
    /** The remote branch holds other trees but was built for the same fixes, and still merges: nothing was pushed. */
    case Kept = 'kept';
}
