<?php

declare(strict_types=1);

namespace PluginKernel\DI;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Registre runtime pour les services fournis dynamiquement par les modules
 * (créés via Module::register()).
 *
 * Ce registre stocke les factories et instances en dehors du container Symfony compilé.
 * Il permet aux modules de déclarer des services dynamiquement au runtime, sans avoir
 * à les définir dans services.yml.
 *
 * **Fonctionnement :**
 * 1. Un module appelle `RuntimeServiceRegistry::set('mon.service', $factory)`
 * 2. Le ModuleContainer vérifie d'abord le container Symfony, puis ce registre
 * 3. À la première récupération, la factory est exécutée et l'instance est mise en cache
 */
class RuntimeServiceRegistry
{
    /**
     * Définitions des services (factory callable ou instance directe).
     *
     * @var array<string, callable|object>
     */
    private static array $definitions = [];

    /**
     * Instances singleton des services déjà instanciés.
     *
     * @var array<string, object>
     */
    private static array $instances = [];

    /**
     * Enregistre un service dans le registre runtime.
     *
     * @param string $id Identifiant unique du service (ex: 'module.mon_service')
     * @param callable|object $factoryOrInstance Factory qui crée le service, ou instance directe
     */
    public static function set(string $id, callable|object $factoryOrInstance): void
    {
        self::$definitions[$id] = $factoryOrInstance;
        unset(self::$instances[$id]);
    }

    /**
     * Vérifie si un service est enregistré dans le registre.
     */
    public static function has(string $id): bool
    {
        return isset(self::$instances[$id]) || isset(self::$definitions[$id]);
    }

    /**
     * Récupère un service du registre runtime.
     *
     * Si le service est une factory, elle sera exécutée une seule fois et l'instance
     * sera mise en cache pour les appels suivants (pattern singleton).
     *
     * @throws \RuntimeException Si le service n'existe pas ou si la factory ne retourne pas un objet
     */
    public static function get(string $id, ?ContainerInterface $container = null): object
    {
        if (isset(self::$instances[$id])) {
            return self::$instances[$id];
        }

        if (!isset(self::$definitions[$id])) {
            throw new \RuntimeException("Runtime service '{$id}' not found.");
        }

        $def = self::$definitions[$id];

        if (\is_object($def) && !\is_callable($def)) {
            return self::$instances[$id] = $def;
        }

        $container ??= ContainerRegistry::get();
        $instance = ($def)($container);

        if (!\is_object($instance)) {
            throw new \RuntimeException("Factory for '{$id}' must return an object.");
        }

        return self::$instances[$id] = $instance;
    }
}
