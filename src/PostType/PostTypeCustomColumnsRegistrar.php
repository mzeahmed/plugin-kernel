<?php

declare(strict_types=1);

namespace PluginKernel\PostType;

use PluginKernel\PostType\Interfaces\HasCustomColumnsInterface;
use PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface;

/**
 * Enregistre automatiquement les colonnes personnalisées des Custom Post Types.
 *
 * Suit le même pattern que {@see PostTypeRegistrar} :
 * - Les CPTs déclarant leurs colonnes implémentent {@see HasCustomColumnsInterface}.
 * - Ce registrar est alimenté par {@see PostTypeRegistrar::add()} lors de la découverte des CPTs.
 * - {@see registerAll()} doit être appelé APRÈS l'enregistrement des CPTs (typiquement sur le hook `init`).
 *
 * Pour chaque définition ajoutée, il enregistre :
 *  - Le filtre `manage_{slug}_posts_columns` → délègue à {@see HasCustomColumnsInterface::getCustomColumns()}
 *  - L'action `manage_{slug}_posts_custom_column` → délègue à {@see HasCustomColumnsInterface::renderCustomColumn()}
 */
class PostTypeCustomColumnsRegistrar
{
    /** @var array<int, PostTypeDefinitionInterface&HasCustomColumnsInterface> */
    private array $definitions = [];

    /**
     * Ajoute une définition de CPT qui déclare des colonnes personnalisées.
     */
    public function add(PostTypeDefinitionInterface&HasCustomColumnsInterface $definition): void
    {
        $this->definitions[] = $definition;
    }

    /**
     * Enregistre les hooks WordPress pour toutes les définitions ajoutées.
     *
     * À appeler sur le hook `init` (après l'enregistrement des CPTs).
     */
    public function registerAll(): void
    {
        foreach ($this->definitions as $cpt) {
            $slug = $cpt->getSlug();

            add_filter(
                "manage_{$slug}_posts_columns",
                fn (array $columns): array => $cpt->getCustomColumns($columns)
            );

            add_action(
                "manage_{$slug}_posts_custom_column",
                function (string $column, int $postId) use ($cpt): void {
                    $cpt->renderCustomColumn($column, $postId);
                },
                10,
                2
            );

            add_filter(
                "manage_edit-{$slug}_sortable_columns",
                fn (array $columns): array => $cpt->getSortableColumns($columns)
            );
        }
    }
}
