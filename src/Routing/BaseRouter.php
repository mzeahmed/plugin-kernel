<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * BaseRouter : moteur principal du système de routage.
 *
 * Il gère :
 *  - Les routes "virtuelles" (pas de page WordPress en base)
 *  - Les routes "page" (une page WordPress existe et on force son template)
 *  - Les règles de réécriture WordPress (rewrite rules)
 *  - Le template loader (sélection finale du template)
 *  - La prévention des faux 404 et des redirections WordPress indésirables
 *
 * Le fonctionnement général :
 *  1) Route::page()   → n'ajoute pas de rewrite, mais mappe un template sur une vraie page WP.
 *  2) Route::virtual()→ crée une URL personnalisée avec placeholders, via rewrite rules.
 *  3) ->boot()        → enregistre toutes les hooks WordPress nécessaires
 *
 * WordPress ne possède pas de routeur natif.
 * Ce routeur recrée une logique de routing moderne, compatible avec WordPress.
 */
class BaseRouter
{
    /**
     * Option posée quand une route virtuelle n'est pas encore dans les rewrite rules
     * persistées : l'application la lit pour déclencher flush_rewrite_rules().
     */
    public const string FLUSH_REWRITE_OPTION = 'plugin_kernel_flush_rewrite_rules';

    /** @var BaseRoute[] Liste interne des routes déclarées */
    private array $routes = [];
    /**
     * Répertoires plugin dans lesquels chercher les templates relatifs,
     * utilisés uniquement si le thème actif n'a pas surchargé la vue (voir {@see resolveTemplatePath()}).
     */
    private readonly ?string $baseTemplateDir;
    private readonly ?string $modulesTemplateDir;

    /**
     * @param string|null $baseTemplateDir Répertoire de templates racine côté plugin (fallback).
     * @param string|null $modulesTemplateDir Répertoire des templates de modules côté plugin (fallback).
     * @param string $themeViewsDir Sous-dossier des vues à l'intérieur du thème actif, vérifié en premier.
     * @param string|null $locateTemplateFilter Nom du filtre de résolution custom à appliquer avant le fallback plugin
     *                                          (ex: 'my_plugin_locate_template'). Null = pas de point d'extension.
     * @param \Closure|null $themeCandidateResolver Point d'extension pour la convention de nommage de module de
     *                                              l'appelant (ex: `<Module>/UI/Templates/<vue>` → `<module>/<vue>`).
     *                                              Reçoit le template normalisé, retourne des formes alternatives
     *                                              (string[]) à tester côté thème en plus du template lui-même.
     *                                              Ce routeur ne fait aucune hypothèse sur cette convention.
     */
    public function __construct(
        ?string $baseTemplateDir = null,
        ?string $modulesTemplateDir = null,
        private readonly string $themeViewsDir = '/views',
        private readonly ?string $locateTemplateFilter = null,
        private readonly ?\Closure $themeCandidateResolver = null,
    ) {
        $this->baseTemplateDir = $baseTemplateDir ? rtrim($baseTemplateDir, '/') : null;
        $this->modulesTemplateDir = $modulesTemplateDir ? rtrim($modulesTemplateDir, '/') : null;
    }

    /* ============================================================
     *               BOOT : enregistrement des hooks WP
     * ============================================================*/

    /**
     * Active le routeur dans WordPress :
     *
     *  - init                → ajoute les règles de réécriture
     *  - query_vars          → déclare les variables de query personnalisées
     *  - template_include    → finalise le choix du template
     *  - request             → simule pagename pour les routes virtuelles
     *  - pre_handle_404      → empêche WordPress de renvoyer une fausse 404
     *  - redirect_canonical  → empêche les redirections auto sur nos routes
     *  - template_redirect   → protège avant exécution finale
     *
     * À appeler UNE FOIS, après toutes les déclarations de routes.
     */
    public function boot(): void
    {
        add_action('init', $this->addRules(...));
        $this->scheduleRewriteFlushIfNeeded();
        add_filter('query_vars', $this->addQueryVars(...));
        add_filter('template_include', $this->templateInclude(...), 0);
        add_filter('request', $this->mapRequestToRoutes(...));
        add_filter('pre_handle_404', $this->prevent404ForRoutes(...), 10, 2);
        add_filter('redirect_canonical', $this->disableCanonicalForRoutes(...), 9999, 2);
        add_action('template_redirect', $this->earlyTemplateRedirectGuard(...), 0);
        add_action('template_redirect', $this->executeRouteGuards(...), 1);
        add_action('parse_request', $this->handleEarlyRoutes(...), 0);

        // 1. Pour l'onglet du navigateur (Balise <title>) -  WordPress core
        add_filter('document_title_parts', $this->customizeVirtualPageTitleParts(...), 10);
        add_filter('pre_get_document_title', $this->customizeVirtualPageTitleParts(...), 10);

        // 2. Pour les plugins SEO (Yoast, RankMath) qui écrasent souvent le standard WP
        add_filter('wpseo_title', $this->customizeVirtualPageTitleString(...), 10); // Yoast
        add_filter('rank_math/frontend/title', $this->customizeVirtualPageTitleString(...), 10); // RankMath
    }

    /**
     * Marque les rewrite rules pour flush si un pattern de route virtuelle
     * est absent des règles persistées en base de données.
     *
     * Le flush effectif est à la charge de l'application : lire l'option
     * {@see self::FLUSH_REWRITE_OPTION} sur `init` (après addRules(), priorité 10),
     * appeler flush_rewrite_rules() puis supprimer l'option pour n'agir qu'une fois.
     */
    private function scheduleRewriteFlushIfNeeded(): void
    {
        $dbRules = (array) get_option('rewrite_rules', []);

        foreach ($this->routes as $route) {
            if (!$route->isVirtual() || '' === $route->getPattern()) {
                continue;
            }

            if (!isset($dbRules[$route->getPattern()])) {
                update_option(self::FLUSH_REWRITE_OPTION, true, true);

                return;
            }
        }
    }

    /* ============================================================
     *               PUBLIC API : page() et virtual()
     * ============================================================*/

    /**
     * Déclare une route basée sur une page WordPress existante.
     *
     * Usage :
     *   Route::page('connexion', '/public/auth/login.php');
     *
     * Fonctionnement :
     *   - NE crée PAS de règle de réécriture
     *   - N'associe JAMAIS de query vars
     *   - Déclenche simplement un remplacement de template lorsque
     *     WordPress détecte la page (is_page('slug'))
     *
     * @param string $slug Slug EXACT de la page WP en base de données.
     * @param string $templatePath Template à charger, résolu thème-first (voir {@see resolveTemplatePath()}).
     */
    public function page(string $slug, string $templatePath): BaseRoute
    {
        $route = new BaseRoute(
            name: trim($slug, '/'),
            queryVars: [],
            isVirtual: false,
            templatePath: $this->resolveTemplatePath($templatePath)
        );

        $this->routes[] = $route;

        return $route;
    }

    /**
     * Déclare une route virtuelle (sans page WordPress).
     *
     * Usage :
     *   Route::virtual('profil/{slug}/edit/{tab}', '/public/profil-edit.php');
     *
     * Fonctionnement :
     *   - Analyse l’URI pour extraire le nom principal, les placeholders
     *   - Compile les contraintes (ex: ['id' => 'numeric'])
     *   - Génère la règle de réécriture WordPress
     *   - Permet d’associer un template statique OU un callback
     *
     * @param string $uri URI déclarative : segments fixes + {placeholders}
     * @param string $templatePath Chemin template si route statique, résolu thème-first
     *                             (voir {@see resolveTemplatePath()}) ; sinon callback utilisé
     * @param callable|null $callback Fonction appelée pour déterminer dynamiquement le template
     * @param array $constraints Contraintes par placeholder (regex simplifiée ou listes de valeurs)
     */
    public function virtual(string $uri, string $templatePath = '', ?callable $callback = null, array $constraints = []): BaseRoute
    {
        [$name, $queryVars, $patterns] = $this->compileUri($uri, $constraints);

        $route = new BaseRoute(
            name: $name,
            queryVars: $queryVars,
            isVirtual: true,
            templatePath: '' === $templatePath ? '' : $this->resolveTemplatePath($templatePath),
            callback: $callback,
            validQueryVarValues: $patterns
        );

        $this->routes[] = $route;

        return $route;
    }

    /* ============================================================
     *            RÉÉCRITURE DES URLS (rewrite rules)
     * ============================================================*/

    /**
     * Ajoute les règles rewrite WordPress pour CHAQUE route virtuelle.
     *
     * Les routes basées sur des pages WP ne doivent PAS générer de rewrite.
     *
     * La règle rewrite convertit :
     *   /profil/john/edit
     * en :
     *   index.php?pagename=profil&user_slug=john&tab=edit
     */
    public function addRules(): void
    {
        foreach ($this->routes as $route) {
            if (!$route->isVirtual()) {
                continue;
                // Pas de rewrite
            }

            if ('' === $route->getName()) {
                continue;
                // Empêche de casser la home
            }

            add_rewrite_rule($route->getPattern(), $route->getQuery(), 'top');
        }
    }

    /**
     * Ajoute toutes les query vars déclarées dans les routes virtuelles.
     *
     * WordPress ignore toute query var non déclarée → indispensable.
     */
    public function addQueryVars(array $vars): array
    {
        foreach ($this->routes as $route) {
            foreach ($route->getQueryVars() as $qv) {
                if ($qv) {
                    $vars[] = $qv;
                }
            }
        }

        return $vars;
    }

    /* ============================================================
     *              TEMPLATE LOADER : coeur du router
     * ============================================================*/

    /**
     * Sélectionne le template final à inclure pour la requête.
     *
     * Priorité :
     *   1) Route virtuelle avec callback valide
     *   2) Route virtuelle avec template statique
     *   3) Route "page WordPress" → remplace template du thème
     *   4) Sinon → WordPress garde son template par défaut
     *
     * C'est le cœur du routeur : ce filtre décide quel fichier PHP sera réellement chargé.
     */
    public function templateInclude(string $template): string
    {
        global $wp_query;
        $vars = $wp_query->query_vars;
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            $templatePath = $route->getTemplatePath();

            if ($route->isVirtual()) {
                if ('' === $route->getName()) {
                    // Route globale (home)
                    if ($route->hasCallback()) {
                        $request = $this->buildRequestPayload($route, $vars);
                        $out = $this->invokeCallback($route->getCallback(), $request);
                        if ($out) {
                            return $out;
                        }
                    }

                    continue;
                }

                // Vérifier si l'URL courant correspond à cette route
                if (!$this->matchesRoute($route, $currentUrl)) {
                    continue;
                }

                // Callback prioritaire
                if ($route->hasCallback()) {
                    $request = $this->buildRequestPayload($route, $vars);
                    if ($this->satisfiesConstraints($route, $request['named'])) {
                        $out = $this->invokeCallback($route->getCallback(), $request);
                        if ($out) {
                            return $out;
                        }

                        if ('' === $route->getTemplatePath()) {
                            return $this->resolveTemplatePath('public/blank');
                        }
                    }
                }

                // Template statique
                if ('' !== $templatePath) {
                    $request = $this->buildRequestPayload($route, $vars);
                    if ($this->satisfiesConstraints($route, $request['named'])) {
                        return $this->resolveTemplatePath($templatePath);
                    }
                }

                continue;
            }

            // Route basée sur une page WordPress
            if (is_page($route->getName())) {
                return $this->resolveTemplatePath($templatePath);
            }
        }

        return $template;
    }

    /* ============================================================
     *        PROTECTION CONTRE LES 404 ET REDIRECTIONS WP
     * ============================================================*/

    /**
     * Empêche WordPress de générer une fausse 404 sur une route virtuelle.
     *
     * WP a tendance à considérer une URL custom comme inexistante → 404.
     * Cette méthode marque explicitement la requête comme VALIDE.
     *
     * Important : le filtre `pre_handle_404` de WordPress court-circuite
     * WP::handle_404() uniquement si la valeur retournée est différente
     * de `false` (`false` est aussi sa valeur par défaut) :
     *
     *   if ( false !== apply_filters( 'pre_handle_404', false, $wp_query ) ) {
     *       return;
     *   }
     *
     * Retourner `false` ici équivaut donc à ne pas filtrer du tout : WP
     * poursuit sa détection de 404 et envoie quand même le header HTTP
     * 404, même si le contenu rendu (via templateInclude()) est correct.
     * D'où `return true;` — la seule valeur qui empêche réellement WP de
     * traiter la requête comme un 404.
     */
    public function prevent404ForRoutes(?bool $preempt, \WP_Query $wp_query): ?bool
    {
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            if ($route->isVirtual() && $this->matchesRoute($route, $currentUrl)) {
                $wp_query->is_404 = false;
                $wp_query->is_page = true;
                $wp_query->is_singular = true;
                $wp_query->is_home = false;
                $wp_query->is_front_page = false;

                // Stocker le nom de la route pour le titre
                global $plugin_kernel_virtual_page_title;
                $plugin_kernel_virtual_page_title = ucfirst($route->getName());

                return true;
            }
        }

        return $preempt;
    }

    /**
     * Empêche WordPress de rediriger automatiquement nos routes virtuelles
     * vers une URL canonique (ex: /profil → /profil/).
     */
    public function disableCanonicalForRoutes($redirect, string $requested): false|string
    {
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            if ($route->isVirtual() && $this->matchesRoute($route, $currentUrl)) {
                return false;
            }
        }

        return $redirect;
    }

    /**
     * Annule la redirection canonique AVANT qu’elle ne s'exécute.
     *
     * WP redirige souvent des routes custom → home, /truc/, etc.
     * Cette méthode protège toutes les routes virtuelles.
     */
    public function earlyTemplateRedirectGuard(): void
    {
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            if ($route->isVirtual() && $this->matchesRoute($route, $currentUrl)) {
                remove_action('template_redirect', 'redirect_canonical');

                return;
            }
        }
    }

    /**
     * Exécute les guards de la route matchée (s'il y en a).
     *
     * Appelée via le hook template_redirect avec priorité 1 (après earlyTemplateRedirectGuard).
     */
    public function executeRouteGuards(): void
    {
        $route = $this->findMatchedRoute();

        if (null === $route) {
            return;
        }

        $guards = $route->getGuards();
        if (empty($guards)) {
            return;
        }

        RouteGuardRunner::run($guards);
    }

    /**
     * Trouve la route matchée pour la requête actuelle.
     */
    private function findMatchedRoute(): ?BaseRoute
    {
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            if ($this->matchesRoute($route, $currentUrl)) {
                return $route;
            }
        }

        return null;
    }

    /* ============================================================
     *                         HELPERS
     * ============================================================*/

    /**
     * Extrait l'URL relative depuis REQUEST_URI
     */
    private function getCurrentRequestPath(): string
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $homePath = trim((string) parse_url(home_url('/'), PHP_URL_PATH), '/');

        if ('' !== $homePath) {
            $normalized = preg_replace('#^/' . preg_quote($homePath, '#') . '/#', '/', $path, 1);
            if (null !== $normalized) {
                $path = $normalized;
            }
        }

        return trim($path, '/');
    }

    /**
     * Vérifie si une URL correspond à une route
     * Supporte les routes avec placeholders
     */
    private function matchesRoute(BaseRoute $route, string $currentUrl): bool
    {
        if ('' === $route->getName()) {
            return '' === $currentUrl;
        }

        $routeSegments = explode('/', $route->getName());
        $urlSegments = explode('/', $currentUrl);

        // Si la route a un nombre de segments fixe (sans placeholders)
        if (0 === count($route->getQueryVars())) {
            // Comparaison exacte des segments littéraux
            return implode('/', \array_slice($urlSegments, 0, count($routeSegments))) ===
                   implode('/', $routeSegments);
        }

        // Sinon, matching flexible avec placeholders
        return count($urlSegments) >= count($routeSegments) &&
               implode('/', \array_slice($urlSegments, 0, count($routeSegments))) ===
               implode('/', $routeSegments);
    }

    /**
     * Simule le pagename WordPress pour les routes virtuelles.
     *
     * Ex :
     *   URL : /profil/john
     *   pagename simulé = profil
     *
     * C'est ce mécanisme qui permet au routeur de "prendre la main"
     * avant que WordPress ne cherche une vraie page.
     */
    public function mapRequestToRoutes(array $queryVars): array
    {
        if (!empty($queryVars['pagename'])) {
            return $queryVars;
        }

        $currentUrl = $this->getCurrentRequestPath();
        $segments = array_filter(explode('/', $currentUrl), strlen(...));

        foreach ($this->routes as $route) {
            if (!$route->isVirtual()) {
                continue;
            }

            if ('' === $route->getName()) {
                continue;
            }

            // Essayer de matcher cette route
            if ($this->matchesRoute($route, $currentUrl)) {
                $queryVars['pagename'] = $route->getName();

                // Enrichir avec les paramètres d'URL
                $routeSegments = explode('/', $route->getName());
                $remainingSegments = \array_slice($segments, count($routeSegments));

                foreach ($route->getQueryVars() as $i => $qv) {
                    if (isset($remainingSegments[$i])) {
                        $queryVars[$qv] = $remainingSegments[$i];
                    }
                }

                break;
            }
        }

        return $queryVars;
    }

    /**
     * Interception anticipée des routes techniques (SSE, endpoints long-lived, API internes).
     *
     * Cette méthode permet de traiter certaines routes AVANT le cycle normal de WordPress
     * (WP_Query, template loader, canonical redirect, gestion des 404).
     *
     * Pourquoi c’est nécessaire :
     * ------------------------------------------------------------
     * - Les endpoints SSE (Server-Sent Events) ne sont PAS des vues.
     * - Ils n’ont pas vocation à charger un template WordPress, ni à
     *   passer par template_include().
     * - WordPress peut marquer ces requêtes comme 404 ou déclencher
     *   des redirections canoniques avant que le routeur ne prenne la main.
     *
     * En s’exécutant sur le hook `parse_request` avec priorité 0 :
     * ------------------------------------------------------------
     * - La route est détectée très tôt dans le cycle WP.
     * - Le moteur de routing garde le contrôle total.
     * - Le template est inclus manuellement.
     * - L’exécution WordPress est immédiatement interrompue (exit).
     *
     * Cas d’usage typiques :
     * ------------------------------------------------------------
     * - SSE (Server-Sent Events)
     * - Webhooks internes
     * - Endpoints techniques long-lived
     * - Futures API non REST dépendantes du thème
     *
     * Comportement :
     * ------------------------------------------------------------
     * 1) Compare l’URL courante avec les routes virtuelles déclarées
     * 2) Si une route correspond ET est identifiée comme technique
     *    (ex: préfixe "sse/"):
     *    - Résout le chemin du template
     *    - L’inclut manuellement
     *    - Stoppe totalement WordPress
     *
     * @param \WP $wp Instance WordPress courante (parse_request)
     */
    public function handleEarlyRoutes(\WP $wp): void
    {
        $currentUrl = $this->getCurrentRequestPath();

        foreach ($this->routes as $route) {
            if (!$route->isVirtual()) {
                continue;
            }

            if (!$this->matchesRoute($route, $currentUrl)) {
                continue;
            }

            /**
             * Identification des routes techniques.
             *
             * Convention actuelle :
             * - Toute route dont le nom commence par "sse"
             *   est considérée comme un endpoint technique.
             *
             * Cette logique pourra évoluer vers :
             * - un flag dédié dans BaseRoute
             * - une interface TechnicalRouteInterface
             * - une déclaration explicite Route::technical()
             */
            if (str_starts_with($route->getName(), 'sse')) {
                $template = $this->resolveTemplatePath($route->getTemplatePath());

                require $template;

                // Sortie immédiate : empêche WP_Query, templates, redirects, etc.
                exit;
            }
        }
    }

    /**
     * Résolution du chemin final vers un template, thème-first / plugin-fallback
     * (voir {@see TemplateLocator}) :
     *  1) thème actif (enfant puis parent) sous {@see $themeViewsDir}
     *  2) filtre {@see $locateTemplateFilter} si défini
     *  3) modules du plugin, puis templates racine du plugin
     *  4) si rien ne correspond, retourne le chemin normalisé tel quel (comportement historique)
     */
    private function resolveTemplatePath(string $template): string
    {
        $extraThemeCandidates = $this->themeCandidateResolver
            ? ($this->themeCandidateResolver)(TemplateLocator::normalize($template))
            : [];

        return TemplateLocator::locate(
            $template,
            $this->modulesTemplateDir,
            $this->baseTemplateDir,
            $this->themeViewsDir,
            $this->locateTemplateFilter,
            $extraThemeCandidates,
        ) ?? TemplateLocator::normalize($template);
    }

    /**
     * Analyse une URI déclarative et détecte :
     *  - Le nom de la route (1er segment littéral)
     *  - Les placeholders → query vars
     *  - Les contraintes associées
     *
     * Exemple :
     *   profil/{slug}/{tab}
     * → name = "profil"
     * → vars = [slug, tab]
     */
    private function compileUri(string $uri, array $constraints): array
    {
        $uri = trim($uri, '/');
        $segments = explode('/', $uri);
        $name = '';
        $vars = [];
        $patterns = [];
        $nameSegments = [];

        foreach ($segments as $seg) {
            if (preg_match('/^{(.+)}$/', $seg, $m)) {
                $raw = $m[1];
                $varName = preg_replace('/[^a-zA-Z0-9_]/', '_', $raw);
                $vars[] = $varName;
                if (isset($constraints[$raw])) {
                    $patterns[$varName] = \is_array($constraints[$raw])
                        ? $constraints[$raw]
                        : [$constraints[$raw]];
                }
            } else {
                $nameSegments[] = $seg;
            }
        }

        $name = implode('/', $nameSegments);

        return [$name, $vars, $patterns];
    }

    /**
     * Construction du payload passé aux callbacks :
     *   - params  : liste ordonnée
     *   - named   : map param → valeur
     *   - vars    : toutes les query vars WP
     *   - method  : GET/POST
     */
    private function buildRequestPayload(BaseRoute $route, array $vars): array
    {
        $vars = $this->enrichVarsFromRequestPath($route, $vars);

        foreach ($_GET as $k => $v) {
            if (!\array_key_exists($k, $vars)) {
                $vars[$k] = $v;
            }
        }

        $qvs = $route->getQueryVars();
        $params = [];
        $named = [];

        foreach ($qvs as $qv) {
            $val = $vars[$qv] ?? null;
            $params[] = $val;
            $named[$qv] = $val;
        }

        return [
            'method' => strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            'params' => $params,
            'named' => $named,
            'vars' => $vars,
        ];
    }

    /**
     * Exécute un callback utilisateur.
     * Compatibilité : accepte callback($req) ou callback().
     *
     * Détermine l'arité via Reflection plutôt que via un try/catch(\Throwable) :
     * un catch générique masquait toute exception levée par le callback lui-même
     * (ex: erreur de config, appel API échoué) derrière un faux ArgumentCountError.
     */
    private function invokeCallback(callable $callback, array $req)
    {
        $reflection = new \ReflectionFunction(\Closure::fromCallable($callback));

        return $reflection->getNumberOfParameters() > 0 ? $callback($req) : $callback();
    }

    /**
     * Remplit les query vars manquantes en relisant l'URL brute.
     *
     * Utile quand les rewrite rules ne sont pas encore flushées,
     * ou quand WordPress ne remonte pas correctement les segments.
     */
    private function enrichVarsFromRequestPath(BaseRoute $route, array $vars): array
    {
        if (!$route->isVirtual()) {
            return $vars;
        }

        $currentUrl = $this->getCurrentRequestPath();
        $segments = array_filter(explode('/', $currentUrl), strlen(...));
        $routeSegments = explode('/', $route->getName());

        $routeSegmentsCount = count($routeSegments);

        if (count($segments) < $routeSegmentsCount) {
            return $vars;
        }

        // Vérifier que les segments littéraux correspondent
        for ($i = 0; $i < $routeSegmentsCount; $i++) {
            if ($segments[$i] !== $routeSegments[$i]) {
                return $vars;
            }
        }

        // Remplir les placeholders avec les segments restants
        $remainingSegments = \array_slice($segments, count($routeSegments));
        foreach ($route->getQueryVars() as $i => $qv) {
            if (!isset($vars[$qv]) || '' === $vars[$qv]) {
                $vars[$qv] = $remainingSegments[$i] ?? null;
            }
        }

        return $vars;
    }

    /**
     * Vérifie que les paramètres extraits respectent bien les contraintes.
     *
     * Exemples :
     *   ['id' => 'numeric']
     *   ['section' => ['about','settings','agenda']]
     */
    private function satisfiesConstraints(BaseRoute $route, array $named): bool
    {
        $patterns = $route->getValidQueryVarValues();
        if (!$patterns) {
            return true;
        }

        foreach ($patterns as $var => $allowed) {
            $val = $named[$var] ?? null;
            if (null === $val || '' === $val) {
                return false;
            }

            if ($allowed === ['numeric'] && !preg_match('/^[0-9]+$/', (string) $val)) {
                return false;
            }

            if (
                $allowed !== ['numeric'] &&
                !\in_array((string) $val, array_map(strval(...), $allowed), true)
            ) {
                return false;
            }
        }

        return true;
    }

    public function customizeVirtualPageTitleParts(mixed $parts): array
    {
        if (!\is_array($parts)) {
            return [];
        }

        $resolved = $this->resolveCurrentRouteTitle();

        if ($resolved) {
            $parts['title'] = $resolved;
        }

        return $parts;
    }

    public function customizeVirtualPageTitleString(mixed $title): string
    {
        $resolved = $this->resolveCurrentRouteTitle();

        if ($resolved !== null && $resolved !== '') {
            return $resolved;
        }

        // Sécurité absolue : RankMath veut une string
        return \is_string($title) ? $title : '';
    }

    /**
     * Résout le titre logique de la route courante.
     * Retourne une string ou null si aucune route ne s’applique.
     */
    private function resolveCurrentRouteTitle(): ?string
    {
        $currentUrl = $this->getCurrentRequestPath();

        global $wp_query;
        $vars = $wp_query->query_vars ?? [];

        foreach ($this->routes as $route) {
            if (!$route->isVirtual()) {
                continue;
            }

            if (!$this->matchesRoute($route, $currentUrl)) {
                continue;
            }

            $request = $this->buildRequestPayload($route, $vars);

            // Callback dynamique prioritaire
            if ($route->hasTitleCallback()) {
                try {
                    $out = ($route->getTitleCallback())($request);
                    if (\is_string($out) && '' !== $out) {
                        return $out;
                    }
                } catch (\Throwable) {
                }
            }

            // Titre statique
            if ($route->getTitle()) {
                return $route->getTitle();
            }

            // Fallback
            if ('' !== $route->getName()) {
                return ucfirst(str_replace('/', ' – ', $route->getName()));
            }

            return null;
        }

        return null;
    }
}
