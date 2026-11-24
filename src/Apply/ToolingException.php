<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * A tool the ecosystem needs is missing from the environment, or is not the one the project expects. Rustine never
 * installs tools: the environment that runs it must provide them.
 */
class ToolingException extends \RuntimeException
{
}
