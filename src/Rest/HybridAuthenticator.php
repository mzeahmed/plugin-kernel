<?php

declare(strict_types=1);

namespace PluginKernel\Rest;

use PluginKernel\Rest\Support\BearerToken;
use PluginKernel\Rest\Contract\BearerTokenResolverInterface;
use PluginKernel\Rest\Contract\HybridAuthenticatorInterface;

/**
 * Implémentation par défaut de {@see HybridAuthenticatorInterface} : autorise
 * si l'utilisateur WordPress est connecté (cookie), sinon retombe sur un token
 * porteur (Bearer).
 *
 * Fallback appelé par RestRouteLoader (permission 'logged_in') uniquement
 * lorsque is_user_logged_in() a déjà échoué.
 */
final class HybridAuthenticator implements HybridAuthenticatorInterface
{
    /**
     * @param BearerTokenResolverInterface $resolver Résout le token en ID utilisateur
     * @param callable|null $authorize Callback(int $userId, \WP_REST_Request $request): bool
     *                                 appelé après résolution du token, avant d'authentifier
     *                                 l'utilisateur — vérification supplémentaire libre côté
     *                                 application (compte actif, rôle, restriction d'IP...).
     *                                 Sans lui, tout ID utilisateur résolu est accepté.
     */
    public function __construct(
        private readonly BearerTokenResolverInterface $resolver,
        private $authorize = null,
    ) {
    }

    public function authenticate(\WP_REST_Request $request): bool|\WP_Error
    {
        if (is_user_logged_in()) {
            return true;
        }

        $token = BearerToken::extract($request);
        $userId = $token ? $this->resolver->resolveUserId($token) : null;

        if ($userId && (null === $this->authorize || ($this->authorize)($userId, $request))) {
            wp_set_current_user($userId);

            return true;
        }

        return new \WP_Error('auth_required', 'Authentication required (Bearer token or cookie).', ['status' => 401]);
    }
}
