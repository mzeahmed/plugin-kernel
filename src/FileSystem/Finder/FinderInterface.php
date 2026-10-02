<?php

declare(strict_types=1);

namespace PluginKernel\FileSystem\Finder;

interface FinderInterface
{
    /**
     * @return string[]
     */
    public function find(): array;
}
