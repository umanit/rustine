<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * A JSON object found in a text, with the offsets of its members.
 */
final readonly class JsonObject
{
    /**
     * @param list<JsonMember> $members
     */
    public function __construct(public int $start, public int $end, public array $members)
    {
    }

    public function member(string $key): ?JsonMember
    {
        foreach ($this->members as $member) {
            if ($member->key === $key) {
                return $member;
            }
        }

        return null;
    }
}
