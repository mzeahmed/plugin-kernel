<?php

declare(strict_types=1);

namespace PluginKernel\FileSystem\Finder;

/**
 * Recherche récursive de fichiers via RecursiveDirectoryIterator.
 */
final readonly class RecursiveFinder implements FinderInterface
{
    public function __construct(
        private string $directory,
        private array $extensions = ['php'],
        private array $excludeDirectories = [],
    ) {
    }

    /**
     * @return string[]
     */
    public function find(): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS),
                fn (\SplFileInfo $current): bool => !$current->isDir()
                    || !\in_array($current->getFilename(), $this->excludeDirectories, true),
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && \in_array($file->getExtension(), $this->extensions, true)) {
                $files[] = $file->getRealPath();
            }
        }

        sort($files, SORT_STRING);

        return $files;
    }
}
