<?php

declare(strict_types=1);

namespace PluginKernel\Event\Contract;

/**
 * Permet de définir un nom personnalisé pour un événement. Si un événement
 * implémente cette interface, son nom sera utilisé au lieu de la classe.
 */
interface NamedEventInterface
{
    /**
     * Retourne le nom de l'événement.
     */
    public function getName(): string;
}
