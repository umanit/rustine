<?php

declare(strict_types=1);

namespace Rustine\Command;

/**
 * An option holds a value the command cannot use; the message is meant for the user.
 */
final class InvalidOptionException extends \InvalidArgumentException
{
}
