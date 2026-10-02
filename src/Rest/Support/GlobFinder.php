<?php

declare(strict_types=1);

namespace PluginKernel\Rest\Support;

/**
 * Recherche de fichiers via glob().
 *
 * Usage :
 *   $finder = new GlobFinder('/var/www/uploads/*.csv');
 *   $files  = $finder->find();
 *
 *   // Récursif (un niveau de sous-répertoires) :
 *   $files = (new GlobFinder('/var/www/uploads/ * /*.php', true))->find();
 */
class GlobFinder
{
    public function __construct(
        private readonly string $pattern,
        private readonly bool $recursive = false,
        private readonly ?string $extension = null,
        private readonly int $flags = 0,
    ) {
    }

    /**
     * @return string[] Chemins absolus des fichiers trouvés.
     */
    public function find(): array
    {
        $files = glob($this->pattern, $this->flags) ?: [];

        if ($this->recursive) {
            $dir = \dirname($this->pattern);
            $filename = basename($this->pattern);
            $files = array_merge($files, glob($dir . '/*/' . $filename) ?: []);
        }

        if (null !== $this->extension) {
            $ext = $this->extension;
            $files = array_values(array_filter(
                $files,
                static fn (string $f): bool => pathinfo($f, PATHINFO_EXTENSION) === $ext,
            ));
        }

        return $files;
    }
}
