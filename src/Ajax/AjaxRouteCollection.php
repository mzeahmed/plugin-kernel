<?php

declare(strict_types=1);

namespace PluginKernel\Ajax;

/**
 * Collection centralisée de toutes les routes AJAX déclarées
 * via les attributs #[AjaxRoute] des contrôleurs.
 */
class AjaxRouteCollection
{
    /** @var AjaxRouteDefinition[] */
    private array $routes = [];

    public function get(string $path, array $handler, bool $protected = true, array $middleware = []): self
    {
        return $this->add('GET', $path, $handler, $protected, $middleware);
    }

    public function post(string $path, array $handler, bool $protected = true, array $middleware = []): self
    {
        return $this->add('POST', $path, $handler, $protected, $middleware);
    }

    public function add(string $method, string $path, array $handler, bool $protected = true, array $middleware = []): self
    {
        $this->routes[] = new AjaxRouteDefinition(
            method: strtoupper($method),
            path: $this->normalizePath($path),
            handler: $handler,
            protected: $protected,
            middleware: $middleware
        );

        return $this;
    }

    /**
     * Ajoute des middlewares à la dernière route ajoutée.
     */
    public function middleware(array $middleware): self
    {
        $last = array_key_last($this->routes);
        if (null !== $last) {
            $this->routes[$last]->middleware = array_merge($this->routes[$last]->middleware, $middleware);
        }

        return $this;
    }

    /**
     * @return AjaxRouteDefinition[]
     */
    public function all(): array
    {
        return $this->routes;
    }

    private function normalizePath(string $path): string
    {
        $path = '/' . ltrim(trim($path), '/');

        return preg_replace('#/+#', '/', $path);
    }
}
