<?php

declare(strict_types=1);

namespace PluginKernel\PostType;

use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface;

/**
 * Scanne un dossier de définitions de Custom Post Types (fichiers `*PostType.php`),
 * les instancie via le container DI, et les enregistre via un PostTypeRegistrar.
 */
class PostTypeLoader implements ModuleLoaderInterface
{
    private readonly PostTypeRegistrar $registrar;
    private bool $hooked = false;

    /**
     * @param callable|null $isModuleActive Callback(string $moduleName): bool permettant
     *                                      d'ignorer les CPT d'un module désactivé. Optionnel :
     *                                      sans lui, tous les CPT trouvés sont chargés.
     */
    public function __construct(
        private readonly mixed $isModuleActive = null,
    ) {
        $this->registrar = new PostTypeRegistrar();
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        if (!$this->hooked) {
            add_action('init', $this->registrar->registerAll(...));
            $this->hooked = true;
        }

        $files = glob($modulesPath . '/*/Infrastructure/PostType/*PostType.php');

        foreach ($files as $file) {
            require_once $file;

            if (\is_callable($this->isModuleActive)) {
                $moduleDir = \dirname($file, 3);
                $moduleName = basename($moduleDir);

                if (!($this->isModuleActive)($moduleName)) {
                    continue;
                }
            }

            $class = $this->fileToClass($file, $modulesPath, $modulesNamespace);
            if (!class_exists($class)) {
                continue;
            }

            $definition = $container?->get($class);

            if ($definition instanceof PostTypeDefinitionInterface) {
                $this->registrar->add($definition);
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
}
