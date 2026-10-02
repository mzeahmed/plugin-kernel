# mzeahmed/plugin-kernel

![PHP](https://img.shields.io/badge/PHP-%3E%3D8.3-777bb4?logo=php&logoColor=white)
![WordPress](https://img.shields.io/badge/WordPress-compatible-21759b?logo=wordpress&logoColor=white)
![Symfony](https://img.shields.io/badge/Symfony-7.4-000000?logo=symfony&logoColor=white)
![Type](https://img.shields.io/badge/type-library-blue)
![Stability](https://img.shields.io/badge/stability-dev-yellow)
![License](https://img.shields.io/badge/license-MIT-green)

Noyau réutilisable pour bâtir un plugin WordPress modulaire : système de modules
(découverte/activation), bootstrap DI Symfony, routage AJAX/REST/web par attribut, cron,
custom post types/taxonomies, middlewares, assets et blocs Gutenberg par module, jobs
asynchrones.

**Philosophie du package** : chaque brique est générique et ne connaît rien de l'application
qui l'utilise. Tout ce qui est spécifique (chemins, textes, persistance, conventions de nommage,
autorisation) est soit injecté en paramètre, soit délégué à un contrat (`interface`) que
l'application implémente. Le package ne fait aucune supposition sur la structure de données
métier, la base de données, ou le framework de templating utilisé.

## Sommaire

- [Installation](#installation)
- [Démarrage rapide](#démarrage-rapide)
- [Vue d'ensemble](#vue-densemble)
- [Kernel](#kernel)
- [Système de modules](#système-de-modules)
- [Injection de dépendances (DI)](#injection-de-dépendances-di)
- [Routes AJAX](#routes-ajax)
- [Routes REST](#routes-rest)
- [Routes web](#routes-web)
- [Hooks WordPress](#hooks-wordpress)
- [Cron](#cron)
- [Custom Post Types & Taxonomies](#custom-post-types--taxonomies)
- [Contrôleurs](#contrôleurs)
- [Middlewares](#middlewares)
- [Assets par module](#assets-par-module)
- [Jobs asynchrones](#jobs-asynchrones)
- [Événements](#événements)
- [Validation](#validation)
- [Contrats à implémenter (récapitulatif)](#contrats-à-implémenter-récapitulatif)
- [Pièges connus](#pièges-connus-à-lire-avant-détendre-ce-package)
- [Développement](#développement)

Voir aussi : [CHANGELOG.md](CHANGELOG.md) (historique des versions) et
[TROUBLESHOOTING.md](TROUBLESHOOTING.md) (dépannage par symptôme).

## Installation

Le package n'est pas encore publié sur Packagist : déclarez le dépôt GitHub comme dépôt VCS.

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/mzeahmed/plugin-kernel"
    }
  ],
  "require": {
    "mzeahmed/plugin-kernel": "^0.2"
  }
}
```

```bash
composer require mzeahmed/plugin-kernel:^0.2
```

Si vous n'avez besoin de la classe que pour l'analyse statique/l'IDE (cas d'un plugin qui se
greffe sur un Kernel déjà démarré par un autre plugin, cf. `addModuleSource()`), déclarez-la en
`require-dev` plutôt qu'en `require`.

**Dépendances** : `symfony/dependency-injection`, `symfony/config`, `symfony/yaml`,
`symfony/validator`, `firebase/php-jwt` (toutes tirées automatiquement par Composer).

## Démarrage rapide

Exemple minimal de `Bootstrap.php` (point d'entrée d'un plugin), à adapter :

```php
use PluginKernel\Kernel;
use PluginKernel\Rest\JwtTokenResolver;

add_action('plugins_loaded', function () {
    $kernel = new Kernel(
        modulesPath: MY_PLUGIN_PATH . 'src/Modules',
        modulesNamespace: 'MyPlugin\\Modules',
        configPath: MY_PLUGIN_PATH . 'config/services.yml',
        cachePath: MY_PLUGIN_CACHE_PATH . 'DiContainerCache.php',
        cacheClass: 'DiContainerCache',
        cacheNamespace: 'MyPlugin\\Infrastructure\\Cache\\DI',
        isDev: WP_DEBUG,
        pluginPath: MY_PLUGIN_PATH,
        pluginUrl: MY_PLUGIN_URL,
        pluginVersion: '1.0.0',
        pluginFile: MY_PLUGIN_FILE,
        textDomain: 'my-plugin',
        assetHandlePrefix: 'my-plugin-module',
        restNamespace: 'my-plugin/v1',
        bearerResolver: new JwtTokenResolver(secret: MY_JWT_SECRET),
        authorize: static fn (int $userId): bool => (bool) get_userdata($userId),
    );

    $kernel->boot();
});
```

Un module minimal (`src/Modules/Chat/Module.php` + `config.php`) :

```php
// src/Modules/Chat/config.php
return ['module.enabled' => true];
```

```php
// src/Modules/Chat/Module.php
namespace MyPlugin\Modules\Chat;

use PluginKernel\Hook\HookCollection;

final class Module
{
    public function register(HookCollection $hooks): void
    {
        $hooks->action('init', [$this, 'onInit']);
    }

    public function onInit(): void { /* ... */ }
}
```

Rien d'autre à faire : le `Kernel` scanne `src/Modules/*/Module.php`, lit chaque `config.php`,
active le module, exécute `register()`, puis scanne `src/Modules/*/Controller/**` pour les
contrôleurs AJAX/REST/web par attribut.

## Vue d'ensemble

| Brique | Rôle |
|---|---|
| [`PluginKernel\Kernel`](#kernel) | Orchestrateur racine : bootstrap DI, boot des modules/routes/assets/async dans l'ordre strict. Entièrement générique — tout ce qui est propre à l'application est fourni au constructeur. |
| [`PluginKernel\Module\*`](#système-de-modules) | Découverte/activation des modules (`Module.php` + `config.php`), `ModuleLoader`, `ModuleLoaderRegistry`, `ModuleConfigRegistry` |
| [`PluginKernel\DI\*`](#injection-de-dépendances-di) | `ContainerRegistry`, `RuntimeServiceRegistry`, `ModuleContainer` (autowiring de secours par réflexion), `SymfonyContainerLoader` (compile/cache un container Symfony depuis un `services.yml`) |
| [`PluginKernel\Ajax\*`](#routes-ajax) | `#[AjaxRoute]`, `AjaxRouteCollection`/`Definition`, `AjaxRouteLoader`, `AjaxRouter` |
| [`PluginKernel\Rest\*`](#routes-rest) | `#[RestRoute]`, `RestRouteLoader`, `RestAuthMiddleware`, `HybridAuthenticator`, `JwtTokenResolver`, `Support\BearerToken` |
| [`PluginKernel\Routing\*`](#routes-web) | `Route` (façade statique), `BaseRouter`/`BaseRoute`, `RouteRegistry`, `RouteGuardRunner`, `HasWebRoutes`/`WebRouteCollection`/`PendingWebRoute`/`WebRouteLoader` |
| [`PluginKernel\Hook\*`](#hooks-wordpress) | `HookCollection`, `HookRouter` — déclaration et enregistrement des actions, filtres et shortcodes |
| [`PluginKernel\Cron\*`](#cron) | `CronCollection`, `CronLoader`, `CronRouter` — fréquences et événements WP-Cron |
| [`PluginKernel\PostType\*`](#custom-post-types--taxonomies) | `PostTypeLoader`/`TaxonomyLoader` + interfaces opt-in (colonnes custom, metaboxes, post meta) |
| [`PluginKernel\Controller\*`](#contrôleurs) | `ControllerLoader`, `ControllerKit` (trait : service locator, paramètres de requête, réponses AJAX/REST) |
| [`PluginKernel\Middleware\*`](#middlewares) | `MiddlewareInterface`, `MiddlewareQueue`, `AuthMiddleware`, `NonceMiddleware`, `AdminMiddleware`, `ValidationMiddleware` |
| [`PluginKernel\Http\*`](#contrôleurs) | `AjaxRequest`, `AjaxResponse`, `Response\RestResponseFactory` (réponses standardisées) |
| [`PluginKernel\Assets\*`](#assets-par-module) | `ModuleAssetLoader` (enqueue JS/CSS par module actif, via manifest Webpack) |
| [`PluginKernel\Async\*`](#jobs-asynchrones) | `#[AsyncHandler]`, `JobInterface`/`JobHandlerInterface`, `HandlerDiscovery`, `HandlerResolver`, `JobDispatcher`, `JobWorker`, `AsyncKernel` |
| [`PluginKernel\Event\*`](#événements) | `EventDispatcher` (wrap `add_action`/`do_action`), `AbstractEvent` |
| [`PluginKernel\Validation\*`](#validation) | `ValidatorFactory` (bootstrap `symfony/validator` avec mapping par attributs) |
| `PluginKernel\Contract\*` | `NonceVerifierInterface` |


## Kernel

`PluginKernel\Kernel` est le point d'entrée. `boot()` exécute, dans cet ordre strict :

1. **Container DI** (`initContainer`) — compile/charge le container Symfony
2. **Infrastructure async** (`initAsyncInfrastructure`) — si un `$async` a été fourni
3. **Router AJAX** (`initAjaxRouter`)
4. **ModuleLoader** (`initModuleLoader`)
5. **Middleware REST** (`initRestMiddleware`)
6. **Tous les loaders** (`bootLoaders`) — modules, web routes, post types/taxonomies, ajax, cron,
   rest, contrôleurs, dans cet ordre (voir code source pour le détail — l'ordre est documenté et
   volontaire, chaque loader dépend souvent du précédent)
7. **Découverte des handlers async** (`discoverAsyncHandlers`)
8. **Assets** (`ModuleAssetLoader::register`)

### Constructeur

| Paramètre | Type | Description |
|---|---|---|
| `modulesPath` | `string` | Chemin absolu du dossier de modules principal (ex: `.../src/Modules`) |
| `modulesNamespace` | `string` | Namespace racine correspondant (ex: `MyPlugin\Modules`) |
| `configPath` | `string` | Chemin du `services.yml` Symfony |
| `cachePath` | `string` | Chemin du fichier de container compilé (cache) |
| `cacheClass` | `string` | Nom de la classe générée pour le container compilé |
| `cacheNamespace` | `string` | Namespace de cette classe générée |
| `isDev` | `bool` | Si `true`, recompile le container quand `services.yml` ou un fichier surveillé change |
| `pluginPath` | `string` | Chemin absolu du plugin (pour les assets) |
| `pluginUrl` | `string` | URL publique du plugin (pour les assets) |
| `pluginVersion` | `string` | Version (cache-busting des assets) |
| `pluginFile` | `string` | Fichier principal du plugin |
| `textDomain` | `string` | Text domain i18n (traductions des scripts) |
| `assetHandlePrefix` | `string` | Préfixe des handles `wp_enqueue_script`/`style` |
| `restNamespace` | `string` | Namespace REST par défaut pour `#[RestRoute]` |
| `bearerResolver` | `BearerTokenResolverInterface` | Résout un token porteur en ID utilisateur (REST + middleware) |
| `envParameters` | `array<string,mixed>` | *(optionnel)* Paramètres exposés au container Symfony via `%env(NAME)%` |
| `watchedDirectories` | `string[]` | *(optionnel)* Dossiers surveillés en mode dev pour déclencher un rebuild du container |
| `authorize` | `?\Closure(int $userId): bool` | *(optionnel)* Vérification appliquée après résolution d'un token porteur |
| `async` | `?AsyncBootstrapperInterface` | *(optionnel)* Câblage de l'infrastructure asynchrone — `null` = pas de jobs async |

### Points d'extension

- **`?\Closure $authorize`** — appelé avec l'ID utilisateur résolu depuis un token porteur, doit
  renvoyer `true`/`false`. `null` = aucune vérification au-delà de la résolution du token.
- **`?AsyncBootstrapperInterface $async`** — voir [Jobs asynchrones](#jobs-asynchrones).

### Multi-plugins : `addModuleSource()`

```php
$kernel->addModuleSource($otherPluginModulesPath, 'OtherPlugin\\Modules');
```

Permet à un autre plugin déjà chargé d'ajouter ses propres modules sur un `Kernel` déjà
instancié, **avant `boot()`** (un appel après n'a aucun effet). Tous les loaders ainsi que la
découverte des handlers async parcourent la source principale puis chaque source additionnelle,
dans l'ordre d'enregistrement. Pattern typique : le plugin propriétaire du Kernel déclenche un
hook WordPress juste avant `boot()` (ex: `do_action('my_kernel_registering_modules', $kernel)`),
et un autre plugin s'y accroche pour appeler `addModuleSource()`.

### API d'instance

```php
$kernel->boot(): void
$kernel->ajaxRouter(): AjaxRouter
$kernel->container(): ContainerInterface
$kernel->addModuleSource(string $modulesPath, string $modulesNamespace): void
```

## Système de modules

Un plugin est composé de "modules" — un dossier par fonctionnalité, activable/désactivable
indépendamment.

### Convention `Module.php`

Aucune interface n'est **requise** : `ModuleLoader` utilise le duck-typing (`method_exists`), un
module n'implémente que les méthodes dont il a besoin.

```php
final class Module // implements ModuleInterface — optionnel, juste pour l'IDE
{
    public function register(HookCollection $hooks): void
    {
        $hooks->action('init', [$this, 'onInit']);
        $hooks->filter('the_content', [$this, 'filterContent']);
        $hooks->shortcode('my_shortcode', [$this, 'renderShortcode']);
    }

    public function registerAjax(AjaxRouteCollection $routes): void
    {
        // Alternative à #[AjaxRoute] pour un enregistrement manuel/dynamique
        $routes->post('/chat/send', [SendMessageController::class, 'handle']);
    }

    public function registerCron(CronCollection $crons): void
    {
        $crons->event('my_module_cleanup', [CleanupService::class, 'run'], 'daily');
    }
}
```

### Convention `config.php`

```php
// src/Modules/Chat/config.php
return [
    'module.enabled' => true,              // ou 'chat.enabled', ou 'enabled' (ordre de priorité)
    'chat.disabled_modules' => ['LegacyChat'], // désactive un autre module si celui-ci est actif
    'listeners' => [
        UserRegisteredEvent::class => [SendWelcomeEmailListener::class],
    ],
    'assets' => [
        'front' => fn () => is_page('chat'), // gate conditionnel — false = pas d'enqueue
        'admin' => true,
    ],
];
```

Clés reconnues :
- **Activation** — vérifiée dans cet ordre : `module.enabled`, `<slug>.enabled` (slug = nom du
  dossier en minuscules), `enabled`. Par défaut `true` si pas de `config.php`.
- **`<slug>.disabled_modules`** — si CE module est actif, désactive les modules listés (traité
  après un premier passage sur tous les modules).
- **`listeners`** — `['NomEvenement' => [ListenerClass::class, ...]]`, câblés via
  `add_action($event, [$listener, '__invoke'], 10, 1)` (résolu depuis le container si déclaré,
  sinon `new $listenerClass()`).
- **`assets`** — consommé par `ModuleAssetLoader` (voir [Assets par module](#assets-par-module)).

### Classes clés

```php
// ModuleLoader — le moteur de découverte
new ModuleLoader(
    ajaxRoutes: ?AjaxRouteCollection $ajaxRoutes = null,    // transmis tel quel à Module::registerAjax()
    hookCollectionClass: string = HookCollection::class,    // à surcharger si votre Module::register() type sur une sous-classe
);
$loader->load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed;
$loader->getModules(): array<string, object>;   // instances actives, indexées par nom de module
$loader->active(): array;                       // modules activés
$loader->isActive(string $moduleName): bool;

// ModuleConfigRegistry — état runtime modifiable (contrairement au ParameterBag Symfony, figé après compilation)
ModuleConfigRegistry::set(string $key, mixed $value): void;
ModuleConfigRegistry::get(string $key, mixed $default = null): mixed;
ModuleConfigRegistry::all(): array;

// ModuleLoaderRegistry — orchestre plusieurs loaders dans l'ordre voulu (utilisé en interne par Kernel)
$registry->addLoader(ModuleLoaderInterface $loader): void;
$registry->loadAll(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): void;
```

`load()` peut être appelée plusieurs fois avec des sources différentes (cf. `addModuleSource()`
du Kernel) : un appel n'efface jamais les modules déjà collectés par un appel précédent.

## Injection de dépendances (DI)

Le container est un `Symfony\Component\DependencyInjection\ContainerBuilder` compilé depuis un
`services.yml`, enrichi d'un mécanisme d'autowiring de secours par réflexion pour tout ce qui
n'est pas déclaré explicitement (utile pour les classes de modules découvertes dynamiquement).

```php
// SymfonyContainerLoader — compile ou charge depuis le cache
SymfonyContainerLoader::load(
    configPath: string,       // chemin du services.yml
    cachePath: string,        // chemin du cache compilé
    cacheClass: string = 'GeneratedContainer',
    cacheNamespace: string = '',
    isDev: bool = false,      // recompile si services.yml ou un fichier surveillé a changé
    envParameters: array = [],       // injectés comme %env(NAME)% avant compilation
    watchedDirectories: array = [],  // fichiers .php surveillés en mode dev (hors index.php)
): ContainerInterface
```

```php
// ModuleContainer implements ContainerInterface — décore le container compilé
new ModuleContainer(ContainerInterface $inner);
$container->get(string $id): ?object;   // ordre : RuntimeServiceRegistry -> container compilé -> autowiring par réflexion
$container->has(string $id): bool;
$container->set(string $id, ?object $service): void;   // délègue à RuntimeServiceRegistry (le container compilé est figé)
$container->getParameter(string $name): mixed;         // ModuleConfigRegistry d'abord, puis le container compilé
$container->setParameter(string $name, mixed $value): void; // écrit uniquement dans ModuleConfigRegistry
```

```php
// ContainerRegistry — accès global au container courant (réservé au code interne du Kernel :
// contrôleurs, routeurs, toolkits — ne pas l'utiliser depuis du code métier applicatif)
ContainerRegistry::set(ContainerInterface $container): void;
ContainerRegistry::get(): ContainerInterface;   // throw RuntimeException si non initialisé
ContainerRegistry::service(string $id): mixed;

// RuntimeServiceRegistry — services déclarés dynamiquement par les modules (hors services.yml,
// puisque le container compilé ne peut pas être muté après compilation)
RuntimeServiceRegistry::set(string $id, callable|object $factoryOrInstance): void;
RuntimeServiceRegistry::has(string $id): bool;
RuntimeServiceRegistry::get(string $id, ?ContainerInterface $container = null): object; // singleton après première résolution
```

## Routes AJAX

### Par attribut

```php
// src/Modules/Chat/Controller/Ajax/MessageController.php
use PluginKernel\Ajax\Attribute\AjaxRoute;
use PluginKernel\Http\Request\AjaxRequest;
use PluginKernel\Http\Response\AjaxResponse;

final class MessageController
{
    #[AjaxRoute(method: 'POST', path: 'chat/send', protected: true)]
    public function send(AjaxRequest $request): AjaxResponse
    {
        $message = $request->getString('message');

        return AjaxResponse::success(['sent' => true]);
    }
}
```

`AjaxRouteLoader` scanne `<modulesPath>/*/Controller/Ajax/*.php`, réfléchit les méthodes
publiques annotées `#[AjaxRoute]`. Si `path` est `null`, il est déduit automatiquement :
`/{module}/{méthode-en-kebab-case}`.

```php
#[\Attribute(\Attribute::TARGET_METHOD)]
class AjaxRoute
{
    public function __construct(
        public string $method = 'POST',        // GET|POST
        public ?string $path = null,            // auto-calculé si null
        public bool $protected = true,          // exige login + vérification de nonce
        public array $middleware = [],          // FQCN de middlewares additionnels (résolus via le container)
    ) {}
}
```

### Par enregistrement manuel (`Module::registerAjax()`)

```php
public function registerAjax(AjaxRouteCollection $routes): void
{
    $routes->post('/chat/send', [SendMessageController::class, 'handle'])
           ->middleware([RateLimitMiddleware::class]);

    $routes->get('/chat/history', [HistoryController::class, 'list'], protected: false);
}
```

### Dispatch

Un seul endpoint WordPress (`wp_ajax_*`) route vers `AjaxRouter::dispatch()` :

```php
add_action('wp_ajax_my_plugin', fn () => $kernel->ajaxRouter()->dispatch());
add_action('wp_ajax_nopriv_my_plugin', fn () => $kernel->ajaxRouter()->dispatch());
```

`dispatch()` lit `$_REQUEST['route']` + la méthode HTTP, résout le contrôleur (container puis
réflexion), construit une `MiddlewareQueue` (si `protected`, préfixée par `AuthMiddleware` +
`NonceMiddleware`), exécute le pipeline, et sérialise le retour (`wp_send_json_success/error`, ou
`AjaxResponse::send()` si le contrôleur retourne directement une `AjaxResponse`).

`AjaxRequest` (paramètre injecté au contrôleur) :

```php
$request->get(string $key, mixed $default = null): mixed;
$request->getString(string $key, string $default = ''): string;  // sanitize_text_field()
$request->getInt(string $key, int $default = 0): int;
$request->getBool(string $key, bool $default = false): bool;
$request->getArray(string $key): array;
$request->getAll(): array;
```

`AjaxResponse` :

```php
AjaxResponse::success(array $data = [], ?string $message = null): static;
AjaxResponse::error(string $message, int $status = 400, array $data = []): static;
$response->send(): void; // wp_send_json_success/error()
```

> ⚠️ Si vous créez une sous-classe façade de `AjaxResponse`, voir [Pièges connus](#pièges-connus-à-lire-avant-détendre-ce-package) point 2.

## Routes REST

### Par attribut

```php
use PluginKernel\Rest\Attribute\RestRoute;

final class ChatRestController
{
    #[RestRoute(path: 'chat/messages', methods: 'GET', permission: 'logged_in')]
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        // ...
    }

    #[RestRoute(path: 'chat/admin/purge', methods: 'POST', permission: 'administrator')]
    public function purge(\WP_REST_Request $request): \WP_REST_Response { /* ... */ }

    #[RestRoute(path: 'chat/webhook', methods: 'POST', permission: 'public')]
    public function webhook(\WP_REST_Request $request): \WP_REST_Response { /* ... */ }

    #[RestRoute(path: 'chat/mine', methods: 'GET', permission: [self::class, 'ownsResource'])]
    public function mine(\WP_REST_Request $request): \WP_REST_Response { /* ... */ }
}
```

```php
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class RestRoute
{
    public function __construct(
        public string $path,                                 // ex: 'chat/messages' (sans le namespace)
        public string|array $methods = 'GET',
        public ?string $namespace = null,                     // par défaut : $restNamespace du Kernel
        public string|array|null $permission = 'logged_in',   // 'public'|'logged_in'|'administrator'|[Class::class,'method']
        public array $args = [],                              // args register_rest_route() additionnels (validation, schema...)
    ) {}
}
```

`RestRouteLoader` scanne `<modulesPath>/*/Controller/Rest/*.php`. Le contrôleur n'est instancié
qu'au moment où la route est réellement appelée (résolution paresseuse), pas à l'enregistrement.

Résolution de `permission` :
- `'public'` → accessible sans authentification
- `'logged_in'` → `is_user_logged_in()` ; sinon, si un `HybridAuthenticator` a été fourni au
  Kernel, tentative d'authentification par token porteur
- `'administrator'` → `current_user_can('manage_options')`
- `[Class::class, 'method']` → résolu depuis le container (ou `new`), utilisé directement comme
  callback de permission WordPress

### Authentification par token porteur (mobile/SPA)

Deux mécanismes complémentaires, tous deux basés sur `BearerTokenResolverInterface` (implémenté
par `JwtTokenResolver`, ou toute autre implémentation — le format du token n'est pas imposé) :

- **`RestAuthMiddleware::register($resolver, $authorize)`** — hydrate `wp_set_current_user()`
  globalement sur `rest_pre_dispatch` (priorité 5, avant la plupart des vérifications de
  permission). Idempotent : si plusieurs plugins chargent ce package et appellent `register()`,
  le filtre ne s'attache qu'une seule fois (flag statique).
- **`HybridAuthenticator`** — utilisé uniquement pour `permission: 'logged_in'`, comme fallback
  quand `is_user_logged_in()` échoue. Injecté au Kernel via `bearerResolver`/`authorize`.

```php
use PluginKernel\Rest\JwtTokenResolver;

$resolver = new JwtTokenResolver(
    secret: MY_JWT_SECRET,
    algo: 'HS256',           // défaut
    userIdClaim: 'user_id',  // défaut
    ttl: 604800,             // 7 jours, défaut
);

$token = $resolver->createToken($userId);
$userId = $resolver->resolveUserId($token); // ?int, null si invalide/expiré
```

`JwtTokenResolver` ne gère PAS l'extraction du token depuis les headers — c'est le rôle de
`Rest\Support\BearerToken::extract(\WP_REST_Request $request): ?string`, qui lit `Authorization:
Bearer …`, avec fallback sur `X-Auth-Token` (utile pour les clients mobiles où Apache filtre
parfois le header `Authorization`).

### Réponses standardisées

```php
$factory = $container->get(\PluginKernel\Http\Response\RestResponseFactory::class);

$factory->success(data: [...], message: 'OK', status: 200): \WP_REST_Response;
$factory->error(code: 'not_found', message: 'Introuvable', status: 404): \WP_REST_Response;
```

## Routes web

Système de routing basé sur les rewrite rules WordPress, pour des pages virtuelles (sans page WP
existante) ou associées à une page WP réelle.

```php
use PluginKernel\Routing\Route;
use PluginKernel\Routing\BaseRouter;

// Bootstrap, une seule fois
$router = new BaseRouter(baseTemplateDir: MY_PLUGIN_PATH . 'resources/views');
Route::init($router);
$router->boot();
```

```php
// Déclaration de routes
Route::page('connexion', '/public/auth/login.php');       // associée à une page WP existante (slug)
Route::virtual('profil/{slug}', '/public/users/profile.php', constraints: []);
Route::view('a-propos', '/public/pages/about.php');        // alias de virtual()
Route::get('google/callback', fn ($req) => /* ... */);     // callback direct, ou 'Controller@method'
```

### Flush des rewrite rules

Quand une route virtuelle n'est pas encore présente dans les rewrite rules persistées,
`BaseRouter` pose l'option `BaseRouter::FLUSH_REWRITE_OPTION`. Le flush lui-même est à la
charge de l'application, une fois les règles ajoutées (`init`, priorité 10) :

```php
add_action('init', static function (): void {
    if (get_option(\PluginKernel\Routing\BaseRouter::FLUSH_REWRITE_OPTION)) {
        delete_option(\PluginKernel\Routing\BaseRouter::FLUSH_REWRITE_OPTION);
        flush_rewrite_rules(false);
    }
}, 20);
```

### Routes déclarées par module (`HasWebRoutes`)

```php
final class Module implements HasWebRoutes
{
    public function registerRoutes(WebRouteCollection $routes): void
    {
        $routes->virtual('chat', '/Chat/UI/Templates/chat')
               ->guard(ChatAccessGuard::class)
               ->titleCallback(fn () => 'Chat');

        $routes->page('connexion', '/Auth/UI/Templates/login');
    }
}
```

`WebRouteLoader` (un des loaders du Kernel) itère les modules actifs `instanceof HasWebRoutes`
après leur chargement, matérialise via `Route::queueCollection()`.

### Guards

```php
interface RouteGuardInterface { public function guard(): void; }
```

`$route->guard(MyGuard::class)` — exécuté avant de servir la route (`template_redirect`
priorité 1). Un guard peut rediriger ou lever une exception pour bloquer l'accès. Implémentation
à la charge de l'application (contrôle d'accès, abonnement, rôles...).

> Note : `Routing\Attribute\WebRoute` (`#[WebRoute]`) existe et `Route::queueAttributeRoute()`
> peut consommer des définitions de route par attribut, mais **aucun scanner de ce package ne lit
> `#[WebRoute]` automatiquement** — contrairement à `#[AjaxRoute]`/`#[RestRoute]`. Si vous voulez
> des routes web par attribut, il faut écrire votre propre scanner qui appelle
> `Route::queueAttributeRoute()`.

## Hooks WordPress

```php
public function register(HookCollection $hooks): void
{
    $hooks->action('init', [$this, 'onInit'], priority: 10, acceptedArgs: 1);
    $hooks->filter('the_content', [$this, 'filterContent']);
    $hooks->shortcode('my_shortcode', [$this, 'renderShortcode']);
}
```

`HookCollection` est un simple accumulateur (aucune validation). `HookRouter` (utilisé en
interne par `ModuleLoader`) résout chaque callback (`[Classe, méthode]` → instancié via le
container ou par réflexion ; `Classe::class` seule → doit implémenter `__invoke`) et enregistre
réellement les hooks WordPress.

## Cron

```php
public function registerCron(CronCollection $crons): void
{
    $crons->schedule('every_fifteen_minutes', 15 * MINUTE_IN_SECONDS, 'Toutes les 15 minutes');
    $crons->event(
        hook: 'my_module_job',
        callback: [MyCronService::class, 'run'],
        recurrence: 'every_fifteen_minutes',
        args: ['foo' => 'bar'],
    );
}
```

`CronLoader` scanne tous les `Module.php`, appelle `registerCron()` (duck-typing, fail-soft — un
module qui échoue n'empêche pas les autres), délègue à `CronRouter` qui fusionne les fréquences
personnalisées (`cron_schedules`), enregistre les `add_action()`, et planifie chaque événement au
premier `init` si `wp_next_scheduled()` ne le trouve pas déjà planifié.

## Custom Post Types & Taxonomies

```php
// src/Modules/Event/Infrastructure/PostType/EventPostType.php
use PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface;

final class EventPostType implements PostTypeDefinitionInterface
{
    public function getSlug(): string { return 'event'; }
    public function useGutenberg(): bool { return true; }
    public function getArgs(): array
    {
        return ['label' => 'Événements', 'public' => true, 'supports' => ['title', 'editor']];
    }
}
```

Interfaces opt-in additionnelles (détectées via `instanceof`) :

| Interface | Rôle |
|---|---|
| `HasCustomColumnsInterface` | Colonnes personnalisées dans la liste admin (`getCustomColumns`, `renderCustomColumn`, `getSortableColumns`) |
| `HasMetaboxInterface` | Metaboxes d'édition (`registerMetaboxes`, `saveMetaboxes`) |
| `HasPostMetaBehaviorInterface` | Déclaration de post meta via `register_post_meta()` (`getPostMetaArgs`) |
| `TaxonomyDefinitionInterface` | Équivalent pour les taxonomies (`getSlug`, `getPostTypes`, `getArgs`) |
| `HasTaxonomyBehaviorInterface` | Personnalise `wp_terms_checklist_args` (`customizeChecklistArgs`) |

`PostTypeLoader`/`TaxonomyLoader` scannent `<modulesPath>/*/Infrastructure/PostType/*PostType.php`
(resp. `*Taxonomy.php`), résolvent chaque classe via le container, et enregistrent tout sur
`init` via `PostTypeRegistrar`/`TaxonomyRegistrar`.

## Contrôleurs

`ControllerLoader` scanne `<modulesPath>/*/Controller/*.php` et laisse le container (ou
l'autowiring par réflexion en secours) instancier chaque contrôleur — utile pour les
contrôleurs qui ne portent pas de route par attribut mais ont besoin d'être construits/câblés
(ex: enregistrement d'un listener dans leur constructeur).

`ControllerKit` (trait) fournit des utilitaires communs à composer dans le trait applicatif :

```php
trait MyControllerToolkit
{
    use \PluginKernel\Controller\ControllerKit;

    // ... méthodes propres à l'application (rendu de template, guards métier...)
}
```

```php
$this->get(string $service): object;                    // service locator (RuntimeServiceRegistry puis container)
$this->getParameter(string $key): mixed;                 // ModuleConfigRegistry puis container
$this->verifyNonce(string $field, string $action): bool;
$this->param(string $key, mixed $default = null): mixed;      // $_POST puis $_GET, sanitize_text_field()
$this->intParam(string $key, int $default = 0): int;
$this->boolParam(string $key, bool $default = false): bool;
$this->ajaxSuccess(array $data = [], ?string $message = null): AjaxResponse;
$this->ajaxError(string $message, int $status = 400, array $data = []): AjaxResponse;
$this->restSuccess(array $data = [], string $message = 'OK', array $headers = [], int $status = 200): \WP_REST_Response;
$this->restError(string $code, string $message = 'NOK', array $data = []): \WP_REST_Response;
$this->getAuthMode(\WP_REST_Request $request): string;  // 'jwt'|'cookie'|'none'
```

Volontairement absent : rendu de templates et guards métier (auth + rôles applicatifs) — trop
spécifiques à chaque application, à composer dans le trait consommateur.

## Middlewares

Pipeline "onion" exécuté par `AjaxRouter::dispatch()` (routes `protected`) :

```php
interface MiddlewareInterface { public function handle(array $request, callable $next): mixed; }
```

| Middleware | Comportement |
|---|---|
| `AuthMiddleware` | 401 si `!is_user_logged_in()` |
| `NonceMiddleware` | 401 si la vérification de nonce (`NonceVerifierInterface`) échoue |
| `AdminMiddleware` | 401 si `!is_admin()`, 403 si `!current_user_can('manage_options')` |
| `ValidationMiddleware` | 400 `"Missing field: x"` si un champ requis (`$rules`) est absent de la requête |

`MiddlewareQueue::run(array $request, callable $final)` empile les middlewares (ordre :
`AuthMiddleware` + `NonceMiddleware` si `protected: true`, puis le middleware propre à la route,
via `#[AjaxRoute(middleware: [...])]` ou `$routes->middleware([...])`).

## Assets par module

```php
ModuleAssetLoader::register(
    modules: $moduleLoader,
    pluginPath: MY_PLUGIN_PATH,
    pluginUrl: MY_PLUGIN_URL,
    pluginVersion: '1.0.0',
    pluginFile: MY_PLUGIN_FILE,
    textDomain: 'my-plugin',
    handlePrefix: 'my-plugin-module',
);
```

Pour chaque module actif, enqueue automatiquement (front + admin séparément) les assets sous
`resources/build/modules/{module}[/admin]/`, en résolvant les noms de fichiers hashés via
`resources/build/asset-manifest.json` (manifest Webpack) et les dépendances de script via le
fichier `.asset.php` associé. Le gate `config.php['assets']['front'|'admin']` (callable ou bool)
permet de conditionner l'enqueue (ex: seulement sur certaines pages).

### Blocs Gutenberg par module

```php
ModuleBlockLoader::register(
    modules: $moduleLoader,
    container: $container,
    pluginPath: MY_PLUGIN_PATH,
    pluginUrl: MY_PLUGIN_URL,
    textDomain: 'my-plugin',
    handlePrefix: 'my-plugin-module',
);
```

Appelé par `Kernel::boot()`. Au hook `init`, enregistre (`register_block_type()`) chaque bloc
trouvé dans `<Module>/Assets/blocks/<bloc>/block.json` des modules **activés** :

```
src/Modules/Challenge/Assets/blocks/challenge-collaboration/
├── block.json   # métadonnées (nom, attributs, usesContext…)
├── index.js     # script éditeur → resources/build/modules/challenge/blocks/challenge-collaboration/
└── edit.js      # (et tout fichier importé par index.js)
```

- **Build** : l'application doit compiler `Assets/blocks/*/index.js` vers l'entrée Webpack
  `modules/<module>/blocks/<bloc>/index` (manifest). Fichiers compilés reconnus, tous optionnels :
  `index.js` + `.asset.php` (script éditeur), `index.css` (style éditeur), `style-index.css`
  (style front + éditeur). Un bloc non compilé reste enregistré (rendu conservé) mais n'apparaît
  pas dans l'éditeur.
- **Rendu dynamique** : natif (`"render": "file:./render.php"` dans `block.json`) ou callback
  déclaré dans le `config.php` du module, résolu via le container au premier rendu :

```php
'blocks' => [
    'challenge-collaboration' => [ChallengeCollaborationBlock::class, 'render'],
],
```

## Jobs asynchrones

```
packages/plugin-kernel/src/Async/         # générique, réutilisable par n'importe quel plugin
├── Attribute/AsyncHandler.php            # Attribut pour marquer les handlers
├── Discovery/
│   ├── HandlerDiscovery.php              # Découverte automatique des handlers
│   └── HandlerResolver.php               # Résolution par réflexion (fail-soft)
├── Job/
│   ├── JobInterface.php
│   └── JobHandlerInterface.php
├── Contract/
│   ├── JobQueueRepositoryInterface.php   # à implémenter par l'application (persistance)
│   ├── JobLoggerInterface.php            # optionnel
│   └── AsyncBootstrapperInterface.php    # à implémenter par l'application, injecté au Kernel
├── Services/
│   ├── JobDispatcher.php
│   └── JobWorker.php
└── AsyncKernel.php                       # câblage générique
```

### Créer un job + un handler

```php
use PluginKernel\Async\Job\JobInterface;
use PluginKernel\Async\Job\JobHandlerInterface;
use PluginKernel\Async\Attribute\AsyncHandler;

final class SendWelcomeEmailJob implements JobInterface
{
    public function __construct(public readonly int $userId) {}

    public static function fromArray(array $payload): self
    {
        return new self($payload['userId']);
    }

    public function toArray(): array { return ['userId' => $this->userId]; }
}

#[AsyncHandler('send_welcome_email')]
final class SendWelcomeEmailHandler implements JobHandlerInterface
{
    public function __construct(private readonly MailerInterface $mailer) {}

    public function supports(string $jobType): bool { return 'send_welcome_email' === $jobType; }

    public function handle(JobInterface $job): void
    {
        $this->mailer->send($job->userId, 'welcome');
    }
}
```

Le handler doit être dans un dossier `Async/` sous un module (`src/Modules/*/Async/*Handler.php`)
pour être découvert par `HandlerDiscovery`.

### Câblage applicatif (`AsyncBootstrapperInterface`)

Le Kernel ne connaît que ce contrat — l'implémentation concrète (persistance, logger, convention
de nommage, hooks/cron) reste dans l'application :

```php
interface AsyncBootstrapperInterface
{
    public function register(ContainerInterface $container): void;
    public function registerHooks(): void;
    public function registerCron(): void;
    public function discoverAndRegisterHandlers(ContainerInterface $container, string $modulesPath, string $modulesNamespace): void;
}
```

Implémentation de référence (délègue à `AsyncKernel`) :

```php
final class MyAsyncLoader implements AsyncBootstrapperInterface
{
    public function register(ContainerInterface $container): void
    {
        AsyncKernel::register($container, new MyJobQueueRepository(), new MyJobLogger());
    }

    public function discoverAndRegisterHandlers(ContainerInterface $container, string $modulesPath, string $modulesNamespace): void
    {
        AsyncKernel::discoverAndRegisterHandlers(
            $container, $modulesPath, $modulesNamespace,
            onHandlerResolved: self::registerJobFactory(...),   // votre convention Handler -> Job
            onResolutionFailure: fn (string $class) => error_log("Échec résolution: $class"),
        );
    }

    // registerHooks()/registerCron() : add_action('my_dispatch_job', ...), wp_schedule_event(...), etc.
}
```

```php
new Kernel(/* ... */, async: new MyAsyncLoader());
```

### Dispatcher un job

```php
$dispatcher = $container->get(\PluginKernel\Async\Services\JobDispatcher::class);
$dispatcher->dispatch(new SendWelcomeEmailJob($userId));

// ou via un hook WordPress si registerHooks() le prévoit
do_action('my_dispatch_job', new SendWelcomeEmailJob($userId));
```

## Événements

```php
use PluginKernel\Event\EventDispatcher;
use PluginKernel\Event\AbstractEvent;

final class UserRegisteredEvent extends AbstractEvent
{
    public function __construct(public readonly int $userId) { parent::__construct(); }
}

$dispatcher = $container->get(EventDispatcher::class);
$dispatcher->addListener(UserRegisteredEvent::class, function (UserRegisteredEvent $event) {
    // ...
});
$dispatcher->dispatch(new UserRegisteredEvent($userId));
```

`EventDispatcher` est un pub/sub minimal reposant sur `add_action`/`do_action` (le nom du hook
est `Event::class`, sauf si l'événement implémente `Contract\NamedEventInterface::getName()`).
`AbstractEvent` fournit uniquement un horodatage (`occurredAt(): \DateTimeImmutable`).

## Validation

```php
use PluginKernel\Validation\ValidatorFactory;

$validator = ValidatorFactory::build(); // Symfony\Component\Validator\Validator\ValidatorInterface, singleton process
$violations = $validator->validate($dto); // mapping par attributs (#[Assert\NotBlank], etc.)
```

## Contrats à implémenter (récapitulatif)

Ce package ne fournit **aucune persistance** ni logique métier — les contrats suivants doivent
être implémentés par l'application consommatrice :

| Contrat | Rôle | Utilisé par |
|---|---|---|
| `PluginKernel\Async\Contract\JobQueueRepositoryInterface` | Stockage de la file de jobs (SQL, Redis...) | `AsyncKernel::register()` |
| `PluginKernel\Async\Contract\JobLoggerInterface` *(optionnel)* | Logging des jobs | `AsyncKernel::register()` |
| `PluginKernel\Async\Contract\AsyncBootstrapperInterface` | Câblage complet de l'infra async | `Kernel` (paramètre `async`) |
| `PluginKernel\Contract\NonceVerifierInterface` | Vérification de nonce | `AjaxRouter`, `NonceMiddleware` |
| `PluginKernel\Routing\Interface\RouteGuardInterface` | Guard exécuté avant de servir une route | `BaseRoute::guard()`/`PendingWebRoute::guard()` |
| `PluginKernel\Rest\Contract\BearerTokenResolverInterface` | Résout un token porteur en ID utilisateur | `RestAuthMiddleware`, `HybridAuthenticator`, `Kernel` (paramètre `bearerResolver`) |
| `PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface` | Déclaration d'un CPT | `PostTypeLoader` |
| `PluginKernel\PostType\Interfaces\TaxonomyDefinitionInterface` | Déclaration d'une taxonomie | `TaxonomyLoader` |

`JwtTokenResolver` est fourni par ce package comme implémentation prête à l'emploi de
`BearerTokenResolverInterface`. Les autres contrats (persistance des jobs, logger, vérification
de nonce, câblage async) sont propres à chaque application.

## Pièges connus (à lire avant d'étendre ce package)

1. **Une méthode/service statique qui instancie une classe interne du package et la transmet à du
   code appelant** casse si l'appelant type son paramètre sur une sous-classe (ex: le `HookCollection`
   que `ModuleLoader` récupère du container doit être exactement le FQCN attendu par
   `Module::register()`, pas la classe de base du package). Fix : accepter le FQCN cible en paramètre
   de constructeur plutôt que le coder en dur (voir `ModuleLoader::$hookCollectionClass`,
   `AjaxRouter::$ajaxRequestClass`).
2. **Une factory statique type `Foo::success()` doit utiliser `new static()`, jamais `new self()`** —
   sinon un contrôleur qui type son retour sur une sous-classe (façade) plante avec un `TypeError`
   (voir `AjaxResponse::success()`/`error()`).
3. **Une interface dont une méthode prend en paramètre une autre classe du package** (ex:
   `JobHandlerInterface::handle(JobInterface $job)`) ne doit **pas** être étendue par une interface
   fille si l'implémenteur type son paramètre sur cette classe fille — violation de contravariance.
   Solution retenue ici : ne pas créer de façade du tout pour `JobInterface`/`JobHandlerInterface`,
   les consommateurs référencent directement `PluginKernel\Async\Job\*`. Même piège rencontré avec
   `HasWebRoutes::registerRoutes(WebRouteCollection $routes)` : un module qui implémente une façade
   applicative `App\Routing\HasWebRoutes` en typant son paramètre sur la façade
   `App\Routing\WebRouteCollection` (sous-classe) plante avec un fatal
   "Declaration must be compatible" — les modules référencent donc directement
   `PluginKernel\Routing\HasWebRoutes`/`WebRouteCollection`, sans façade.
4. **Les méthodes statiques (contrairement aux constructeurs) exigent une signature compatible**
   entre parent et enfant en PHP, même sans interface — un override avec moins de paramètres
   optionnels est un fatal. Les constructeurs, eux, sont exemptés de cette règle.
5. **Une propriété typée ne peut pas être `callable`** (PHP l'interdit, y compris pour les
   propriétés promues d'un constructeur) — utiliser `?\Closure` à la place (voir
   `HybridAuthenticator`, `Kernel::$authorize`) ; les appelants passent une closure ou une syntaxe
   de callable de première classe (`$obj->method(...)`), les deux sont acceptées.
6. **Un callable nullable ne peut pas s'invoquer via l'opérateur nullsafe** (`$cb?->($arg)` est une
   erreur de syntaxe) — utiliser `if (null !== $cb) { $cb($arg); }` (voir `AsyncKernel`).

## Développement

```bash
make install   # dépendances, dont les outils de qualité (require-dev)
make lint      # php-cs-fixer en mode vérification (lintf : avec correction)
make rector    # Rector en dry-run (rectorf : applique les transformations)
make stan      # PHPStan (niveau 3, extension phpstan-wordpress)
```

`make` (ou `make help`) liste toutes les commandes. Configuration : `.php-cs-fixer.php`,
`rector.php`, `phpstan.neon`.
