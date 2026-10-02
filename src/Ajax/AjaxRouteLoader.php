<?php

declare(strict_types=1);

namespace PluginKernel\Ajax;

use PluginKernel\Ajax\Attribute\AjaxRoute;
use PluginKernel\FileSystem\Finder\GlobFinder;
use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parcourt les modules et localise les contrôleurs AJAX (pattern Controller/Ajax/*.php),
 * lit les attributs #[AjaxRoute] posés sur les méthodes publiques par réflexion, et
 * enregistre les routes correspondantes dans l'AjaxRouteCollection fournie.
 *
 * Si l'attribut ne précise pas le path/method, des valeurs par défaut sont calculées :
 * - method par défaut : POST
 * - path par défaut : /{module}/{method-kebab}
 */
readonly class AjaxRouteLoader implements ModuleLoaderInterface
{
    public function __construct(
        private AjaxRouteCollection $collection
    ) {
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        $finder = new GlobFinder($modulesPath . '/*/Controller/Ajax/*.php', true);
        $files = $finder->find();

        foreach ($files as $file) {
            require_once $file;

            $class = $this->fileToClass($file, $modulesPath, $modulesNamespace);
            if (!class_exists($class)) {
                continue;
            }

            $refClass = new \ReflectionClass($class);

            foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attributes = $method->getAttributes(AjaxRoute::class, \ReflectionAttribute::IS_INSTANCEOF);
                if (!$attributes) {
                    continue;
                }

                /** @var AjaxRoute $attr */
                $attr = $attributes[0]->newInstance();
                $path = $attr->path ?? $this->autoPath($refClass, $method);
                $verb = strtoupper($attr->method);
                $this->collection->add($verb, $path, [$class, $method->getName()], $attr->protected, $attr->middleware);
            }
        }

        return true;
    }

    public function fileToClass(string $file, string $basePath, string $baseNamespace): string
    {
        $relative = substr($file, \strlen($basePath));
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        $relative = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        return $baseNamespace . '\\' . $relative;
    }

    private function autoPath(\ReflectionClass $ref, \ReflectionMethod $method): string
    {
        $module = strtolower(basename(\dirname($ref->getFileName(), 2)));
        $name = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $method->getName()));

        return "/$module/$name";
    }
}
