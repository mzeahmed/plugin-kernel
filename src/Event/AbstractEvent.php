<?php

declare(strict_types=1);

namespace PluginKernel\Event;

/**
 * Classe de base pour tous les événements applicatifs. Fournit automatiquement
 * un timestamp de création.
 */
abstract class AbstractEvent
{
    private readonly \DateTimeImmutable $occurredAt;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Retourne la date de création de l'événement.
     */
    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
