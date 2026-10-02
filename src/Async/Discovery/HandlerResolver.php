<?php

declare(strict_types=1);

namespace PluginKernel\Async\Discovery;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Résout une instance par réflexion en auto-branchant chaque paramètre du
 * constructeur depuis le container.
 *
 * Volontairement tolérant aux pannes : retourne null (au lieu de lever une
 * exception) si une dépendance est irrésolvable, pour ne pas bloquer la
 * découverte des autres handlers asynchrones. Cette philosophie fail-soft est
 * différente de celle de PluginKernel\Controller\ControllerLoader (fail-hard) —
 * les deux ne sont pas fusionnées volontairement.
 */
final class HandlerResolver
{
    public static function resolve(ContainerInterface $container, string $className): ?object
    {
        try {
            $reflection = new \ReflectionClass($className);
            $constructor = $reflection->getConstructor();

            if (!$constructor) {
                return new $className();
            }

            $dependencies = [];

            foreach ($constructor->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (!$type instanceof \ReflectionNamedType || $type->isBuiltin()) {
                    $dependencies[] = $parameter->isDefaultValueAvailable()
                        ? $parameter->getDefaultValue()
                        : null;

                    continue;
                }

                $typeName = $type->getName();

                $dependencies[] = $container->has($typeName)
                    ? $container->get($typeName)
                    : self::resolve($container, $typeName);
            }

            return $reflection->newInstanceArgs($dependencies);
        } catch (\ReflectionException) {
            return null;
        }
    }
}
