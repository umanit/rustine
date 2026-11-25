<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * A JSON value that is not an object (string, number, boolean, null, array), as one span of the text.
 */
final readonly class JsonScalar
{
    public function __construct(public int $start, public int $end, public bool $isString)
    {
    }
}
