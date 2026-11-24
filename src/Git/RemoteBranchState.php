<?php

declare(strict_types=1);

namespace Rustine\Git;

enum RemoteBranchState: string
{
    /** The branch does not exist on the remote. */
    case Absent = 'absent';
    /** Every commit it carries on top of the default branch was created by Rustine. */
    case Rustine = 'rustine';
    /** It carries commits Rustine did not create: it must not be overwritten. */
    case Foreign = 'foreign';
}
