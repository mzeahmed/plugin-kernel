<?php

declare(strict_types=1);

namespace PluginKernel\Async\Contract;

/**
 * Contrat de logging optionnel pour le JobWorker. Une application consommatrice
 * peut brancher son propre système de logs (base de données, fichier, etc.).
 */
interface JobLoggerInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function logJobFailed(int $jobId, string $message, array $context = []): void;

    /**
     * @param array<string, mixed> $context
     */
    public function logJobCompleted(int $jobId, string $jobType, array $context = []): void;
}
