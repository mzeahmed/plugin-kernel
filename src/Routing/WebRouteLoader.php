<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

use PluginKernel\Module\ModuleLoader;
use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Charge les routes web déclarées par les modules via HasWebRoutes::registerRoutes().
 *
 * Appelé par le kernel de l'application après le ModuleLoader.
 * Les collections sont mises en queue et matérialisées lors de Route::init().
 */
class WebRouteLoader implements ModuleLoaderInterface
{
    public function __construct(
        private readonly ?ModuleLoader $modules = null
    ) {
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        if (!$this->modules instanceof ModuleLoader) {
            return true;
        }

        foreach ($this->modules->getModules() as $module) {
            if (!($module instanceof HasWebRoutes)) {
                continue;
            }

            $collection = new WebRouteCollection();
            $module->registerRoutes($collection);
            Route::queueCollection($collection);
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
}
