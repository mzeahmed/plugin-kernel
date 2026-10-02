<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

interface MiddlewareInterface
{
    /**
     * @param array $request Les données de la requête ($_POST, $_GET)
     * @param callable $next Middleware suivant
     */
    public function handle(array $request, callable $next): mixed;
}
