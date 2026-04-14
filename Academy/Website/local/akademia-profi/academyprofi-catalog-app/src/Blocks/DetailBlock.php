<?php
declare(strict_types=1);

namespace AcademyProfi\CatalogApp\Blocks;

final class DetailBlock implements BlockDefinition
{
    public function code(): string
    {
        return 'academyprofi.detail';
    }

    public function fields(BlockRuntimeContext $ctx): array
    {
        return [
            'NAME' => 'Детальная карточка услуги',
            'DESCRIPTION' => 'Детальная карточка услуги с CTA “Оставить заявку”.',
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
            'nodes' => [
                '.landing-block-node-button-container' => [
                    'name' => 'Кнопка (CTA)',
                    'type' => 'link',
                ],
            ],
            'style' => [
                'block' => [
                    // allow background + paddings for whole block
                    'type' => ['block-default-background'],
                ],
                'nodes' => [
                    '.landing-block-node-button-container' => [
                        'name' => 'Кнопка (CTA)',
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
            'assets' => [
                'css' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.css?v=' . $v,
                ],
                'js' => [
                    rtrim($ctx->assetsBaseUrl, '/') . '/academyprofi-blocks.js?v=' . $v,
                ],
            ],
        ];
    }

    private function content(BlockRuntimeContext $ctx): string
    {
        $api = htmlspecialchars($ctx->apiBaseUrl, ENT_QUOTES);
        $param = htmlspecialchars($ctx->productHashParam, ENT_QUOTES);
        $defaultId = (string)$ctx->defaultProductId;
        $ctaAnchor = htmlspecialchars($ctx->ctaAnchor, ENT_QUOTES);
        $ctaLabel = htmlspecialchars($ctx->ctaLabel, ENT_QUOTES);

        return <<<HTML
<section class="landing-block ap-block ap-detail ap-detail__container" data-ap-block="detail" data-ap-api-base="{$api}" data-ap-product-param="{$param}" data-ap-default-product-id="{$defaultId}" data-ap-cta-anchor="{$ctaAnchor}">
  <div class="ap-detail__wrapper">
    <div class="ap-detail__empty" hidden>Выберите услугу в каталоге</div>
  <div class="ap-detail__inner ap-detail__content" hidden>
    <div class="ap-detail__header">
      <div class="ap-detail__title"></div>
      <div class="ap-detail__aside">
        <div class="ap-detail__price"></div>
        <a class="landing-block-node-button-container ap-detail__cta btn g-bg--hover g-button-color g-border-color--hover g-color--hover g-color g-text-transform-none g-rounded-auto g-btn-type-solid g-font-open-sans" href="{$ctaAnchor}">{$ctaLabel}</a>
      </div>
    </div>
    <div class="ap-detail__service-type"></div>
    <div class="ap-detail__grid"></div>
    <div class="ap-detail__sections">
      <div class="ap-detail__requirements ap-detail__section"></div>
      <div class="ap-detail__education ap-detail__section"></div>
    </div>
  </div>
  </div>
</section>
HTML;
    }

    private function previewUrl(BlockRuntimeContext $ctx): string
    {
        $v = rawurlencode($ctx->assetsVersion);
        // Fallback: if preview-detail.png is not deployed, reuse search preview
        // (Bitrix landing repo shows preview image but doesn't require unique ones).
        return rtrim($ctx->assetsBaseUrl, '/') . '/preview-search.png?v=' . $v;
    }
}

