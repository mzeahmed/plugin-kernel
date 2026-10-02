<?php

declare(strict_types=1);

namespace PluginKernel\PostType;

use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use PluginKernel\PostType\Interfaces\TaxonomyDefinitionInterface;

/**
 * Scanne un dossier de définitions de taxonomies (fichiers `*Taxonomy.php`),
 * les instancie via le container DI, et les enregistre via un TaxonomyRegistrar.
 */
class TaxonomyLoader implements ModuleLoaderInterface
{
    private readonly TaxonomyRegistrar $registrar;
    private bool $hooked = false;

    /**
     * @param callable|null $isModuleActive Callback(string $moduleName): bool permettant
     *                                      d'ignorer les taxonomies d'un module désactivé.
     *                                      Optionnel : sans lui, toutes les taxonomies trouvées
     *                                      sont chargées.
     */
    public function __construct(
        private readonly mixed $isModuleActive = null,
    ) {
        $this->registrar = new TaxonomyRegistrar();
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        if (!$this->hooked) {
            add_action('init', $this->registrar->registerAll(...));
            $this->hooked = true;
        }

        $files = glob($modulesPath . '/*/Infrastructure/PostType/*Taxonomy.php');

        foreach ($files as $file) {
            require_once $file;

            if (\is_callable($this->isModuleActive)) {
                $dir = \dirname($file, 3);
                $moduleName = basename($dir);

                if (!($this->isModuleActive)($moduleName)) {
                    continue;
                }
            }

            $class = $this->fileToClass($file, $modulesPath, $modulesNamespace);
            if (!class_exists($class)) {
                continue;
            }

            $definition = $container?->get($class);

            if ($definition instanceof TaxonomyDefinitionInterface) {
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
