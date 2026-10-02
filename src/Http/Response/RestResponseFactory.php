<?php

declare(strict_types=1);

namespace PluginKernel\Http\Response;

/**
 * Factory pour créer des réponses REST API WordPress standardisées.
 */
final class RestResponseFactory
{
    /**
     * Crée une réponse de succès.
     *
     * @param array $data Données à retourner
     * @param string $message Message de succès
     * @param int $status Code HTTP (200 par défaut)
     * @param array $headers Headers HTTP additionnels
     */
    public function success(array $data = [], string $message = 'OK', int $status = 200, array $headers = []): \WP_REST_Response
    {
        return $this->build(
            success: true,
            data: $data,
            message: $message,
            code: 'success',
            status: $status,
            headers: $headers
        );
    }

    /**
     * Crée une réponse d'erreur.
     *
     * @param string $code Code d'erreur
     * @param string $message Message d'erreur
     * @param int $status Code HTTP (400 par défaut)
     * @param array $data Données supplémentaires
     * @param array $headers Headers HTTP additionnels
     */
    public function error(
        string $code,
        string $message = 'NOK',
        int $status = 400,
        array $data = [],
        array $headers = []
    ): \WP_REST_Response {
        return $this->build(
            success: false,
            data: $data,
            message: $message,
            code: $code,
            status: $status,
            headers: $headers
        );
    }

    /**
     * Construit une réponse REST.
     *
     * @param bool $success Indicateur de succès
     * @param array $data Données de la réponse
     * @param string $message Message
     * @param string $code Code de réponse
     * @param int $status Code HTTP
     * @param array $headers Headers HTTP
     */
    private function build(bool $success, array $data, string $message, string $code, int $status, array $headers): \WP_REST_Response
    {
        $data['message'] = $message;

        return new \WP_REST_Response([
            'code' => $code,
            'success' => $success,
            'data' => $data,
        ], $status, $headers);
    }
}
