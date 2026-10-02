<?php

declare(strict_types=1);

namespace PluginKernel\Module\Interfaces;

use PluginKernel\Cron\CronCollection;
use PluginKernel\Hook\HookCollection;
use PluginKernel\Ajax\AjaxRouteCollection;

interface ModuleInterface
{
    public function register(HookCollection $hooks): void;

    /**
     * Utiliser si les routes ne sont pas declarées via #[AjaxRoute]
     */
    public function registerAjax(AjaxRouteCollection $routes): void;

    public function registerCron(CronCollection $crons): void;

    public function get(string $key, mixed $default = null);
}
