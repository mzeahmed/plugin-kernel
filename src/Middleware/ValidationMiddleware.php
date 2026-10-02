<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

class ValidationMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly array $rules = []
    ) {
    }

    public function handle(array $request, callable $next): mixed
    {
        foreach ($this->rules as $field) {
            if (!isset($request[$field])) {
                wp_send_json_error(['error' => "Missing field: $field"], 400);
            }
        }

        return $next($request);
    }
}
