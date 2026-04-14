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

		var levelField = document.querySelector('#editLevel select[name="myLevel"]');
		if (levelField) {
			levelField.value = userLevel > 0 ? String(userLevel) : '';
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

		var levelField = form.querySelector('select[name="myLevel"]');
		var submitButton = form.querySelector('button[type="submit"]');
		var isSubmitting = false;
		var pendingLowScoreLevel = null;
		var lastFocusedElement = null;

		if (form.getAttribute('data-level-happiness-bound') === 'Y') {
			return;
		}
		form.setAttribute('data-level-happiness-bound', 'Y');

		var modal = document.getElementById('lhLowScoreModal');
		var modalTitle = document.getElementById('lhLowScoreModalTitle');
		var modalHint = document.getElementById('lhLowScoreModalHint');
		var modalError = document.getElementById('lhLowScoreError');
		var modalReason = document.getElementById('lhLowScoreReason');
		var modalConfirm = document.getElementById('lhLowScoreConfirm');
		var modalCancel = document.getElementById('lhLowScoreCancel');
		var modalBackdrop = document.getElementById('lhLowScoreBackdrop');
		var modalDialog = modal ? modal.querySelector('.lh-modal__dialog') : null;

		function setLowScoreModalOpen(open) {
			if (!modal) {
				return;
			}
			modal.setAttribute('data-open', open ? 'Y' : 'N');
			modal.setAttribute('aria-hidden', open ? 'false' : 'true');
			if (document.body) {
				document.body.classList.toggle('lh-modal-open', open);
			}
			if (levelField) {
				levelField.disabled = open;
			}
		}

		function resetLowScoreModalState(restoreFocus) {
			clearLowScoreModalError();
			if (modalReason) {
				modalReason.value = '';
			}
			document.removeEventListener('keydown', onLowScoreModalEscape);
			document.removeEventListener('keydown', trapLowScoreModalFocus);
			pendingLowScoreLevel = null;
			setLowScoreModalOpen(false);
			if (restoreFocus && lastFocusedElement && typeof lastFocusedElement.focus === 'function') {
				lastFocusedElement.focus();
			}
			lastFocusedElement = null;
		}

		function setLowScoreModalError(message) {
			if (!modalError) {
				showMessage(message);
				return;
			}

			modalError.textContent = String(message);
			modalError.hidden = false;
		}

		function clearLowScoreModalError() {
			if (!modalError) {
				return;
			}

			modalError.textContent = '';
			modalError.hidden = true;
		}

		function onLowScoreModalEscape(ev) {
			if ((ev.key === 'Escape' || ev.keyCode === 27) && !isSubmitting) {
				resetLowScoreModalState(true);
			}
		}

		function trapLowScoreModalFocus(ev) {
			if (!modal || modal.getAttribute('data-open') !== 'Y' || ev.key !== 'Tab') {
				return;
			}

			var focusableNodes = modal.querySelectorAll('button, [href], textarea, input, select, [tabindex]:not([tabindex="-1"])');
			if (!focusableNodes.length) {
				return;
			}

			var focusable = Array.prototype.filter.call(focusableNodes, function (node) {
				return !node.disabled && node.offsetParent !== null;
			});
			if (!focusable.length) {
				return;
			}

			var first = focusable[0];
			var last = focusable[focusable.length - 1];

			if (ev.shiftKey && document.activeElement === first) {
				ev.preventDefault();
				last.focus();
				return;
			}

			if (!ev.shiftKey && document.activeElement === last) {
				ev.preventDefault();
				first.focus();
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
			if (!window.BX || !BX.ajax || typeof BX.ajax.runComponentAction !== 'function') {
				showMessage(getText('saveError', 'Не удалось сохранить оценку.'));
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
				resetLowScoreModalState(false);
				releaseSubmit();
			}, function (response) {
				var errorMessage = getText('saveError', 'Не удалось сохранить оценку.');
				if (response && response.errors && response.errors.length > 0 && response.errors[0].message) {
					errorMessage = response.errors[0].message;
				}

				if (modal && modal.getAttribute('data-open') === 'Y') {
					setLowScoreModalError(errorMessage);
				} else {
					showMessage(errorMessage);
				}
				releaseSubmit();
				if (modal && modal.getAttribute('data-open') === 'Y' && modalReason) {
					modalReason.focus();
				}
			});
		}

		function openLowScoreModal(level) {
			if (!modal) {
				showMessage(getText('reasonRequired', 'Опишите причину (обязательно для низкой оценки).'));
				return;
			}
			lastFocusedElement = document.activeElement;
			pendingLowScoreLevel = level;
			clearLowScoreModalError();
			document.removeEventListener('keydown', trapLowScoreModalFocus);
			document.removeEventListener('keydown', onLowScoreModalEscape);
			document.addEventListener('keydown', trapLowScoreModalFocus);
			document.addEventListener('keydown', onLowScoreModalEscape);
			setLowScoreModalOpen(true);
			if (modalReason) {
				window.setTimeout(function () {
					modalReason.focus();
				}, 0);
			} else if (modalDialog) {
				window.setTimeout(function () {
					modalDialog.focus();
				}, 0);
			}
		}

		if (modalCancel) {
			modalCancel.addEventListener('click', function () {
				if (!isSubmitting) {
					resetLowScoreModalState(true);
				}
			});
		}

		if (modalBackdrop) {
			modalBackdrop.addEventListener('click', function () {
				if (!isSubmitting) {
					resetLowScoreModalState(true);
				}
			});
		}

		if (modalConfirm) {
			modalConfirm.addEventListener('click', function () {
				if (pendingLowScoreLevel === null) {
					return;
				}

				var currentLevel = levelField ? Number(levelField.value) : pendingLowScoreLevel;
				var minLevel = getMinLevel();
				var maxLevel = getMaxLevel();
				if (!Number.isInteger(currentLevel) || currentLevel < minLevel || currentLevel > maxLevel) {
					showMessage(getText('selectLevel', 'Выбери уровень от ' + minLevel + ' до ' + maxLevel + ' звезд.'));
					return;
				}

				if (!isLowScoreLevel(currentLevel)) {
					if (isSubmitting) {
						return;
					}
					isSubmitting = true;
					if (submitButton) {
						submitButton.disabled = true;
					}
					resetLowScoreModalState(false);
					runComponentSave(currentLevel, '');
					return;
				}

				pendingLowScoreLevel = currentLevel;
				var msg = modalReason ? modalReason.value.trim() : '';
				if (msg === '') {
					setLowScoreModalError(getText('reasonRequired', 'Опишите причину (обязательно для низкой оценки).'));
					if (modalReason) {
						modalReason.focus();
					}
					return;
				}
				clearLowScoreModalError();
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
