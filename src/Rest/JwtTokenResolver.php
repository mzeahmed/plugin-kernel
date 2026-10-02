<?php

declare(strict_types=1);

namespace PluginKernel\Rest;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PluginKernel\Rest\Contract\BearerTokenResolverInterface;

/**
 * Décodage/validation de JWT (firebase/php-jwt) et résolution d'un token
 * porteur en ID utilisateur — pour {@see RestAuthMiddleware}
 * et {@see HybridAuthenticator}.
 *
 * Ne fournit pas la génération de token (`createToken`) ni l'extraction depuis
 * les superglobales : ces deux préoccupations sont propres à l'application
 * consommatrice (nom des claims, durée de validité, secret...).
 */
class JwtTokenResolver implements BearerTokenResolverInterface
{
    /**
     * @param string $secret Clé de signature
     * @param string $algo Algorithme de signature (ex: 'HS256')
     * @param string $userIdClaim Nom du claim JWT contenant l'ID utilisateur
     * @param int $ttl Durée de validité d'un token généré par createToken(), en secondes
     * @param string $audience Valeur du claim `aud` — identifie les consommateurs
     *                         prévus du token (ex: 'my-app'), pas encore
     *                         vérifiée à la lecture (voir validateToken())
     */
    public function __construct(
        protected readonly string $secret,
        protected readonly string $algo = 'HS256',
        protected readonly string $userIdClaim = 'user_id',
        protected readonly int $ttl = 604800, // 7 jours
        protected readonly string $audience = 'plugin-kernel',
    ) {
    }

    /**
     * Génère un JWT signé contenant l'ID utilisateur.
     *
     * Porte, en plus du claim historique `$userIdClaim`, les claims
     * standards `sub`/`aud`/`nbf`/`jti` — contrat minimal pour qu'un
     * vérificateur tiers (ex. une API externe) puisse valider le token.
     * `jti` ne fait l'objet
     * d'aucune liste de révocation à ce stade — présent pour permettre
     * d'en ajouter une plus tard sans re-signer le contrat.
     */
    public function createToken(int $userId): string
    {
        $now = time();
        $payload = [
            'iss' => get_bloginfo('url'),
            'aud' => $this->audience,
            'sub' => (string) $userId,
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $this->ttl,
            'jti' => bin2hex(random_bytes(16)),
            $this->userIdClaim => $userId,
        ];

        return JWT::encode($payload, $this->secret, $this->algo);
    }

    /**
     * Valide un token : retourne [valid => bool, payload => array|null, error => string|null]
     */
    public function validateToken(string $token): array
    {
        try {
            $decoded = (array) JWT::decode($token, new Key($this->secret, $this->algo));

            return [
                'valid' => true,
                'payload' => $decoded,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'payload' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * {@inheritDoc}
     */
    public function resolveUserId(string $token): ?int
    {
        $decoded = $this->validateToken($token);
        if (false === $decoded['valid']) {
            return null;
        }

        $userId = $decoded['payload'][$this->userIdClaim] ?? null;

        return null !== $userId ? (int) $userId : null;
    }
}
