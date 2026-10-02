<?php

declare(strict_types=1);

namespace PluginKernel\DI;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Dumper\PhpDumper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

/**
 * Chargeur du conteneur Symfony DI.
 *
 * Cette classe gère le cycle de vie complet du conteneur de dépendances :
 * - Compilation du conteneur depuis un fichier services.yml
 * - Génération du cache PHP optimisé
 * - Détection automatique des changements en mode développement
 * - Rechargement du cache en production
 *
 * **Architecture :**
 * 1. En production : le cache est utilisé tant qu'il existe
 * 2. En développement ($isDev = true) : le cache est reconstruit si :
 *    - Le fichier services.yml a été modifié
 *    - Un fichier PHP dans un des $watchedDirectories a été modifié
 *    - Le cache n'existe pas
 */
class SymfonyContainerLoader
{
    /**
     * Charge ou compile le conteneur Symfony DI.
     *
     * @param string $configPath Chemin absolu vers le fichier services.yml
     * @param string $cachePath Chemin absolu vers le fichier de cache PHP à générer
     * @param string $cacheClass Nom de la classe générée par le PhpDumper
     * @param string $cacheNamespace Namespace de la classe générée
     * @param bool $isDev Active la détection de changements + recompilation automatique
     * @param array<string,string> $envParameters Paramètres %env(NOM)% à injecter manuellement
     *                                            dans le ContainerBuilder avant compilation
     *                                            (ex: ['WP_ENV' => 'development', 'APP_PATH' => '/path/'])
     * @param string[] $watchedDirectories Répertoires PHP surveillés en mode dev pour déclencher
     *                                     un rebuild (vide = jamais de rebuild automatique par fichier)
     *
     * @throws \RuntimeException Si le chargement YAML ou la compilation échoue
     */
    public static function load(
        string $configPath,
        string $cachePath,
        string $cacheClass = 'GeneratedContainer',
        string $cacheNamespace = '',
        bool $isDev = false,
        array $envParameters = [],
        array $watchedDirectories = [],
    ): ContainerInterface {
        $fqcn = '' !== $cacheNamespace ? $cacheNamespace . '\\' . $cacheClass : $cacheClass;

        $needsRebuild = self::needsRebuild($configPath, $cachePath, $isDev, $watchedDirectories);

        if (!$needsRebuild && file_exists($cachePath)) {
            if (!class_exists($fqcn, false)) {
                require_once $cachePath;
            }

            return new $fqcn();
        }

        if (file_exists($cachePath)) {
            @unlink($cachePath);
        }

        $container = new ContainerBuilder();

        foreach ($envParameters as $name => $value) {
            $container->setParameter("env({$name})", $value);
        }

        $loader = new YamlFileLoader($container, new FileLocator(\dirname($configPath)));

        try {
            $loader->load(basename($configPath));
        } catch (\Throwable $e) {
            throw new \RuntimeException("Erreur lors du chargement YAML {$configPath}: " . $e->getMessage());
        }

        try {
            $container->compile();
        } catch (\Throwable $e) {
            throw new \RuntimeException('Erreur lors de la compilation du conteneur Symfony: ' . $e->getMessage());
        }

        self::dumpCache($container, $cachePath, $cacheClass, $cacheNamespace);

        if (!class_exists($fqcn, false)) {
            require_once $cachePath;
        }

        return new $fqcn();
    }

    /**
     * Génère le fichier de cache PHP optimisé du conteneur.
     *
     * @throws \RuntimeException Si le dump ou l'écriture du fichier échoue
     */
    private static function dumpCache(ContainerBuilder $container, string $cachePath, string $cacheClass, string $cacheNamespace): void
    {
        $dumper = new PhpDumper($container);
        try {
            $php = $dumper->dump([
                'class' => $cacheClass,
                'namespace' => $cacheNamespace,
            ]);

            $dir = \dirname($cachePath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException("Unable to create cache directory: {$dir}");
            }

            file_put_contents($cachePath, $php);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Impossible de dumper le conteneur Symfony : ' . $e->getMessage());
        }
    }

    /**
     * Détermine si le conteneur doit être reconstruit.
     */
    private static function needsRebuild(string $configPath, string $cachePath, bool $isDev, array $watchedDirectories): bool
    {
        if (!$isDev) {
            return !file_exists($cachePath);
        }

        if (!file_exists($cachePath)) {
            return true;
        }

        $cacheMtime = filemtime($cachePath);

        if (file_exists($configPath) && filemtime($configPath) > $cacheMtime) {
            return true;
        }

        return self::hasChangesInWatchedFiles($watchedDirectories, $cacheMtime);
    }

    /**
     * Vérifie si des fichiers surveillés ont été modifiés après la création du cache.
     */
    private static function hasChangesInWatchedFiles(array $watchedDirectories, int $cacheMtime): bool
    {
        foreach ($watchedDirectories as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            if (self::hasDirectoryChanges($directory, $cacheMtime)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Vérifie récursivement si un répertoire contient des fichiers PHP modifiés.
     */
    private static function hasDirectoryChanges(string $directory, int $cacheMtime): bool
    {
        $excludeFiles = ['index.php'];

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                if ('php' !== pathinfo((string) $file->getPathname(), PATHINFO_EXTENSION)) {
                    continue;
                }

                if (\in_array($file->getFilename(), $excludeFiles, true)) {
                    continue;
                }

                if ($file->getMTime() > $cacheMtime) {
                    return true;
                }
            }
        } catch (\Exception) {
            // Ignorer les erreurs de lecture de répertoire
        }

        return false;
    }
}
