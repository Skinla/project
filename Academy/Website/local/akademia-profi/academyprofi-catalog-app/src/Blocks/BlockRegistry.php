<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

use AcademyProfi\CatalogApp\Bitrix\BitrixOAuthClient;
use AcademyProfi\CatalogApp\Logging\Logger;

final class BlockRegistry
{
    /**
     * @return BlockDefinition[]
     */
    public function all(): array
    {
        return [
            new SearchBlock(),
            new CatalogBlock(),
            new DetailBlock(),
        ];
    }

    public function registerAll(BitrixOAuthClient $oauth, BlockRuntimeContext $ctx, Logger $logger, bool $reset = false): array
    {
        $results = [];
        foreach ($this->all() as $block) {
            $payload = [
                'code' => $block->code(),
                'fields' => array_merge($block->fields($ctx), $reset ? ['RESET' => 'Y'] : []),
                'manifest' => $block->manifest($ctx),
            ];

            $logger->info('landing.repo.register start', ['code' => $block->code(), 'reset' => $reset]);
            $resp = $oauth->call('landing.repo.register', $payload);
            $results[$block->code()] = $resp['result'] ?? null;
            $logger->info('landing.repo.register ok', ['code' => $block->code()]);
        }

        return $results;
    }
}

