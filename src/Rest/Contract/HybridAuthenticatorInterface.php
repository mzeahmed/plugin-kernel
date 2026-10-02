<?php

declare(strict_types=1);

namespace PluginKernel\Rest\Contract;

/**
 * Stratégie d'authentification appelée par le loader pour la permission
 * 'logged_in', uniquement lorsque is_user_logged_in() a déjà échoué
 * (ex : session cookie absente mais token porteur type JWT présent).
 */
interface HybridAuthenticatorInterface
{
    public function authenticate(\WP_REST_Request $request): bool|\WP_Error;
}
