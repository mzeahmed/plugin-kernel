<?php

declare(strict_types=1);

namespace PluginKernel\Module;

use PluginKernel\Hook\HookRouter;
use PluginKernel\Hook\HookCollection;
use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ModuleLoader
 *
 * Charge automatiquement tous les modules d'une application WordPress :
 *
 *  - Recherche <modulesPath>/*​/Module.php
 *  - Vérifie si le module est activé via sa config (config.php)
 *  - Appelle Module::register($hooks) si la méthode existe
 *  - Appelle Module::registerAjax($routes) si la méthode existe
 *
 * Un "module" n'a pas besoin d'implémenter une interface particulière : seule
 * la présence des méthodes register()/registerAjax() (duck typing) est requise,
 * ce qui permet à ce loader de rester indépendant de tout contrat métier propre
 * à une application consommatrice.
 *
 * Objectif : encapsuler la logique d'activation + initialisation transversale.
 */
class ModuleLoader implements ModuleLoaderInterface
{
    /** @var array<string, array{enabled: bool, config: array}> */
    private array $enabledModules = [];

    /** @var array<string, object> Instances des modules actifs, indexées par nom de module. */
    private array $moduleInstances = [];

    /**
     * @param mixed $ajaxRoutes Collection transmise telle quelle à Module::registerAjax()
     *                          si cette méthode existe (ex: une AjaxRouteCollection).
     * @param class-string $hookCollectionClass FQCN à instancier/résoudre via le container pour
     *                                          la collection de hooks transmise à Module::register(). À surcharger par
     *                                          une application consommatrice si son Module::register() type son
     *                                          paramètre sur sa propre sous-classe de HookCollection (les types PHP
     *                                          n'acceptent pas qu'une instance de la classe parente satisfasse un
     *                                          type-hint sur une classe enfant).
     */
    public function __construct(
        private readonly mixed $ajaxRoutes = null,
        private readonly string $hookCollectionClass = HookCollection::class,
    ) {
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        // Prépare la liste des modules actifs à partir de chaque Module/config.php de CETTE source,
        // puis la fusionne dans l'état global. load() peut être appelée plusieurs fois avec des
        // sources différentes : un appel ne doit jamais effacer les modules déjà collectés par un
        // appel précédent.
        $modulesFromThisSource = $this->loadModulesFromModuleConfigs($modulesPath);
        $this->enabledModules = [...$this->enabledModules, ...$modulesFromThisSource];

        if (!$container instanceof ContainerInterface) {
            return false;
        }

        /** @var HookCollection $hooks */
        $hooks = $container->get($this->hookCollectionClass);

        // RecursiveDirectoryIterator restitue les entrées dans l'ordre natif du
        // système de fichiers (readdir()), non garanti et potentiellement
        // différent d'un environnement à l'autre (ex: volume Docker en local vs
        // disque du serveur après un rsync) — contrairement à glob(), qui trie
        // alphabétiquement par défaut. Comme WordPress affiche les sous-menus
        // dans l'ordre d'appel de add_submenu_page() (aucun tri côté core), un
        // ordre de découverte non déterministe change l'ordre d'affichage des
        // menus entre environnements à code strictement identique. On force
        // donc un tri stable par chemin avant d'itérer.
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesPath));
        $files = iterator_to_array($iterator, false);
        usort($files, static fn (\SplFileInfo $a, \SplFileInfo $b): int => $a->getPathname() <=> $b->getPathname());

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Un module doit s'appeler strictement "Module.php"
            if ('Module.php' !== $file->getFilename()) {
                continue;
            }

            $fqcn = $this->fileToClass($file->getPathname(), $modulesPath, $modulesNamespace);
            if (!class_exists($fqcn)) {
                continue;
            }

            // Ex: /src/Modules/Chat/Module.php → Chat
            $moduleName = basename($file->getPath());

            // module désactivée → on skip
            if (!$this->isActive($moduleName)) {
                continue;
            }

            // Instancie le module et appelle ses méthodes d'enregistrement
            try {
                $module = new $fqcn();
            } catch (\Throwable) {
                continue;
            }

            $this->moduleInstances[$moduleName] = $module;

            if (method_exists($module, 'register')) {
                try {
                    $module->register($hooks);
                } catch (\Throwable) {
                    // noop
                }
            }

            if ($this->ajaxRoutes && method_exists($module, 'registerAjax')) {
                try {
                    $module->registerAjax($this->ajaxRoutes);
                } catch (\Throwable) {
                    // noop
                }
            }
        }

        // Après collecte, on enregistre tous les hooks dans WordPress via HookRouter
        (new HookRouter($container))->register($hooks);

        // Ne câble que les listeners des modules de CETTE source : $this->enabledModules contient
        // aussi les sources précédemment chargées, dont les listeners sont déjà câblés (sinon un
        // second appel à load() re-enregistrerait deux fois les listeners de la première source).
        $this->initEventDispatcher($modulesFromThisSource, $container);

        return true;
    }

    public function fileToClass(string $file, string $basePath, string $baseNamespace): string
    {
        $relative = substr($file, \strlen($basePath));
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        $relative = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        return $baseNamespace . '\\' . $relative;
    }

    /**
     * Retourne les instances des modules actifs, indexées par nom de module.
     *
     * @return array<string, object>
     */
    public function getModules(): array
    {
        return $this->moduleInstances;
    }

    /**
     * Retourne la liste des modules activés sous la forme [ModuleName => true].
     */
    public function active(): array
    {
        return array_filter($this->enabledModules, static fn ($v) => (bool) $v);
    }

    /**
     * Indique si un module est actif.
     */
    public function isActive(string $moduleName): bool
    {
        if (!isset($this->enabledModules[$moduleName])) {
            return false;
        }
        $module = $this->enabledModules[$moduleName];

        return \is_array($module) && (bool) ($module['enabled'] ?? false);
    }

    /**
     * Lit les fichiers config.php de chaque module pour déterminer l'activation.
     * Compatibilité : supporte plusieurs clés : 'module.enabled', '<slug>.enabled', ou 'enabled'.
     * Supporte également les dépendances croisées via '<slug>.disabled_modules'.
     *
     * `path` : dossier absolu du module — les modules pouvant venir de plusieurs
     * sources ({@see \PluginKernel\Kernel::addModuleSource()}), c'est la seule façon
     * fiable de retrouver leurs fichiers (ex: Assets/blocks/, voir ModuleBlockLoader).
     *
     * @return array<string, array{enabled: bool, config: array, path: string}>
     */
    private function loadModulesFromModuleConfigs(string $path): array
    {
        $enabled = [];
        $disabledByOthers = [];
        $dirs = glob($path . '/*', GLOB_ONLYDIR) ?: [];

        // Premier passage : charger les configurations et déterminer l'état de base
        foreach ($dirs as $dir) {
            $moduleName = basename($dir);
            $isEnabled = true;
            $configFile = $dir . '/config.php';

            $configs = [];

            if (file_exists($configFile)) {
                $config = include $configFile;

                if (\is_array($config)) {
                    $slug = strtolower($moduleName);

                    // Vérifier si le module est activé
                    if (\array_key_exists('module.enabled', $config)) {
                        $isEnabled = (bool) $config['module.enabled'];
                    } elseif (\array_key_exists($slug . '.enabled', $config)) {
                        $isEnabled = (bool) $config[$slug . '.enabled'];
                    } elseif (\array_key_exists('enabled', $config)) {
                        $isEnabled = (bool) $config['enabled'];
                    }

                    // Si le module est activé, vérifier s'il désactive d'autres modules
                    if ($isEnabled) {
                        $disabledModulesKey = $slug . '.disabled_modules';
                        if (\array_key_exists($disabledModulesKey, $config) && \is_array($config[$disabledModulesKey])) {
                            foreach ($config[$disabledModulesKey] as $disabledModule) {
                                $disabledByOthers[$disabledModule] = $moduleName;
                            }
                        }
                    }
                }

                $configs = [
                    'enabled' => $isEnabled,
                    'config' => $config,
                    'path' => $dir,
                ];
            }

            $enabled[$moduleName] = $configs;
        }

        // Deuxième passage : appliquer les désactivations croisées
        foreach ($disabledByOthers as $disabledModule => $disablingModule) {
            if (isset($enabled[$disabledModule]) && $enabled[$disabledModule]) {
                $enabled[$disabledModule] = [
                    'enabled' => false,
                    'config' => $enabled[$disabledModule]['config'],
                    'path' => $enabled[$disabledModule]['path'],
                ];
            }
        }

        return $enabled;
    }

    private function initEventDispatcher(array $enabledModules, ContainerInterface $container): void
    {
        foreach ($enabledModules as $module) {
            $listeners = $module['config']['listeners'] ?? [];

            if (!$listeners) {
                continue;
            }

            foreach ($listeners as $event => $listenerClasses) {
                foreach ($listenerClasses as $listenerClass) {
                    $listener = $container->has($listenerClass)
                        ? $container->get($listenerClass)
                        : new $listenerClass();

                    add_action(
                        $event,
                        [$listener, '__invoke'],
                        10,
                        1
                    );
                }
            }
        }
    }
}
