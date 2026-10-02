<?php

declare(strict_types=1);

namespace PluginKernel\Ajax\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
class AjaxRoute
{
    /**
     * @param string $method GET|POST (par défaut POST)
     * @param string|null $path Chemin du endpoint (optionnel, sinon auto)
     * @param bool $protected Vérifie login + nonce
     * @param array $middleware Liste de middlewares
     */
    public function __construct(
        public string $method = 'POST',
        public ?string $path = null,
        public bool $protected = true,
        public array $middleware = [],
    ) {
    }
}
