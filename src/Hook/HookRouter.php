<?php

declare(strict_types=1);

namespace PluginKernel\Hook;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Injecte dans WordPress les actions/filtres/shortcodes déclarés dans une HookCollection.
 */
readonly class HookRouter
{
    public function __construct(
        private ContainerInterface $container
    ) {
    }

    public function register(HookCollection $hooks): void
    {
        foreach ($hooks->actions() as $item) {
            add_action($item['hook'], $this->resolveCallback($item['callback']), $item['priority'], $item['args']);
        }

        foreach ($hooks->filters() as $item) {
            add_filter($item['hook'], $this->resolveCallback($item['callback']), $item['priority'], $item['args']);
        }

        foreach ($hooks->shortcodes() as $item) {
            add_shortcode(sanitize_key($item['tag']), $this->resolveCallback($item['callback']));
        }
    }

    private function resolveCallback(mixed $callback): callable
    {
        // Cas : [ClassName::class, 'method']
        if (\is_array($callback) && isset($callback[0]) && \is_string($callback[0])) {
            $instance = $this->instantiate($callback[0]);
            $method = $callback[1] ?? '__invoke';
            $callable = [$instance, $method];

            if (!\is_callable($callable)) {
                throw new \RuntimeException(\sprintf('Callback %s::%s is not callable.', $callback[0], $method));
            }

            return $callable;
        }

        // Cas : ClassName::class (avec __invoke)
        if (\is_string($callback) && \class_exists($callback)) {
            $instance = $this->instantiate($callback);
            if (\is_callable($instance)) {
                return $instance;
            }
            throw new \RuntimeException(\sprintf('Class %s is not callable (no __invoke method).', $callback));
        }

        // Callable direct (fonction, closure, etc.)
        if (\is_callable($callback)) {
            return $callback;
        }

        throw new \RuntimeException(\sprintf('Invalid callback provided: %s', print_r($callback, true)));
    }

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
                $args[] = $this->container->get($depClass);
                continue;
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
