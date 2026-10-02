<?php

declare(strict_types=1);

namespace PluginKernel\Rest;

use PluginKernel\Rest\Support\BearerToken;
use PluginKernel\Rest\Contract\BearerTokenResolverInterface;

/**
 * Hydrate l'utilisateur WordPress courant sur les requêtes REST authentifiées
 * par token porteur (Bearer), quand aucun cookie de session n'est présent
 * (mobile, SPA).
 *
 * `register()` est idempotent (garde statique) : si plusieurs plugins chargent
 * ce package et appellent `register()` chacun de leur côté, le filtre
 * `rest_pre_dispatch` n'est attaché qu'une seule fois.
 */
final class RestAuthMiddleware
{
    private static bool $registered = false;

    /**
     * @param BearerTokenResolverInterface $resolver Résout le token en ID utilisateur
     * @param callable|null $authorize Callback(int $userId, \WP_REST_Request $request): bool
     *                                 appelé après résolution du token, avant d'authentifier
     *                                 l'utilisateur — vérification supplémentaire libre côté
     *                                 application (compte actif, rôle, restriction d'IP...).
     *                                 Sans lui, tout ID utilisateur résolu est accepté.
     */
    public static function register(BearerTokenResolverInterface $resolver, ?callable $authorize = null): void
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        add_filter(
            'rest_pre_dispatch',
            static function (mixed $result, \WP_REST_Server $server, \WP_REST_Request $request) use ($resolver, $authorize) {
                if (is_user_logged_in()) {
                    return $result;
                }

                $token = BearerToken::extract($request);
                if (!$token) {
                    return $result;
                }

                $userId = $resolver->resolveUserId($token);
                if (!$userId) {
                    return $result;
                }

                if (null !== $authorize && !$authorize($userId, $request)) {
                    return $result;
                }

                wp_set_current_user($userId);

                return $result;
            },
            5,
            3
        );
    }
}
