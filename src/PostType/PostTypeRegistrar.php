<?php

declare(strict_types=1);

namespace PluginKernel\PostType;

use PluginKernel\PostType\Interfaces\HasMetaboxInterface;
use PluginKernel\PostType\Interfaces\HasCustomColumnsInterface;
use PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface;
use PluginKernel\PostType\Interfaces\HasPostMetaBehaviorInterface;

class PostTypeRegistrar
{
    /** @var PostTypeDefinitionInterface[] */
    private array $definitions = [];

    private readonly PostTypeCustomColumnsRegistrar $customColumnsRegistrar;

    public function __construct()
    {
        $this->customColumnsRegistrar = new PostTypeCustomColumnsRegistrar();
    }

    public function add(PostTypeDefinitionInterface $definition): void
    {
        $this->definitions[] = $definition;

        // Déléguer au registrar de colonnes si le CPT déclare des colonnes personnalisées
        if ($definition instanceof HasCustomColumnsInterface) {
            /** @var PostTypeDefinitionInterface&HasCustomColumnsInterface $definition */
            $this->customColumnsRegistrar->add($definition);
        }
    }

    /**
     * À appeler sur le hook 'init'
     */
    public function registerAll(): void
    {
        foreach ($this->definitions as $cpt) {
            register_post_type($cpt->getSlug(), $cpt->getArgs());

            add_filter('use_block_editor_for_post_type', function ($useBlockEditor, $postType) use ($cpt) {
                if (($postType === $cpt->getSlug()) && !$cpt->useGutenberg()) {
                    return false;
                }

                return $useBlockEditor;
            }, 10, 2);

            if ($cpt instanceof HasMetaboxInterface) {
                add_action('add_meta_boxes', function (string $postType, \WP_Post $post) use ($cpt) {
                    if ($postType === $cpt->getSlug()) {
                        $cpt->registerMetaboxes($postType, $post);
                    }
                }, 10, 2);

                add_action('save_post', function (int $postid, \WP_Post $post) use ($cpt) {
                    if ($post->post_type === $cpt->getSlug()) {
                        $cpt->saveMetaboxes($postid, $post);
                    }
                }, 10, 2);
            }

            if ($cpt instanceof HasPostMetaBehaviorInterface) {
                $args = $cpt->getPostMetaArgs();
                register_post_meta($cpt->getSlug(), $args['meta_key'], $args);
            }
        }

        $this->customColumnsRegistrar->registerAll();
    }
}
