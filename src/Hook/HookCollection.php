<?php

declare(strict_types=1);

namespace PluginKernel\Hook;

/**
 * Stocke des actions, filtres et shortcodes à enregistrer plus tard.
 *
 * Cette collection ne valide PAS les callbacks : ils seront résolus par HookRouter.
 */
class HookCollection
{
    private array $actions = [];
    private array $filters = [];
    private array $shortcodes = [];

    public function action(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void
    {
        $this->actions[] = compact('hook', 'callback', 'priority', 'args');
    }

    public function filter(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void
    {
        $this->filters[] = compact('hook', 'callback', 'priority', 'args');
    }

    public function shortcode(string $tag, callable|array $callback): void
    {
        $this->shortcodes[] = compact('tag', 'callback');
    }

    public function actions(): array
    {
        return $this->actions;
    }

    public function filters(): array
    {
        return $this->filters;
    }

    public function shortcodes(): array
    {
        return $this->shortcodes;
    }
}
