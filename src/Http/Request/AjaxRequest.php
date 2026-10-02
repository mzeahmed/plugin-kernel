<?php

declare(strict_types=1);

namespace PluginKernel\Http\Request;

class AjaxRequest
{
    public function __construct(
        private readonly array $data
    ) {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getString(string $key, string $default = ''): string
    {
        return sanitize_text_field($this->get($key, $default));
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOLEAN);
    }

    public function getArray(string $key): array
    {
        return (array) $this->get($key, []);
    }

    public function getAll(): array
    {
        return $this->data;
    }
}
