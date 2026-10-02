<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Façade statique de routing.
 *
 * Usage :
 *   Route::page('connexion', '/public/auth/login-v2.php');
 *
 *   Route::view('profil/{slug}', '/public/users/profile.php');
 *
 *   Route::get('google/callback', fn($req) => ...);
 */
class Route
{
    private static ?BaseRouter $router = null;
    private static array $queuedAttributeRoutes = [];

    /** @var WebRouteCollection[] Collections déclarées par les modules (HasWebRoutes). */
    private static array $queuedCollections = [];

    /**
     * Appelée au bootstrap pour injecter l'instance réelle du routeur.
     * Flush les routes en attente (attributs + modules HasWebRoutes).
     */
    public static function init(BaseRouter $router): void
    {
        self::$router = $router;
        self::flushQueuedCollections();
        self::flushQueuedAttributeRoutes();
    }

    /**
     * Enqueue une WebRouteCollection déclarée par un module (HasWebRoutes).
     * Si le routeur est déjà prêt (cas rare), matérialise immédiatement.
     */
    public static function queueCollection(WebRouteCollection $collection): void
    {
        if (self::$router instanceof BaseRouter) {
            $collection->materializeAll();

            return;
        }

        self::$queuedCollections[] = $collection;
    }

    private static function flushQueuedCollections(): void
    {
        foreach (self::$queuedCollections as $collection) {
            $collection->materializeAll();
        }

        self::$queuedCollections = [];
    }

    private static function ensureInitialized(): void
    {
        if (!self::$router) {
            throw new \RuntimeException('Route::init() must be called before using Route::* methods.');
        }
    }

    // ------------------------------------------------------------
    // 1) ROUTE BASÉE SUR UNE PAGE WORDPRESS
    // ------------------------------------------------------------
    /**
     * Associe un template à une page WordPress existante (slug réel dans WP admin)
     */
    public static function page(string $slug, string $templatePath): BaseRoute
    {
        self::ensureInitialized();

        return self::$router->page($slug, $templatePath);
    }

    // ------------------------------------------------------------
    // 2) ROUTES 100% VIRTUELLES (pas de page WP nécessaire)
    // ------------------------------------------------------------
    /**
     * Route virtuelle statique vers un template
     * (alias direct vers BaseRouter::virtual)
     */
    public static function virtual(string $uri, string $templatePath, array $constraints = []): BaseRoute
    {
        self::ensureInitialized();

        return self::$router->virtual($uri, $templatePath, null, $constraints);
    }

    // ------------------------------------------------------------
    // 3) ALIAS
    // ------------------------------------------------------------
    /**
     * Alias de virtual()
     */
    public static function view(string $uri, string $templatePath, array $constraints = []): BaseRoute
    {
        self::ensureInitialized();

        return self::$router->virtual($uri, $templatePath, null, $constraints);
    }

    /**
     * Route GET (callable ou "Controller@method")
     */
    public static function get(string $uri, callable|string|null $action = null, array $constraints = []): BaseRoute
    {
        self::ensureInitialized();
        // Template simple -> bascule sur virtual()
        if (\is_string($action) && !str_contains($action, '@')) {
            return self::$router->virtual($uri, $action, null, $constraints);
        }

        // Controller@method
        if (\is_string($action) && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $callback = fn ($vars) => (new $class())->$method($vars);

            return self::$router->virtual($uri, '', $callback, $constraints);
        }

        // Callback direct
        return self::$router->virtual($uri, '', $action, $constraints);
    }

    /**
     * Enfile une route déclarée via attribut pour exécution différée.
     * kind: 'page'|'virtual'|'view'|'get'
     */
    public static function queueAttributeRoute(array $def): void
    {
        // Validation minimale
        $def = array_merge([
            'kind' => 'get',
            'uri' => null,
            'template' => null,
            'constraints' => [],
            'handler' => null,
        ], $def);
        self::$queuedAttributeRoutes[] = $def;
        // Si le routeur est prêt, on flush immédiatement
        if (self::$router instanceof BaseRouter) {
            self::flushQueuedAttributeRoutes();
        }
    }

    private static function flushQueuedAttributeRoutes(): void
    {
        if (!self::$router || !self::$queuedAttributeRoutes) {
            return;
        }

        foreach (self::$queuedAttributeRoutes as $def) {
            $kind = $def['kind'] ?? 'get';
            $uri = $def['uri'] ?? '';
            $template = $def['template'] ?? '';
            $constraints = $def['constraints'] ?? [];
            $handler = $def['handler'] ?? null;
            switch ($kind) {
                case 'page':
                    if ($uri && $template) {
                        self::$router->page($uri, $template);
                    }

                    break;
                case 'view':
                    if ($uri && $template) {
                        self::$router->virtual($uri, $template, null, $constraints);
                    }

                    break;
                case 'virtual':
                    self::$router->virtual((string) $uri, (string) $template, $handler, $constraints);

                    break;
                case 'get':
                default:
                    // handler peut être callable ou null (dans ce cas, pas d'effet)

                    self::$router->virtual((string) $uri, '', $handler, $constraints);

                    break;
            }
        }

        self::$queuedAttributeRoutes = [];
    }
}
