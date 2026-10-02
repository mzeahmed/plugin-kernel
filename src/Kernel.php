<?php

declare(strict_types=1);

namespace PluginKernel;

use PluginKernel\Ajax\AjaxRouter;
use PluginKernel\Cron\CronLoader;
use PluginKernel\DI\ModuleContainer;
use PluginKernel\Cron\CronCollection;
use PluginKernel\Module\ModuleLoader;
use PluginKernel\Ajax\AjaxRouteLoader;
use PluginKernel\DI\ContainerRegistry;
use PluginKernel\Rest\RestRouteLoader;
use PluginKernel\Routing\WebRouteLoader;
use PluginKernel\PostType\PostTypeLoader;
use PluginKernel\PostType\TaxonomyLoader;
use PluginKernel\Rest\RestAuthMiddleware;
use PluginKernel\Ajax\AjaxRouteCollection;
use PluginKernel\Assets\ModuleAssetLoader;
use PluginKernel\Assets\ModuleBlockLoader;
use PluginKernel\Rest\HybridAuthenticator;
use PluginKernel\DI\SymfonyContainerLoader;
use PluginKernel\Controller\ControllerLoader;
use PluginKernel\Module\ModuleLoaderRegistry;
use PluginKernel\Async\Contract\AsyncBootstrapperInterface;
use PluginKernel\Rest\Contract\BearerTokenResolverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Kernel générique pour un plugin WordPress modulaire : bootstrap DI Symfony,
 * découverte/activation des modules, routage AJAX/REST/web par attribut, assets par
 * module, infrastructure asynchrone.
 *
 * Tout ce qui est propre à l'application consommatrice (chemins, namespaces, textes,
 * conventions d'authentification/async) est fourni au constructeur — cette classe ne
 * référence aucune constante ni fonction spécifique à une application donnée.
 */
final class Kernel
{
    private ContainerInterface $container;
    private AjaxRouter $ajaxRouter;
    private ModuleLoader $moduleLoader;

    /** @var array<int, array{0: string, 1: string}> Sources de modules additionnelles (path, namespace). */
    private array $additionalModuleSources = [];

    /**
     * @param array<string, mixed> $envParameters Paramètres exposés au container Symfony (%env(...)%)
     * @param string[] $watchedDirectories Répertoires surveillés en mode dev pour déclencher un rebuild du container
     */
    public function __construct(
        private readonly string $modulesPath,
        private readonly string $modulesNamespace,
        private readonly string $configPath,
        private readonly string $cachePath,
        private readonly string $cacheClass,
        private readonly string $cacheNamespace,
        private readonly bool $isDev,
        private readonly string $pluginPath,
        private readonly string $pluginUrl,
        private readonly string $pluginVersion,
        private readonly string $pluginFile,
        private readonly string $textDomain,
        private readonly string $assetHandlePrefix,
        private readonly string $restNamespace,
        private readonly BearerTokenResolverInterface $bearerResolver,
        private readonly array $envParameters = [],
        private readonly array $watchedDirectories = [],
        private readonly ?\Closure $authorize = null,
        private readonly ?AsyncBootstrapperInterface $async = null,
    ) {
    }

    /**
     * Enregistre une source de modules supplémentaire (ex: le dossier `src/Modules` d'un
     * plugin add-on) sur ce Kernel déjà instancié, pour que ses Module.php/config.php soient
     * chargés au même titre que ceux de la source principale.
     *
     * Doit être appelée avant `boot()` — un appel après n'a aucun effet, les loaders ont déjà
     * été exécutés.
     */
    public function addModuleSource(string $modulesPath, string $modulesNamespace): void
    {
        $this->additionalModuleSources[] = [$modulesPath, $modulesNamespace];
    }

    /**
     * Boot complet du Kernel.
     */
    public function boot(): void
    {
        // 1. DI Symfony
        $this->initContainer();
        // 2. Initialise l'infrastructure asynchrone
        $this->initAsyncInfrastructure();
        // 3. Initialise le router AJAX
        $this->initAjaxRouter();
        // 4. Prépare le loader unifié des Modules
        $this->initModuleLoader();
        $this->initRestMiddleware();
        // 5. Tous les loaders dans un ordre strict
        $this->bootLoaders();
        // 6. Découvrir et enregistrer les handlers asynchrones (après chargement des modules)
        $this->discoverAsyncHandlers();
        // 7. Assets (admin + modules)
        ModuleAssetLoader::register(
            modules: $this->moduleLoader,
            pluginPath: $this->pluginPath,
            pluginUrl: $this->pluginUrl,
            pluginVersion: $this->pluginVersion,
            pluginFile: $this->pluginFile,
            textDomain: $this->textDomain,
            handlePrefix: $this->assetHandlePrefix,
        );
        // 8. Blocs Gutenberg des modules (Assets/blocks/*/block.json)
        ModuleBlockLoader::register(
            modules: $this->moduleLoader,
            container: $this->container,
            pluginPath: $this->pluginPath,
            pluginUrl: $this->pluginUrl,
            textDomain: $this->textDomain,
            handlePrefix: $this->assetHandlePrefix,
        );
    }

    /**
     * Retourne le routeur AJAX global.
     */
    public function ajaxRouter(): AjaxRouter
    {
        return $this->ajaxRouter;
    }

    /**
     * Retourne le container DI Symfony.
     */
    public function container(): ContainerInterface
    {
        return $this->container;
    }

    /**
     * 1. Initialisation du container Symfony
     */
    private function initContainer(): void
    {
        $base = SymfonyContainerLoader::load(
            configPath: $this->configPath,
            cachePath: $this->cachePath,
            cacheClass: $this->cacheClass,
            cacheNamespace: $this->cacheNamespace,
            isDev: $this->isDev,
            envParameters: $this->envParameters,
            watchedDirectories: $this->watchedDirectories,
        );
        // Wrap in ModuleContainer to enable runtime module services & reflection DI
        $this->container = new ModuleContainer($base);
        // Expose globally for runtime resolution (controllers, hooks, toolkits)
        ContainerRegistry::set($this->container);
    }

    /**
     * 2. Initialisation de l'infrastructure asynchrone
     */
    private function initAsyncInfrastructure(): void
    {
        if (null === $this->async) {
            return;
        }

        $this->async->register($this->container);
        $this->async->registerHooks();
        $this->async->registerCron();
    }

    /**
     * 3. ModuleLoader unifié — Activation + initialisation des Modules
     */
    private function initModuleLoader(): void
    {
        // Ajoute les routes Ajax explicites déclarées dans les Modules
        $this->moduleLoader = new ModuleLoader($this->ajaxRouter->routes());
    }

    /**
     * 3. Routeur AJAX global (AjaxRouter)
     */
    private function initAjaxRouter(): void
    {
        $this->ajaxRouter = new AjaxRouter(
            $this->container, // Symfony DI
            new AjaxRouteCollection()   // Collecteur des routes attribuées
        );
    }

    /**
     * 4. Tous les ModuleLoaders dans un ordre strict et obligatoire.
     */
    private function bootLoaders(): void
    {
        $registry = new ModuleLoaderRegistry();
        // IMPORTANT : l'ordre exact compte

        // 1) Modules (Module.php → register hooks, register ajax routes)
        $registry->addLoader($this->moduleLoader);
        // 2) Web routes (HasWebRoutes::registerRoutes — dépend uniquement de ModuleLoader)
        $registry->addLoader(new WebRouteLoader($this->moduleLoader));
        // 3) Post Types (Domain/PostType/*PostType.php)
        $registry->addLoader(new PostTypeLoader($this->moduleLoader->isActive(...)));
        // 3bis) Taxonomies (Domain/PostType/*Taxonomy.php)
        $registry->addLoader(new TaxonomyLoader($this->moduleLoader->isActive(...)));
        // 4) Routes AJAX détectées par attribut #[AjaxRoute]
        $registry->addLoader(new AjaxRouteLoader($this->ajaxRouter->routes()));
        // 5) Cron Loader (modules can declare cron events/schedules via Module::registerCron)
        $registry->addLoader(new CronLoader(
            isModuleActive: $this->moduleLoader->isActive(...),
            cronCollectionClass: CronCollection::class,
        ));
        // 6) REST Attribute routes (#[RestRoute] on controller methods)
        $registry->addLoader(new RestRouteLoader(
            defaultNamespace: $this->restNamespace,
            isModuleActive: $this->moduleLoader->isActive(...),
            authenticator: new HybridAuthenticator($this->bearerResolver, $this->authorize),
        ));
        // 7) Controllers des Modules (ControllerKit, injection, etc.)
        $registry->addLoader(new ControllerLoader());

        // Exécution globale : la source principale, puis chaque source additionnelle
        // enregistrée via addModuleSource() (ex: un plugin add-on). Tous les loaders
        // sont additifs (cf. ModuleLoader, RestRouteLoader, AjaxRouteLoader...) — un appel par
        // source ne fait qu'ajouter ses modules/routes/hooks à ceux déjà collectés.
        $registry->loadAll($this->modulesPath, $this->modulesNamespace, $this->container);

        foreach ($this->additionalModuleSources as [$modulesPath, $modulesNamespace]) {
            $registry->loadAll($modulesPath, $modulesNamespace, $this->container);
        }
    }

    private function initRestMiddleware(): void
    {
        RestAuthMiddleware::register(
            resolver: $this->bearerResolver,
            authorize: $this->authorize,
        );
    }

    /**
     * Découvre et enregistre les handlers asynchrones après le chargement des modules.
     * Parcourt la source principale puis chaque source additionnelle enregistrée via
     * addModuleSource(), comme bootLoaders().
     */
    private function discoverAsyncHandlers(): void
    {
        if (null === $this->async) {
            return;
        }

        $this->async->discoverAndRegisterHandlers($this->container, $this->modulesPath, $this->modulesNamespace);

        foreach ($this->additionalModuleSources as [$modulesPath, $modulesNamespace]) {
            $this->async->discoverAndRegisterHandlers($this->container, $modulesPath, $modulesNamespace);
        }
    }
}
