<?php

declare(strict_types=1);

namespace PluginKernel\Async\Discovery;

use ReflectionClass;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use PluginKernel\Async\Attribute\AsyncHandler;
use PluginKernel\Async\Job\JobHandlerInterface;

/**
 * Découvre automatiquement les handlers de jobs asynchrones dans les modules
 * en utilisant l'attribut #[AsyncHandler].
 */
final class HandlerDiscovery
{
    /**
     * Découvre tous les handlers dans un répertoire.
     *
     * @param string $modulesPath Chemin vers le répertoire des modules
     * @param string $modulesNamespace Namespace de base des modules
     *
     * @return array<string, string> Map [jobType => handlerClass]
     */
    public static function discover(string $modulesPath, string $modulesNamespace): array
    {
        $handlers = [];

        $asyncDirs = self::findAsyncDirectories($modulesPath);

        foreach ($asyncDirs as $asyncDir) {
            $foundHandlers = self::discoverInDirectory($asyncDir, $modulesPath, $modulesNamespace);
            $handlers = [...$handlers, ...$foundHandlers];
        }

        return $handlers;
    }

    /**
     * @return array<string>
     */
    private static function findAsyncDirectories(string $modulesPath): array
    {
        $asyncDirs = [];

        if (!is_dir($modulesPath)) {
            return $asyncDirs;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulesPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir() && 'Async' === $file->getFilename()) {
                $asyncDirs[] = $file->getPathname();
            }
        }

        return $asyncDirs;
    }

    /**
     * @return array<string, string>
     */
    private static function discoverInDirectory(string $directory, string $modulesPath, string $modulesNamespace): array
    {
        $handlers = [];

        $files = glob($directory . '/*Handler.php');
        if (!$files) {
            return $handlers;
        }

        foreach ($files as $file) {
            $className = self::getClassNameFromFile($file, $modulesPath, $modulesNamespace);
            if (!$className || !class_exists($className)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($className);

                if (!$reflection->implementsInterface(JobHandlerInterface::class)) {
                    continue;
                }

                $attributes = $reflection->getAttributes(AsyncHandler::class);
                if (empty($attributes)) {
                    continue;
                }

                /** @var AsyncHandler $attribute */
                $attribute = $attributes[0]->newInstance();
                $handlers[$attribute->jobType] = $className;
            } catch (\ReflectionException) {
                continue;
            }
        }

        return $handlers;
    }

    private static function getClassNameFromFile(string $filePath, string $modulesPath, string $modulesNamespace): string
    {
        $relativePath = str_replace(
            [$modulesPath . '/', '.php', '/'],
            ['', '', '\\'],
            $filePath
        );

        return $modulesNamespace . '\\' . $relativePath;
    }
}
