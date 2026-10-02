<?php

declare(strict_types=1);

namespace PluginKernel\Module\Interfaces;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Contrat commun à tous les loaders de modules.
 *
 * Chaque implémentation charge une partie du framework (config, modules,
 * routes, hooks, contrôleurs, assets…), à partir d'un chemin et d'un
 * namespace racine.
 */
interface ModuleLoaderInterface
{
    /**
     * Charge dynamiquement les éléments d'un module (controllers, modules,
     * configs, routes, etc.).
     *
     * @param string $modulesPath Chemin absolu vers le dossier des modules
     * @param string $modulesNamespace Namespace racine des modules
     * @param ContainerInterface|null $container Container DI (optionnel pour les loaders
     *                                           qui en ont besoin)
     *
     * @return mixed Résultat spécifique au loader
     */
    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed;

    /**
     * Convertit un chemin de fichier en FQCN de module.
     *
     * Ex: /src/Modules/Chat/Module.php => MyApp\\Modules\\Chat\\Module
     */
    public function fileToClass(string $file, string $basePath, string $baseNamespace): string;
}
