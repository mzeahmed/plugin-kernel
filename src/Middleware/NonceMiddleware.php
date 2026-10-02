<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

use PluginKernel\Contract\NonceVerifierInterface;

class NonceMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly NonceVerifierInterface $nonce
    ) {
    }

    public function handle(array $request, callable $next): mixed
    {
        if (!$this->nonce->verify(null, null, false)) {
            wp_send_json_error(['error' => 'Invalid nonce'], 401);
        }

        return $next($request);
    }
}
