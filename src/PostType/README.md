# PluginKernel\PostType

Déclare des Custom Post Types et Taxonomies WordPress via de petites interfaces (pas d'attributs
PHP ici, contrairement à `PluginKernel\Rest`), scannées et enregistrées automatiquement.

Zéro dépendance à un plugin ou thème en particulier.

## Convention de dossiers

```
src/
  Demo/
    Infrastructure/
      PostType/
        EventPostType.php     # implémente PostTypeDefinitionInterface
        CategoryTaxonomy.php  # implémente TaxonomyDefinitionInterface
```

## Déclarer un Custom Post Type

```php
use PluginKernel\PostType\Interfaces\PostTypeDefinitionInterface;

final class EventPostType implements PostTypeDefinitionInterface
{
    public function getSlug(): string { return 'event'; }
    public function getArgs(): array { return ['public' => true, 'show_in_rest' => true]; }
    public function useGutenberg(): bool { return true; }
}
```

Interfaces optionnelles à ajouter selon les besoins :

- `HasMetaboxInterface` — hooks `add_meta_boxes`/`save_post`
- `HasPostMetaBehaviorInterface` — `register_post_meta()`
- `HasCustomColumnsInterface` — colonnes personnalisées dans la liste admin (`manage_{slug}_posts_columns`, etc.)

## Déclarer une taxonomie

```php
use PluginKernel\PostType\Interfaces\TaxonomyDefinitionInterface;

final class CategoryTaxonomy implements TaxonomyDefinitionInterface
{
    public function getSlug(): string { return 'event_category'; }
    public function getPostTypes(): array { return ['event']; }
    public function getArgs(): array { return ['hierarchical' => true]; }
}
```

Interface optionnelle : `HasTaxonomyBehaviorInterface` (personnalisation de `wp_terms_checklist()`).

## Démarrer les loaders

```php
use PluginKernel\PostType\PostTypeLoader;use PluginKernel\PostType\TaxonomyLoader;

(new PostTypeLoader())->load(__DIR__ . '/src', 'MyApp', $container);
(new TaxonomyLoader())->load(__DIR__ . '/src', 'MyApp', $container);
```

`$container` (optionnel) est un `Symfony\Component\DependencyInjection\ContainerInterface` utilisé
pour instancier chaque définition trouvée.

## Filtrer par module actif

Comme pour `PluginKernel\Rest`, un callback optionnel permet d'ignorer les CPT/taxonomies d'un
module désactivé :

```php
new PostTypeLoader(isModuleActive: fn (string $moduleName): bool => $moduleRegistry->isActive($moduleName));
```
