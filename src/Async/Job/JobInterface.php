<?php

declare(strict_types=1);

namespace PluginKernel\Async\Job;

/**
 * Interface pour les jobs asynchrones.
 */
interface JobInterface
{
    /**
     * Retourne le type de job.
     */
    public function getType(): string;

    /**
     * Retourne le payload du job sous forme de tableau.
     *
     * @return array<string, mixed>
     */
    public function getPayload(): array;
}
