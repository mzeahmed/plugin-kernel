<?php

declare(strict_types=1);

namespace PluginKernel\Module;

use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registre central des loaders (basés sur ModuleLoaderInterface).
 *
 * Le Kernel enregistre ici les différents loaders responsables de charger
 * dynamiquement la configuration, les modules, les routes, les hooks,
 * et les contrôleurs des modules.
 */
class ModuleLoaderRegistry
{
    /** @var ModuleLoaderInterface[] */
    private array $loaders = [];

    public function addLoader(ModuleLoaderInterface $loader): void
    {
        $this->loaders[] = $loader;
    }

    /**
     * Exécute tous les loaders enregistrés dans l'ordre.
     */
    public function loadAll(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): void
    {
        foreach ($this->loaders as $loader) {
            try {
                $loader->load($modulesPath, $modulesNamespace, $container);
            } catch (\Throwable $e) {
                error_log('[PluginKernel] Loader failed: ' . $loader::class . ' - ' . $e->getMessage());
            }
        }
    }
}
