<?php

declare(strict_types=1);

namespace PluginKernel\Routing\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
class WebRoute
{
    /**
     * @param string $uri URI déclarative (ex: "profil/{slug}")
     * @param string|null $template Template statique optionnel (sinon le handler retourne le template)
     * @param array<string,mixed> $constraints Contraintes par placeholder (ex: ['id' => 'numeric'])
     * @param string $method Méthode HTTP attendue (GET par défaut)
     * @param string $type "virtual" ou "page"
     */
    public function __construct(
        public string $uri,
        public ?string $template = null,
        public array $constraints = [],
        public string $method = 'GET',
        public string $type = 'virtual',
    ) {
    }
}
