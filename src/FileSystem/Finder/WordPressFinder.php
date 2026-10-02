<?php

declare(strict_types=1);

namespace PluginKernel\FileSystem\Finder;

/**
 * Recherche de fichiers via WP_Filesystem::dirlist().
 */
final readonly class WordPressFinder implements FinderInterface
{
    public function __construct(
        private \WP_Filesystem_Base $filesystem,
        private string $directory,
        private array $extensions = [],
        private bool $recursive = false,
        private bool $includeHidden = false,
    ) {
    }

    /**
     * @return string[]
     */
    public function find(): array
    {
        $entries = $this->findWithMeta();

        if ([] !== $this->extensions) {
            $entries = array_filter(
                $entries,
                fn (array $entry): bool => \in_array(
                    strtolower(pathinfo($entry['name'], PATHINFO_EXTENSION)),
                    $this->extensions,
                    true,
                ),
            );
        }

        return array_column(array_values($entries), 'name');
    }

    /**
     * @return array<string, array{name: string, size: int, type: string, ...}>
     */
    public function findWithMeta(): array
    {
        return $this->filesystem->dirlist(
            path: $this->directory,
            include_hidden: $this->includeHidden,
            recursive: $this->recursive,
        ) ?: [];
    }
}
