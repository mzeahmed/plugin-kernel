<?php

declare(strict_types=1);

namespace PluginKernel\Async;

use PluginKernel\Async\Services\JobWorker;
use PluginKernel\Async\Services\JobDispatcher;
use PluginKernel\Async\Discovery\HandlerResolver;
use PluginKernel\Async\Discovery\HandlerDiscovery;
use PluginKernel\Async\Contract\JobLoggerInterface;
use PluginKernel\Async\Contract\JobQueueRepositoryInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Câblage générique de l'infrastructure asynchrone dans un container DI.
 *
 * L'application consommatrice fournit ses propres implémentations des contrats
 * (repository, logger) ainsi que, optionnellement, ses propres conventions pour
 * dériver une factory de job à partir d'un handler résolu.
 */
final class AsyncKernel
{
    /**
     * Enregistre JobDispatcher et JobWorker dans le container à partir des
     * implémentations fournies par l'application.
     */
    public static function register(
        ContainerInterface $container,
        JobQueueRepositoryInterface $repository,
        ?JobLoggerInterface $logger = null,
    ): void {
        $container->set(JobDispatcher::class, static fn (): JobDispatcher => new JobDispatcher($repository));

        $container->set(JobWorker::class, new JobWorker($repository, $logger));
    }

    /**
     * Découvre les handlers marqués #[AsyncHandler] et les enregistre sur le JobWorker.
     *
     * @param callable(string $jobType, string $handlerClass, JobWorker $worker): void|null $onHandlerResolved
     *                                                                                                         Appelé après l'ajout de chaque handler résolu avec succès — permet à l'application
     *                                                                                                         de brancher sa propre convention de factory de job (ex: dérivation Handler -> Job).
     * @param callable(string $handlerClass): void|null $onResolutionFailure
     *                                                                       Appelé si un handler n'a pas pu être instancié (dépendance non résolvable).
     */
    public static function discoverAndRegisterHandlers(
        ContainerInterface $container,
        string $modulesPath,
        string $modulesNamespace,
        ?callable $onHandlerResolved = null,
        ?callable $onResolutionFailure = null,
    ): void {
        /** @var JobWorker $worker */
        $worker = $container->get(JobWorker::class);

        $handlers = HandlerDiscovery::discover($modulesPath, $modulesNamespace);

        foreach ($handlers as $jobType => $handlerClass) {
            $handler = HandlerResolver::resolve($container, $handlerClass);

            if (!$handler) {
                if (null !== $onResolutionFailure) {
                    $onResolutionFailure($handlerClass);
                }

                continue;
            }

            $worker->addHandler($handler);

            if (null !== $onHandlerResolved) {
                $onHandlerResolved($jobType, $handlerClass, $worker);
            }
        }
    }
}
