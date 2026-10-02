<?php

declare(strict_types=1);

namespace PluginKernel\Module;

/**
 * Magasin runtime des paramètres de configuration des modules.
 *
 * Évite de modifier le container Symfony compilé (ParameterBag figée).
 * Les modules peuvent déclarer un config.php retournant un tableau
 * [ 'module.clef' => valeur ] et déposer ces paramètres ici au runtime si besoin.
 */
class ModuleConfigRegistry
{
    /** @var array<string, mixed> */
    private static array $params = [];

    /**
     * Définit un paramètre de configuration pour un module.
     *
     * Bonnes pratiques : utiliser un préfixe de module (ex: "chat.items_per_page")
     * pour éviter les collisions.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$params[$key] = $value;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return \array_key_exists($key, self::$params) ? self::$params[$key] : $default;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return self::$params;
    }
}
