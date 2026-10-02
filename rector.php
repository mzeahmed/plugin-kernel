<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
                   ->withPaths([
                       __DIR__ . '/src',
                   ])
                   ->withPhpVersion(80300)
                   ->withPhpSets(php83: true)
                   ->withPreparedSets(deadCode: true);
