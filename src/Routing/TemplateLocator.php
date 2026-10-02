<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * Résolution théme-first / plugin-fallback d'un chemin de template (pattern WooCommerce
 * `wc_locate_template()`), partagée par {@see BaseRouter} et par toute route déclarant
 * elle-même son propre callback de résolution (ex: routes `Route::get()` avec closure).
 *
 * Ordre de résolution :
 *   1. `get_stylesheet_directory() . $themeViewsDir . <template>` (thème enfant)
 *   2. `get_template_directory() . $themeViewsDir . <template>` (thème parent)
 *      — voir aussi `$extraThemeCandidates` pour tester d'autres formes équivalentes
 *   3. `$locateFilter` si fourni par l'appelant (point d'extension explicite)
 *   4. `$modulesTemplateDir . <template>` (modules du plugin)
 *   5. `$baseTemplateDir . <template>` (templates racine du plugin)
 *
 * Ne fait aucune hypothèse sur le nom du filtre, le dossier de vues du thème, ni sur une
 * quelconque convention de nommage de module : ce package est partagé par plusieurs
 * plugins/thèmes, chacun garde son propre préfixe et ses propres conventions. Un appelant
 * qui a besoin de tester des formes alternatives de template côté thème (ex: convention
 * `<Module>/UI/Templates/<vue>` → `<module>/<vue>`) les calcule lui-même et les fournit via
 * `$extraThemeCandidates`.
 */
final class TemplateLocator
{
    /**
     * @param string $template Chemin relatif du template (ex: '/Auth/UI/Templates/login', 'issue-report/report-bug').
     * @param string|null $modulesTemplateDir Répertoire des templates de modules côté plugin (fallback).
     * @param string|null $baseTemplateDir Répertoire de templates racine côté plugin (fallback).
     * @param string $themeViewsDir Sous-dossier des vues à l'intérieur du thème actif.
     * @param string|null $locateFilter Nom du filtre `apply_filters(?string, string)` à appliquer avant le fallback plugin.
     * @param string[] $extraThemeCandidates Formes alternatives (déjà normalisées, ex: '/feed/index.php') à
     *                                       tester côté thème en plus de `$template`, calculées par l'appelant
     *                                       selon sa propre convention de nommage.
     *
     * @return string|null Chemin absolu du premier template trouvé, ou null si aucun ne correspond.
     */
    public static function locate(
        string $template,
        ?string $modulesTemplateDir = null,
        ?string $baseTemplateDir = null,
        string $themeViewsDir = '/views',
        ?string $locateFilter = null,
        array $extraThemeCandidates = [],
    ): ?string {
        $normalized = self::normalize($template);
        $themeCandidates = [$normalized, ...$extraThemeCandidates];

        foreach (array_unique(array_filter([get_stylesheet_directory(), get_template_directory()])) as $themeDir) {
            foreach ($themeCandidates as $candidate) {
                $themeFile = rtrim((string) $themeDir, '/') . $themeViewsDir . $candidate;

                if (file_exists($themeFile)) {
                    return $themeFile;
                }
            }
        }

        if (null !== $locateFilter) {
            $viewId = ltrim((string) preg_replace('/\.php$/', '', $normalized), '/');
            $filtered = apply_filters($locateFilter, null, $viewId);

            if (\is_string($filtered) && file_exists($filtered)) {
                return $filtered;
            }
        }

        if ($modulesTemplateDir) {
            $moduleFile = rtrim($modulesTemplateDir, '/') . $normalized;
            if (file_exists($moduleFile)) {
                return $moduleFile;
            }
        }

        if ($baseTemplateDir) {
            $baseFile = rtrim($baseTemplateDir, '/') . $normalized;
            if (file_exists($baseFile)) {
                return $baseFile;
            }
        }

        return null;
    }

    /**
     * Normalise un chemin de template : slash de tête garanti, extension `.php` ajoutée si absente.
     */
    public static function normalize(string $template): string
    {
        $normalized = '/' . ltrim($template, '/');

        return str_ends_with($normalized, '.php') ? $normalized : $normalized . '.php';
    }
}
