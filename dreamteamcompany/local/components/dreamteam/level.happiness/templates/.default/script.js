(function () {
	'use strict';

	var widgetConfig = window.LevelHappinessWidgetConfig || {};
	var messages = widgetConfig.messages || {};

	function getWidgetElement() {
		return document.getElementById('levelHappiness');
	}

	function getComponentName() {
		var widget = getWidgetElement();
		if (widget && widget.dataset && widget.dataset.componentName) {
			return String(widget.dataset.componentName);
		}
		if (typeof widgetConfig.componentName === 'string' && widgetConfig.componentName !== '') {
			return widgetConfig.componentName;
		}
		return '';
	}

	function getSaveActionName() {
		var widget = getWidgetElement();
		if (widget && widget.dataset && widget.dataset.saveActionName) {
			return String(widget.dataset.saveActionName);
		}
		if (typeof widgetConfig.saveActionName === 'string' && widgetConfig.saveActionName !== '') {
			return widgetConfig.saveActionName;
		}
		return 'saveLevel';
	}

	function getLevelOptions() {
		return Array.isArray(widgetConfig.levelOptions) && widgetConfig.levelOptions.length > 0
			? widgetConfig.levelOptions
			: [5, 4, 3, 2, 1];
	}

	function getMinLevel() {
		var value = Number(widgetConfig.minLevel);
		return Number.isInteger(value) ? value : 1;
	}

	function getMaxLevel() {
		var value = Number(widgetConfig.maxLevel);
		return Number.isInteger(value) ? value : 5;
	}

	function getLowScoreMaxLevel() {
		var value = Number(widgetConfig.lowScoreMaxLevel);
		return Number.isInteger(value) ? value : 3;
	}

	function isLowScoreLevel(level) {
		return Number.isInteger(level) && level >= getMinLevel() && level <= getLowScoreMaxLevel();
	}

	function getNotifyAutohideMs() {
		var value = Number(widgetConfig.uiNotifyAutohideMs);
		return Number.isInteger(value) ? value : 3000;
	}

	function getObserverTimeoutMs() {
		var value = Number(widgetConfig.domObserverTimeoutMs);
		return Number.isInteger(value) ? value : 5000;
	}

	function getStarSymbol() {
		return typeof widgetConfig.starSymbol === 'string' && widgetConfig.starSymbol !== '' ? widgetConfig.starSymbol : '⭐';
	}

	function getStarSymbolFilled() {
		return typeof widgetConfig.starSymbolFilled === 'string' && widgetConfig.starSymbolFilled !== '' ? widgetConfig.starSymbolFilled : '★';
	}

	function getStarSymbolEmpty() {
		return typeof widgetConfig.starSymbolEmpty === 'string' && widgetConfig.starSymbolEmpty !== '' ? widgetConfig.starSymbolEmpty : '☆';
	}

	function getText(name, fallback) {
		return typeof messages[name] === 'string' && messages[name] !== '' ? messages[name] : fallback;
	}

	function showMessage(message) {
		if (window.BX && BX.UI && BX.UI.Notification && BX.UI.Notification.Center) {
			BX.UI.Notification.Center.notify({
				content: message,
				autoHideDelay: getNotifyAutohideMs()
			});
			return;
		}
		// Fallback: non-blocking inline toast inside widget.
		var widget = getWidgetElement();
		if (!widget) {
			return;
		}

		var toast = widget.querySelector('.lh-toast');
		if (!toast) {
			toast = document.createElement('div');
			toast.className = 'lh-toast';
			widget.appendChild(toast);
		}

		toast.textContent = String(message);
		toast.setAttribute('data-visible', 'Y');

		window.clearTimeout(toast._lhTimer);
		toast._lhTimer = window.setTimeout(function () {
			toast.setAttribute('data-visible', 'N');
		}, getNotifyAutohideMs());
	}

	function setText(id, value) {
		var element = document.getElementById(id);
		if (element) {
			element.textContent = String(value);
		}
	}

	function setBar(level, percent) {
		var bar = document.getElementById('levelHappinessBar' + level);
		if (bar) {
			bar.style.width = String(percent) + '%';
		}
	}

	function renderAverageStars(classes) {
		var container = document.getElementById('levelHappinessAverageStars');
		if (!container || !Array.isArray(classes)) {
			return;
		}

		var html = '';
		for (var i = 0; i < classes.length; i++) {
			var state = String(classes[i] || 'empty');
			var emptySymbol = getStarSymbolEmpty();
			html += '<span class="lh-star lh-star--' + state + '" data-star-filled="' + getStarSymbolFilled() + '" data-star-empty="' + emptySymbol + '">' + emptySymbol + '</span>';
		}
		container.innerHTML = html;
	}

	function applyWidgetData(widget) {
		if (!widget || !widget.stars || !widget.percent) {
			return;
		}

		setText('levelHappinessAverageValue', widget.average);
		setText('levelHappinessVotesCount', widget.stars.GLAVCOUNT);
		var levels = getLevelOptions();
		for (var i = 0; i < levels.length; i++) {
			var level = levels[i];
			setText('levelHappinessCount' + level, widget.stars.STATIC[level] || 0);
			setBar(level, widget.percent[level] || 0);
		}

		renderAverageStars(widget.starClasses || []);

		var userBlock = document.getElementById('levelHappinessUserRatingBlock');
		var userValue = document.getElementById('levelHappinessUserRatingValue');
		var userLevel = widget.stars.USER || 0;

		if (userBlock && userValue) {
			if (userLevel > 0) {
				userValue.textContent = String(userLevel) + getStarSymbol();
				userBlock.style.display = '';
			} else {
				userBlock.style.display = 'none';
			}
		}
	}

	function moveWidgetNearPulse() {
		var widget = document.getElementById('levelHappiness');
		var targetId = typeof widgetConfig.widgetMoveTargetId === 'string' && widgetConfig.widgetMoveTargetId !== ''
			? widgetConfig.widgetMoveTargetId
			: 'pulse_open_btn';
		var insertPosition = typeof widgetConfig.widgetMovePosition === 'string' && widgetConfig.widgetMovePosition !== ''
			? widgetConfig.widgetMovePosition
			: 'afterend';
		var pulseButton = document.getElementById(targetId);

		if (!widget || !pulseButton || !pulseButton.parentNode) {
			return false;
		}

		pulseButton.insertAdjacentElement(insertPosition, widget);
		return true;
	}

	function moveWidgetNearPulseFast() {
		if (moveWidgetNearPulse()) {
			return;
		}

		var observer = new MutationObserver(function () {
			if (moveWidgetNearPulse()) {
				observer.disconnect();
			}
		});

		observer.observe(document.body, {
			childList: true,
			subtree: true
		});

		// Страховка: не держим observer бесконечно.
		setTimeout(function () {
			observer.disconnect();
		}, getObserverTimeoutMs());
	}

	function bindFormSubmit() {
		var form = document.getElementById('editLevel');
		if (!form) {
			return;
		}

		var submitButton = form.querySelector('button[type="submit"]');
		var isSubmitting = false;
		var pendingLowScoreLevel = null;

		if (form.getAttribute('data-level-happiness-bound') === 'Y') {
			return;
		}
		form.setAttribute('data-level-happiness-bound', 'Y');

		var modal = document.getElementById('lhLowScoreModal');
		var modalTitle = document.getElementById('lhLowScoreModalTitle');
		var modalHint = document.getElementById('lhLowScoreModalHint');
		var modalReason = document.getElementById('lhLowScoreReason');
		var modalConfirm = document.getElementById('lhLowScoreConfirm');
		var modalCancel = document.getElementById('lhLowScoreCancel');
		var modalBackdrop = document.getElementById('lhLowScoreBackdrop');

		function setLowScoreModalOpen(open) {
			if (!modal) {
				return;
			}
			modal.setAttribute('data-open', open ? 'Y' : 'N');
			modal.setAttribute('aria-hidden', open ? 'false' : 'true');
		}

		function closeLowScoreModal() {
			document.removeEventListener('keydown', onLowScoreModalEscape);
			pendingLowScoreLevel = null;
			setLowScoreModalOpen(false);
		}

		function onLowScoreModalEscape(ev) {
			if (ev.key === 'Escape' || ev.keyCode === 27) {
				closeLowScoreModal();
			}
		}

		if (modalTitle) {
			modalTitle.textContent = getText('lowScoreModalTitle', 'Что пошло не так?');
		}
		if (modalHint) {
			modalHint.textContent = getText('lowScoreModalHint', '');
		}
		if (modalReason) {
			modalReason.placeholder = getText('lowScoreModalPlaceholder', '');
		}
		if (modalCancel) {
			modalCancel.textContent = getText('lowScoreModalCancel', 'Отмена');
		}
		if (modalConfirm) {
			modalConfirm.textContent = getText('lowScoreModalSubmit', 'Отправить оценку');
		}

		function releaseSubmit() {
			isSubmitting = false;
			if (submitButton) {
				submitButton.disabled = false;
			}
			if (modalConfirm) {
				modalConfirm.disabled = false;
			}
			if (modalCancel) {
				modalCancel.disabled = false;
			}
		}

		function runComponentSave(level, message) {
			var componentName = getComponentName();
			if (!componentName) {
				showMessage('Не удалось определить namespace компонента.');
				releaseSubmit();
				return;
			}

			var data = {
				level: level,
				sessid: BX.bitrix_sessid ? BX.bitrix_sessid() : ''
			};
			if (message !== undefined && message !== null && String(message) !== '') {
				data.message = String(message);
			}

			BX.ajax.runComponentAction(
				componentName,
				getSaveActionName(),
				{
					mode: 'class',
					data: data
				}
			).then(function (response) {
				var widget = response && response.data ? response.data.widget : null;
				applyWidgetData(widget);
				showMessage(getText('saveSuccess', 'Оценка сохранена.'));
				closeLowScoreModal();
				if (modalReason) {
					modalReason.value = '';
				}
				releaseSubmit();
			}, function (response) {
				var errorMessage = getText('saveError', 'Не удалось сохранить оценку.');
				if (response && response.errors && response.errors.length > 0 && response.errors[0].message) {
					errorMessage = response.errors[0].message;
				}

				showMessage(errorMessage);
				releaseSubmit();
			});
		}

		function openLowScoreModal(level) {
			if (!modal) {
				showMessage(getText('reasonRequired', 'Опишите причину (обязательно для низкой оценки).'));
				return;
			}
			pendingLowScoreLevel = level;
			document.removeEventListener('keydown', onLowScoreModalEscape);
			document.addEventListener('keydown', onLowScoreModalEscape);
			setLowScoreModalOpen(true);
			if (modalReason) {
				window.setTimeout(function () {
					modalReason.focus();
				}, 0);
			}
		}

		if (modalCancel) {
			modalCancel.addEventListener('click', function () {
				closeLowScoreModal();
			});
		}

		if (modalBackdrop) {
			modalBackdrop.addEventListener('click', function () {
				closeLowScoreModal();
			});
		}

		if (modalConfirm) {
			modalConfirm.addEventListener('click', function () {
				if (pendingLowScoreLevel === null) {
					return;
				}
				var msg = modalReason ? modalReason.value.trim() : '';
				if (msg === '') {
					showMessage(getText('reasonRequired', 'Опишите причину (обязательно для низкой оценки).'));
					return;
				}
				if (isSubmitting) {
					return;
				}
				isSubmitting = true;
				if (submitButton) {
					submitButton.disabled = true;
				}
				modalConfirm.disabled = true;
				if (modalCancel) {
					modalCancel.disabled = true;
				}

				runComponentSave(pendingLowScoreLevel, msg);
			});
		}

		form.addEventListener('submit', function (event) {
			event.preventDefault();

			var levelField = form.querySelector('select[name="myLevel"]');
			var level = levelField ? Number(levelField.value) : NaN;
			var minLevel = getMinLevel();
			var maxLevel = getMaxLevel();
			if (!Number.isInteger(level) || level < minLevel || level > maxLevel) {
				showMessage(getText('selectLevel', 'Выбери уровень от ' + minLevel + ' до ' + maxLevel + ' звезд.'));
				return;
			}

			if (isLowScoreLevel(level)) {
				openLowScoreModal(level);
				return;
			}

			if (isSubmitting) {
				return;
			}

			if (submitButton) {
				submitButton.disabled = true;
			}
			isSubmitting = true;

			runComponentSave(level, '');
		});
	}

	function bootWidget() {
		var shouldMoveWidget = widgetConfig.enableWidgetMove !== false;
		if (shouldMoveWidget) {
			moveWidgetNearPulseFast();
		}

		bindFormSubmit();
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', bootWidget);
	} else {
		bootWidget();
	}
})();
