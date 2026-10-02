<?php

declare(strict_types=1);

namespace PluginKernel\Event\Contract;

/**
 * Contrat pour un dispatcher d'événements basé sur le système de hooks WordPress
 * (add_action / do_action).
 */
interface EventDispatcherInterface
{
    /**
     * Ajoute un listener pour un événement donné.
     *
     * @param string $event Nom de l'événement (souvent la classe de l'event).
     * @param callable $listener Fonction ou méthode appelée lorsque l'événement est dispatché.
     * @param int $priority Priorité d'exécution du listener.
     */
    public function addListener(string $event, callable $listener, int $priority = 10): void;

    /**
     * Supprime un listener enregistré.
     */
    public function removeListener(string $event, callable $listener, int $priority = 10): void;

    /**
     * Dispatch un événement. Tous les listeners enregistrés pour cet événement
     * seront exécutés.
     *
     * @return object Retourne l'événement dispatché.
     */
    public function dispatch(object $event): object;
}
