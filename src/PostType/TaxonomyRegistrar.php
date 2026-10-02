<?php

declare(strict_types=1);

namespace PluginKernel\PostType;

use PluginKernel\PostType\Interfaces\TaxonomyDefinitionInterface;
use PluginKernel\PostType\Interfaces\HasTaxonomyBehaviorInterface;

class TaxonomyRegistrar
{
    /** @var TaxonomyDefinitionInterface[] */
    private array $definitions = [];

    private bool $registered = false;

    public function add(TaxonomyDefinitionInterface $definition): void
    {
        $this->definitions[] = $definition;
    }

    public function registerAll(): void
    {
        if ($this->registered) {
            return;
        }

        $this->registered = true;

        foreach ($this->definitions as $taxonomy) {
            register_taxonomy(
                $taxonomy->getSlug(),
                $taxonomy->getPostTypes(),
                $taxonomy->getArgs()
            );

            if ($taxonomy instanceof HasTaxonomyBehaviorInterface) {
                add_filter(
                    'wp_terms_checklist_args',
                    [$taxonomy, 'customizeChecklistArgs'],
                    10,
                    2
                );
            }
        }
    }
}
