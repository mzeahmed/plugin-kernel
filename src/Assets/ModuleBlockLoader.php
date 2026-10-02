<?php

declare(strict_types=1);

namespace PluginKernel\Assets;

use PluginKernel\Module\ModuleLoader;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Enregistrement automatique des blocs Gutenberg déclarés par les modules actifs.
 *
 * Convention (même principe que les assets JS/SCSS, voir ModuleAssetLoader) :
 *
 *     <Module>/Assets/blocks/<bloc>/block.json   ← métadonnées lues par register_block_type()
 *     <Module>/Assets/blocks/<bloc>/index.js     ← script éditeur, compilé par Webpack vers
 *                                                   resources/build/modules/<module>/blocks/<bloc>/
 *
 * Fichiers compilés recherchés dans le manifest Webpack (tous optionnels) :
 *  - modules/<module>/blocks/<bloc>/index.js + index.php (asset.php) → script éditeur ;
 *  - modules/<module>/blocks/<bloc>/index.css                        → style éditeur ;
 *  - modules/<module>/blocks/<bloc>/style-index.css                  → style front + éditeur.
 *
 * Rendu dynamique : soit natif WordPress (`"render": "file:./render.php"` dans block.json),
 * soit un callback déclaré dans le config.php du module, résolu via le container :
 *
 *     'blocks' => ['<bloc>' => [MaClasse::class, 'render']],
 *
 * Un bloc non compilé reste enregistré (son rendu public est conservé) mais n'est pas
 * proposé dans l'éditeur, faute de script.
 */
final class ModuleBlockLoader
{
    /**
     * @var array{pluginPath: string, pluginUrl: string, textDomain: string, handlePrefix: string}
     */
    private static array $config = [
        'pluginPath' => '',
        'pluginUrl' => '',
        'textDomain' => '',
        'handlePrefix' => 'module',
    ];

    private static ?array $manifest = null;

    /**
     * Déclare le hook `init` qui enregistre les blocs des modules activés.
     *
     * @param ModuleLoader $modules Loader unifié des modules
     * @param ContainerInterface $container Container utilisé pour résoudre les callbacks de rendu
     * @param string $pluginPath Chemin absolu racine du plugin (contient resources/build/...)
     * @param string $pluginUrl URL racine du plugin
     * @param string $textDomain Text domain pour wp_set_script_translations()
     * @param string $handlePrefix Préfixe des handles (ex: 'my-plugin-module')
     */
    public static function register(
        ModuleLoader $modules,
        ContainerInterface $container,
        string $pluginPath = '',
        string $pluginUrl = '',
        string $textDomain = '',
        string $handlePrefix = 'module',
    ): void {
        self::$config = [
            'pluginPath' => $pluginPath,
            'pluginUrl' => $pluginUrl,
            'textDomain' => $textDomain,
            'handlePrefix' => $handlePrefix,
        ];

        add_action('init', static function () use ($modules, $container): void {
            self::registerAll($modules, $container);
        });
    }

    private static function registerAll(ModuleLoader $modules, ContainerInterface $container): void
    {
        foreach ($modules->active() as $moduleName => $values) {
            // active() renvoie aussi les modules désactivés (tableau non vide) : on teste `enabled`.
            if (!\is_array($values) || true !== ($values['enabled'] ?? false) || empty($values['path'])) {
                continue;
            }

            $blockDirs = glob($values['path'] . '/Assets/blocks/*', GLOB_ONLYDIR) ?: [];

            foreach ($blockDirs as $blockDir) {
                if (!is_file($blockDir . '/block.json')) {
                    continue;
                }

                $block = basename($blockDir);
                $renderCallbacks = $values['config']['blocks'] ?? [];

                self::registerBlock(
                    strtolower((string) $moduleName),
                    $block,
                    $blockDir,
                    \is_array($renderCallbacks) ? ($renderCallbacks[$block] ?? null) : null,
                    $container,
                );
            }
        }
    }

    private static function registerBlock(
        string $module,
        string $block,
        string $blockDir,
        mixed $renderCallback,
        ContainerInterface $container,
    ): void {
        $prefix = self::$config['handlePrefix'];
        $baseKey = "modules/$module/blocks/$block";
        $handle = "$prefix-block-$block";
        $args = [];

        $jsFile = self::buildFile("$baseKey/index.js");
        $assetFile = self::buildFile("$baseKey/index.php");

        if (null !== $jsFile) {
            $asset = null !== $assetFile && is_file(self::$config['pluginPath'] . "resources/build/$assetFile")
                ? require self::$config['pluginPath'] . "resources/build/$assetFile"
                : ['dependencies' => [], 'version' => false];

            wp_register_script(
                $handle . '-editor',
                self::$config['pluginUrl'] . "resources/build/$jsFile",
                $asset['dependencies'] ?? [],
                $asset['version'] ?? false,
                ['in_footer' => true]
            );

            if ('' !== self::$config['textDomain']) {
                wp_set_script_translations(
                    $handle . '-editor',
                    self::$config['textDomain'],
                    self::$config['pluginPath'] . 'resources/i18n/'
                );
            }

            $args['editor_script'] = $handle . '-editor';
        }

        $editorCss = self::buildFile("$baseKey/index.css");
        if (null !== $editorCss) {
            wp_register_style($handle . '-editor-style', self::$config['pluginUrl'] . "resources/build/$editorCss");
            $args['editor_style'] = $handle . '-editor-style';
        }

        $styleCss = self::buildFile("$baseKey/style-index.css");
        if (null !== $styleCss) {
            wp_register_style($handle . '-style', self::$config['pluginUrl'] . "resources/build/$styleCss");
            $args['style'] = $handle . '-style';
        }

        if (null !== $renderCallback) {
            $args['render_callback'] = self::lazyCallback($renderCallback, $container);
        }

        register_block_type($blockDir, $args);
    }

    /**
     * La classe du callback n'est instanciée qu'au premier rendu du bloc, pas à chaque
     * requête (`init`). Même résolution que les hooks : container, sinon `new`.
     */
    private static function lazyCallback(mixed $callback, ContainerInterface $container): callable
    {
        if (\is_array($callback) && isset($callback[0], $callback[1]) && \is_string($callback[0])) {
            [$class, $method] = $callback;

            return static function (mixed ...$args) use ($class, $method, $container): mixed {
                $instance = $container->has($class) ? $container->get($class) : new $class();

                return $instance->{$method}(...$args);
            };
        }

        if (\is_callable($callback)) {
            return $callback;
        }

        throw new \InvalidArgumentException(\sprintf('Callback de rendu de bloc invalide : %s', print_r($callback, true)));
    }

    private static function buildFile(string $key): ?string
    {
        $file = self::getManifest()[$key] ?? null;

        return \is_string($file) && is_file(self::$config['pluginPath'] . "resources/build/$file") ? $file : null;
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

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        return self::$manifest = \is_array($manifest) ? $manifest : [];
    }
}
