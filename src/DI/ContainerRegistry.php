<?php

declare(strict_types=1);

namespace PluginKernel\DI;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registry global pour exposer le container DI principal.
 *
 * ⚠️ Ne doit JAMAIS être utilisé directement dans du code métier.
 * Sert seulement au noyau (controllers, routeurs, etc.).
 */
class ContainerRegistry
{
    private static ?ContainerInterface $container = null;

    /**
     * Définit le container principal (Symfony).
     */
    public static function set(ContainerInterface $container): void
    {
        self::$container = $container;
    }

    /**
     * Retourne le container principal.
     */
    public static function get(): ContainerInterface
    {
        if (!self::$container) {
            throw new \RuntimeException('DI container (Symfony) not initialized.');
        }

        return self::$container;
    }

    /**
     * Récupère n'importe quel service du container.
     */
    public static function service(string $id): mixed
    {
        return self::get()->get($id);
    }
}
