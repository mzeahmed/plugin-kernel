<?php

declare(strict_types=1);

namespace PluginKernel\Async\Contract;

use PluginKernel\Async\Job\JobInterface;

/**
 * Contrat de persistance d'une file de jobs asynchrones. L'implémentation
 * concrète (table SQL, Redis, etc.) reste à la charge de l'application
 * consommatrice — ce package ne fournit qu'orchestration générique.
 */
interface JobQueueRepositoryInterface
{
    /**
     * Ajoute un job dans la file. Retourne l'identifiant du job créé.
     */
    public function push(JobInterface $job, ?\DateTimeInterface $availableAt = null): int;

    /**
     * Récupère les jobs en attente (statut pending, disponibles maintenant), triés par ancienneté.
     *
     * @return array<int, object> Chaque élément doit exposer au minimum : id, job_type, payload, attempts
     */
    public function getPending(int $limit = 5): array;

    public function markProcessing(int $id): bool;

    public function markDone(int $id): bool;

    public function markFailed(int $id): bool;

    /**
     * Remet un job en pending pour retry si le nombre de tentatives n'a pas atteint $maxAttempts.
     */
    public function retryJob(int $id, int $maxAttempts = 3): bool;
}
