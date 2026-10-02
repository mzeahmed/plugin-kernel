<?php

declare(strict_types=1);

namespace PluginKernel\Contract;

interface NonceVerifierInterface
{
    public function verify(?string $field = null, ?string $action = null, bool $sendJsonResponse = true): bool;
}
