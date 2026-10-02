<?php

declare(strict_types=1);

namespace PluginKernel\Async\Attribute;

/**
 * Attribut pour marquer une classe comme handler de jobs asynchrones.
 * Le handler sera automatiquement découvert et enregistré par HandlerDiscovery.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsyncHandler
{
    /**
     * @param string $jobType Le type de job supporté par ce handler
     */
    public function __construct(
        public string $jobType
    ) {
    }
}
