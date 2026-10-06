<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
                   ->withPaths([
                       __DIR__ . '/src',
                   ])
                   // Stubs WordPress : sans eux, Rector ignore les fonctions WP (is_user_logged_in…)
                   // et propose moins de transformations qu'à la racine du monorepo.
                   ->withBootstrapFiles([
                       __DIR__ . '/vendor/php-stubs/wordpress-stubs/wordpress-stubs.php',
                   ])
                   ->withPhpVersion(80300)
                   ->withPhpSets(php83: true)
                   ->withPreparedSets(deadCode: true);
