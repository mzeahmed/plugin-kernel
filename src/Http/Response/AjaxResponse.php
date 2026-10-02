<?php

declare(strict_types=1);

namespace PluginKernel\Http\Response;

/**
 * Classe standardisée pour les réponses AJAX.
 */
class AjaxResponse
{
    public function __construct(
        private readonly bool $success,
        private readonly array $data = [],
        private readonly ?string $message = null,
        private readonly int $status = 200
    ) {
    }

    /**
     * Utilise `new static()` (liaison statique tardive) et non `new self()` :
     * une application consommatrice qui sous-classe AjaxResponse (façade FQCN)
     * doit récupérer une instance de SA propre classe, pas de celle-ci — sinon
     * un contrôleur déclarant `: SubClassAjaxResponse` en retour casserait avec
     * un TypeError (une instance de la classe de base ne satisfait jamais un
     * type de retour sur une classe enfant).
     */
    public static function success(array $data = [], ?string $message = null): static
    {
        return new static(true, $data, $message, 200);
    }

    public static function error(string $message, int $status = 400, array $data = []): static
    {
        return new static(false, $data, $message, $status);
    }

    /**
     * Envoie la réponse JSON et arrête l'exécution.
     */
    public function send(): void
    {
        $payload = $this->data;

        if (null !== $this->message) {
            $payload = array_merge(['message' => $this->message], $payload);
        }

        if ($this->success) {
            wp_send_json_success($payload, $this->status);
        }

        wp_send_json_error($payload, $this->status);
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getStatus(): int
    {
        return $this->status;
    }
}
