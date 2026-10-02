<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

class AdminMiddleware implements MiddlewareInterface
{
    public function handle(array $request, callable $next): mixed
    {
        if (!is_admin()) {
            wp_send_json_error(['error' => 'Unauthorized, admin area action only'], 401);
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['error' => 'Unauthorized'], 403);
        }

        return $next($request);
    }
}
