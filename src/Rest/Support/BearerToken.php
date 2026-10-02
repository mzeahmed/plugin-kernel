<?php

declare(strict_types=1);

namespace PluginKernel\Rest\Support;

/**
 * Extraction du token porteur (Bearer) d'une requête REST WordPress.
 * Partagée entre {@see \PluginKernel\Rest\RestAuthMiddleware} et
 * {@see \PluginKernel\Rest\HybridAuthenticator}.
 */
final class BearerToken
{
    /**
     * Lit l'en-tête Authorization: Bearer X, avec repli sur X-Auth-Token
     * (utile en mobile quand Apache ne transmet pas Authorization).
     */
    public static function extract(\WP_REST_Request $request): ?string
    {
        $authHeader = $request->get_header('authorization');
        if ($authHeader && str_starts_with(strtolower($authHeader), 'bearer ')) {
            return trim(substr($authHeader, 7));
        }

        $xAuthToken = $request->get_header('x-auth-token');
        if ($xAuthToken) {
            return trim($xAuthToken);
        }

        return null;
    }
}
