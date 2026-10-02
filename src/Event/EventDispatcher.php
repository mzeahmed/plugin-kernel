<?php

declare(strict_types=1);

namespace PluginKernel\Event;

use PluginKernel\Event\Contract\NamedEventInterface;
use PluginKernel\Event\Contract\EventDispatcherInterface;

/**
 * Implémentation d'un dispatcher d'événements basé sur le système de hooks
 * WordPress : add_action() pour enregistrer les listeners, do_action() pour
 * dispatcher les événements.
 *
 * Les événements sont identifiés par leur classe (par défaut) ou un nom
 * personnalisé via NamedEventInterface.
 *
 * Exemple :
 *
 *   $dispatcher->dispatch(new UserRegisteredEvent($userId));
 *
 * Les listeners seront exécutés via add_action(UserRegisteredEvent::class, $listener).
 */
class EventDispatcher implements EventDispatcherInterface
{
    public function addListener(string $event, callable $listener, int $priority = 10): void
    {
        add_action($event, $listener, $priority, 1);
    }

    public function removeListener(string $event, callable $listener, int $priority = 10): void
    {
        remove_action($event, $listener, $priority);
    }

    public function dispatch(object $event): object
    {
        $name = $event instanceof NamedEventInterface
            ? $event->getName()
            : $event::class;

        do_action($name, $event);

        return $event;
    }
}
