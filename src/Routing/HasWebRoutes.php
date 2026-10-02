<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Interface opt-in pour les modules qui déclarent leurs propres routes web.
 *
 * Un module qui implémente cette interface voit ses routes enregistrées
 * automatiquement par le loader, sans passer par une déclaration centrale.
 *
 * @example
 *   final class Module implements ModuleInterface, HasWebRoutes
 *   {
 *       public function registerRoutes(WebRouteCollection $routes): void
 *       {
 *           $routes->virtual('yuna', '/Ai/UI/Templates/yuna')
 *                  ->guard(YunaAccessGuard::class)
 *                  ->titleCallback(fn() => 'Yuna');
 *       }
 *   }
 */
interface HasWebRoutes
{
    public function registerRoutes(WebRouteCollection $routes): void;
}
