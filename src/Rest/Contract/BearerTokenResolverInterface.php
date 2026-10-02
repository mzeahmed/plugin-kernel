<?php

declare(strict_types=1);

namespace PluginKernel\Rest\Contract;

/**
 * Résout un token porteur (Bearer) en identifiant utilisateur.
 * Utilisé par {@see \PluginKernel\Rest\RestAuthMiddleware} pour hydrater
 * l'utilisateur courant sur les requêtes REST sans cookie de session
 * (mobile, SPA) — indépendamment du format de token (JWT ou autre).
 */
interface BearerTokenResolverInterface
{
    /**
     * Retourne l'ID utilisateur si le token est valide (signature, expiration...),
     * ou null s'il est invalide/expiré/illisible.
     */
    public function resolveUserId(string $token): ?int;
}
