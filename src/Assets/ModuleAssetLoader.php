<?php

declare(strict_types=1);

namespace PluginKernel\Assets;

use PluginKernel\Module\ModuleLoader;

/**
 * Enqueue automatique des assets front et admin des modules actifs.
 *
 * Parcourt <pluginPath>/resources/build/modules/{module}/ et enregistre les JS/CSS
 * générés par Webpack pour chaque module actif.
 */
class ModuleAssetLoader
{
    /**
     * Cache runtime pour éviter les accès disque répétés.
     *
     * @var array<string, array<int, string>>
     */
    private static array $assetCache = [];

    /**
     * Cache du manifest webpack.
     */
    private static ?array $manifest = null;

    /**
     * Configuration courante (fixée par register()).
     *
     * @var array{pluginPath: string, pluginUrl: string, pluginVersion: string, pluginFile: string, textDomain: string, handlePrefix: string, themePath: string, themeUrl: string}
     */
    private static array $config = [
        'pluginPath' => '',
        'pluginUrl' => '',
        'pluginVersion' => '',
        'pluginFile' => '',
        'textDomain' => '',
        'handlePrefix' => 'module',
        'themePath' => '',
        'themeUrl' => '',
    ];

    /**
     * Déclare le hook wp_enqueue_scripts pour attacher les bundles des modules actifs.
     *
     * @param ModuleLoader $modules Loader unifié des modules (expose active())
     * @param string $pluginPath Chemin absolu racine du plugin (contient resources/build/...)
     * @param string $pluginUrl URL racine du plugin
     * @param string $pluginVersion Version par défaut pour le cache-busting
     * @param string $pluginFile Fichier principal du plugin (pour les traductions JS)
     * @param string $textDomain Text domain WordPress pour wp_set_script_translations()
     * @param string $handlePrefix Préfixe des handles wp_enqueue_script/style (ex: 'my-plugin-module')
     * @param string $themePath Chemin absolu racine du thème (défaut: thème actif)
     * @param string $themeUrl URL racine du thème (défaut: thème actif)
     */
    public static function register(
        ModuleLoader $modules,
        string $pluginPath = '',
        string $pluginUrl = '',
        string $pluginVersion = '',
        string $pluginFile = '',
        string $textDomain = '',
        string $handlePrefix = 'module',
        string $themePath = '',
        string $themeUrl = '',
    ): void {
        $themePath = '' !== $themePath ? $themePath : get_stylesheet_directory();
        $themeUrl = '' !== $themeUrl ? $themeUrl : get_stylesheet_directory_uri();

        self::$config = [
            'pluginPath' => $pluginPath,
            'pluginUrl' => $pluginUrl,
            'pluginVersion' => $pluginVersion,
            'pluginFile' => $pluginFile,
            'textDomain' => $textDomain,
            'handlePrefix' => $handlePrefix,
            'themePath' => $themePath,
            'themeUrl' => $themeUrl,
        ];

        add_action(
            'wp_enqueue_scripts',
            static function () use ($modules) {
                self::load($modules, 'front');
            }
        );

        add_action(
            'admin_enqueue_scripts',
            static function () use ($modules) {
                self::load($modules, 'admin');
                wp_enqueue_media();
            }
        );
    }

    /**
     * @param 'front'|'admin' $context
     */
    private static function load(ModuleLoader $modules, string $context): void
    {
        foreach ($modules->active() as $module => $values) {
            $module = strtolower((string) $module);

            $config = $values['config'];

            // Module dont les assets front sont buildés côté thème
            // (ex: axe, auth) : enqueue depuis <themePath>/assets/build/theme/modules/{module}/.
            if ('front' === $context && !empty($config['assets']['theme'])) {
                if (!self::shouldEnqueue($config, 'front')) {
                    continue;
                }

                self::enqueueThemeModule($module);
                continue;
            }

            $baseDir = self::$config['pluginPath'] . "resources/build/modules/$module";
            $dir = 'admin' === $context ? "$baseDir/admin" : $baseDir;

            if (!is_dir($dir)) {
                continue;
            }

            if (!self::shouldEnqueue($config, $context)) {
                continue;
            }

            self::enqueueModule($module, $dir, $context);
        }
    }

    private static function enqueueThemeModule(string $module): void
    {
        $dir = self::$config['themePath'] . "/assets/build/theme/modules/$module";

        if (!is_dir($dir)) {
            return;
        }

        $assetPath = $dir . "/$module.asset.php";
        $asset = is_file($assetPath) ? require $assetPath : ['dependencies' => [], 'version' => null];

        $prefix = self::$config['handlePrefix'];
        $hookPrefix = str_replace('-', '_', $prefix);

        $jsAbs = $dir . "/$module.js";
        if (is_file($jsAbs)) {
            $handle = "$prefix-$module";
            wp_enqueue_script(
                $handle,
                self::$config['themeUrl'] . "/assets/build/theme/modules/$module/$module.js",
                $asset['dependencies'] ?? [],
                (string) filemtime($jsAbs),
                ['in_footer' => true]
            );

            do_action(
                "{$hookPrefix}_script_enqueued",
                $handle,
                $module
            );
        }

        $cssAbs = $dir . "/$module.css";
        if (is_file($cssAbs)) {
            $handle = "$prefix-$module-style";
            wp_enqueue_style(
                $handle,
                self::$config['themeUrl'] . "/assets/build/theme/modules/$module/$module.css",
                [],
                (string) filemtime($cssAbs)
            );

            do_action(
                "{$hookPrefix}_style_enqueued",
                $handle,
                $module
            );
        }
    }

    private static function shouldEnqueue(array $config, string $context): bool
    {
        $condition = $config['assets'][$context] ?? null;

        if (\is_callable($condition)) {
            return $condition();
        }

        return true;
    }

    private static function enqueueModule(string $module, string $dir, string $context): void
    {
        $files = self::findAssets($dir, $module);

        if ($files === []) {
            return;
        }

        $isAdmin = 'admin' === $context;
        $manifestKey = $isAdmin
            ? "modules/$module/admin/$module.php"
            : "modules/$module/$module.php";

        $manifest = self::getManifest();
        $assetPhpHashed = $manifest[$manifestKey] ?? null;
        $assetFile = $assetPhpHashed
            ? self::$config['pluginPath'] . "resources/build/$assetPhpHashed"
            : $dir . "/$module.asset.php";

        $asset = is_file($assetFile)
            ? require $assetFile
            : ['dependencies' => []];

        $deps = array_merge($asset['dependencies'] ?? [], ['jquery', 'wp-element']);

        foreach ($files as $file) {
            self::enqueueFile($module, $file, $deps, $context);
        }
    }

    private static function findAssets(string $dir, string $basename): array
    {
        if (isset(self::$assetCache[$dir])) {
            return self::$assetCache[$dir];
        }

        $manifest = self::getManifest();
        $files = [];

        $isAdmin = str_contains($dir, '/admin');
        $module = $basename;

        if ($isAdmin) {
            $prefix = "modules/$module/admin/$module";
        } else {
            $prefix = "modules/$module/$module";
        }

        foreach (['.js', '.css'] as $ext) {
            $key = $prefix . $ext;

            if (isset($manifest[$key])) {
                $hashedFile = $manifest[$key];
                $fullPath = self::$config['pluginPath'] . "resources/build/$hashedFile";

                if (file_exists($fullPath)) {
                    $files[] = $fullPath;
                }
            }
        }

        return self::$assetCache[$dir] = $files;
    }

    private static function enqueueFile(string $module, string $file, array $deps, string $context): void
    {
        $isAdmin = 'admin' === $context;
        $basename = basename($file);

        $relativePath = str_replace(self::$config['pluginPath'] . 'resources/build/', '', $file);
        $url = self::$config['pluginUrl'] . "resources/build/$relativePath";

        $version = self::$config['pluginVersion'];
        if (preg_match('/-([a-f0-9]+)\.(js|css)$/', $basename, $matches)) {
            $version = $matches[1];
        }

        $prefix = self::$config['handlePrefix'];
        $hookPrefix = str_replace('-', '_', $prefix);

        if (str_ends_with($file, '.js')) {
            $handle = $isAdmin
                ? "$prefix-admin-$module"
                : "$prefix-$module";

            wp_enqueue_script(
                $handle,
                $url,
                $deps,
                $version,
                ['in_footer' => true]
            );

            if ('' !== self::$config['textDomain']) {
                wp_set_script_translations(
                    $handle,
                    self::$config['textDomain'],
                    plugin_dir_path(self::$config['pluginFile']) . 'resources/i18n/'
                );
            }

            do_action(
                $isAdmin
                    ? "{$hookPrefix}_admin_script_enqueued"
                    : "{$hookPrefix}_script_enqueued",
                $handle,
                $module
            );
        }

        if (str_ends_with($file, '.css')) {
            $handle = $isAdmin
                ? "$prefix-admin-$module-style"
                : "$prefix-$module-style";

            wp_enqueue_style(
                $handle,
                $url,
                [],
                $version
            );

            do_action(
                $isAdmin
                    ? "{$hookPrefix}_admin_style_enqueued"
                    : "{$hookPrefix}_style_enqueued",
                $handle,
                $module
            );
        }
    }

    private static function getManifest(): array
    {
        if (null !== self::$manifest) {
            return self::$manifest;
        }

        $manifestPath = self::$config['pluginPath'] . 'resources/build/asset-manifest.json';

        if (!file_exists($manifestPath)) {
            return self::$manifest = [];
        }

        $content = file_get_contents($manifestPath);
        $manifest = json_decode($content, true);

        return self::$manifest = \is_array($manifest) ? $manifest : [];
    }
}
