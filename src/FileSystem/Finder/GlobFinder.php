<?php

declare(strict_types=1);

namespace PluginKernel\FileSystem\Finder;

/**
 * Recherche des fichiers à partir d'un motif glob.
 */
final readonly class GlobFinder implements FinderInterface
{
    public function __construct(
        private string $pattern,
        private bool $recursive = false,
        private ?string $extension = null,
        private int $flags = 0,
    ) {
    }

    /**
     * @return string[]
     */
    public function find(): array
    {
        $files = glob($this->pattern, $this->flags) ?: [];

        if ($this->recursive) {
            $files = array_merge($files, $this->findRecursively());
        }

        $files = array_values(array_unique(array_filter($files, is_file(...))));

        if (null !== $this->extension) {
            $files = array_values(array_filter(
                $files,
                fn (string $file): bool => pathinfo($file, PATHINFO_EXTENSION) === $this->extension,
            ));
        }

        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return string[]
     */
    private function findRecursively(): array
    {
        $directories = glob(\dirname($this->pattern), GLOB_ONLYDIR) ?: [];
        $filenamePattern = basename($this->pattern);
        $files = [];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY,
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && fnmatch($filenamePattern, $file->getFilename())) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }
}
