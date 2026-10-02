<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Représente une route déclarée dans un module avant que le routeur ne soit prêt.
 *
 * Accumulateur : stocke la définition + les appels fluents (guard, titleCallback)
 * jusqu'à ce que materialize() soit appelé par WebRouteCollection::materializeAll().
 */
class PendingWebRoute
{
    private ?string $routeName = null;

    /** @var array<string|callable> */
    private array $guards = [];

    private ?\Closure $titleCallback = null;

    private ?string $titleString = null;

    /**
     * @param string $kind 'virtual' | 'page' | 'get'
     * @param string $uri URI ou slug
     * @param mixed $action Template path (string) ou callable/Controller@method pour 'get'
     * @param array $constraints Contraintes par placeholder
     */
    public function __construct(
        private readonly string $kind,
        private readonly string $uri,
        private readonly mixed $action = '',
        private readonly array $constraints = [],
    ) {
    }

    public function name(string $name): self
    {
        $this->routeName = $name;

        return $this;
    }

    public function guard(string|callable $guard): self
    {
        $this->guards[] = $guard;

        return $this;
    }

    public function titleCallback(callable $callback): self
    {
        $this->titleCallback = \Closure::fromCallable($callback);

        return $this;
    }

    public function title(string $title): self
    {
        $this->titleString = $title;

        return $this;
    }

    /**
     * Matérialise la route via la façade Route (router déjà initialisé à ce stade).
     * Applique ensuite les guards et le titre sur le BaseRoute retourné.
     */
    public function materialize(): void
    {
        $route = match ($this->kind) {
            'page' => Route::page($this->uri, (string) $this->action),
            'virtual' => Route::virtual($this->uri, (string) $this->action, $this->constraints),
            default => Route::get($this->uri, $this->action, $this->constraints),
        };

        foreach ($this->guards as $guard) {
            $route->guard($guard);
        }

        if (null !== $this->titleCallback) {
            $route->titleCallback($this->titleCallback);
        }

        if (null !== $this->titleString) {
            $route->title($this->titleString);
        }

        if (null !== $this->routeName) {
            $route->name($this->routeName);
        }
    }
}
