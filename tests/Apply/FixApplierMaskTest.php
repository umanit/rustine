<?php

declare(strict_types=1);

namespace Rustine\Tests\Apply;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Rustine\Apply\FixApplier;

final class FixApplierMaskTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function secrets(): iterable
    {
        yield 'url with user and password' => [
            'GET https://bot:glpat-abc123@registry.example.com/npm/x failed',
            'GET https://***@registry.example.com/npm/x failed',
        ];
        yield 'url with a token only' => ['fetch http://tok3n@host/pkg', 'fetch http://***@host/pkg'];
        yield 'authorization header' => ['Authorization: Bearer abc.def-123', 'Authorization: Bearer ***'];
        yield 'npm auth token' => ['//registry/:_authToken=NpmToken123', '//registry/:_authToken=***'];
        yield 'nothing to hide' => [
            'https://registry.yarnpkg.com/braces/-/braces-3.0.2.tgz not found',
            'https://registry.yarnpkg.com/braces/-/braces-3.0.2.tgz not found',
        ];
    }

    #[Test]
    #[DataProvider('secrets')]
    public function credentialsAreMasked(string $text, string $expected): void
    {
        $this->assertSame($expected, FixApplier::maskSecrets($text));
    }
}
