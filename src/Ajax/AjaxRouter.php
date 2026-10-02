<?php

declare(strict_types=1);

namespace PluginKernel\Ajax;

use PluginKernel\Http\Request\AjaxRequest;
use PluginKernel\Middleware\AuthMiddleware;
use PluginKernel\Http\Response\AjaxResponse;
use PluginKernel\Middleware\MiddlewareQueue;
use PluginKernel\Middleware\NonceMiddleware;
use PluginKernel\Middleware\MiddlewareInterface;
use PluginKernel\Contract\NonceVerifierInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Routeur AJAX.
 *
 * - Centralise l'inscription et le dispatch des routes AJAX déclarées côté modules.
 * - Résout les contrôleurs via le container Symfony si possible, sinon par autowiring réflexif.
 * - Applique un pipeline de middlewares : globaux (auth + nonce si route protégée) puis spécifiques.
 * - Utilise les helpers WordPress (wp_send_json_success/wp_send_json_error) pour répondre.
 */
class AjaxRouter
{
    /**
     * @param ContainerInterface $container Container (services transverses, middlewares, etc.)
     * @param AjaxRouteCollection $routes Collection des routes AJAX préalablement enregistrées
     * @param class-string $nonceVerifierServiceId Identifiant/FQCN à résoudre via le container pour
     *                                             obtenir l'implémentation de NonceVerifierInterface — à surcharger par une
     *                                             application consommatrice si elle type/alias son propre vérificateur de
     *                                             nonce sur un FQCN différent de celui du package.
     * @param class-string $ajaxRequestClass FQCN à instancier pour envelopper la requête transmise
     *                                       à chaque action de contrôleur — à surcharger si les contrôleurs typent
     *                                       leur paramètre sur une sous-classe de AjaxRequest (sinon TypeError : une
     *                                       instance de la classe de base ne satisfait pas un type-hint sur l'enfant).
     */
    public function __construct(
        private readonly ContainerInterface $container,
        private readonly AjaxRouteCollection $routes,
        private readonly string $nonceVerifierServiceId = NonceVerifierInterface::class,
        private readonly string $ajaxRequestClass = AjaxRequest::class,
    ) {
    }

    public function dispatch(): mixed
    {
        try {
            return $this->doDispatch();
        } catch (\Throwable $e) {
            AjaxResponse::error($e->getMessage(), 500)->send();

            return null;
        }
    }

    /**
     * @throws \ReflectionException
     */
    private function doDispatch(): mixed
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = $this->normalizePath(sanitize_text_field($_REQUEST['route'] ?? ''));

        foreach ($this->routes->all() as $route) {
            if ($route->method !== $method) {
                continue;
            }

            if ($route->path !== $path) {
                continue;
            }

            [$class, $action] = $route->handler;
            if (!class_exists($class)) {
                wp_send_json_error([
                    'error' => "Controller {$class} not found",
                ], 500);
            }

            $controller = $this->instantiate($class);
            if (!method_exists($controller, $action)) {
                wp_send_json_error([
                    'error' => "Method {$action} not found on {$class}",
                ], 500);
            }

            $queue = new MiddlewareQueue();
            if ($route->protected) {
                $queue->add(new AuthMiddleware());
                $queue->add(new NonceMiddleware($this->container->get($this->nonceVerifierServiceId)));
            }

            foreach ($route->middleware as $middlewareClass) {
                if (!class_exists($middlewareClass)) {
                    wp_send_json_error([
                        'error' => "Middleware class {$middlewareClass} does not exist.",
                    ], 500);
                }

                if (!$this->container->has($middlewareClass)) {
                    wp_send_json_error([
                        'error' => "Middleware {$middlewareClass} is not registered in the DI container.",
                    ], 500);
                }

                $middleware = $this->container->get($middlewareClass);
                if (!$middleware instanceof MiddlewareInterface) {
                    wp_send_json_error([
                        'error' => "Middleware {$middlewareClass} must implement MiddlewareInterface.",
                    ], 500);
                }

                $queue->add($middleware);
            }

            $request = $_REQUEST;
            $requestClass = $this->ajaxRequestClass;
            $ajaxRequest = new $requestClass($request);
            $response = $queue->run($request, fn () => $controller->$action($ajaxRequest));

            if ($response instanceof AjaxResponse) {
                $response->send();

                return null;
            }

            wp_send_json_success($response);
        }

        wp_send_json_error(['error' => 'Route not found'], 404);
    }

    public function routes(): AjaxRouteCollection
    {
        return $this->routes;
    }

    private function normalizePath(string $path): string
    {
        return '/' . ltrim($path, '/');
    }

    /**
     * @throws \RuntimeException|\ReflectionException
     */
    private function instantiate(string $class): object
    {
        if ($this->container->has($class)) {
            return $this->container->get($class);
        }

        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if (null === $ctor || 0 === $ctor->getNumberOfParameters()) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $depClass = $type->getName();
                if ($this->container->has($depClass)) {
                    $args[] = $this->container->get($depClass);
                    continue;
                }

                if (class_exists($depClass)) {
                    $args[] = $this->instantiate($depClass);
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException('Unable to autowire constructor parameter $' . $param->getName() . " for class $class");
        }

        return $ref->newInstanceArgs($args);
    }
}
