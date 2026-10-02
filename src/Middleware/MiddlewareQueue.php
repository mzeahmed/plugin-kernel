<?php

declare(strict_types=1);

namespace PluginKernel\Middleware;

/**
 * Exécute une suite de middlewares de façon ordonnée.
 *
 * Chaque middleware doit implémenter :
 *     public function handle(array $request, callable $next): mixed
 *
 * Le dernier $next() correspond au controller final.
 */
class MiddlewareQueue
{
    /** @var array<int, MiddlewareInterface> */
    private array $queue = [];

    public function add(MiddlewareInterface $middleware): void
    {
        $this->queue[] = $middleware;
    }

    /**
     * @param array $request La requête
     * @param callable $final Le controller final (callable)
     */
    public function run(array $request, callable $final): mixed
    {
        $pipeline = array_reduce(
            array_reverse($this->queue),
            fn (callable $next, MiddlewareInterface $middleware) => fn (array $request) => $middleware->handle($request, $next),
            $final
        );

        return $pipeline($request);
    }
}
