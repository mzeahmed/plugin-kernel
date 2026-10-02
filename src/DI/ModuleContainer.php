<?php

declare(strict_types=1);

namespace PluginKernel\DI;

use PluginKernel\Module\ModuleConfigRegistry;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * ModuleContainer — enveloppe le container Symfony compilé pour permettre
 * aux modules de déclarer des services au runtime (via RuntimeServiceRegistry)
 * et d'utiliser un autowiring par réflexion en secours.
 *
 * - get()/has() vérifient d'abord RuntimeServiceRegistry, puis le container Symfony
 * - set() enregistre une Closure (fabrique) ou une instance dans RuntimeServiceRegistry
 * - getParameter()/hasParameter() privilégient ModuleConfigRegistry puis délèguent au container
 * - Secours : instanciation par réflexion si la classe n'est pas un service du container
 */
readonly class ModuleContainer implements ContainerInterface
{
    /**
     * @param ContainerInterface $inner Container Symfony compilé.
     */
    public function __construct(
        private ContainerInterface $inner
    ) {
    }

    /**
     * Récupère un service.
     *
     * Ordre de résolution :
     * 1) Services runtime (RuntimeServiceRegistry)
     * 2) Services du container Symfony
     * 3) Secours : autowiring par réflexion si $id est une classe
     */
    public function get(string $id, int $invalidBehavior = ContainerInterface::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        if (RuntimeServiceRegistry::has($id)) {
            return RuntimeServiceRegistry::get($id, $this);
        }

        if ($this->inner->has($id)) {
            try {
                return $this->inner->get($id, $invalidBehavior);
            } catch (\Throwable) {
                // Secours: instanciation par réflexion plus bas
            }
        }

        if (class_exists($id)) {
            return $this->instantiate($id);
        }

        return $this->inner->get($id, $invalidBehavior);
    }

    /**
     * Indique si un service existe (runtime, container ou classe instanciable).
     */
    public function has(string $id): bool
    {
        if (RuntimeServiceRegistry::has($id) || $this->inner->has($id)) {
            return true;
        }

        return class_exists($id);
    }

    /**
     * Enregistre un service au runtime.
     *
     * Accepte une instance ou une Closure (fabrique) qui recevra le container
     * en argument au moment de la résolution.
     */
    public function set(string $id, ?object $service): void
    {
        if (null === $service) {
            return;
        }

        RuntimeServiceRegistry::set($id, $service);
    }

    /**
     * Vrai si le service est déjà initialisé (runtime ou container interne).
     */
    public function initialized(string $id): bool
    {
        if (RuntimeServiceRegistry::has($id)) {
            return true;
        }

        return $this->inner->initialized($id);
    }

    /**
     * Récupère un paramètre de configuration.
     *
     * Priorité :
     * 1) ModuleConfigRegistry (runtime)
     * 2) Container Symfony (ParameterBag)
     *
     * @throws \RuntimeException Si le paramètre est introuvable.
     */
    public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null
    {
        $value = ModuleConfigRegistry::get($name);
        if (null !== $value) {
            return $value;
        }

        if (method_exists($this->inner, 'getParameter')) {
            return $this->inner->getParameter($name);
        }

        throw new \RuntimeException("Parameter '{$name}' not found.");
    }

    /**
     * Indique si un paramètre de configuration existe.
     */
    public function hasParameter(string $name): bool
    {
        if (null !== ModuleConfigRegistry::get($name)) {
            return true;
        }

        return $this->inner->hasParameter($name);
    }

    /**
     * Définit un paramètre de configuration (runtime uniquement).
     *
     * Évite les erreurs de ParameterBag figée sur le container compilé.
     */
    public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void
    {
        ModuleConfigRegistry::set($name, $value);
    }

    /**
     * Délégation générique vers le container interne pour les méthodes inconnues.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return $this->inner->$name(...$arguments);
    }

    /**
     * Instancie une classe par réflexion (autowiring léger).
     *
     * Stratégie de résolution des dépendances constructeurs :
     * - Services runtime (RuntimeServiceRegistry)
     * - Services du container interne
     * - Récursion par réflexion si la dépendance est une classe connue
     * - Valeur par défaut si disponible
     *
     * @throws \ReflectionException
     */
    private function instantiate(string $class): object
    {
        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();
        if (null === $ctor || 0 === $ctor->getNumberOfParameters()) {
            return $ref->newInstance();
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $depClass = $type->getName();
                if (RuntimeServiceRegistry::has($depClass)) {
                    $args[] = RuntimeServiceRegistry::get($depClass, $this);
                    continue;
                }

                if ($this->inner->has($depClass)) {
                    $args[] = $this->inner->get($depClass);
                    continue;
                }

                if (class_exists($depClass)) {
                    $args[] = $this->instantiate($depClass);
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException('Unable to autowire constructor parameter $' . $param->getName() . " for class $class");
        }

        return $ref->newInstanceArgs($args);
    }
}
