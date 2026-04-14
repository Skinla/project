<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

$starSymbol = (string)($arResult['STAR_SYMBOL'] ?? '⭐');
$starSymbolFilled = (string)($arResult['STAR_SYMBOL_FILLED'] ?? '★');
$starSymbolEmpty = (string)($arResult['STAR_SYMBOL_EMPTY'] ?? '☆');
$levelOptions = is_array($arResult['LEVEL_OPTIONS_DESC']) ? $arResult['LEVEL_OPTIONS_DESC'] : [5, 4, 3, 2, 1];
$levelLabelSuffix = (string)$arResult['LEVEL_LABEL_SUFFIX'];
$barColors = is_array($arResult['BAR_COLORS']) ? $arResult['BAR_COLORS'] : [];
$frontendConfigJs = \CUtil::PhpToJSObject($arResult['FRONTEND_CONFIG']);
?>

<div
	class="lh-widget"
	id="levelHappiness"
	data-component-name="<?= htmlspecialcharsbx((string)($arResult['FRONTEND_CONFIG']['componentName'] ?? '')) ?>"
	data-save-action-name="<?= htmlspecialcharsbx((string)($arResult['FRONTEND_CONFIG']['saveActionName'] ?? 'saveLevel')) ?>"
>
	<div class="lh-card">
		<span class="heading"><span class="lh-heading-line"><?= htmlspecialcharsbx((string)$arResult['FORM_TITLE']) ?></span><br><b class="lh-heading-sub"><?= htmlspecialcharsbx((string)$arResult['COMPANY_NAME']) ?></b></span>
		<span id="levelHappinessAverageStars" class="lh-stars">
			<?php foreach ($arResult['STAR_CLASSES'] as $starState): ?>
				<span
					class="lh-star lh-star--<?= htmlspecialcharsbx((string)$starState) ?>"
					data-star-filled="<?= htmlspecialcharsbx($starSymbolFilled) ?>"
					data-star-empty="<?= htmlspecialcharsbx($starSymbolEmpty) ?>"
				><?= htmlspecialcharsbx($starSymbolEmpty) ?></span>
			<?php endforeach; ?>
		</span>
		<p class="lh-summary"><b id="levelHappinessAverageValue"><?= (float)$arResult['AVERAGE'] ?></b> в среднем на основе <b id="levelHappinessVotesCount"><?= (int)$arResult['STARS']['GLAVCOUNT'] ?></b> голосов.</p>
		<hr class="lh-divider">

		<div class="row">
			<?php foreach ($levelOptions as $level): ?>
				<div class="side"><div><?= (int)$level ?> <?= htmlspecialcharsbx($levelLabelSuffix) ?></div></div>
				<div class="middle">
					<div class="bar-container">
						<div
							class="lh-bar"
							id="levelHappinessBar<?= (int)$level ?>"
							style="width: <?= (float)($arResult['PERCENT'][$level] ?? 0) ?>%; background-color: <?= htmlspecialcharsbx((string)($barColors[$level] ?? '#6f7bb2')) ?>;"
						></div>
					</div>
				</div>
				<div class="side right"><div id="levelHappinessCount<?= (int)$level ?>"><?= (int)($arResult['STARS']['STATIC'][$level] ?? 0) ?></div></div>
			<?php endforeach; ?>
		</div>

		<hr class="lh-divider">

		<div class="lh-footer">
			<span class="title"><?= htmlspecialcharsbx((string)$arResult['FORM_QUESTION']) ?></span>
			<p class="description">
				<?= htmlspecialcharsbx((string)$arResult['ANONYMITY_TEXT']) ?>
				<span id="levelHappinessUserRatingBlock"<?= (int)$arResult['STARS']['USER'] === 0 ? ' style="display:none;"' : '' ?>>
					<br><?= htmlspecialcharsbx((string)$arResult['USER_LEVEL_PREFIX']) ?> <b id="levelHappinessUserRatingValue"><?= (int)$arResult['STARS']['USER'] ?><?= htmlspecialcharsbx($starSymbol) ?></b>
				</span>
			</p>

			<form method="post" id="editLevel">
				<?= bitrix_sessid_post() ?>
				<input name="userID" type="hidden" value="<?= (int)$arResult['CURRENT_USER_ID'] ?>"/>
				<div class="lh-select-wrap">
					<select name="myLevel" class="lh-select">
						<option selected="true" disabled="disabled"><?= htmlspecialcharsbx((string)$arResult['SELECT_PLACEHOLDER']) ?></option>
						<?php foreach ($levelOptions as $level): ?>
							<option value="<?= (int)$level ?>" <?= (int)$arResult['STARS']['USER'] === (int)$level ? 'selected="selected"' : '' ?>><?= htmlspecialcharsbx(str_repeat($starSymbol, (int)$level)) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="actions">
					<button class="accept" type="submit"><?= htmlspecialcharsbx((string)$arResult['BUTTON_TEXT']) ?></button>
					<?php if (!empty($arResult['ENABLE_MANAGEMENT_BUTTON'])): ?>
						<a
							class="accept"
							href="<?= htmlspecialcharsbx((string)($arResult['MANAGEMENT_URL'] ?? 'https://kmechty.ru/')) ?>"
							target="_blank"
							rel="noopener noreferrer"
						><?= htmlspecialcharsbx((string)($arResult['MANAGEMENT_BUTTON_TEXT'] ?? 'Написать руководству')) ?></a>
					<?php endif; ?>
				</div>
			</form>
		</div>
	</div>

	<div class="lh-modal" id="lhLowScoreModal" data-open="N" aria-hidden="true">
		<div class="lh-modal__backdrop" id="lhLowScoreBackdrop" tabindex="-1"></div>
		<div class="lh-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="lhLowScoreModalTitle">
			<h2 class="lh-modal__title" id="lhLowScoreModalTitle"></h2>
			<p class="lh-modal__hint" id="lhLowScoreModalHint"></p>
			<label class="lh-modal__label" for="lhLowScoreReason">
				<textarea
					id="lhLowScoreReason"
					class="lh-modal__textarea"
					rows="4"
					maxlength="4000"
				></textarea>
			</label>
			<div class="lh-modal__actions">
				<button type="button" class="accept lh-modal__btn lh-modal__btn--secondary" id="lhLowScoreCancel"></button>
				<button type="button" class="accept lh-modal__btn" id="lhLowScoreConfirm"></button>
			</div>
		</div>
	</div>
</div>
<script>
	window.LevelHappinessWidgetConfig = <?= $frontendConfigJs ?>;
</script>
