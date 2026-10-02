<?php

declare(strict_types=1);

namespace PluginKernel\Routing\Interface;

/**
 * Contrat des guards attachables à une route via BaseRoute::guard()/PendingWebRoute::guard().
 * Une application consommatrice fournit ses propres implémentations (contrôle
 * d'accès, abonnement, rôle, etc.).
 */
interface RouteGuardInterface
{
    /**
     * Exécute la vérification d'accès. Peut rediriger ou lever une exception
     * pour bloquer l'accès ; sinon laisse la requête continuer.
     */
    public function guard(): void;
}
