<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Class_\InlineConstructorDefaultToPropertyRector;
use Rector\CodingStyle\Rector\ArrowFunction\ArrowFunctionDelegatingCallToFirstClassCallableRector;
use Rector\Config\RectorConfig;
use Rector\Php74\Rector\If_\IfToNullCoalescingAssignRector;
use Rector\TypeDeclaration\Rector\Property\TypedPropertyFromAssignsRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/tools',
    ])
    ->withPhpVersion(PhpVersion::PHP_84)
    ->withPreparedSets(deadCode: true, phpunitCodeQuality: true)
    ->withComposerBased(phpunit: true)
    ->withPhpSets(php84: true)
    ->withAttributesSets(all: true)
    // Import the project and vendor classes, never the native ones (\Exception, \DateTimeImmutable, …).
    ->withImportNames(importShortClasses: false)
    ->withRules([
        InlineConstructorDefaultToPropertyRector::class,
    ])
    ->withConfiguredRule(TypedPropertyFromAssignsRector::class, [
        'inline_public' => true,
    ])
    ->withSkip([
        // Do not force the null coalescing assignment on "isset"/"if null" checks
        IfToNullCoalescingAssignRector::class,
        // `version_compare(...)` has an optional third parameter: PHPStan rejects it as a usort() callback.
        ArrowFunctionDelegatingCallToFirstClassCallableRector::class => [
            __DIR__.'/src/Apply/LockfileVersions.php',
        ],
    ])
;
