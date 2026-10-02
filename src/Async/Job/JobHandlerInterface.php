<?php

declare(strict_types=1);

namespace PluginKernel\Async\Job;

/**
 * Interface pour les handlers de jobs.
 */
interface JobHandlerInterface
{
    /**
     * Vérifie si ce handler supporte le type de job donné.
     */
    public function supports(string $jobType): bool;

    /**
     * Traite le job.
     */
    public function handle(JobInterface $job): void;
}
