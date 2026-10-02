# Troubleshooting

*Dernière mise à jour : 2026-07-30*

Ce guide recense les problèmes réellement rencontrés en utilisant/étendant ce package, classés
par symptôme. Pour les pièges de conception (contravariance, factories statiques...), voir aussi
la section « Pièges connus » du `README.md`.

## Sommaire

- [Installation / Composer](#installation--composer)
- [Container DI](#container-di)
- [Modules](#modules)
- [Routes AJAX](#routes-ajax)
- [Routes REST](#routes-rest)
- [Routes web](#routes-web)
- [Jobs asynchrones](#jobs-asynchrones)
- [Assets](#assets)
- [Pièges PHP à connaître](#pièges-php-à-connaître)
- [Outils de diagnostic](#outils-de-diagnostic)

## Installation / Composer

### `Class "PluginKernel\X" not found` après un `composer install`/`update`

**Cause la plus fréquente** : le dépôt `path` n'est pas symlinké (`"options": {"symlink":
true}` manquant), ou le cache d'autoload n'a pas été régénéré.

```bash
composer dump-autoload
```

Vérifiez aussi que le package est bien résolu :

```bash
composer show mzeahmed/plugin-kernel
php -r "require 'vendor/autoload.php'; var_dump(class_exists('PluginKernel\\Kernel'));"
```

### Le renommage du package casse tous les projets qui le consomment

Si vous changez le champ `"name"` dans le `composer.json` du package (ou le vendor GitHub), tout
projet qui déclare encore l'ancien nom en `require`/`require-dev` échoue immédiatement — le dépôt
`path`/`vcs` n'expose plus que le nouveau nom, l'ancien devient introuvable.

**À faire dans chaque projet consommateur, dans cet ordre** :

1. Mettre à jour le `require`/`require-dev` avec le nouveau nom.
2. `composer update <ancien-ou-nouveau-nom>` (scope sur le package pour ne pas toucher au reste).
3. Vérifier qu'aucun symlink orphelin ne subsiste dans `vendor/<ancien-vendor>/` (Composer ne le
   nettoie pas toujours automatiquement selon l'état du lock file) :
   ```bash
   ls vendor/<ancien-vendor>/
   ```
4. Si vous avez plusieurs copies du projet (ex: un dossier de dev synchronisé vers un dossier de
   build/déploiement), répéter l'opération dans **chacune** — elles ont chacune leur propre
   `vendor/`, un `composer.json` synchronisé ne suffit pas à lui seul.

### `Your requirements could not be resolved` avec un dépôt `path`

Vérifiez que l'`url` du dépôt `path` est correcte **relativement au `composer.json` qui le
déclare** (pas relativement à votre répertoire de travail courant) :

```bash
realpath --relative-to=<dossier-du-consommateur> <dossier-du-package>
```

## Container DI

### `Unable to autowire constructor parameter $x for class Y`

Le container Symfony compilé (`services.yml`) ne connaît pas cette classe/interface. Deux
options :
- L'ajouter (ou un alias interface → implémentation) dans `services.yml`.
- Si c'est une classe concrète simple, `ModuleContainer` tente un autowiring par réflexion en
  secours — mais uniquement pour des paramètres eux-mêmes résolvables (récursivement) ; un
  paramètre scalaire ou une interface sans alias reste en échec.

### Une modification de `services.yml` ne semble jamais prise en compte

Le container est compilé et **mis en cache** sur disque (`cachePath` du Kernel). En mode
`isDev: false`, il n'est jamais recompilé tant que le fichier de cache existe. En mode `isDev:
true`, il n'est recompilé que si `services.yml` (ou un fichier dans `watchedDirectories`) a une
date de modification postérieure au cache.

**Fix** : supprimer le fichier de cache pour forcer une recompilation immédiate.

```bash
rm <cachePath>
```

Si le rebuild automatique en dev ne se déclenche pas comme attendu, vérifiez que le dossier
contenant le fichier modifié fait bien partie de `watchedDirectories` passé au `Kernel`.

### `ServiceNotFoundException: ... has been removed or inlined when the container was compiled`

Un service enregistré dynamiquement (`RuntimeServiceRegistry::set()`) n'est pas trouvé au moment
où une factory tente de le résoudre. Vérifiez que la factory reçoit bien l'instance complète de
`ModuleContainer` (qui consulte `RuntimeServiceRegistry` avant de déléguer au container Symfony
compilé) et non le container Symfony brut — un service dynamique n'existe que dans
`RuntimeServiceRegistry`, jamais dans le container compilé lui-même.

### `ContainerRegistry::get()` lève une `RuntimeException`

`ContainerRegistry::set()` n'a pas encore été appelé — cela arrive si du code s'exécute avant
`Kernel::boot()` (ou avant `initContainer()` en interne). Vérifiez l'ordre de vos hooks
WordPress : tout ce qui dépend du container doit s'exécuter après le boot du Kernel (typiquement
sur `plugins_loaded` avec une priorité suffisamment basse, ou plus tard).

## Modules

### Un module ne se charge pas / `Module.php` semble ignoré

Checklist :
- Le fichier s'appelle **exactement** `Module.php` (sensible à la casse, pas `module.php`).
- Le chemin correspond bien à `<modulesPath>/<NomDuModule>/Module.php` (un seul niveau sous
  `modulesPath`).
- Le module n'est pas désactivé via son `config.php` (`module.enabled`, `<slug>.enabled`, ou
  `enabled` à `false`) — ni désactivé indirectement par `<slug>.disabled_modules` d'un autre
  module actif.
- La classe est bien autoloadable (namespace PSR-4 cohérent avec `modulesNamespace` +
  arborescence des dossiers).

### `Module::register()`/`registerAjax()`/`registerCron()` semble ne jamais être appelée

Ces appels sont **volontairement encapsulés dans un `try/catch`** (fail-soft) : une exception
levée à l'intérieur est silencieusement avalée pour ne pas bloquer le chargement des autres
modules. Si votre méthode plante, vous ne verrez rien dans les logs par défaut — instrumentez
temporairement (ex: `error_log()` en tout début de méthode) pour confirmer qu'elle est bien
appelée, puis pour capturer l'exception réelle.

## Routes AJAX

### La route répond toujours par une erreur générique / rien ne se passe

- Vérifiez `$_REQUEST['route']` correspond exactement au `path` déclaré (ou auto-généré :
  `/{module}/{méthode-en-kebab-case}` si `path` est `null` sur `#[AjaxRoute]`).
- Une route `protected: true` (par défaut) exige `is_user_logged_in()` **et** un nonce valide
  (`NonceVerifierInterface`) — vérifiez que le nonce envoyé correspond à celui attendu par votre
  implémentation de `NonceVerifierInterface`.
- Un middleware additionnel (`middleware: [...]`) qui n'est pas enregistré dans le container (ou
  n'implémente pas `MiddlewareInterface`) fait échouer la requête avec un 500 générique.

## Routes REST

### `permission: 'logged_in'` refuse un utilisateur authentifié par token porteur (mobile/SPA)

`RestRouteLoader` ne tente une authentification par token que si un `HybridAuthenticator` a été
injecté au `Kernel` (paramètres `bearerResolver`/`authorize`). Sans cet authenticator, seule
l'authentification par cookie WordPress fonctionne pour `'logged_in'`.

### Le token porteur n'est jamais extrait

`BearerToken::extract()` lit le header `Authorization: Bearer …`, avec un fallback sur
`X-Auth-Token`. Si votre serveur (Apache notamment) filtre le header `Authorization` avant qu'il
n'atteigne PHP, utilisez `X-Auth-Token` côté client, ou configurez votre serveur pour transmettre
`Authorization` (`CGIPassAuth On` / règle `.htaccess` équivalente).

### Une route REST publique (`permission: 'public'`) apparaît quand même protégée

Vérifiez qu'aucun autre filtre WordPress (plugin de sécurité, `rest_authentication_errors`
d'un autre acteur) n'intercepte la requête avant que la permission de la route ne soit évaluée.

## Routes web

### Une route déclarée via `HasWebRoutes::registerRoutes()` n'apparaît jamais

`WebRouteLoader` itère les modules déjà chargés (`ModuleLoader::getModules()`) — il doit donc
s'exécuter **après** `ModuleLoader::load()`. Si vous orchestrez les loaders vous-même (hors du
`Kernel` fourni), respectez cet ordre.

### `#[WebRoute]` (attribut) n'a aucun effet

Ce package **ne fournit pas** de scanner automatique pour `#[WebRoute]` — contrairement à
`#[AjaxRoute]`/`#[RestRoute]`. L'attribut et `Route::queueAttributeRoute()` existent comme point
d'API pour un scanner que vous écrivez vous-même. Utilisez `HasWebRoutes` (par module) ou
`Route::page()`/`Route::virtual()`/`Route::get()` directement pour un enregistrement fonctionnel
immédiat.

### Rewrite rules qui ne se mettent pas à jour après l'ajout d'une route

Après un déploiement qui ajoute/modifie des routes, forcez `flush_rewrite_rules()` une fois
(ex: sur activation du plugin, ou via une option à vérifier au boot).

### `Uncaught Error: Too few arguments to function ... Route::{closure}()`

Ce message est **trompeur** : avant la version qui a introduit la détection d'arité par
Reflection, `BaseRouter::invokeCallback()` retentait silencieusement le callback sans argument
dès qu'une exception quelconque était levée pendant `$callback($req)` — masquant l'erreur réelle
(config manquante, appel HTTP en échec, service non résolu...) derrière ce faux
`ArgumentCountError`. Si vous voyez cette erreur :

- Mettez à jour vers une version du package où `invokeCallback()` détermine l'arité via
  `ReflectionFunction` plutôt que via `try/catch(\Throwable)`.
- En attendant, ajoutez un `try/catch` explicite **dans votre propre callback** pour voir
  l'exception d'origine (elle n'apparaît dans aucun log tant que le catch générique du router
  l'intercepte en premier).

### Une route virtuelle affiche le bon contenu mais répond en HTTP 404

Vérifiable via les headers de réponse (`curl -I`), pas visuellement : le rendu est correct
(`templateInclude()` sert bien le bon template) mais le serveur envoie quand même un statut 404.

Avant la version qui corrige `prevent404ForRoutes()`, la méthode retournait `false` quand une
route matchait l'URL courante. Or WordPress (`WP::handle_404()`) ne court-circuite sa propre
détection de 404 que si le filtre `pre_handle_404` renvoie une valeur **différente** de `false` —
qui est aussi sa valeur par défaut :

```php
if ( false !== apply_filters( 'pre_handle_404', false, $wp_query ) ) {
    return; // jamais atteint si notre filtre renvoie false
}
```

Autrement dit, `return false;` équivalait à ne pas filtrer du tout : WordPress poursuivait sa
détection et envoyait le header 404 malgré `$wp_query->is_404 = false` posé juste avant.

- Mettez à jour vers une version du package où `prevent404ForRoutes()` retourne `true` (pas
  `false`) quand une route matche.
- Symptôme fréquent en aval : un rechargement complet de page sur une route virtuelle fonctionne
  visuellement mais casse le SEO, le monitoring d'uptime, ou tout code qui vérifie le statut HTTP
  plutôt que le contenu.

## Jobs asynchrones

### Un handler `#[AsyncHandler]` n'est jamais découvert

- Le fichier doit être dans un dossier nommé **exactement** `Async/` sous un module
  (`<modulesPath>/<Module>/Async/*Handler.php`), et se terminer par `Handler.php`.
- La classe doit implémenter `JobHandlerInterface`.
- L'attribut `#[AsyncHandler('mon_job_type')]` doit être présent sur la classe.

### Le handler est découvert mais jamais exécuté

`HandlerResolver::resolve()` est **fail-soft** : si une dépendance du constructeur du handler
n'est pas résolvable (ni via le container, ni par réflexion récursive), la méthode renvoie
`null` au lieu de lever une exception — le handler est silencieusement ignoré. Branchez le
callback `onResolutionFailure` de `AsyncKernel::discoverAndRegisterHandlers()` pour logguer ces
échecs plutôt que de les découvrir en production.

### Un job dispatché n'est jamais traité

- Vérifiez que le cron applicatif est bien planifié : `wp_next_scheduled('<votre_hook_cron>')`
  doit renvoyer un timestamp.
- Vérifiez que `JobQueueRepositoryInterface::getPending()` renvoie bien des objets exposant au
  minimum `id`, `job_type`, `payload`, `attempts` — un format inattendu fait échouer
  silencieusement `JobWorker::processJob()`.
- Un job répété qui échoue systématiquement finit par être marqué `failed` après épuisement des
  tentatives (`MAX_ATTEMPTS`) — vérifiez le statut en base plutôt que de supposer une boucle
  infinie de retries.

## Assets

### Les assets d'un module ne sont pas chargés

`ModuleAssetLoader::register()` a besoin de **tous** ses paramètres (`pluginPath`, `pluginUrl`,
`pluginVersion`, `pluginFile`) pour résoudre les chemins vers
`resources/build/modules/{module}/` — un paramètre manquant ou vide fait échouer silencieusement
la détection (`is_dir()` sur un chemin invalide).

Vérifiez aussi le gate conditionnel `config.php['assets']['front'|'admin']` : s'il s'agit d'un
callable qui renvoie `false`, l'enqueue est délibérément sauté pour ce contexte.

## Pièges PHP à connaître

Ces points ne sont pas des bugs du package mais des contraintes du langage à connaître si vous
l'étendez (voir aussi le `README.md`) :

- **Une propriété typée ne peut pas être `callable`** (y compris une propriété promue de
  constructeur) — utilisez `?\Closure` à la place.
- **Un callable nullable ne s'invoque pas via l'opérateur nullsafe** — `$cb?->($arg)` est une
  erreur de syntaxe ; utilisez `if (null !== $cb) { $cb($arg); }`.
- **Les méthodes statiques exigent une signature strictement compatible** entre parent et enfant,
  même sans interface — contrairement aux constructeurs, qui en sont exemptés.
- **Une factory statique (`Foo::success()`) doit utiliser `new static()`, jamais `new self()`** —
  sinon une sous-classe (façade applicative) avec un type de retour resserré plante avec un
  `TypeError`.
- **Un paramètre typé sur une classe interne du package ne doit pas être resserré sur une
  sous-classe applicative** dans une méthode qui implémente une interface du package — violation
  de contravariance PHP, fatal `Declaration must be compatible`.

## Outils de diagnostic

Pour inspecter l'état réel du container/Kernel en environnement WordPress (WP-CLI) :

```php
<?php
// probe.php — à exécuter via `wp eval-file probe.php`, puis supprimer
use PluginKernel\DI\ContainerRegistry;

$container = ContainerRegistry::get();
var_dump($container->has(\PluginKernel\Async\Services\JobWorker::class));
var_dump(get_class($container->get(\PluginKernel\Async\Services\JobWorker::class)));
```

```bash
wp eval-file probe.php
```

Pensez à toujours supprimer ce type de script de sondage après diagnostic — ne pas le laisser
traîner dans une arborescence versionnée ou accessible publiquement.
