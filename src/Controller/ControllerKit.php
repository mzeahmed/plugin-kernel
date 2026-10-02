<?php

declare(strict_types=1);

namespace PluginKernel\Controller;

use PluginKernel\DI\ContainerRegistry;
use PluginKernel\DI\RuntimeServiceRegistry;
use PluginKernel\Http\Response\AjaxResponse;
use PluginKernel\Module\ModuleConfigRegistry;
use PluginKernel\Contract\NonceVerifierInterface;
use PluginKernel\Http\Response\RestResponseFactory;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Trait ControllerKit
 *
 * Outils génériques pour les contrôleurs d'un plugin WordPress : service
 * locator, paramètres de requête, réponses AJAX/REST standardisées.
 *
 * Ne couvre pas le rendu de templates ni les guards applicatifs (auth +
 * rôles métier) — trop spécifiques à chaque application ; à composer dans le
 * trait de l'application consommatrice (voir `PluginKernel\Contract\NonceVerifierInterface`).
 */
trait ControllerKit
{
    protected function get(string $service): object
    {
        if (RuntimeServiceRegistry::has($service)) {
            return RuntimeServiceRegistry::get($service, $this->container());
        }

        return $this->container()->get($service);
    }

    protected function getParameter(string $key): mixed
    {
        $value = ModuleConfigRegistry::get($key);
        if (null !== $value) {
            return $value;
        }

        try {
            return $this->container()->getParameter($key);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function verifyNonce(string $field, string $action): bool
    {
        return $this->get(NonceVerifierInterface::class)->verify($field, $action);
    }

    /**
     * Récupère un paramètre de requête (POST ou GET).
     *
     * @param string $key Clé du paramètre
     * @param mixed $default Valeur par défaut si le paramètre n'est pas trouvé
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        if (isset($_POST[$key])) {
            return sanitize_text_field($_POST[$key]);
        }

        if (isset($_GET[$key])) {
            return sanitize_text_field($_GET[$key]);
        }

        return $default;
    }

    /**
     * Récupère un paramètre de requête (POST ou GET) et le convertit en entier.
     *
     * @param string $key Clé du paramètre
     * @param int $default Valeur par défaut si le paramètre n'est pas trouvé
     */
    protected function intParam(string $key, int $default = 0): int
    {
        return (int) $this->param($key, $default);
    }

    /**
     * Récupère un paramètre de requête (POST ou GET) et le convertit en booléen.
     *
     * @param string $key Clé du paramètre
     * @param bool $default Valeur par défaut si le paramètre n'est pas trouvé
     */
    protected function boolParam(string $key, bool $default = false): bool
    {
        $value = $this->param($key);

        return null !== $value ? \in_array($value, ['1', 'true', 'yes'], true) : $default;
    }

    /**
     * Retourne une réponse AJAX de succès.
     *
     * @param mixed $data Données à inclure dans la réponse
     * @param string|null $message Message de succès
     */
    protected function ajaxSuccess(array $data = [], ?string $message = null): AjaxResponse
    {
        return AjaxResponse::success($data, $message);
    }

    /**
     * Retourne une réponse AJAX d'erreur.
     *
     * @param string $message Message d'erreur
     * @param int $status Code HTTP de la réponse (défaut : 400)
     * @param mixed $data Données supplémentaires
     */
    protected function ajaxError(string $message, int $status = 400, array $data = []): AjaxResponse
    {
        return AjaxResponse::error($message, $status, $data);
    }

    protected function restSuccess(array $data = [], string $message = 'OK', array $headers = [], int $status = 200): \WP_REST_Response
    {
        return $this->rest()->success(
            data: $data,
            message: $message,
            status: $status,
            headers: $headers
        );
    }

    protected function restError(string $code, string $message = 'NOK', array $data = []): \WP_REST_Response
    {
        if (isset($data['status'])) {
            $status = $data['status'];
            unset($data['status']);
        } else {
            $status = 400;
        }

        return $this->rest()->error(
            code: $code,
            message: $message,
            status: $status,
            data: $data
        );
    }

    /**
     * Retourne le mode d'authentification utilisé pour la requête.
     */
    protected function getAuthMode(\WP_REST_Request $request): string
    {
        // Vérifier d'abord les headers JWT (mobile)
        $auth = $request->get_header('Authorization');
        if ($auth && str_starts_with($auth, 'Bearer ')) {
            return 'jwt';
        }

        // Fallback: X-Auth-Token pour mobile (Apache ne transmet pas toujours Authorization)
        $xAuthToken = $request->get_header('x-auth-token');
        if ($xAuthToken) {
            return 'jwt';
        }

        // Sinon, vérifier si connecté via cookie WordPress
        if (is_user_logged_in()) {
            return 'cookie';
        }

        return 'none';
    }

    private function rest(): RestResponseFactory
    {
        return $this->get(RestResponseFactory::class);
    }

    private function container(): ContainerInterface
    {
        return ContainerRegistry::get();
    }
}
