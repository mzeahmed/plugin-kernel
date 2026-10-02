<?php

declare(strict_types=1);

namespace PluginKernel\Cron;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enregistre les fréquences cron personnalisées, attache les callbacks de tâches
 * via add_action, et programme les événements (single ou récurrents) s'ils ne le
 * sont pas déjà — à partir d'une CronCollection.
 */
readonly class CronRouter
{
    public function __construct(
        private ContainerInterface $container
    ) {
    }

    /**
     * @throws \ReflectionException
     */
    public function register(CronCollection $crons): void
    {
        // 1) Register custom schedules
        add_filter('cron_schedules', function (array $schedules) use ($crons) {
            foreach ($crons->schedules() as $name => $def) {
                $schedules[$name] = [
                    'interval' => (int) ($def['interval'] ?? 0),
                    'display' => (string) ($def['display'] ?? $name),
                ];
            }

            return $schedules;
        });

        // 2) Register callbacks for cron hooks
        foreach ($crons->events() as $event) {
            $hook = (string) ($event['hook'] ?? '');
            $callback = $event['callback'] ?? null;
            $priority = (int) ($event['priority'] ?? 10);
            $acceptedArgs = (int) ($event['acceptedArgs'] ?? 1);
            if (!$hook || !$callback) {
                continue;
            }

            add_action($hook, $this->resolveCallback($callback), $priority, $acceptedArgs);
        }

        // 3) Schedule events if not already scheduled (on init)
        add_action('init', function () use ($crons) {
            foreach ($crons->events() as $event) {
                $hook = (string) ($event['hook'] ?? '');
                $recurrence = (string) ($event['recurrence'] ?? 'hourly');
                $args = (array) ($event['args'] ?? []);
                $startAt = $event['startAt'] ?? null;
                $single = (bool) ($event['single'] ?? false);
                if (!$hook) {
                    continue;
                }

                if (wp_next_scheduled($hook, $args)) {
                    continue;
                }

                $timestamp = \is_int($startAt) && $startAt > 0 ? $startAt : time();
                if ($single) {
                    wp_schedule_single_event($timestamp, $hook, $args);
                } else {
                    wp_schedule_event($timestamp, $recurrence, $hook, $args);
                }
            }
        });
    }

    /**
     * @throws \ReflectionException
     */
    private function resolveCallback(mixed $callback): callable
    {
        if (\is_array($callback) && \is_string($callback[0])) {
            $class = $callback[0];
            $method = $callback[1] ?? '__invoke';

            $instance = $this->instantiate($class);

            if (!method_exists($instance, $method)) {
                throw new \InvalidArgumentException(
                    \sprintf('La méthode "%s" n\'existe pas dans la classe "%s".', $method, $class)
                );
            }

            $resolvedCallback = [$instance, $method];

            if (!\is_callable($resolvedCallback)) {
                throw new \InvalidArgumentException(
                    \sprintf('Le callback [%s, %s] n\'est pas un callable valide.', $class, $method)
                );
            }

            return $resolvedCallback;
        }

        if (!\is_callable($callback)) {
            throw new \InvalidArgumentException('Le callback fourni n\'est pas un callable valide.');
        }

        return $callback;
    }

    /**
     * @throws \ReflectionException
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
