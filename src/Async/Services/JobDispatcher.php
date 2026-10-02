<?php

declare(strict_types=1);

namespace PluginKernel\Async\Services;

use PluginKernel\Async\Job\JobInterface;
use PluginKernel\Async\Contract\JobQueueRepositoryInterface;

/**
 * Dispatcher de jobs asynchrones.
 */
final readonly class JobDispatcher
{
    public function __construct(
        private JobQueueRepositoryInterface $repository
    ) {
    }

    /**
     * Dispatche un job dans la queue.
     */
    public function dispatch(JobInterface $job, ?\DateTimeInterface $availableAt = null): int
    {
        return $this->repository->push($job, $availableAt);
    }

    /**
     * Dispatche un job depuis un hook WordPress.
     */
    public function dispatchFromHook(mixed $job): void
    {
        if ($job instanceof JobInterface) {
            $this->dispatch($job);
        }
    }
}
