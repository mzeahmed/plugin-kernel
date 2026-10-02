<?php

declare(strict_types=1);

namespace PluginKernel\Cron;

use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Parcourt les modules, instancie chaque Module.php actif, et appelle sa méthode
 * optionnelle registerCron(CronCollection $crons) — détectée par duck typing
 * (method_exists), sans exiger d'implémenter une interface particulière. Remet
 * ensuite la collection à CronRouter pour enregistrement/planification.
 */
final readonly class CronLoader implements ModuleLoaderInterface
{
    /**
     * @param callable|null $isModuleActive Callback(string $moduleName): bool permettant
     *                                      d'ignorer les modules désactivés. Optionnel :
     *                                      sans lui, tous les Module.php trouvés sont chargés.
     * @param class-string $cronCollectionClass FQCN à instancier pour la collection transmise à
     *                                          Module::registerCron() — à surcharger si les modules typent leur
     *                                          paramètre sur une sous-classe de CronCollection (sinon TypeError : une
     *                                          instance de la classe de base ne satisfait pas un type-hint sur l'enfant).
     */
    public function __construct(
        private mixed $isModuleActive = null,
        private string $cronCollectionClass = CronCollection::class,
    ) {
    }

    public function load(string $modulesPath, string $modulesNamespace, ?ContainerInterface $container = null): mixed
    {
        $collectionClass = $this->cronCollectionClass;
        $crons = new $collectionClass();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($modulesPath));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile() || 'Module.php' !== $file->getFilename()) {
                continue;
            }

            if (\is_callable($this->isModuleActive)) {
                $moduleName = basename($file->getPath());
                if (!($this->isModuleActive)($moduleName)) {
                    continue;
                }
            }

            $fqcn = $this->fileToClass($file->getPathname(), $modulesPath, $modulesNamespace);
            if (!class_exists($fqcn)) {
                continue;
            }

            try {
                $module = new $fqcn();
            } catch (\Throwable) {
                continue;
            }

            if (method_exists($module, 'registerCron')) {
                try {
                    $module->registerCron($crons);
                } catch (\Throwable) {
                    // noop: ignore a failing module cron registration
                }
            }
        }

        if ($container instanceof ContainerInterface) {
            (new CronRouter($container))->register($crons);
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
