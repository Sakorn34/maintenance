/**
 * Modal กลาง maintenance — แทน window.alert / confirm ด้วย overlay สวยงามและใช้ร่วมกันในโปรเจกต์
 *
 * API:
 * - mnModalAlert(message, options?)
 *   options: { title?: string, variant?: 'info'|'success'|'warning'|'danger', onClose?: function,
 *     extraAction?: { label: string, onClick?: function } }
 * - mnModalConfirm(message, options?)
 *   options: { title?, variant?, onConfirm?: function, onCancel?: function, okLabel?, cancelLabel? }
 * - mnModalPrompt(options)
 *   options: { title?, variant?, hint?, initialValue?, rows?, fieldLabel?, okLabel?, cancelLabel?, onConfirm(value: string), onCancel? }
 * - mnModalFieldEdit(options)
 *   options: { title?, variant?, oldLabel?, oldValue?, newLabel?, initialValue?, maxlength?, inputType?: 'text'|'textarea'|'select', rows?, options?: string[]|{value,label}[], validate?(value)=>string|null, onConfirm(value), onCancel?, okLabel?, cancelLabel? }
 *
 * ต้องโหลด mn-modal.css และไฟล์นี้ก่อนสคริปต์ที่เรียก mnModalAlert / mnModalConfirm / mnModalPrompt / mnModalFieldEdit
 */
(function (global) {
	'use strict';

	var VARIANT_DEFAULT_TITLE = {
		info: 'แจ้งเตือน',
		success: 'สำเร็จ',
		warning: 'คำเตือน',
		danger: 'เกิดข้อผิดพลาด'
	};

	function escapeHtml(s) {
		var d = document.createElement('div');
		d.textContent = s;
		return d.innerHTML;
	}

	function ensureDom() {
		var existing = document.getElementById('mn-modal-overlay');
		if (existing) {
			if (!document.getElementById('mn-modal-extra')) {
				var footerLegacy = document.getElementById('mn-modal-footer');
				if (!footerLegacy) {
					footerLegacy = existing.querySelector('.mn-modal-footer');
					if (footerLegacy) {
						footerLegacy.id = 'mn-modal-footer';
					}
				}
				if (footerLegacy) {
					var extraLegacy = document.createElement('button');
					extraLegacy.type = 'button';
					extraLegacy.id = 'mn-modal-extra';
					extraLegacy.className = 'mn-modal-btn mn-modal-btn--secondary mn-modal-btn--extra';
					extraLegacy.style.display = 'none';
					extraLegacy.textContent = '—';
					footerLegacy.insertBefore(extraLegacy, footerLegacy.firstChild);
				}
			}
			return;
		}
		var overlay = document.createElement('div');
		overlay.id = 'mn-modal-overlay';
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info" id="mn-modal-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" id="mn-modal-icon" aria-hidden="true"></span>' +
			'<span id="mn-modal-title">แจ้งเตือน</span>' +
			'</div>' +
			'<div class="mn-modal-body" id="mn-modal-body"></div>' +
			'<div class="mn-modal-footer" id="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary mn-modal-btn--extra" id="mn-modal-extra" style="display:none">—</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-modal-cancel" style="display:none">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-modal-ok">ตกลง</button>' +
			'</div>' +
			'</div>';
		document.body.appendChild(overlay);

		function closeModal() {
			overlay.classList.remove('mn-modal-overlay--open');
			document.removeEventListener('keydown', onKey);
			var cb = overlay._mnOnClose;
			overlay._mnOnClose = null;
			overlay._mnConfirmMode = false;
			overlay._mnPromptMode = false;
			overlay._mnFieldEditMode = false;
			overlay._mnFieldEditValidate = null;
			var extraBtnClose = document.getElementById('mn-modal-extra');
			if (extraBtnClose) {
				extraBtnClose.style.display = 'none';
				extraBtnClose.onclick = null;
			}
			var footerEl = document.getElementById('mn-modal-footer');
			if (footerEl) {
				footerEl.classList.remove('mn-modal-footer--has-extra');
			}
			var bodyEl = document.getElementById('mn-modal-body');
			var dlgEl = document.getElementById('mn-modal-dialog');
			if (bodyEl) {
				bodyEl.classList.remove('mn-modal-body--prompt');
				bodyEl.classList.remove('mn-modal-body--field-edit');
			}
			if (dlgEl) {
				dlgEl.classList.remove('mn-modal--field-edit');
			}
			if (typeof cb === 'function') {
				cb();
			}
		}

		function onKey(e) {
			if (e.keyCode === 27) {
				e.preventDefault();
				if (overlay._mnConfirmMode) {
					document.getElementById('mn-modal-cancel').click();
				} else {
					document.getElementById('mn-modal-ok').click();
				}
			}
		}

		document.getElementById('mn-modal-ok').onclick = function () {
			if (overlay._mnFieldEditMode) {
				var inpEl = document.getElementById('mn-modal-field-edit-input');
				var fieldVal = inpEl ? String(inpEl.value).trim() : '';
				var validateField = overlay._mnFieldEditValidate;
				if (typeof validateField === 'function') {
					var fieldErr = validateField(fieldVal);
					if (fieldErr) {
						mnModalAlert(String(fieldErr), { variant: 'warning' });
						return;
					}
				}
				var fnFieldEdit = overlay._mnOnConfirm;
				overlay._mnOnConfirm = null;
				overlay._mnOnCancel = null;
				overlay._mnFieldEditValidate = null;
				closeModal();
				if (typeof fnFieldEdit === 'function') {
					fnFieldEdit(fieldVal);
				}
				return;
			}
			if (overlay._mnPromptMode) {
				var ta = document.getElementById('mn-modal-prompt-field');
				var val = ta ? ta.value : '';
				var fnPrompt = overlay._mnOnConfirm;
				overlay._mnOnConfirm = null;
				overlay._mnOnCancel = null;
				closeModal();
				if (typeof fnPrompt === 'function') {
					fnPrompt(val);
				}
				return;
			}
			if (overlay._mnConfirmMode) {
				var fn = overlay._mnOnConfirm;
				overlay._mnOnConfirm = null;
				overlay._mnOnCancel = null;
				closeModal();
				if (typeof fn === 'function') {
					fn();
				}
			} else {
				closeModal();
			}
		};

		document.getElementById('mn-modal-cancel').onclick = function () {
			if (overlay._mnConfirmMode) {
				var fn = overlay._mnOnCancel;
				overlay._mnOnConfirm = null;
				overlay._mnOnCancel = null;
				closeModal();
				if (typeof fn === 'function') {
					fn();
				}
			}
		};

		overlay.addEventListener('click', function (e) {
			if (e.target === overlay) {
				if (overlay._mnConfirmMode || overlay._mnPromptMode) {
					document.getElementById('mn-modal-cancel').click();
				} else {
					closeModal();
				}
			}
		});

		overlay._openWith = function (message, opts) {
			opts = opts || {};
			overlay._mnPromptMode = false;
			var bodyEl0 = document.getElementById('mn-modal-body');
			if (bodyEl0) {
				bodyEl0.classList.remove('mn-modal-body--prompt');
			}
			var dlg0 = document.getElementById('mn-modal-dialog');
			if (dlg0) {
				dlg0.classList.remove('mn-modal--prompt');
			}
			var variant = opts.variant || 'info';
			var title = opts.title || VARIANT_DEFAULT_TITLE[variant] || VARIANT_DEFAULT_TITLE.info;
			var dlg = document.getElementById('mn-modal-dialog');
			dlg.className = 'mn-modal-dialog mn-modal mn-modal--' + variant;
			document.getElementById('mn-modal-title').textContent = title;
			document.getElementById('mn-modal-body').innerHTML = escapeHtml(String(message));
			var iconEl = document.getElementById('mn-modal-icon');
			var iconInner = '<span class="mn-modal-header__glyph">\u2139</span>';
			if (variant === 'success') {
				iconInner = '<span class="mn-modal-header__glyph mn-modal-header__glyph--ok">\u2713</span>';
			} else if (variant === 'warning') {
				iconInner = '<span class="mn-modal-header__glyph">\u26A0</span>';
			} else if (variant === 'danger') {
				iconInner = '<span class="mn-modal-header__glyph">\u2715</span>';
			}
			iconEl.innerHTML = iconInner;
			var okBtn = document.getElementById('mn-modal-ok');
			var cancelBtn = document.getElementById('mn-modal-cancel');
			var isConfirm = opts.confirm === true;
			overlay._mnConfirmMode = isConfirm;
			cancelBtn.style.display = isConfirm ? '' : 'none';
			okBtn.textContent = isConfirm ? (opts.okLabel || 'บันทึก') : 'ตกลง';
			cancelBtn.textContent = opts.cancelLabel || 'ยกเลิก';
			okBtn.className = 'mn-modal-btn mn-modal-btn--' + (variant === 'danger' ? 'danger' : 'primary');
			var extraBtn = document.getElementById('mn-modal-extra');
			var footerEl = document.getElementById('mn-modal-footer');
			var extraAct = !isConfirm && opts.extraAction && opts.extraAction.label ? opts.extraAction : null;
			if (extraBtn) {
				if (extraAct) {
					extraBtn.style.display = '';
					extraBtn.textContent = String(extraAct.label);
					extraBtn.onclick = function () {
						if (typeof extraAct.onClick === 'function') {
							extraAct.onClick();
						}
					};
					if (footerEl) {
						footerEl.classList.add('mn-modal-footer--has-extra');
					}
				} else {
					extraBtn.style.display = 'none';
					extraBtn.onclick = null;
					if (footerEl) {
						footerEl.classList.remove('mn-modal-footer--has-extra');
					}
				}
			}
			if (isConfirm) {
				overlay._mnOnConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
				overlay._mnOnCancel = typeof opts.onCancel === 'function' ? opts.onCancel : null;
				overlay._mnOnClose = null;
			} else {
				overlay._mnOnConfirm = null;
				overlay._mnOnCancel = null;
				overlay._mnOnClose = opts.onClose || null;
			}
			overlay.classList.add('mn-modal-overlay--open');
			document.addEventListener('keydown', onKey);
			setTimeout(function () {
				(isConfirm ? okBtn : okBtn).focus();
			}, 50);
		};

		/**
		 * เปิด modal แบบมี textarea (ใช้ภายใน ensureDom ร่วมกับ mnModalPrompt)
		 */
		overlay._openPrompt = function (opts) {
			opts = opts || {};
			overlay._mnPromptMode = false;
			var variant = opts.variant || 'info';
			var title = opts.title || 'กรอกข้อมูล';
			var dlg = document.getElementById('mn-modal-dialog');
			dlg.className = 'mn-modal-dialog mn-modal mn-modal--' + variant + ' mn-modal--prompt';
			document.getElementById('mn-modal-title').textContent = title;
			var bodyEl = document.getElementById('mn-modal-body');
			bodyEl.classList.add('mn-modal-body--prompt');
			bodyEl.innerHTML = '';
			if (opts.hint) {
				var p = document.createElement('p');
				p.className = 'mn-modal-prompt-hint';
				p.textContent = String(opts.hint);
				bodyEl.appendChild(p);
			}
			var ta = document.createElement('textarea');
			ta.id = 'mn-modal-prompt-field';
			ta.className = 'mn-modal-prompt-field';
			if (opts.fieldLabel) {
				ta.setAttribute('aria-label', String(opts.fieldLabel));
			}
			var rows = opts.rows ? parseInt(opts.rows, 10) : 0;
			ta.rows = rows > 0 ? rows : 6;
			ta.value = opts.initialValue != null ? String(opts.initialValue) : '';
			bodyEl.appendChild(ta);
			var iconEl = document.getElementById('mn-modal-icon');
			var iconInner = '<span class="mn-modal-header__glyph">\u2139</span>';
			if (variant === 'success') {
				iconInner = '<span class="mn-modal-header__glyph mn-modal-header__glyph--ok">\u2713</span>';
			} else if (variant === 'warning') {
				iconInner = '<span class="mn-modal-header__glyph">\u26A0</span>';
			} else if (variant === 'danger') {
				iconInner = '<span class="mn-modal-header__glyph">\u2715</span>';
			}
			iconEl.innerHTML = iconInner;
			var okBtn = document.getElementById('mn-modal-ok');
			var cancelBtn = document.getElementById('mn-modal-cancel');
			overlay._mnConfirmMode = true;
			overlay._mnPromptMode = true;
			cancelBtn.style.display = '';
			okBtn.textContent = opts.okLabel || 'บันทึก';
			cancelBtn.textContent = opts.cancelLabel || 'ยกเลิก';
			okBtn.className = 'mn-modal-btn mn-modal-btn--' + (variant === 'danger' ? 'danger' : 'primary');
			overlay._mnOnConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
			overlay._mnOnCancel = typeof opts.onCancel === 'function' ? opts.onCancel : null;
			overlay._mnOnClose = null;
			overlay.classList.add('mn-modal-overlay--open');
			document.addEventListener('keydown', onKey);
			setTimeout(function () {
				ta.focus();
			}, 50);
		};

		/**
		 * เปิด modal แก้ไขฟิลด์เดียว — แสดงค่าเดิม (อ่านอย่างเดียว) และช่องค่าใหม่
		 */
		overlay._openFieldEdit = function (opts) {
			opts = opts || {};
			overlay._mnPromptMode = false;
			overlay._mnFieldEditMode = false;
			var variant = opts.variant || 'info';
			var title = opts.title || 'แก้ไขข้อมูล';
			var dlg = document.getElementById('mn-modal-dialog');
			dlg.className = 'mn-modal-dialog mn-modal mn-modal--' + variant + ' mn-modal--field-edit'
				+ (opts.inputType === 'textarea' ? ' mn-modal--field-edit-textarea' : '');
			document.getElementById('mn-modal-title').textContent = title;
			var bodyEl = document.getElementById('mn-modal-body');
			bodyEl.className = 'mn-modal-body mn-modal-body--field-edit';
			bodyEl.innerHTML = '';
			var oldLabel = opts.oldLabel || 'ค่าเดิม';
			var oldVal = opts.oldValue != null ? String(opts.oldValue) : '';
			var dl = document.createElement('dl');
			dl.className = 'mn-modal-field-edit-old';
			var dt = document.createElement('dt');
			dt.textContent = oldLabel;
			var dd = document.createElement('dd');
			if (opts.inputType === 'textarea') {
				dd.className = 'mn-modal-field-edit-old-dd mn-modal-field-edit-old-dd--pre';
			}
			dd.textContent = oldVal !== '' ? oldVal : '—';
			dl.appendChild(dt);
			dl.appendChild(dd);
			bodyEl.appendChild(dl);
			var newLabel = opts.newLabel || opts.fieldLabel || 'ค่าใหม่';
			var lbl = document.createElement('label');
			lbl.className = 'mn-modal-field-edit-label';
			lbl.setAttribute('for', 'mn-modal-field-edit-input');
			lbl.textContent = newLabel;
			bodyEl.appendChild(lbl);
			var initialVal = opts.initialValue != null ? String(opts.initialValue) : oldVal;
			var fieldEl;
			var selectOpts = opts.options;
			if (selectOpts && selectOpts.length) {
				fieldEl = document.createElement('select');
				fieldEl.id = 'mn-modal-field-edit-input';
				fieldEl.className = 'mn-modal-field-edit-input mn-modal-field-edit-select';
				fieldEl.setAttribute('aria-label', newLabel);
				var hasSelected = false;
				for (var oi = 0; oi < selectOpts.length; oi++) {
					var optItem = selectOpts[oi];
					var optVal = typeof optItem === 'string' ? optItem : String(optItem.value || '');
					var optLabel = typeof optItem === 'string' ? optItem : String(optItem.label != null ? optItem.label : optVal);
					var optNode = document.createElement('option');
					optNode.value = optVal;
					optNode.textContent = optLabel;
					if (optVal === initialVal) {
						optNode.selected = true;
						hasSelected = true;
					}
					fieldEl.appendChild(optNode);
				}
				if (!hasSelected && initialVal !== '') {
					var optCur = document.createElement('option');
					optCur.value = initialVal;
					optCur.textContent = initialVal;
					optCur.selected = true;
					fieldEl.insertBefore(optCur, fieldEl.firstChild);
				}
			} else if (opts.inputType === 'textarea') {
				fieldEl = document.createElement('textarea');
				fieldEl.id = 'mn-modal-field-edit-input';
				fieldEl.className = 'mn-modal-field-edit-input mn-modal-field-edit-textarea';
				fieldEl.setAttribute('aria-label', newLabel);
				var taRows = opts.rows ? parseInt(opts.rows, 10) : 0;
				fieldEl.rows = taRows > 0 ? taRows : 4;
				if (opts.maxlength) {
					var mlTa = parseInt(opts.maxlength, 10);
					if (mlTa > 0) {
						fieldEl.maxLength = mlTa;
					}
				}
				fieldEl.value = initialVal;
			} else {
				fieldEl = document.createElement('input');
				fieldEl.type = 'text';
				fieldEl.id = 'mn-modal-field-edit-input';
				fieldEl.className = 'mn-modal-field-edit-input';
				fieldEl.setAttribute('aria-label', newLabel);
				if (opts.maxlength) {
					var ml = parseInt(opts.maxlength, 10);
					if (ml > 0) {
						fieldEl.maxLength = ml;
					}
				}
				fieldEl.value = initialVal;
			}
			bodyEl.appendChild(fieldEl);
			if (opts.hint) {
				var hintP = document.createElement('p');
				hintP.className = 'mn-modal-field-edit-hint';
				hintP.textContent = String(opts.hint);
				bodyEl.appendChild(hintP);
			}
			var iconEl = document.getElementById('mn-modal-icon');
			var iconInner = '<span class="mn-modal-header__glyph">\u2139</span>';
			if (variant === 'success') {
				iconInner = '<span class="mn-modal-header__glyph mn-modal-header__glyph--ok">\u2713</span>';
			} else if (variant === 'warning') {
				iconInner = '<span class="mn-modal-header__glyph">\u26A0</span>';
			} else if (variant === 'danger') {
				iconInner = '<span class="mn-modal-header__glyph">\u2715</span>';
			}
			iconEl.innerHTML = iconInner;
			var okBtn = document.getElementById('mn-modal-ok');
			var cancelBtn = document.getElementById('mn-modal-cancel');
			overlay._mnConfirmMode = true;
			overlay._mnFieldEditMode = true;
			overlay._mnFieldEditValidate = typeof opts.validate === 'function' ? opts.validate : null;
			cancelBtn.style.display = '';
			okBtn.textContent = opts.okLabel || 'บันทึก';
			cancelBtn.textContent = opts.cancelLabel || 'ยกเลิก';
			okBtn.className = 'mn-modal-btn mn-modal-btn--' + (variant === 'danger' ? 'danger' : 'primary');
			overlay._mnOnConfirm = typeof opts.onConfirm === 'function' ? opts.onConfirm : null;
			overlay._mnOnCancel = typeof opts.onCancel === 'function' ? opts.onCancel : null;
			overlay._mnOnClose = null;
			overlay.classList.add('mn-modal-overlay--open');
			document.addEventListener('keydown', onKey);
			setTimeout(function () {
				fieldEl.focus();
				if (fieldEl.select && fieldEl.tagName !== 'SELECT') {
					fieldEl.select();
				}
			}, 50);
		};
	}

	/**
	 * Purpose:
	 * แสดงข้อความแจ้งเตือนแบบ modal แทน alert
	 *
	 * Parameters สำคัญ:
	 * - message: string ข้อความ
	 * - options: { title?, variant?, onClose?, extraAction?: { label, onClick? } }
	 *
	 * Return value:
	 * - undefined
	 */
	function mnModalAlert(message, options) {
		ensureDom();
		document.getElementById('mn-modal-overlay')._openWith(message, options || {});
	}

	/**
	 * Purpose:
	 * แสดง modal ยืนยัน (ตกลง / ยกเลิก) แทน window.confirm
	 *
	 * Parameters สำคัญ:
	 * - message: string ข้อความ
	 * - options: { title?, variant?, onConfirm?, onCancel?, okLabel?, cancelLabel? }
	 *
	 * Return value:
	 * - undefined
	 */
	function mnModalConfirm(message, options) {
		ensureDom();
		var opts = options || {};
		opts.confirm = true;
		document.getElementById('mn-modal-overlay')._openWith(message, opts);
	}

	/**
	 * Purpose:
	 * แสดง modal ที่มี textarea ให้แก้ไขข้อความ (เช่น ที่อยู่) แทน prompt ของเบราว์เซอร์
	 *
	 * Parameters สำคัญ:
	 * - options: { title?, variant?, hint?, initialValue?, rows?, fieldLabel?, okLabel?, cancelLabel?, onConfirm(value: string), onCancel? }
	 *
	 * Return value:
	 * - undefined
	 */
	function mnModalPrompt(options) {
		ensureDom();
		document.getElementById('mn-modal-overlay')._openPrompt(options || {});
	}

	/**
	 * Purpose:
	 * แสดง modal แก้ไขฟิลด์เดียว — แสดงค่าเดิมและช่องค่าใหม่ (ใช้ร่วมกับหน้า detail_job และอื่น ๆ)
	 *
	 * Parameters สำคัญ:
	 * - options: { title?, variant?, oldLabel?, oldValue?, newLabel?, fieldLabel?, initialValue?, maxlength?, hint?, validate?, onConfirm(value), onCancel?, okLabel?, cancelLabel? }
	 *
	 * Return value:
	 * - undefined
	 */
	function mnModalFieldEdit(options) {
		ensureDom();
		document.getElementById('mn-modal-overlay')._openFieldEdit(options || {});
	}

	global.mnModalAlert = mnModalAlert;
	global.mnModalConfirm = mnModalConfirm;
	global.mnModalPrompt = mnModalPrompt;
	global.mnModalFieldEdit = mnModalFieldEdit;
})(typeof window !== 'undefined' ? window : this);
