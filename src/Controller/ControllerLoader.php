<?php

declare(strict_types=1);

namespace PluginKernel\Controller;

use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Détecte automatiquement tous les contrôleurs dans les modules :
 *
 *   <modulesPath>/*​/Controller/*.php
 *
 * Et laisse le container DI instancier + injecter les dépendances (avec
 * autowiring par réflexion en secours si le container ne connaît pas la classe).
 */
final class ControllerLoader implements ModuleLoaderInterface
{
    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        foreach (glob($modulesPath . '/*/Controller/*.php') as $file) {
            $class = $this->fileToClass($file, $modulesPath, $modulesNamespace);

            // Skip require_once if the class was already loaded (ex: via l'autoloader PSR-4)
            // pour éviter "Cannot redeclare class" quand les chemins se normalisent différemment.
            if (!class_exists($class, false)) {
                require_once $file;
            }

            if (!class_exists($class)) {
                continue;
            }

            $this->instantiate($class, $container);
        }

        return true;
    }

    public function fileToClass(string $file, string $basePath, string $baseNamespace): string
    {
        $relative = substr($file, strlen($basePath));
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        $relative = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        return $baseNamespace . '\\' . $relative;
    }

    private function instantiate(string $class, ?ContainerInterface $container = null): object
    {
        if ($container && $container->has($class)) {
            return $container->get($class);
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
                if ($container && $container->has($depClass)) {
                    $args[] = $container->get($depClass);
                    continue;
                }

                if (class_exists($depClass)) {
                    $args[] = $this->instantiate($depClass, $container);
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
