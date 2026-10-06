# Changelog

Tous les changements notables de ce package sont documentés dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/), et ce projet
adhère au [Semantic Versioning](https://semver.org/lang/fr/). Chaque version correspond à un
tag Git ; `[Unreleased]` regroupe ce qui est commité mais pas encore tagué.

## [Unreleased]

### Changed

- Outils de qualité : Rector passe en `^2.7` (PHPStan 2.3 requis, déjà permis par `^2.2`), et
  `rector.php` charge les stubs WordPress (`withBootstrapFiles()`). `make rector` propose
  désormais les mêmes transformations ici qu'à la racine du monorepo. Sans changement de
  comportement.
- Transformations Rector appliquées, sans changement de comportement :
  - `CronRouter` : suppression des casts redondants sur les événements, déjà typés par
    `CronCollection::event()`, seul point d'entrée de la collection ;
  - `RestRouteLoader` : la `permission_callback` par défaut devient `is_user_logged_in(...)`
    au lieu de `static fn () => is_user_logged_in()`.

## [0.2.2] - 2026-10-02

### Added

- `Kernel` : paramètre optionnel `blockCategory` (titre). `ModuleBlockLoader` crée alors une
  catégorie d'inserteur Gutenberg (slug `sanitize_title()`, placée en tête) et y range tous les
  blocs des modules, à la place de la `category` de leur `block.json`. Sans ce paramètre,
  comportement inchangé.

## [0.2.1] - 2026-10-02

### Added

- Outils de qualité en `require-dev` (php-cs-fixer, PHPStan + phpstan-wordpress, Rector), leur
  configuration (`.php-cs-fixer.php`, `phpstan.neon`, `rector.php`) et un `Makefile`
  (`help`, `install`, `lint`/`lintf`, `rector`/`rectorf`, `stan`).
- Règles php-cs-fixer custom (`tools/CsFixer/`) : `PluginKernel/split_method_attribute_args`
  (un argument par ligne sur les attributs de méthode à 2+ arguments) et
  `PluginKernel/blank_line_after_control_structure`.

### Changed

- Mise en forme selon `PluginKernel/blank_line_after_control_structure` (ligne vide après un
  bloc de contrôle) dans `HookRouter` et `ModuleLoader`, sans changement de comportement.

### Fixed

- `BaseRouter` : suppression de l'affectation `$wp_query->is_front_page = false`, sans effet
  (`WP_Query` n'a pas de propriété de ce nom, seulement la méthode `is_front_page()`).
- `AjaxResponse` : annotation `@phpstan-consistent-constructor`, qui documente que les
  sous-classes doivent garder un constructeur compatible avec `new static()`.

## [0.2.0] - 2026-10-02

Première version publique.

### Added

- **Kernel** (`PluginKernel\Kernel`) — orchestrateur racine générique : bootstrap DI, boot des
  modules/routes/assets/jobs asynchrones dans un ordre strict, entièrement paramétré au
  constructeur (aucune dépendance à une constante ou fonction applicative).
  Support multi-plugins via `addModuleSource()`.
- **Système de modules** (`PluginKernel\Module\*`) — découverte/activation par convention
  `Module.php` + `config.php` (duck-typing, aucune interface obligatoire), `ModuleLoader`,
  `ModuleLoaderRegistry`, `ModuleConfigRegistry` (état runtime, contourne le `ParameterBag`
  Symfony figé après compilation).
- **DI** (`PluginKernel\DI\*`) — `SymfonyContainerLoader` (compile/cache un container Symfony
  depuis un `services.yml`, invalidation par mtime en mode dev), `ModuleContainer` (décorateur
  avec autowiring de secours par réflexion), `ContainerRegistry`, `RuntimeServiceRegistry`
  (services déclarés dynamiquement par les modules, hors container compilé).
- **Routes AJAX** (`PluginKernel\Ajax\*`) — attribut `#[AjaxRoute]`, `AjaxRouteCollection`,
  `AjaxRouteLoader`, `AjaxRouter` (dispatch, pipeline de middlewares, résolution du contrôleur
  container-first avec fallback réflexion).
- **Routes REST** (`PluginKernel\Rest\*`) — attribut `#[RestRoute]` (repeatable), `RestRouteLoader`
  (résolution paresseuse du contrôleur, permissions `public`/`logged_in`/`administrator`/callback
  explicite), `RestAuthMiddleware` (hydratation `wp_set_current_user()` par token porteur sur
  `rest_pre_dispatch`, idempotent), `HybridAuthenticator` (fallback cookie/Bearer),
  `JwtTokenResolver` (JWT via `firebase/php-jwt`), `Contract\BearerTokenResolverInterface`
  (format de token agnostique), `Support\BearerToken` (extraction partagée du header).
- **Routes web** (`PluginKernel\Routing\*`) — façade statique `Route`, `BaseRouter`/`BaseRoute`
  (rewrite rules WordPress, placeholders `{name}` avec contraintes), `RouteGuardRunner` +
  `Interface\RouteGuardInterface`, `HasWebRoutes`/`WebRouteCollection`/`PendingWebRoute`/
  `WebRouteLoader` (routes déclarées par module).
- **Hooks** (`PluginKernel\Hook\*`) — `HookCollection`, `HookRouter` (résolution de callback
  container/réflexion, `sanitize_key()` sur les shortcodes).
- **Cron** (`PluginKernel\Cron\*`) — `CronCollection`, `CronLoader`, `CronRouter` (fréquences
  personnalisées, planification idempotente au premier `init`).
- **Post Types & Taxonomies** (`PluginKernel\PostType\*`) — `PostTypeLoader`/`TaxonomyLoader` +
  interfaces opt-in (`PostTypeDefinitionInterface`, `TaxonomyDefinitionInterface`,
  `HasCustomColumnsInterface`, `HasMetaboxInterface`, `HasPostMetaBehaviorInterface`,
  `HasTaxonomyBehaviorInterface`).
- **Contrôleurs** (`PluginKernel\Controller\*`) — `ControllerLoader` (autowiring des contrôleurs
  découverts), `ControllerKit` (trait : service locator, paramètres de requête, réponses
  AJAX/REST standardisées, détection du mode d'authentification).
- **Middlewares** (`PluginKernel\Middleware\*`) — `MiddlewareInterface`, `MiddlewareQueue`,
  `AuthMiddleware`, `NonceMiddleware`, `AdminMiddleware`, `ValidationMiddleware`.
- **HTTP** (`PluginKernel\Http\*`) — `AjaxRequest`, `AjaxResponse`, `Response\RestResponseFactory`.
- **Assets** (`PluginKernel\Assets\*`) — `ModuleAssetLoader` (enqueue JS/CSS par module actif,
  résolution via manifest Webpack + fichiers `.asset.php`, gate conditionnel par `config.php`).
- **Jobs asynchrones** (`PluginKernel\Async\*`) — attribut `#[AsyncHandler]`, `JobInterface`/
  `JobHandlerInterface`, `HandlerDiscovery`, `Discovery\HandlerResolver` (résolution par
  réflexion, fail-soft), `JobDispatcher`, `JobWorker`, `AsyncKernel` (câblage générique avec
  callbacks d'extension `$onHandlerResolved`/`$onResolutionFailure`),
  `Contract\JobQueueRepositoryInterface`, `Contract\JobLoggerInterface` (optionnel),
  `Contract\AsyncBootstrapperInterface` (point d'extension injecté au Kernel).
- **Événements** (`PluginKernel\Event\*`) — `EventDispatcher` (pub/sub sur `add_action`/
  `do_action`), `AbstractEvent`, `Contract\EventDispatcherInterface`, `Contract\NamedEventInterface`.
- **Validation** (`PluginKernel\Validation\*`) — `ValidatorFactory` (bootstrap
  `symfony/validator` avec mapping par attributs, fallback si indisponible).
- `PluginKernel\Contract\NonceVerifierInterface`.
- Documentation : `README.md` (installation, démarrage rapide, référence de chaque brique,
  contrats à implémenter, pièges connus) et `TROUBLESHOOTING.md` (dépannage par symptôme).
- **Finders de fichiers** (`PluginKernel\FileSystem\Finder\*`) — `FinderInterface`, `GlobFinder`,
  `RecursiveFinder`, `WordPressFinder`, centralisés depuis l'application consommatrice.
- `JwtTokenResolver::createToken()` : claims standards `aud`/`sub`/`nbf`/`jti` en plus du claim
  `$userIdClaim` (`user_id` par défaut), pour qu'un vérificateur tiers (ex. une API externe)
  puisse valider le token. `jti` ne fait l'objet d'aucune liste de révocation à ce stade.
- **Blocs Gutenberg par module** (`PluginKernel\Assets\ModuleBlockLoader`) — enregistrement
  automatique des blocs `<Module>/Assets/blocks/<bloc>/block.json` des modules activés (script
  éditeur et styles résolus via le manifest Webpack `modules/<module>/blocks/<bloc>/…`, callback de
  rendu optionnel via `config.php['blocks']`). Branché dans `Kernel::boot()`.
- **ModuleLoader** — chaque module expose le chemin de son dossier (`path`) dans
  `active()`, nécessaire pour retrouver ses fichiers quel que soit sa source.

### Changed

Par rapport aux versions 0.1.x, utilisées en interne avant la publication :

- Package renommé `mzeahmed/plugin-kernel`, sous licence MIT.
- `JwtTokenResolver` : audience par défaut `'plugin-kernel'` (paramètre `$audience`).
- `BaseRouter` : l'option signalant un flush des rewrite rules est exposée en constante
  `BaseRouter::FLUSH_REWRITE_OPTION` (`'plugin_kernel_flush_rewrite_rules'`) ; le flush reste
  à la charge de l'application (voir README, « Flush des rewrite rules »).
- `JobWorker` : transient de verrou renommé `plugin_kernel_job_worker_lock`.
