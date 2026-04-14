<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

final class BlockRuntimeContext
{
    public function __construct(
        public readonly string $sectionsCsv,
        public readonly string $assetsBaseUrl,
        public readonly string $assetsVersion,
        public readonly string $apiBaseUrl,
        public readonly string $productHashParam,
        public readonly int $defaultProductId,
        public readonly string $detailPagePath,
        public readonly string $ctaAnchor,
        public readonly string $ctaLabel,
    ) {
    }
}

