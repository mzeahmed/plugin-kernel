<?php

declare(strict_types=1);

namespace PluginKernel\PostType\Interfaces;

interface TaxonomyDefinitionInterface
{
    /**
     * Retourne la clé unique de la taxonomie (ex: 'resource_category')
     */
    public function getSlug(): string;

    /**
     * Retourne les types de posts associés à cette taxonomie
     *
     * @return string[]
     */
    public function getPostTypes(): array;

    /**
     * Retourne la configuration pour register_taxonomy()
     *
     * @return array<string, mixed>
     */
    public function getArgs(): array;
}
