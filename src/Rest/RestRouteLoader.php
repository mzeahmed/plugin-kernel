<?php

declare(strict_types=1);

namespace PluginKernel\Rest;

use PluginKernel\Rest\Attribute\RestRoute;
use PluginKernel\FileSystem\Finder\GlobFinder;
use PluginKernel\Module\Interfaces\ModuleLoaderInterface;
use PluginKernel\Rest\Contract\HybridAuthenticatorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Scanne un dossier de contrôleurs, lit les attributs #[RestRoute] posés sur
 * les méthodes publiques, et enregistre les routes correspondantes via
 * register_rest_route() (hook rest_api_init).
 */
class RestRouteLoader implements ModuleLoaderInterface
{
    /**
     * Cache des routes publiques détectées via les attributs #[RestRoute(permission: 'public')].
     */
    private static array $publicRoutes = [];

    /**
     * @param string $defaultNamespace Namespace REST utilisé quand l'attribut ne
     *                                 précise pas namespace.
     * @param callable|null $isModuleActive Callback(string $moduleName): bool permettant
     *                                      d'ignorer les contrôleurs d'un module désactivé.
     *                                      Optionnel : sans lui, tous les contrôleurs trouvés
     *                                      sont chargés.
     * @param HybridAuthenticatorInterface|null $authenticator Fallback appelé pour la permission
     *                                                         'logged_in' quand is_user_logged_in() est faux
     *                                                         (ex : authentification par token porteur).
     * @param callable|null $onInstantiationError Callback(\Throwable $e): void appelé si
     *                                            l'instanciation réflexive d'un contrôleur échoue.
     */
    public function __construct(
        private readonly string $defaultNamespace,
        private readonly mixed $isModuleActive = null,
        private readonly ?HybridAuthenticatorInterface $authenticator = null,
        private readonly mixed $onInstantiationError = null,
    ) {
    }

    public function load(string $controllersPath, string $baseNamespace, ?ContainerInterface $container = null): mixed
    {
        $entries = [];
        $finder = new GlobFinder($controllersPath . '/*/Controller/Rest/*.php', true);

        $files = $finder->find();

        foreach ($files as $file) {
            require_once $file;

            if (\is_callable($this->isModuleActive)) {
                $moduleName = $this->extractModuleName($file);

                if (!$moduleName || !($this->isModuleActive)($moduleName)) {
                    continue;
                }
            }

            $class = $this->fileToClass($file, $controllersPath, $baseNamespace);

            if (!class_exists($class)) {
                continue;
            }

            $refClass = new \ReflectionClass($class);

            foreach ($refClass->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
                $attrs = $method->getAttributes(RestRoute::class, \ReflectionAttribute::IS_INSTANCEOF);
                if (!$attrs) {
                    continue;
                }

                foreach ($attrs as $a) {
                    /** @var RestRoute $attr */
                    $attr = $a->newInstance();

                    $namespace = $attr->namespace ?? $this->defaultNamespace;
                    $path = $attr->path ?? $this->autoPath($refClass, $method);
                    $methods = $this->normalizeMethods($attr->methods);
                    $permission = $this->resolvePermissionCallback($attr->permission, $container);

                    // Enregistre les routes publiques (utile pour un éventuel service de garde périmètre)
                    if ('public' === $attr->permission || null === $attr->permission) {
                        self::$publicRoutes[] = "/wp-json/{$namespace}/{$path}";
                    }

                    // Lazy instantiation: le contrôleur n'est créé que si la route est appelée
                    $methodName = $method->getName();
                    $lazyCallback = function () use ($class, $methodName, $container): mixed {
                        $instance = $this->instantiate($class, $container);

                        return $instance->$methodName(...\func_get_args());
                    };

                    if (\is_array($methods)) {
                        foreach ($methods as $m) {
                            $routeArgs = $attr->args;
                            $routeArgs['methods'] = $m;
                            $routeArgs['callback'] = $lazyCallback;
                            $routeArgs['permission_callback'] = $permission;
                            $entries[] = [$namespace, $path, $routeArgs];
                        }
                    } else {
                        $routeArgs = $attr->args;
                        $routeArgs['methods'] = $methods;
                        $routeArgs['callback'] = $lazyCallback;
                        $routeArgs['permission_callback'] = $permission;
                        $entries[] = [$namespace, $path, $routeArgs];
                    }
                }
            }
        }

        if ($entries) {
            add_action('rest_api_init', static function () use ($entries) {
                foreach ($entries as [$ns, $path, $args]) {
                    register_rest_route($ns, $path, $args);
                }
            });
        }

        return true;
    }

    public function fileToClass(string $file, string $basePath, string $baseNamespace): string
    {
        $relative = substr($file, \strlen($basePath));
        $relative = ltrim($relative, DIRECTORY_SEPARATOR);
        $relative = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $relative);

        return $baseNamespace . '\\' . $relative;
    }

    /**
     * Extrait le nom du module depuis le chemin du fichier.
     *
     * Exemples :
     * - /Modules/Event/Controller/Rest/File.php → 'Event'
     * - /Modules/Event/Controller/Rest/Webhook/File.php → 'Event'
     */
    private function extractModuleName(string $file): ?string
    {
        $parts = explode(DIRECTORY_SEPARATOR, $file);
        $controllerIndex = array_search('Controller', $parts, true);

        if (false === $controllerIndex || 0 === $controllerIndex) {
            return null;
        }

        return $parts[$controllerIndex - 1];
    }

    private function instantiate(string $class, ?ContainerInterface $container = null): object
    {
        if ($container && $container->has($class)) {
            return $container->get($class);
        }

        $ref = new \ReflectionClass($class);
        $ctor = $ref->getConstructor();

        if (null === $ctor || 0 === $ctor->getNumberOfParameters()) {
            try {
                return $ref->newInstance();
            } catch (\ReflectionException $e) {
                if (\is_callable($this->onInstantiationError)) {
                    ($this->onInstantiationError)($e);
                }
            }
        }

        $args = [];
        foreach ($ctor->getParameters() as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $depClass = $type->getName();
                if ($container && $container->has($depClass)) {
                    $args[] = $container->get($depClass);
                    continue;
                }

                if (class_exists($depClass)) {
                    $args[] = $this->instantiate($depClass, $container);
                    continue;
                }
            }

            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            throw new \RuntimeException('Unable to autowire constructor parameter $' . $param->getName() . " for class $class");
        }

        return $ref->newInstanceArgs($args);
    }

    private function autoPath(\ReflectionClass $ref, \ReflectionMethod $method): string
    {
        $module = strtolower(basename(\dirname($ref->getFileName(), 3)));
        $name = strtolower((string) preg_replace('/([a-z])([A-Z])/', '$1-$2', $method->getName()));

        return "$module/$name";
    }

    private function normalizeMethods(string|array $methods): string|array
    {
        if (\is_string($methods)) {
            return strtoupper($methods);
        }

        return array_map(static fn ($m) => strtoupper((string) $m), $methods);
    }

    private function resolvePermissionCallback(string|array|null $permission, ?ContainerInterface $container): callable|string
    {
        if ('public' === $permission || null === $permission) {
            return '__return_true';
        }

        if ('logged_in' === $permission) {
            $authenticator = $this->authenticator;

            return static function (\WP_REST_Request $request) use ($authenticator) {
                if (is_user_logged_in()) {
                    return true;
                }

                if ($authenticator instanceof HybridAuthenticatorInterface) {
                    $result = $authenticator->authenticate($request);
                    if (true === $result) {
                        return true;
                    }

                    if ($result instanceof \WP_Error) {
                        return $result;
                    }
                }

                return new \WP_Error('auth_required', __('Authentification requise.'), ['status' => 400]);
            };
        }

        if ('administrator' === $permission) {
            return static function () {
                if (current_user_can('manage_options')) {
                    return true;
                }

                return new \WP_Error('forbidden', __('Action réservée à l\'administration.'), ['status' => 403]);
            };
        }

        // Custom callable or [Class::class, 'method']
        if (\is_array($permission) && isset($permission[0], $permission[1]) && is_string($permission[0])) {
            [$class, $method] = $permission;

            $instance = match (true) {
                $container && $container->has($class) => $container->get($class),
                class_exists($class) => new $class(),
                default => null,
            };

            if (null !== $instance) {
                return [$instance, $method];
            }
        }

        if (\is_callable($permission)) {
            return $permission;
        }

        // Fallback: logged_in
        return is_user_logged_in(...);
    }

    /**
     * Retourne la liste des routes publiques détectées via les attributs #[RestRoute].
     *
     * @return array Liste des chemins complets des routes publiques.
     *               Exemple : ['/wp-json/my-plugin/v1/auth/login', ...]
     */
    public static function getPublicRoutes(): array
    {
        return self::$publicRoutes;
    }
}
