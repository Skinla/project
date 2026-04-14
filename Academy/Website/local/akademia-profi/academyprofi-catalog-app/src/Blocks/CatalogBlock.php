<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

final class CatalogBlock implements BlockDefinition
{
    public function code(): string
    {
        return 'academyprofi.catalog';
    }

    public function fields(BlockRuntimeContext $ctx): array
    {
        return [
            'NAME' => 'Каталог услуг',
            'DESCRIPTION' => 'Карточки услуг + пагинация (3 видно, 5 всего).',
            'SECTIONS' => $ctx->sectionsCsv,
            'PREVIEW' => $this->previewUrl($ctx),
            'ACTIVE' => 'Y',
            'CONTENT' => $this->content($ctx),
        ];
    }

    public function manifest(BlockRuntimeContext $ctx): array
    {
        $v = rawurlencode($ctx->assetsVersion);
        return [
            'block' => [
                'name' => 'Каталог услуг',
                'dynamic' => false,
                'section' => array_filter(array_map('trim', explode(',', $ctx->sectionsCsv))),
            ],
            'style' => [
                'block' => [
                    // background + paddings etc (like usual blocks)
                    'type' => ['block-default-background'],
                ],
            ],
            'assets' => [
                'css' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.css?v=' . $v,
                ],
                'js' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.js?v=' . $v,
                ],
            ],
            'attrs' => [
                '.ap-catalog' => [
                    'name' => 'ID каталога (IBLOCK_ID)',
                    'attribute' => 'data-ap-iblock-id',
                    'type' => 'text',
                ],
                '.ap-catalog__container' => [
                    'name' => 'Путь detail-страницы (куда вести карточку)',
                    'attribute' => 'data-ap-detail-path',
                    'type' => 'text',
                ],
                '.ap-catalog__inner' => [
                    'name' => 'Колонки на десктопе (3 или 5)',
                    'attribute' => 'data-ap-columns',
                    'type' => 'list',
                    'items' => [
                        ['name' => '3 колонки', 'value' => '3'],
                        ['name' => '5 колонок', 'value' => '5'],
                    ],
                ],
                '.ap-catalog__pager' => [
                    'name' => 'Показывать срок оказания (раб.дни) вместо уч.часов',
                    'attribute' => 'data-ap-show-duration-work-days',
                    'type' => 'list',
                    'items' => [
                        ['name' => 'Нет', 'value' => 'N'],
                        ['name' => 'Да', 'value' => 'Y'],
                    ],
                ],
                '.ap-catalog__grid' => [
                    'name' => 'ID папки каталога (раздел, iblockSectionId)',
                    'attribute' => 'data-ap-section-id',
                    'type' => 'text',
                ],
            ],
        ];
    }

    private function content(BlockRuntimeContext $ctx): string
    {
        $api = htmlspecialchars($ctx->apiBaseUrl, ENT_QUOTES);
        $param = htmlspecialchars($ctx->productHashParam, ENT_QUOTES);
        $detailPath = htmlspecialchars($ctx->detailPagePath, ENT_QUOTES);

        return <<<HTML
<section class="landing-block ap-block ap-catalog ap-catalog__container" data-ap-block="catalog" data-ap-api-base="{$api}" data-ap-product-param="{$param}" data-ap-detail-path="{$detailPath}" data-ap-columns="5" data-ap-show-duration-work-days="N" data-ap-iblock-id="" data-ap-section-id="">
  <div class="ap-catalog__wrapper">
    <div class="ap-catalog__inner" data-ap-columns="5">
      <div class="ap-catalog__grid" data-ap-section-id=""></div>
      <div class="ap-catalog__pager" data-ap-show-duration-work-days="N"></div>
    </div>
  </div>
</section>
HTML;
    }

    private function previewUrl(BlockRuntimeContext $ctx): string
    {
        $v = rawurlencode($ctx->assetsVersion);
        // Fallback: if preview-catalog.png is not deployed, reuse search preview
        // (Bitrix landing repo shows preview image but doesn't require unique ones).
        return rtrim($ctx->assetsBaseUrl, '/') . '/preview-search.png?v=' . $v;
    }
}

