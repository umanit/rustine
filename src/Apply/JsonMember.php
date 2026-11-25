<?php

declare(strict_types=1);

namespace Rustine\Apply;

final readonly class JsonMember
{
    public int $valueStart;
    public int $valueEnd;

    public function __construct(public string $key, public int $keyStart, public int $keyEnd, public JsonObject|JsonScalar $value)
    {
        $this->valueStart = $value->start;
        $this->valueEnd = $value->end;
    }
}
