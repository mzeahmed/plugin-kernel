<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

class AuthMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): mixed
    {
        if (!is_user_logged_in()) {
            wp_send_json_error(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
