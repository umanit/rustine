<?php

declare(strict_types=1);

namespace Rustine\Apply;

/**
 * Locates the members of the JSON objects of a document (byte offsets), so that a value can be replaced or a member
 * added without re-encoding the rest of the text: what a person wrote (inline arrays, spacing, key order) stays as is.
 *
 * Only objects are explored; arrays and scalars are skipped as one span. Returns null for anything that is not
 * strictly valid JSON: the caller then falls back to a full re-encoding.
 */
final class JsonSpans
{
    private int $pos = 0;

    private function __construct(private readonly string $json)
    {
    }

    /**
     * @return JsonObject|null the root value, when it is an object
     */
    public static function parse(string $json): ?JsonObject
    {
        $parser = new self($json);
        try {
            $root = $parser->value();
            $parser->skipWhitespace();
        } catch (\UnexpectedValueException) {
            return null;
        }

        return $root instanceof JsonObject && $parser->pos === \strlen($json) ? $root : null;
    }

    /**
     * @return JsonObject|JsonScalar
     */
    private function value(): JsonObject|JsonScalar
    {
        $this->skipWhitespace();
        $start = $this->pos;
        $char = $this->json[$this->pos] ?? throw new \UnexpectedValueException('end of input');

        if ('{' === $char) {
            return $this->object();
        }
        if ('[' === $char) {
            $this->skipArray();

            return new JsonScalar($start, $this->pos, false);
        }
        if ('"' === $char) {
            $this->string();

            return new JsonScalar($start, $this->pos, true);
        }

        if (1 !== preg_match('/\G-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?|\Gtrue|\Gfalse|\Gnull/', $this->json, $m, 0, $this->pos)) {
            throw new \UnexpectedValueException('unexpected character');
        }
        $this->pos += \strlen($m[0]);

        return new JsonScalar($start, $this->pos, false);
    }

    private function object(): JsonObject
    {
        $start = $this->pos++;
        $members = [];
        $this->skipWhitespace();

        if ('}' === ($this->json[$this->pos] ?? '')) {
            ++$this->pos;

            return new JsonObject($start, $this->pos, []);
        }

        while (true) {
            $this->skipWhitespace();
            $keyStart = $this->pos;
            if ('"' !== ($this->json[$this->pos] ?? '')) {
                throw new \UnexpectedValueException('key expected');
            }
            $this->string();
            $keyEnd = $this->pos;
            $key = json_decode(substr($this->json, $keyStart, $this->pos - $keyStart), false, 512, \JSON_THROW_ON_ERROR);
            if (!\is_string($key)) {
                throw new \UnexpectedValueException('key expected');
            }

            $this->skipWhitespace();
            if (':' !== ($this->json[$this->pos] ?? '')) {
                throw new \UnexpectedValueException('colon expected');
            }
            ++$this->pos;

            $value = $this->value();
            $members[] = new JsonMember($key, $keyStart, $keyEnd, $value);

            $this->skipWhitespace();
            $next = $this->json[$this->pos] ?? '';
            ++$this->pos;
            if ('}' === $next) {
                return new JsonObject($start, $this->pos, $members);
            }
            if (',' !== $next) {
                throw new \UnexpectedValueException('comma expected');
            }
        }
    }

    private function skipArray(): void
    {
        $depth = 0;
        while ($this->pos < \strlen($this->json)) {
            $char = $this->json[$this->pos];
            if ('"' === $char) {
                $this->string();
                continue;
            }
            ++$this->pos;
            if ('[' === $char || '{' === $char) {
                ++$depth;
            } elseif (']' === $char || '}' === $char) {
                if (0 === --$depth) {
                    return;
                }
            }
        }

        throw new \UnexpectedValueException('unterminated array');
    }

    private function string(): void
    {
        ++$this->pos;
        while ($this->pos < \strlen($this->json)) {
            $char = $this->json[$this->pos++];
            if ('\\' === $char) {
                ++$this->pos;
            } elseif ('"' === $char) {
                return;
            }
        }

        throw new \UnexpectedValueException('unterminated string');
    }

    private function skipWhitespace(): void
    {
        $this->pos += strspn($this->json, " \t\r\n", $this->pos);
    }
}
