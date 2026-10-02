<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Registre statique des routes nommées.
 *
 * Une route est enregistrée ici dès qu'on lui attribue un nom via ->name().
 * Permet de retrouver une route par son nom depuis n'importe où dans l'application.
 */
class RouteRegistry
{
    /** @var array<string, BaseRoute> */
    private static array $routes = [];

    public static function register(string $name, BaseRoute $route): void
    {
        self::$routes[$name] = $route;
    }

    public static function get(string $name): ?BaseRoute
    {
        return self::$routes[$name] ?? null;
    }

    public static function has(string $name): bool
    {
        return isset(self::$routes[$name]);
    }

    /** Vide le registre (utile pour les tests). */
    public static function reset(): void
    {
        self::$routes = [];
    }
}
