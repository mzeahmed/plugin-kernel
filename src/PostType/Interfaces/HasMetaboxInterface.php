<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

interface HasMetaboxInterface
{
    /**
     * Enregistre les metaboxes pour ce post type
     *
     * @param string $post_type Le type de post actuel
     * @param \WP_Post $post Le post en cours d'édition
     */
    public function registerMetaboxes(string $post_type, \WP_Post $post): void;

    /**
     * Enregistre les données des metaboxes pour ce post type
     *
     * @param int $postId L'ID du post
     * @param \WP_Post $post Le post en cours d'édition
     */
    public function saveMetaboxes(int $postId, \WP_Post $post): void;
}
