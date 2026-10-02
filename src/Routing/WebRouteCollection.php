<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Collection de routes web déclarées par un module via HasWebRoutes.
 *
 * Chaque méthode retourne un PendingWebRoute chainable (guard, titleCallback, title).
 * Les routes sont matérialisées via materializeAll() une fois le routeur prêt.
 *
 * @example
 *   $routes->virtual('yuna', '/Ai/UI/Templates/yuna')
 *          ->guard(YunaAccessGuard::class)
 *          ->titleCallback(fn() => 'Yuna');
 *
 *   $routes->page('connexion', '/Auth/UI/Templates/login');
 *
 *   $routes->get('google/callback', 'GoogleController@callback');
 */
class WebRouteCollection
{
    /** @var PendingWebRoute[] */
    private array $pending = [];

    /**
     * Route virtuelle vers un template statique.
     *
     * @param array<string,mixed> $constraints
     */
    public function virtual(string $uri, string $template, array $constraints = []): PendingWebRoute
    {
        return $this->add(kind: 'virtual', uri: $uri, action: $template, constraints: $constraints);
    }

    /**
     * Route associée à une page WordPress existante (slug en base).
     */
    public function page(string $slug, string $template): PendingWebRoute
    {
        return $this->add(kind: 'page', uri: $slug, action: $template);
    }

    /**
     * Route GET avec callback ou Controller@method.
     *
     * @param array<string,mixed> $constraints
     */
    public function get(string $uri, callable|string|null $action = null, array $constraints = []): PendingWebRoute
    {
        return $this->add(kind: 'get', uri: $uri, action: $action, constraints: $constraints);
    }

    /**
     * Matérialise toutes les routes collectées (appelé par Route::init() via la queue).
     */
    public function materializeAll(): void
    {
        foreach ($this->pending as $pending) {
            $pending->materialize();
        }
    }

    private function add(string $kind, string $uri, mixed $action = '', array $constraints = []): PendingWebRoute
    {
        $route = new PendingWebRoute(kind: $kind, uri: $uri, action: $action, constraints: $constraints);
        $this->pending[] = $route;

        return $route;
    }
}
