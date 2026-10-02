<?php

declare(strict_types=1);

namespace PluginKernel\Routing;

/**
 * BaseRoute
 * ----------
 * Représente une route individuelle.
 *
 * Une route peut être :
 *  - **physique**  → liée à une page WordPress existante (slug en base)
 *  - **virtuelle** → URL personnalisée générée par des rewrite rules
 *
 * La route encapsule :
 *  - Son nom (slug principal)
 *  - Ses query vars (placeholders extraits de l’URI)
 *  - Un template statique ou un callback dynamique
 *  - D’éventuelles contraintes sur les query vars
 *  - Un pattern WordPress (regex)
 *  - Une query WordPress (mapping des paramètres)
 *
 * Le BaseRouter orchestre les routes, mais chaque BaseRoute
 * sait générer **son propre comportement de réécriture**.
 */
class BaseRoute
{
    /**
     * Callback dynamique renvoyant un template ou null
     */
    private $callback;

    /**
     * Pattern regex WordPress généré automatiquement
     */
    private string $pattern;

    /**
     * Query string WordPress générée (ex : index.php?pagename=profil&slug=$matches[1])
     */
    private string $query;

    /**
     * Titre explicite associé à la route.
     *
     * Utilisé principalement pour :
     *  - le <title> HTML des pages virtuelles
     *  - le SEO (meta title)
     *  - les breadcrumbs ou OpenGraph à terme
     *
     * Si défini, ce titre a priorité sur les fallbacks automatiques
     * (ex: dérivés du slug de la route).
     *
     * Exemple :
     *   Route::virtual('profil/{slug}', '...')
     *     ->title('Profil utilisateur');
     */
    private ?string $title = null;

    /**
     * Callback dynamique permettant de générer un titre à la volée.
     *
     * Le callback reçoit le payload de requête construit par le router :
     *   [
     *     'method' => 'GET',
     *     'params' => [...],
     *     'named'  => ['slug' => 'john', 'tab' => 'edit'],
     *     'vars'   => [...],
     *   ]
     *
     * Il doit retourner :
     *  - une string (titre final)
     *  - ou null pour laisser le routeur appliquer un fallback
     *
     * Priorité :
     *  - titleCallback() > title() > fallback automatique
     *
     * Exemple :
     *   ->titleCallback(fn($req) => 'Profil – ' . ucfirst($req['named']['slug']));
     */
    private $titleCallback;

    /**
     * Nom logique de la route (ex: 'yuna', 'profile', 'group.settings').
     * Enregistré dans RouteRegistry dès qu'il est défini.
     */
    private ?string $routeName = null;

    /**
     * Liste des classes guard implémentant RouteGuardInterface
     * à exécuter avant de servir cette route.
     *
     * @var array<class-string|callable>
     */
    private array $guards = [];

    /**
     * @param string $name Slug principal de la route
     * @param array $queryVars Liste des placeholders à mapper
     * @param bool $isVirtual Route virtuelle ? (false = page réelle WP)
     * @param string $templatePath Template statique éventuel
     * @param callable|null $callback Handler dynamique générant le template
     * @param array $validQueryVarValues Contraintes sur les query vars
     */
    public function __construct(
        private readonly string $name,
        private readonly array $queryVars = [],
        private readonly bool $isVirtual = false,
        private readonly string $templatePath = '',
        ?callable $callback = null,
        private readonly array $validQueryVarValues = []
    ) {
        $this->callback = $callback;

        // Génère automatiquement :
        //  - le regex WordPress
        //  - le mapping template → query
        $this->generatePatternAndQuery();
    }

    /* ============================================================
     *  GETTERS
     * ============================================================*/

    /** Slug principal (ex: "profil") */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Query vars définies (ex: ['slug','tab'])
     */
    public function getQueryVars(): array
    {
        return $this->queryVars;
    }

    /**
     * Chemin vers le template statique
     */
    public function getTemplatePath(): string
    {
        return $this->templatePath;
    }

    /**
     * Callback dynamique éventuel
     */
    public function getCallback(): ?callable
    {
        return $this->callback;
    }

    /**
     * Indique si un callback existe
     */
    public function hasCallback(): bool
    {
        return null !== $this->callback;
    }

    /**
     * Contraintes par query var (ex: ['id' => ['numeric']])
     */
    public function getValidQueryVarValues(): array
    {
        return $this->validQueryVarValues;
    }

    /**
     * Route virtuelle ? (= route personnalisée sans page WP)
     */
    public function isVirtual(): bool
    {
        return $this->isVirtual;
    }

    /**
     * Regex WordPress généré
     */
    public function getPattern(): string
    {
        return $this->pattern;
    }

    /**
     * Query string WordPress générée
     */
    public function getQuery(): string
    {
        return $this->query;
    }

    /**
     * Retourne le titre statique associé à la route, s’il existe.
     */
    public function getTitle(): ?string
    {
        return $this->title;
    }

    /**
     * Indique si la route possède un callback de titre dynamique.
     */
    public function hasTitleCallback(): bool
    {
        return null !== $this->titleCallback;
    }

    /**
     * Retourne le callback de titre dynamique, s’il existe.
     */
    public function getTitleCallback(): ?callable
    {
        return $this->titleCallback;
    }

    /* ============================================================
     *  SETTERS
     * ============================================================*/
    /**
     * Définit un titre statique pour la route.
     *
     * Ce titre sera utilisé par le routeur pour :
     *  - personnaliser le <title> HTML
     *  - éviter les titres génériques ("Page not found")
     *
     * Le titre statique est ignoré si un titleCallback() est défini.
     *
     * @param string $title Titre explicite de la route
     */
    public function title(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Définit un callback de génération dynamique du titre.
     *
     * Le callback est exécuté au moment du rendu,
     * avec accès aux paramètres de la requête.
     *
     * Utile pour :
     *  - titres dépendants d’un slug
     *  - sous-sections (tabs)
     *  - contextualisation SEO
     *
     * Le callback a priorité absolue sur le titre statique.
     *
     * @param callable $callback Fonction retournant un titre (string|null)
     */
    public function titleCallback(callable $callback): self
    {
        $this->titleCallback = $callback;

        return $this;
    }

    /**
     * Attribue un nom logique à la route et l'enregistre dans RouteRegistry.
     *
     * @param string $name Identifiant unique (ex: 'yuna', 'group.settings')
     */
    public function name(string $name): self
    {
        $this->routeName = $name;
        RouteRegistry::register($name, $this);

        return $this;
    }

    /**
     * Retourne le nom logique de la route, s'il a été défini.
     */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /**
     * Vérifie si le chemin donné correspond à cette route.
     * Utilisé par RouteRegistry / AppContext pour la détection de contexte.
     *
     * @param string $path Chemin URL sans slash de début/fin (ex: 'yuna', 'profil/john')
     */
    public function matchesPath(string $path): bool
    {
        if (!$this->isVirtual) {
            return \function_exists('is_page') && is_page($this->name);
        }

        if ('' === $this->name) {
            return '' === $path;
        }

        return (bool) preg_match('#' . $this->pattern . '#', $path);
    }

    /**
     * Ajoute un guard (classe implémentant RouteGuardInterface) à cette route.
     *
     * Les guards s'exécutent dans l'ordre d'ajout avant de servir la route.
     * Chaque guard peut rediriger ou lever une exception pour bloquer l'accès.
     *
     * Utilisé pour protéger une route avec des conditions d'accès personnalisées.
     *
     * @param callable|class-string $guardClass Classe ou callable implémentant RouteGuardInterface
     */
    public function guard(callable|string $guardClass): self
    {
        $this->guards[] = $guardClass;

        return $this;
    }

    /**
     * Retourne la liste des guards attachés à cette route.
     *
     * @return array<class-string|callable>
     */
    public function getGuards(): array
    {
        return $this->guards;
    }

    /* ============================================================
     *  GÉNÉRATION DU PATTERN ET DE LA QUERY
     * ============================================================*/

    /**
     * Génère automatiquement :
     *
     *  - $pattern :
     *      '^profil/([^/]+)/([^/]+)/?$'
     *
     *  - $query :
     *      'index.php?pagename=profil&slug=$matches[1]&tab=$matches[2]'
     *
     * Fonctionnement :
     *   - Pour les routes physiques : aucun rewrite (WordPress gère via is_page())
     *   - Pour les routes virtuelles : génère un vrai rewrite WordPress
     *
     * C’est la pierre angulaire du système :
     *   WordPress n’a pas de routeur → il ne comprend que des regex.
     */
    private function generatePatternAndQuery(): void
    {
        /** ------------------------------------------
         *  ROUTE PHYSIQUE (page dans WP admin)
         * ------------------------------------------*/
        if (!$this->isVirtual) {
            // Pas de rewrite → la route est matchée par WordPress nativement
            $this->pattern = '';
            $this->query = '';

            return;
        }

        /** ------------------------------------------
         *  ROUTE VIRTUELLE → génération des patterns
         * ------------------------------------------*/

        $segments = [];
        $queryParts = [];

        foreach ($this->queryVars as $index => $var) {
            // --- 1) Création du regex de segment
            if (isset($this->validQueryVarValues[$var])) {
                $values = $this->validQueryVarValues[$var];
                // Contrainte de type "numeric"
                $segments[] = $values === ['numeric']
                    ? '([0-9]+)'
                    : '(' . implode('|', $values) . ')';
            } else {
                // Segment libre
                $segments[] = '([^/]+)';
            }

            // --- 2) Mapping vers $matches[x]
            $queryParts[] = $var . '=$matches[' . ($index + 1) . ']';
        }

        /** ------------------------------------------
         *  PATTERN (regex WP)
         * ------------------------------------------*/
        $pattern = '^' . $this->name;
        if ($segments) {
            $pattern .= '/' . implode('/', $segments);
        }

        $pattern .= '/?$';

        /** ------------------------------------------
         *  QUERY MAPPING (interprétation template)
         * ------------------------------------------*/
        $query = 'index.php?pagename=' . $this->name;
        if ($queryParts) {
            $query .= '&' . implode('&', $queryParts);
        }

        $this->pattern = $pattern;
        $this->query = $query;
    }
}
