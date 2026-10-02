<?php

declare(strict_types=1);

namespace PluginKernel\Async\Contract;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Point d'extension du Kernel pour le câblage de l'infrastructure asynchrone.
 *
 * L'application consommatrice fournit une implémentation qui branche ses propres
 * conventions (repository, logger, nommage des jobs, hooks/cron) sur
 * PluginKernel\Async\AsyncKernel — le Kernel générique ne connaît que ce contrat.
 */
interface AsyncBootstrapperInterface
{
    public function register(ContainerInterface $container): void;

    public function registerHooks(): void;

    public function registerCron(): void;

    public function discoverAndRegisterHandlers(ContainerInterface $container, string $modulesPath, string $modulesNamespace): void;
}
