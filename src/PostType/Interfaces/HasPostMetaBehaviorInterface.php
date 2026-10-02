<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

interface HasPostMetaBehaviorInterface
{
    /**
     * Récupère les arguments qui seront passés à la fonction register_post_meta()
     */
    public function getPostMetaArgs(): array;
}
