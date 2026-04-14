<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

interface BlockDefinition
{
    public function code(): string;

    /**
     * Returns fields for landing.repo.register ("fields" param).
     */
    public function fields(BlockRuntimeContext $ctx): array;

    /**
     * Returns manifest for landing.repo.register ("manifest" param).
     */
    public function manifest(BlockRuntimeContext $ctx): array;
}

