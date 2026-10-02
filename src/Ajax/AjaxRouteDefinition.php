<?php

declare(strict_types=1);

namespace PluginKernel\Ajax;

/**
 * Définition d'une route AJAX.
 *
 * Encapsule toutes les informations nécessaires à l'enregistrement d'une route AJAX
 * (méthode HTTP, chemin, handler contrôleur, protection et middleware). Utilisée par
 * AjaxRouteCollection/AjaxRouter pour déclarer automatiquement les actions WordPress
 * wp_ajax_ et wp_ajax_nopriv_.
 */
class AjaxRouteDefinition
{
    /**
     * @param 'GET'|'POST' $method Méthode HTTP attendue
     * @param non-empty-string $path Chemin de la route (doit commencer par '/')
     * @param array{0: class-string|object, 1: non-empty-string} $handler Callable du contrôleur [class|instance, method]
     * @param bool $protected Si true, route accessible uniquement aux utilisateurs connectés
     * @param list<class-string> $middleware Liste des middlewares à exécuter dans l'ordre
     */
    public function __construct(
        public string $method,
        public string $path,
        public array $handler,
        public bool $protected = true,
        public array $middleware = []
    ) {
    }
}
