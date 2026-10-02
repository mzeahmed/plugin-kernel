<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

/**
 * Contrat pour les Custom Post Types qui déclarent des colonnes personnalisées
 * dans la liste admin (WP_List_Table).
 *
 * L'enregistrement des hooks WordPress est délégué à {@see \PluginKernel\PostType\PostTypeCustomColumnsRegistrar}
 * qui détecte automatiquement les CPTs implémentant cette interface.
 */
interface HasCustomColumnsInterface
{
    /**
     * Modifie la liste des colonnes affichées dans la table admin du CPT.
     *
     * @param array<string, string> $columns Colonnes WordPress existantes (clé ⇒ label)
     *
     * @return array<string, string> Tableau modifié, avec les nouvelles colonnes ajoutées.
     */
    public function getCustomColumns(array $columns): array;

    /**
     * Affiche le contenu d'une cellule pour une colonne personnalisée.
     *
     * Appelé pour chaque ligne de la liste, pour chaque colonne déclarée.
     * La méthode doit filtrer sur $column avant d'afficher quoi que ce soit.
     *
     * @param string $column Clé de la colonne en cours de rendu.
     * @param int $postId ID du post de la ligne courante.
     */
    public function renderCustomColumn(string $column, int $postId): void;

    /**
     * Déclare quelles colonnes personnalisées sont triables dans la liste admin.
     *
     * @param array<string, string> $columns Colonnes triables existantes WordPress
     *
     * @return array<string, string> Tableau avec les colonnes triables personnalisées ajoutées
     */
    public function getSortableColumns(array $columns): array;
}
