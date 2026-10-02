<?php

declare(strict_types=1);

namespace PluginKernel\Cron;

/**
 * Collecteur de déclarations WP-Cron : fréquences personnalisées (schedules) et
 * événements (events), à transmettre à CronRouter pour enregistrement/planification.
 *
 * Exemple d'usage :
 *
 *   $crons->schedule('every_fifteen_minutes', 15 * MINUTE_IN_SECONDS, 'Every 15 minutes');
 *   $crons->event(
 *       'my_app_job',
 *       [MyCronService::class, 'run'],
 *       'every_fifteen_minutes',
 *       ['foo' => 'bar']
 *   );
 */
class CronCollection
{
    private array $events = [];
    private array $schedules = [];

    /**
     * Déclare un événement cron.
     *
     * @param string $hook Nom de l'action WordPress exécutée par WP-Cron.
     * @param callable|array $callback Callable ou [Class::class, 'method'] (résolu via DI par CronRouter).
     * @param string $recurrence Nom de fréquence (ex: hourly/daily) ou fréquence custom déclarée via schedule().
     * @param array $args Arguments passés au callback, utilisés aussi pour l'unicité du scheduling.
     * @param int|null $startAt Timestamp Unix de démarrage (par défaut : maintenant si null).
     * @param bool $single true pour un événement unique (wp_schedule_single_event), sinon récurrent.
     * @param int $priority Priorité du add_action sur le hook.
     * @param int $acceptedArgs Nombre d'arguments acceptés par le callback.
     */
    public function event(
        string $hook,
        callable|array $callback,
        string $recurrence = 'hourly',
        array $args = [],
        ?int $startAt = null,
        bool $single = false,
        int $priority = 10,
        int $acceptedArgs = 1
    ): void {
        $this->events[] = [
            'hook' => $hook,
            'callback' => $callback,
            'recurrence' => $recurrence,
            'args' => $args,
            'startAt' => $startAt,
            'single' => $single,
            'priority' => $priority,
            'acceptedArgs' => $acceptedArgs,
        ];
    }

    /**
     * Déclare une fréquence personnalisée (cron schedule).
     *
     * @param string $name Identifiant unique (utilisé dans $recurrence des events)
     * @param int $interval Intervalle en secondes entre deux exécutions
     * @param string $display Libellé lisible (visible dans des outils type WP Crontrol)
     */
    public function schedule(string $name, int $interval, string $display): void
    {
        $this->schedules[$name] = [
            'interval' => $interval,
            'display' => $display,
        ];
    }

    /**
     * @return array<array{hook:string,callback:mixed,recurrence:string,args:array,startAt:int|null,single:bool,priority:int,acceptedArgs:int}>
     */
    public function events(): array
    {
        return $this->events;
    }

    /**
     * @return array<string,array{interval:int,display:string}>
     */
    public function schedules(): array
    {
        return $this->schedules;
    }
}
