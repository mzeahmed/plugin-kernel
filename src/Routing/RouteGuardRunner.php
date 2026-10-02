<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

use PluginKernel\Routing\Interface\RouteGuardInterface;

/**
 * RouteGuardRunner : exécute les guards d'une route.
 *
 * Responsable de :
 *  - Instancier les classes guard
 *  - Exécuter chaque guard dans l'ordre
 *  - Gérer les erreurs et exceptions
 *
 * Les guards s'exécutent via leur méthode guard(): void
 * Un guard peut rediriger (ne retourne jamais) ou permettre la continuation.
 */
class RouteGuardRunner
{
    /**
     * Exécute tous les guards d'une route.
     *
     * @param array<class-string|callable> $guards Liste des guards à exécuter
     */
    public static function run(array $guards): void
    {
        foreach ($guards as $guard) {
            self::executeGuard($guard);
        }
    }

    /**
     * Exécute un single guard.
     *
     * @param class-string|callable $guard Classe ou callable guard
     *
     * @throws \RuntimeException Si le guard n'implémente pas RouteGuardInterface
     */
    private static function executeGuard($guard): void
    {
        // Si c'est une string (class-string), on l'instancie
        if (\is_string($guard)) {
            $guardInstance = new $guard();
        } else {
            $guardInstance = $guard;
        }

        // Vérifier que c'est une instance valide
        if (!($guardInstance instanceof RouteGuardInterface)) {
            throw new \RuntimeException(
                \sprintf(
                    'Guard must implement %s, got %s',
                    RouteGuardInterface::class,
                    get_debug_type($guardInstance)
                )
            );
        }

        // Exécuter le guard
        $guardInstance->guard();
    }
}
