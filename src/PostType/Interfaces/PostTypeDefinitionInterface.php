<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

/**
 * Contrat pour la définition d'un Custom Post Type.
 * Cette classe ne doit contenir que de la configuration, pas de logique WP.
 */
interface PostTypeDefinitionInterface
{
    /**
     * Retourne la clé unique du CPT (ex: 'project')
     */
    public function getSlug(): string;

    /**
     * Retourne la configuration pour register_post_type()
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array;

    /**
     * Indique si le CPT utilise l'editeur Gutenberg
     */
    public function useGutenberg(): bool;
}
