<?php
declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/php/src',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php81: true)
    ->withSkip([
        __DIR__ . '/vendor',
        __DIR__ . '/php/src/AsyncBridge.php',
        __DIR__ . '/php/src/compat',
    ]);
