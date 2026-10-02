<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

interface HasTaxonomyBehaviorInterface
{
    /**
     * Personnalise le rendu des cases à cocher de la taxonomie dans l'admin
     *
     * @param array $args Arguments passés à wp_terms_checklist()
     * @param int $postId ID du post en cours d'édition
     *
     * @return array Arguments modifiés
     */
    public function customizeChecklistArgs(array $args, int $postId): array;
}
