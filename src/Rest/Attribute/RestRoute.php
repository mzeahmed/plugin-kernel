<?php

declare(strict_types=1);

namespace PluginKernel\Rest\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class RestRoute
{
    /**
     * Attribut permettant de déclarer une route REST WordPress directement
     * au-dessus d’une méthode de contrôleur.
     *
     * Chaque occurrence de cet attribut enregistre automatiquement une route via
     * register_rest_route(), en utilisant le namespace, le chemin, les méthodes HTTP,
     * la stratégie d'autorisation et les arguments fournis.
     *
     * @param string $path Chemin de la route (sans namespace),
     *                     ex : 'google/sync' ou 'chat/get-messages'.
     * @param string|array $methods Méthode(s) HTTP autorisée(s).
     *                              Exemple : 'GET' ou ['GET', 'POST'].
     * @param string|null $namespace Namespace REST WordPress.
     *                               Si null, utilise le namespace par défaut du RestRouteLoader.
     * @param string|array|null $permission Stratégie de permission :
     *                                      - 'public' → accès ouvert
     *                                      - 'logged_in' → utilisateur connecté (+ fallback
     *                                      éventuel via l'authenticator injecté au loader)
     *                                      - 'administrator' → capability manage_options
     *                                      - [Class::class, 'method'] → callback personnalisé
     *                                      Si null, 'public' sera utilisé.
     * @param array $args Arguments transmis à register_rest_route(),
     *                    comme la validation, les schémas, les types,
     *                    ou toute configuration avancée.
     */
    public function __construct(
        public string $path,
        public string|array $methods = 'GET',
        public ?string $namespace = null,
        public string|array|null $permission = 'logged_in',
        public array $args = [],
    ) {
    }
}
