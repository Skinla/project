<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

final class SearchBlock implements BlockDefinition
{
    public function code(): string
    {
        return 'academyprofi.search';
    }

    public function fields(BlockRuntimeContext $ctx): array
    {
        return [
            'NAME' => 'Поиск услуг',
            'DESCRIPTION' => 'Поиск по каталогу услуг (dropdown до 8 результатов).',
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
            'assets' => [
                'css' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.css?v=' . $v,
                ],
                'js' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.js?v=' . $v,
                ],
            ],
            'nodes' => [
                '.landing-block-node-button-container' => [
                    'name' => 'Кнопка',
                    'type' => 'link',
                ],
            ],
            'attrs' => [
                '.ap-search' => [
                    'name' => 'ID каталога (IBLOCK_ID)',
                    'attribute' => 'data-ap-iblock-id',
                    'type' => 'text',
                ],
                '.ap-search__container' => [
                    'name' => 'Путь detail-страницы (куда вести карточку)',
                    'attribute' => 'data-ap-detail-path',
                    'type' => 'text',
                ],
                '.landing-block-node-form' => [
                    'name' => 'ID папки каталога (раздел, iblockSectionId)',
                    'attribute' => 'data-ap-section-id',
                    'type' => 'text',
                ],
            ],
            // Bitrix style system changes ONLY classes (not inline styles).
            // We use standard style groups/types from /bitrix/blocks/bitrix/.style.php on the server.
            'style' => [
                'block' => [
                    // background + paddings etc (like usual blocks)
                    'type' => ['block-default-background'],
                ],
                'nodes' => [
                    '.landing-block-node-input-container' => [
                        'name' => 'Поле поиска',
                        'type' => [
                            'background-color',
                            'background-hover',
                            'border-colors',
                            'border-radius',
                            'padding-left',
                            'padding-right',
                            'color',
                            'color-hover',
                            'font-family',
                            'font-size',
                            'font-weight',
                        ],
                    ],
                    '.landing-block-node-button-container' => [
                        'name' => 'Кнопка',
                        'type' => [
                            'button-color',
                            'button-color-hover',
                            'border-radius',
                            'button-padding',
                            'color',
                            'color-hover',
                            'font-family',
                            'font-size',
                            'font-weight',
                        ],
                    ],
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
<section class="landing-block ap-block ap-search ap-search__container" data-ap-block="search" data-ap-api-base="{$api}" data-ap-product-param="{$param}" data-ap-detail-path="{$detailPath}" data-ap-iblock-id="" data-ap-section-id="">
    <div class="ap-search__wrapper">
      <form class="landing-block-node-form ap-search__form" action="#" method="get" data-ap-section-id="">
        <div class="ap-search__row">
          <div class="landing-block-node-input-container ap-search__input-container g-rounded-30">
            <div class="ap-search__icon" aria-hidden="true">
              <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                <path d="M11.7422 10.3439C12.5329 9.2673 13 7.9382 13 6.5C13 2.91015 10.0899 0 6.5 0C2.91015 0 0 2.91015 0 6.5C0 10.0899 2.91015 13 6.5 13C7.93858 13 9.26801 12.5327 10.3448 11.7415L10.3439 11.7422C10.3734 11.7718 10.4062 11.7995 10.4424 11.8249L14.2929 15.6754C14.6834 16.0659 15.3166 16.0659 15.7071 15.6754C16.0976 15.2849 16.0976 14.6517 15.7071 14.2612L11.8566 10.4107C11.8312 10.3745 11.8035 10.3417 11.7739 10.3121L11.7422 10.3439ZM12 6.5C12 9.53757 9.53757 12 6.5 12C3.46243 12 1 9.53757 1 6.5C1 3.46243 3.46243 1 6.5 1C9.53757 1 12 3.46243 12 6.5Z" fill="currentColor"/>
              </svg>
            </div>
            <input class="landing-block-node-input-text ap-search__input" type="text" placeholder="Поиск по всем направлениям..." autocomplete="off" />
          </div>
          <a class="landing-block-node-button-container ap-search__button btn g-rounded-25" href="#" role="button">Найти</a>
        </div>
        <div class="ap-search__dropdown" hidden></div>
      </form>
    </div>
 </section>
HTML;
    }

    private function previewUrl(BlockRuntimeContext $ctx): string
    {
        $v = rawurlencode($ctx->assetsVersion);
        return rtrim($ctx->assetsBaseUrl, '/') . '/preview-search.png?v=' . $v;
    }
}

