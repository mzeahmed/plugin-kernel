<?php

declare(strict_types=1);

namespace PluginKernel\Async\Services;

use PluginKernel\Async\Job\JobInterface;
use PluginKernel\Async\Job\JobHandlerInterface;
use PluginKernel\Async\Contract\JobLoggerInterface;
use PluginKernel\Async\Contract\JobQueueRepositoryInterface;

/**
 * Worker pour le traitement des jobs asynchrones.
 * Permet l'ajout dynamique de handlers par les modules.
 */
final class JobWorker
{
    /** Transient de verrou empêchant deux exécutions concurrentes du worker. */
    private const string LOCK_KEY = 'plugin_kernel_job_worker_lock';

    private const int BATCH_SIZE = 5;
    private const int MAX_ATTEMPTS = 3;

    /**
     * @var array<JobHandlerInterface>
     */
    private array $handlers = [];

    /**
     * @var array<string, callable>
     */
    private array $jobFactories = [];

    public function __construct(
        private readonly JobQueueRepositoryInterface $repository,
        private readonly ?JobLoggerInterface $logger = null
    ) {
    }

    /**
     * Ajoute un handler de job.
     */
    public function addHandler(JobHandlerInterface $handler): void
    {
        $this->handlers[] = $handler;
    }

    /**
     * Enregistre une factory pour créer des instances de job.
     *
     * @param string $jobType Le type de job
     * @param callable $factory Une fonction qui prend un array et retourne un JobInterface
     */
    public function registerJobFactory(string $jobType, callable $factory): void
    {
        $this->jobFactories[$jobType] = $factory;
    }

    /**
     * Exécute le worker.
     */
    public function run(): void
    {
        if (!$this->acquireLock()) {
            return;
        }

        try {
            $jobs = $this->repository->getPending(self::BATCH_SIZE);

            foreach ($jobs as $jobData) {
                $this->processJob($jobData);
            }
        } finally {
            $this->releaseLock();
        }
    }

    private function processJob(object $jobData): void
    {
        $jobId = (int) $jobData->id;
        $jobType = $jobData->job_type;

        try {
            $this->repository->markProcessing($jobId);

            $handler = $this->findHandler($jobType);
            if (!$handler) {
                $this->logError($jobId, "No handler found for job type: {$jobType}");
                $this->repository->markFailed($jobId);

                return;
            }

            $payload = json_decode($jobData->payload, true);
            if (!\is_array($payload)) {
                $this->logError($jobId, "Invalid payload for job {$jobId}");
                $this->repository->markFailed($jobId);

                return;
            }

            $job = $this->createJobInstance($jobType, $payload);
            if (!$job) {
                $this->logError($jobId, "Failed to create job instance for type: {$jobType}");
                $this->repository->markFailed($jobId);

                return;
            }

            $handler->handle($job);

            $this->repository->markDone($jobId);

            $this->logSuccess($jobId, $jobType);
        } catch (\Throwable $e) {
            $this->logError($jobId, $e->getMessage());

            if ((int) $jobData->attempts < self::MAX_ATTEMPTS - 1) {
                $this->repository->retryJob($jobId, self::MAX_ATTEMPTS);
            } else {
                $this->repository->markFailed($jobId);
            }
        }
    }

    private function findHandler(string $jobType): ?JobHandlerInterface
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($jobType)) {
                return $handler;
            }
        }

        return null;
    }

    private function createJobInstance(string $jobType, array $payload): ?JobInterface
    {
        if (isset($this->jobFactories[$jobType])) {
            $factory = $this->jobFactories[$jobType];

            return $factory($payload);
        }

        return null;
    }

    /**
     * Acquiert un lock pour éviter les exécutions concurrentes.
     */
    private function acquireLock(): bool
    {
        if (get_transient(self::LOCK_KEY)) {
            return false;
        }

        set_transient(self::LOCK_KEY, true, 30);

        return true;
    }

    private function releaseLock(): void
    {
        delete_transient(self::LOCK_KEY);
    }

    private function logError(int $jobId, string $message): void
    {
        $this->logger?->logJobFailed($jobId, $message, ['job_id' => $jobId]);
    }

    private function logSuccess(int $jobId, string $jobType): void
    {
        $this->logger?->logJobCompleted($jobId, $jobType, ['job_id' => $jobId, 'job_type' => $jobType]);
    }
}
