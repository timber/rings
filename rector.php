<?php

declare(strict_types=1);

use Rector\CodeQuality\Rector\Concat\DirnameDirConcatStringToDirectStringPathRector;
use Rector\CodeQuality\Rector\If_\ExplicitNullableObjectInstanceRector;
use Rector\Config\RectorConfig;
use Rector\Php81\Rector\Array_\ArrayToFirstClassCallableRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/tests',
    ])
    // Query Monitor is installed as a WordPress plugin, not autoloaded by Composer (same as in phpstan.neon.dist)
    ->withAutoloadPaths(array_map(
        static fn (string $dir): string => __DIR__ . '/wp-content/plugins/query-monitor/' . $dir,
        ['classes', 'collectors', 'data', 'dispatchers', 'output'],
    ))
    ->withPhpSets()
    ->withComposerBased(twig: true, phpunit: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
        phpunitCodeQuality: true,
    )
    ->withSkip([
        // Hooks are identified by their callback: a closure can't be removed with remove_filter([$object, 'method'])
        ArrayToFirstClassCallableRector::class,
        DirnameDirConcatStringToDirectStringPathRector::class,
        // `$object === null` reads better than `!$object instanceof Foo`
        ExplicitNullableObjectInstanceRector::class,
    ]);
