/**
 * Modal แก้ไขข้อมูลเครื่องซ่อมบนหน้า detail_job — สินค้า + S/N + อาการ ในฟอร์มเดียว
 *
 * Flow:
 * คลิก `.mn-dj-repair-machine-edit-btn` → modal แสดงค่าเดิมทั้ง 3 ช่อง → บันทึก POST `field_key=repair_machine` → reload
 */
(function (global) {
	'use strict';

	var BUNDLE_OVERLAY_ID = 'mn-dj-repair-machine-overlay';

	function validateProduct(v) {
		var s = String(v || '').trim();
		return s === '' ? 'กรุณาเลือกสินค้า' : null;
	}

	function validateSn(v) {
		var s = String(v || '').trim();
		if (s === '') {
			return 'กรุณากรอก S/N เครื่องซ่อม';
		}
		if (!/^[A-Za-z0-9-]+$/.test(s)) {
			return 'S/N ใช้ได้เฉพาะตัวอักษรภาษาอังกฤษ ตัวเลข และเครื่องหมาย -';
		}
		var c = s.charCodeAt(0);
		if (!((c >= 65 && c <= 90) || (c >= 97 && c <= 122) || (c >= 48 && c <= 57))) {
			return 'S/N ต้องขึ้นต้นด้วย [A-Z, 0-9, a-z] เท่านั้น';
		}
		return null;
	}

	function validateBuyDate(v) {
		var s = String(v || '').trim();
		if (s === '') {
			return null;
		}
		if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
			return 'รูปแบบวันที่ซื้อไม่ถูกต้อง';
		}
		return null;
	}

	function validateInform(v) {
		var s = String(v || '').trim();
		if (s === '') {
			return 'กรุณากรอกอาการ';
		}
		if (s.length > 255) {
			return 'อาการต้องไม่เกิน 255 ตัวอักษร';
		}
		return null;
	}

	function mnDjFieldAlert(message, variant) {
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(String(message), { variant: variant || 'info' });
		} else {
			global.alert(String(message));
		}
	}

	function mnDjGetProductNameOptions() {
		var list = (global.MN_DETAIL_JOB || {}).productNames;
		if (!list || !list.length) {
			return [];
		}
		var out = [];
		for (var i = 0; i < list.length; i++) {
			out.push(String(list[i]));
		}
		return out;
	}

	function mnDjPostRepairMachineBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_machine');
		body.set('trp_product', String(payload.trp_product));
		body.set('trp_sn', String(payload.trp_sn));
		body.set('trp_repair_inform', String(payload.trp_repair_inform));
		body.set('trp_buy_date', String(payload.trp_buy_date));
		body.set('old_trp_product', String(payload.old_trp_product));
		body.set('old_trp_sn', String(payload.old_trp_sn));
		body.set('old_trp_repair_inform', String(payload.old_trp_repair_inform));
		body.set('old_trp_buy_date', String(payload.old_trp_buy_date));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	/**
	 * Purpose:
	 * แสดงข้อมูลเดิมแบบกระชับ (label: value บรรทัดเดียว) ใน modal รวม
	 *
	 * Parameters สำคัญ:
	 * - parent: HTMLElement
	 * - items: { label: string, value: string, preWrap?: boolean }[]
	 *
	 * Return value:
	 * - undefined
	 */
	function mnDjAppendOldSummary(parent, items) {
		var wrap = document.createElement('div');
		wrap.className = 'mn-dj-bundle-old-summary';
		var i;
		for (i = 0; i < items.length; i++) {
			var item = items[i];
			var row = document.createElement('div');
			row.className = 'mn-dj-bundle-old-row';
			var lbl = document.createElement('span');
			lbl.className = 'mn-dj-bundle-old-label';
			lbl.textContent = String(item.label) + ':';
			var val = document.createElement('span');
			val.className = 'mn-dj-bundle-old-value' + (item.preWrap ? ' mn-dj-bundle-old-value--pre' : '');
			val.textContent = item.value !== '' && item.value != null ? String(item.value) : '—';
			row.appendChild(lbl);
			row.appendChild(val);
			wrap.appendChild(row);
		}
		parent.appendChild(wrap);
	}

	function mnDjAppendFieldLabel(parent, text, forId) {
		var lbl = document.createElement('label');
		lbl.className = 'mn-modal-field-edit-label';
		lbl.setAttribute('for', forId);
		lbl.textContent = text;
		parent.appendChild(lbl);
	}

	function ensureBundleOverlay() {
		var overlay = document.getElementById(BUNDLE_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = BUNDLE_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-modal--field-edit-textarea mn-dj-repair-machine-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขข้อมูลเครื่องซ่อม</span>' +
			'</div>' +
			'<div id="mn-dj-repair-machine-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-machine-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-machine-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-machine-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeBundleOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnBundleCtx = null;
	}

	function mnDjOpenRepairMachineEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldProduct = btn.getAttribute('data-product') || '';
		var oldSn = btn.getAttribute('data-sn') || '';
		var oldInform = btn.getAttribute('data-inform') || '';
		var oldBuyDate = btn.getAttribute('data-buy-date') || '';
		var oldBuyDateDisplay = btn.getAttribute('data-buy-date-display') || oldBuyDate;
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}
		var productOptions = mnDjGetProductNameOptions();
		if (!productOptions.length) {
			mnDjFieldAlert('ไม่พบรายการสินค้าในระบบ', 'warning');
			return;
		}

		var overlay = ensureBundleOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-machine-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'สินค้า', value: oldProduct },
			{ label: 'S/N', value: oldSn },
			{ label: 'อาการ', value: oldInform, preWrap: true },
			{ label: 'วันที่ซื้อ', value: oldBuyDateDisplay }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'สินค้า', 'mn-dj-bundle-product');
		var sel = document.createElement('select');
		sel.id = 'mn-dj-bundle-product';
		sel.className = 'mn-modal-field-edit-input mn-modal-field-edit-select';
		var oi;
		var hasProductSelected = false;
		for (oi = 0; oi < productOptions.length; oi++) {
			var opt = document.createElement('option');
			opt.value = productOptions[oi];
			opt.textContent = productOptions[oi];
			if (productOptions[oi] === oldProduct) {
				opt.selected = true;
				hasProductSelected = true;
			}
			sel.appendChild(opt);
		}
		if (oldProduct !== '' && !hasProductSelected) {
			var optCur = document.createElement('option');
			optCur.value = oldProduct;
			optCur.textContent = oldProduct;
			optCur.selected = true;
			sel.insertBefore(optCur, sel.firstChild);
		}
		bodyEl.appendChild(sel);

		mnDjAppendFieldLabel(bodyEl, 'S/N เครื่องซ่อม', 'mn-dj-bundle-sn');
		var inpSn = document.createElement('input');
		inpSn.type = 'text';
		inpSn.id = 'mn-dj-bundle-sn';
		inpSn.className = 'mn-modal-field-edit-input';
		inpSn.maxLength = 64;
		inpSn.value = oldSn;
		bodyEl.appendChild(inpSn);
		var snHint = document.createElement('p');
		snHint.className = 'mn-modal-field-edit-hint';
		snHint.textContent = 'ใช้ตัวอักษรภาษาอังกฤษ ตัวเลข และ - ได้ ตัวแรกต้องเป็น A-Z, a-z หรือ 0-9';
		bodyEl.appendChild(snHint);

		mnDjAppendFieldLabel(bodyEl, 'วันที่ซื้อ', 'mn-dj-bundle-buy-date');
		var inpBuyDate = document.createElement('input');
		inpBuyDate.type = 'date';
		inpBuyDate.id = 'mn-dj-bundle-buy-date';
		inpBuyDate.className = 'mn-modal-field-edit-input';
		inpBuyDate.value = oldBuyDate;
		bodyEl.appendChild(inpBuyDate);

		mnDjAppendFieldLabel(bodyEl, 'อาการ', 'mn-dj-bundle-inform');
		var ta = document.createElement('textarea');
		ta.id = 'mn-dj-bundle-inform';
		ta.className = 'mn-modal-field-edit-input mn-modal-field-edit-textarea';
		ta.rows = 4;
		ta.maxLength = 255;
		ta.value = oldInform;
		bodyEl.appendChild(ta);

		overlay._mnBundleCtx = {
			tsId: tsId,
			trpId: trpId,
			oldProduct: oldProduct,
			oldSn: oldSn,
			oldInform: oldInform,
			oldBuyDate: oldBuyDate,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-machine-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-machine-save');
		if (cancelBtn && !cancelBtn._mnDjBound) {
			cancelBtn._mnDjBound = true;
			cancelBtn.addEventListener('click', function () {
				closeBundleOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjBound) {
			saveBtn._mnDjBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnBundleCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newProduct = sel.value;
				var newSn = String(inpSn.value).trim();
				var newInform = String(ta.value).trim();
				var newBuyDate = String(inpBuyDate.value).trim();
				var err = validateProduct(newProduct) || validateSn(newSn) || validateInform(newInform) || validateBuyDate(newBuyDate);
				if (err) {
					mnDjFieldAlert(err, 'warning');
					return;
				}
				ctx.saving = true;
				mnDjPostRepairMachineBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_product: newProduct,
					trp_sn: newSn,
					trp_repair_inform: newInform,
					trp_buy_date: newBuyDate,
					old_trp_product: ctx.oldProduct,
					old_trp_sn: ctx.oldSn,
					old_trp_repair_inform: ctx.oldInform,
					old_trp_buy_date: ctx.oldBuyDate
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeBundleOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjOverlayClickBound) {
			overlay._mnDjOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeBundleOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			sel.focus();
		}, 50);
	}

	function mnDjDomReady(fn) {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	global.mnDjOpenRepairMachineEdit = mnDjOpenRepairMachineEdit;

	// ─────────────────────────────────────────────────────────────────
	// modal แก้ไขข้อมูลส่งสำรอง (วันที่ส่ง / ขนส่ง / ผู้ส่ง)
	// ─────────────────────────────────────────────────────────────────

	var SPARE_EARLY_RETURN_OVERLAY_ID = 'mn-dj-spare-early-return-overlay';

	function mnDjGetLogisticRecorderDefault() {
		var raw = (global.MN_DETAIL_JOB || {}).logisticRecorder;
		return raw !== null && raw !== undefined ? String(raw).trim() : '';
	}

	function mnDjPostSpareEarlyReturnBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('tsp_id', String(payload.tsp_id));
		body.set('field_key', 'spare_early_return');
		body.set('tsp_datereturn', String(payload.tsp_datereturn));
		body.set('tsp_receiveby', String(payload.tsp_receiveby));
		body.set('old_tsp_datereturn', String(payload.old_tsp_datereturn));
		body.set('old_tsp_receiveby', String(payload.old_tsp_receiveby));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureSpareEarlyReturnOverlay() {
		var overlay = document.getElementById(SPARE_EARLY_RETURN_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = SPARE_EARLY_RETURN_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--warning mn-modal--field-edit mn-dj-spare-early-return-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#9888;</span></span>' +
			'<span>ลูกค้าคืนสำรองก่อนปิดงาน</span>' +
			'</div>' +
			'<div id="mn-dj-spare-early-return-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-spare-early-return-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-spare-early-return-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-spare-early-return-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeSpareEarlyReturnOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnSpareEarlyReturnCtx = null;
	}

	function mnDjOpenSpareEarlyReturnEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var tspId = parseInt(btn.getAttribute('data-tsp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-datereturn') || '';
		var oldDateDisplay = btn.getAttribute('data-datereturn-display') || oldDateInput;
		var oldReceiveby = btn.getAttribute('data-receiveby') || mnDjGetLogisticRecorderDefault();
		if (tsId <= 0 || trpId <= 0 || tspId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการสำรองไม่ครบ', 'warning');
			return;
		}

		var overlay = ensureSpareEarlyReturnOverlay();
		var bodyEl = document.getElementById('mn-dj-spare-early-return-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var note = document.createElement('p');
		note.className = 'mn-dj-spare-early-return-note';
		note.textContent =
			'ใช้สำหรับกรณีลูกค้าส่งคืนเครื่องสำรองก่อนงานเสร็จสิ้น เช่น ลูกค้ารออนุมัติงานซ่อมนาน — ไม่ใช่การส่งคืนเครื่องซ่อมให้ลูกค้า';
		bodyEl.appendChild(note);

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่รับสำรองคืน', value: oldDateDisplay || '—' },
			{ label: 'ผู้รับ', value: oldReceiveby || '—' }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่รับสำรองคืน', 'mn-dj-spare-early-datereturn');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-spare-early-datereturn';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ผู้รับ', 'mn-dj-spare-early-receiveby');
		var inpRecv = document.createElement('input');
		inpRecv.type = 'text';
		inpRecv.id = 'mn-dj-spare-early-receiveby';
		inpRecv.className = 'mn-modal-field-edit-input';
		inpRecv.maxLength = 100;
		inpRecv.value = oldReceiveby;
		bodyEl.appendChild(inpRecv);

		overlay._mnSpareEarlyReturnCtx = {
			tsId: tsId,
			trpId: trpId,
			tspId: tspId,
			oldDateInput: oldDateInput,
			oldReceiveby: oldReceiveby,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-spare-early-return-cancel');
		var saveBtn = document.getElementById('mn-dj-spare-early-return-save');
		if (cancelBtn && !cancelBtn._mnDjSpareEarlyReturnBound) {
			cancelBtn._mnDjSpareEarlyReturnBound = true;
			cancelBtn.addEventListener('click', function () {
				closeSpareEarlyReturnOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjSpareEarlyReturnBound) {
			saveBtn._mnDjSpareEarlyReturnBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnSpareEarlyReturnCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newRecv = String(inpRecv.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่รับสำรองคืน', 'warning');
					inpDate.focus();
					return;
				}
				if (newRecv === '') {
					mnDjFieldAlert('กรุณาระบุผู้รับ', 'warning');
					inpRecv.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostSpareEarlyReturnBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					tsp_id: ctx.tspId,
					tsp_datereturn: newDate,
					tsp_receiveby: newRecv,
					old_tsp_datereturn: ctx.oldDateInput,
					old_tsp_receiveby: ctx.oldReceiveby
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeSpareEarlyReturnOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjSpareEarlyReturnOverlayClickBound) {
			overlay._mnDjSpareEarlyReturnOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeSpareEarlyReturnOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	var SPARE_SEND_OVERLAY_ID = 'mn-dj-spare-send-overlay';

	function mnDjGetLogisticsOptions() {
		var list = (global.MN_DETAIL_JOB || {}).logisticsNames;
		if (!list || !list.length) {
			return [];
		}
		var out = [];
		var i;
		for (i = 0; i < list.length; i++) {
			out.push(String(list[i]));
		}
		return out;
	}

	function mnDjGetQvSendOptions() {
		var list = (global.MN_DETAIL_JOB || {}).qvSendOptions;
		if (!list || !list.length) {
			return ['Mail', 'Line', 'Fax'];
		}
		var out = [];
		var i;
		for (i = 0; i < list.length; i++) {
			out.push(String(list[i]));
		}
		return out;
	}

	function mnDjPostSpareSendBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('tsp_id', String(payload.tsp_id));
		body.set('field_key', 'spare_send');
		body.set('tsp_datesend', String(payload.tsp_datesend));
		body.set('tsp_logistics', String(payload.tsp_logistics));
		body.set('tsp_sendby', String(payload.tsp_sendby));
		body.set('old_tsp_datesend', String(payload.old_tsp_datesend));
		body.set('old_tsp_logistics', String(payload.old_tsp_logistics));
		body.set('old_tsp_sendby', String(payload.old_tsp_sendby));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureSpareSendOverlay() {
		var overlay = document.getElementById(SPARE_SEND_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = SPARE_SEND_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-dj-spare-send-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขข้อมูลส่งสำรอง</span>' +
			'</div>' +
			'<div id="mn-dj-spare-send-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-spare-send-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-spare-send-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-spare-send-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeSpareSendOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnSpareSendCtx = null;
	}

	function mnDjOpenSpareSendEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var tspId = parseInt(btn.getAttribute('data-tsp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-datesend') || '';
		var oldDateDisplay = btn.getAttribute('data-datesend-display') || oldDateInput;
		var oldLogistics = btn.getAttribute('data-logistics') || '';
		var oldSendby = btn.getAttribute('data-sendby') || '';
		if (tsId <= 0 || trpId <= 0 || tspId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการสำรองไม่ครบ', 'warning');
			return;
		}
		var logisticsOptions = mnDjGetLogisticsOptions();
		if (!logisticsOptions.length) {
			mnDjFieldAlert('ไม่พบรายการขนส่งในระบบ', 'warning');
			return;
		}

		var overlay = ensureSpareSendOverlay();
		var bodyEl = document.getElementById('mn-dj-spare-send-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่ส่งสำรอง', value: oldDateDisplay },
			{ label: 'ขนส่ง', value: oldLogistics },
			{ label: 'ผู้ส่ง', value: oldSendby }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่ส่งสำรอง', 'mn-dj-spare-datesend');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-spare-datesend';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		if (inpDate.value === '') {
			var todaySp = new Date();
			var mmSp = String(todaySp.getMonth() + 1);
			var ddSp = String(todaySp.getDate());
			if (mmSp.length < 2) {
				mmSp = '0' + mmSp;
			}
			if (ddSp.length < 2) {
				ddSp = '0' + ddSp;
			}
			inpDate.value = todaySp.getFullYear() + '-' + mmSp + '-' + ddSp;
		}
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ขนส่ง', 'mn-dj-spare-logistics');
		var selLog = document.createElement('select');
		selLog.id = 'mn-dj-spare-logistics';
		selLog.className = 'mn-modal-field-edit-input mn-modal-field-edit-select';
		var optEmpty = document.createElement('option');
		optEmpty.value = '';
		optEmpty.textContent = 'ขนส่ง..';
		selLog.appendChild(optEmpty);
		var li;
		var hasLogSelected = false;
		for (li = 0; li < logisticsOptions.length; li++) {
			var optL = document.createElement('option');
			optL.value = logisticsOptions[li];
			optL.textContent = logisticsOptions[li];
			if (logisticsOptions[li] === oldLogistics) {
				optL.selected = true;
				hasLogSelected = true;
			}
			selLog.appendChild(optL);
		}
		if (oldLogistics !== '' && !hasLogSelected) {
			var optCur = document.createElement('option');
			optCur.value = oldLogistics;
			optCur.textContent = oldLogistics;
			optCur.selected = true;
			selLog.insertBefore(optCur, selLog.options[1] || null);
		}
		bodyEl.appendChild(selLog);

		mnDjAppendFieldLabel(bodyEl, 'ผู้ส่ง', 'mn-dj-spare-sendby');
		var inpSend = document.createElement('input');
		inpSend.type = 'text';
		inpSend.id = 'mn-dj-spare-sendby';
		inpSend.className = 'mn-modal-field-edit-input';
		inpSend.maxLength = 100;
		inpSend.value = oldSendby;
		bodyEl.appendChild(inpSend);

		overlay._mnSpareSendCtx = {
			tsId: tsId,
			trpId: trpId,
			tspId: tspId,
			oldDateInput: oldDateInput,
			oldLogistics: oldLogistics,
			oldSendby: oldSendby,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-spare-send-cancel');
		var saveBtn = document.getElementById('mn-dj-spare-send-save');
		if (cancelBtn && !cancelBtn._mnDjSpareBound) {
			cancelBtn._mnDjSpareBound = true;
			cancelBtn.addEventListener('click', function () {
				closeSpareSendOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjSpareBound) {
			saveBtn._mnDjSpareBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnSpareSendCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newLog = String(selLog.value).trim();
				var newSend = String(inpSend.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่ส่งสำรอง', 'warning');
					inpDate.focus();
					return;
				}
				if (newLog === '') {
					mnDjFieldAlert('กรุณาเลือกขนส่ง', 'warning');
					selLog.focus();
					return;
				}
				if (newSend === '') {
					mnDjFieldAlert('กรุณาระบุผู้ส่ง', 'warning');
					inpSend.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostSpareSendBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					tsp_id: ctx.tspId,
					tsp_datesend: newDate,
					tsp_logistics: newLog,
					tsp_sendby: newSend,
					old_tsp_datesend: ctx.oldDateInput,
					old_tsp_logistics: ctx.oldLogistics,
					old_tsp_sendby: ctx.oldSendby
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeSpareSendOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjSpareOverlayClickBound) {
			overlay._mnDjSpareOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeSpareSendOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	// ─────────────────────────────────────────────────────────────────
	// modal แก้ไขวันที่รับเครื่องซ่อม + ผู้รับ
	// ─────────────────────────────────────────────────────────────────

	var REPAIR_RECEIVE_OVERLAY_ID = 'mn-dj-repair-receive-overlay';

	function mnDjPostRepairReceiveBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_receive');
		body.set('trp_receive_date', String(payload.trp_receive_date));
		body.set('trp_user_recive_ma', String(payload.trp_user_recive_ma));
		body.set('old_trp_receive_date', String(payload.old_trp_receive_date));
		body.set('old_trp_user_recive_ma', String(payload.old_trp_user_recive_ma));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureRepairReceiveOverlay() {
		var overlay = document.getElementById(REPAIR_RECEIVE_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = REPAIR_RECEIVE_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-dj-repair-receive-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขวันที่รับเครื่องซ่อม</span>' +
			'</div>' +
			'<div id="mn-dj-repair-receive-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-receive-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-receive-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-receive-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeRepairReceiveOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnRepairReceiveCtx = null;
	}

	function mnDjOpenRepairReceiveEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-receive-date') || '';
		var oldDateDisplay = btn.getAttribute('data-receive-date-display') || oldDateInput;
		var oldUser = btn.getAttribute('data-user-recive-ma') || '';
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}

		var overlay = ensureRepairReceiveOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-receive-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่รับเครื่อง', value: oldDateDisplay },
			{ label: 'ผู้รับ', value: oldUser }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่รับเครื่อง', 'mn-dj-receive-date');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-receive-date';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ผู้รับ', 'mn-dj-receive-user');
		var inpUser = document.createElement('input');
		inpUser.type = 'text';
		inpUser.id = 'mn-dj-receive-user';
		inpUser.className = 'mn-modal-field-edit-input';
		inpUser.maxLength = 100;
		inpUser.value = oldUser;
		bodyEl.appendChild(inpUser);

		overlay._mnRepairReceiveCtx = {
			tsId: tsId,
			trpId: trpId,
			oldDateInput: oldDateInput,
			oldUser: oldUser,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-receive-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-receive-save');
		if (cancelBtn && !cancelBtn._mnDjReceiveBound) {
			cancelBtn._mnDjReceiveBound = true;
			cancelBtn.addEventListener('click', function () {
				closeRepairReceiveOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjReceiveBound) {
			saveBtn._mnDjReceiveBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnRepairReceiveCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newUser = String(inpUser.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่รับเครื่อง', 'warning');
					inpDate.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostRepairReceiveBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_receive_date: newDate,
					trp_user_recive_ma: newUser,
					old_trp_receive_date: ctx.oldDateInput,
					old_trp_user_recive_ma: ctx.oldUser
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeRepairReceiveOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjReceiveOverlayClickBound) {
			overlay._mnDjReceiveOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeRepairReceiveOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	var REPAIR_QV_OVERLAY_ID = 'mn-dj-repair-qv-overlay';

	function mnDjPostRepairQvBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_qv');
		body.set('trp_qv_number', String(payload.trp_qv_number));
		body.set('trp_qv_date', String(payload.trp_qv_date));
		body.set('trp_qv_send', String(payload.trp_qv_send));
		body.set('trp_qv_price', String(payload.trp_qv_price));
		body.set('trp_firm_qv_date', String(payload.trp_firm_qv_date));
		body.set('old_trp_qv_number', String(payload.old_trp_qv_number));
		body.set('old_trp_qv_date', String(payload.old_trp_qv_date));
		body.set('old_trp_qv_send', String(payload.old_trp_qv_send));
		body.set('old_trp_qv_price', String(payload.old_trp_qv_price));
		body.set('old_trp_firm_qv_date', String(payload.old_trp_firm_qv_date));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureRepairQvOverlay() {
		var overlay = document.getElementById(REPAIR_QV_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = REPAIR_QV_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-dj-repair-qv-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขใบเสนอราคา</span>' +
			'</div>' +
			'<div id="mn-dj-repair-qv-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-qv-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-qv-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-qv-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeRepairQvOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnRepairQvCtx = null;
	}

	function mnDjOpenRepairQvEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldNum = (btn.getAttribute('data-qv-number') || '').toUpperCase();
		var oldDateInput = btn.getAttribute('data-qv-date') || '';
		var oldDateDisplay = btn.getAttribute('data-qv-date-display') || oldDateInput;
		var oldPrice = btn.getAttribute('data-qv-price') || '';
		var oldSend = btn.getAttribute('data-qv-send') || '';
		var oldFirmInput = btn.getAttribute('data-firm-qv-date') || '';
		var oldFirmDisplay = btn.getAttribute('data-firm-qv-date-display') || oldFirmInput;
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}
		var sendOptions = mnDjGetQvSendOptions();

		var overlay = ensureRepairQvOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-qv-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'ใบเสนอราคา', value: oldNum },
			{ label: 'วันที่เสนอราคา', value: oldDateDisplay },
			{ label: 'จำนวนเงิน', value: oldPrice },
			{ label: 'รูปแบบการส่ง', value: oldSend },
			{ label: 'วันที่เสนอราคาแนน', value: oldFirmDisplay }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'ใบเสนอราคา (QV)', 'mn-dj-qv-number');
		var inpNum = document.createElement('input');
		inpNum.type = 'text';
		inpNum.id = 'mn-dj-qv-number';
		inpNum.className = 'mn-modal-field-edit-input';
		inpNum.maxLength = 12;
		inpNum.value = oldNum;
		inpNum.addEventListener('input', function () {
			inpNum.value = String(inpNum.value).toUpperCase().replace(/[^A-Z0-9]/g, '');
		});
		bodyEl.appendChild(inpNum);

		mnDjAppendFieldLabel(bodyEl, 'วันที่เสนอราคา', 'mn-dj-qv-date');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-qv-date';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'จำนวนเงิน', 'mn-dj-qv-price');
		var inpPrice = document.createElement('input');
		inpPrice.type = 'text';
		inpPrice.id = 'mn-dj-qv-price';
		inpPrice.className = 'mn-modal-field-edit-input';
		inpPrice.maxLength = 32;
		inpPrice.value = oldPrice;
		bodyEl.appendChild(inpPrice);

		mnDjAppendFieldLabel(bodyEl, 'รูปแบบการส่ง', 'mn-dj-qv-send');
		var selSend = document.createElement('select');
		selSend.id = 'mn-dj-qv-send';
		selSend.className = 'mn-modal-field-edit-input mn-modal-field-edit-select';
		var optSendEmpty = document.createElement('option');
		optSendEmpty.value = '';
		optSendEmpty.textContent = 'เลือกรูปแบบการส่ง..';
		selSend.appendChild(optSendEmpty);
		var si;
		var hasSendSelected = false;
		for (si = 0; si < sendOptions.length; si++) {
			var optS = document.createElement('option');
			optS.value = sendOptions[si];
			optS.textContent = sendOptions[si];
			if (sendOptions[si] === oldSend) {
				optS.selected = true;
				hasSendSelected = true;
			}
			selSend.appendChild(optS);
		}
		if (oldSend !== '' && !hasSendSelected) {
			var optSendCur = document.createElement('option');
			optSendCur.value = oldSend;
			optSendCur.textContent = oldSend;
			optSendCur.selected = true;
			selSend.insertBefore(optSendCur, selSend.options[1] || null);
		}
		bodyEl.appendChild(selSend);

		mnDjAppendFieldLabel(bodyEl, 'วันที่เสนอราคาแนน', 'mn-dj-firm-qv-date');
		var inpFirm = document.createElement('input');
		inpFirm.type = 'date';
		inpFirm.id = 'mn-dj-firm-qv-date';
		inpFirm.className = 'mn-modal-field-edit-input';
		inpFirm.value = oldFirmInput;
		bodyEl.appendChild(inpFirm);

		overlay._mnRepairQvCtx = {
			tsId: tsId,
			trpId: trpId,
			oldNum: oldNum,
			oldDateInput: oldDateInput,
			oldPrice: oldPrice,
			oldSend: oldSend,
			oldFirmInput: oldFirmInput,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-qv-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-qv-save');
		if (cancelBtn && !cancelBtn._mnDjQvBound) {
			cancelBtn._mnDjQvBound = true;
			cancelBtn.addEventListener('click', function () {
				closeRepairQvOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjQvBound) {
			saveBtn._mnDjQvBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnRepairQvCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newNum = String(inpNum.value).trim().toUpperCase();
				var newDate = String(inpDate.value).trim();
				var newPrice = String(inpPrice.value).trim();
				var newSend = String(selSend.value).trim();
				var newFirm = String(inpFirm.value).trim();
				if (newNum === '') {
					mnDjFieldAlert('กรุณาระบุหมายเลขใบเสนอราคา', 'warning');
					inpNum.focus();
					return;
				}
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่เสนอราคา', 'warning');
					inpDate.focus();
					return;
				}
				if (newSend === '') {
					mnDjFieldAlert('กรุณาระบุรูปแบบการส่ง', 'warning');
					selSend.focus();
					return;
				}
				if (newPrice === '') {
					mnDjFieldAlert('กรุณาระบุจำนวนเงิน', 'warning');
					inpPrice.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostRepairQvBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_qv_number: newNum,
					trp_qv_date: newDate,
					trp_qv_send: newSend,
					trp_qv_price: newPrice,
					trp_firm_qv_date: newFirm,
					old_trp_qv_number: ctx.oldNum,
					old_trp_qv_date: ctx.oldDateInput,
					old_trp_qv_send: ctx.oldSend,
					old_trp_qv_price: ctx.oldPrice,
					old_trp_firm_qv_date: ctx.oldFirmInput
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeRepairQvOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjQvOverlayClickBound) {
			overlay._mnDjQvOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeRepairQvOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpNum.focus();
		}, 50);
	}

	var REPAIR_MA_OVERLAY_ID = 'mn-dj-repair-ma-overlay';

	function mnDjPostRepairMaBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_ma');
		body.set('trp_success_date', String(payload.trp_success_date));
		body.set('trp_user_ma', String(payload.trp_user_ma));
		body.set('old_trp_success_date', String(payload.old_trp_success_date));
		body.set('old_trp_user_ma', String(payload.old_trp_user_ma));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureRepairMaOverlay() {
		var overlay = document.getElementById(REPAIR_MA_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = REPAIR_MA_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-dj-repair-ma-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขวันที่ซ่อม/ช่างซ่อม</span>' +
			'</div>' +
			'<div id="mn-dj-repair-ma-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-ma-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-ma-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-ma-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeRepairMaOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnRepairMaCtx = null;
	}

	function mnDjOpenRepairMaEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-success-date') || '';
		var oldDateDisplay = btn.getAttribute('data-success-date-display') || oldDateInput;
		var oldUser = btn.getAttribute('data-user-ma') || '';
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}

		var overlay = ensureRepairMaOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-ma-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่ซ่อม', value: oldDateDisplay },
			{ label: 'ช่างซ่อม', value: oldUser }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่ซ่อม', 'mn-dj-ma-success-date');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-ma-success-date';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ช่างซ่อม', 'mn-dj-ma-user');
		var inpUser = document.createElement('input');
		inpUser.type = 'text';
		inpUser.id = 'mn-dj-ma-user';
		inpUser.className = 'mn-modal-field-edit-input';
		inpUser.maxLength = 100;
		inpUser.value = oldUser;
		bodyEl.appendChild(inpUser);

		overlay._mnRepairMaCtx = {
			tsId: tsId,
			trpId: trpId,
			oldDateInput: oldDateInput,
			oldUser: oldUser,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-ma-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-ma-save');
		if (cancelBtn && !cancelBtn._mnDjMaBound) {
			cancelBtn._mnDjMaBound = true;
			cancelBtn.addEventListener('click', function () {
				closeRepairMaOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjMaBound) {
			saveBtn._mnDjMaBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnRepairMaCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newUser = String(inpUser.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่ซ่อม', 'warning');
					inpDate.focus();
					return;
				}
				if (newUser === '') {
					mnDjFieldAlert('กรุณาระบุช่างซ่อม', 'warning');
					inpUser.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostRepairMaBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_success_date: newDate,
					trp_user_ma: newUser,
					old_trp_success_date: ctx.oldDateInput,
					old_trp_user_ma: ctx.oldUser
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeRepairMaOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjMaOverlayClickBound) {
			overlay._mnDjMaOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeRepairMaOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	var REPAIR_QC_EDIT_OVERLAY_ID = 'mn-dj-repair-qc-edit-overlay';

	function mnDjPostRepairQcEditBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_qc');
		body.set('trp_qc_date', String(payload.trp_qc_date));
		body.set('trp_qc_user', String(payload.trp_qc_user));
		body.set('trp_qc_comment', String(payload.trp_qc_comment));
		body.set('old_trp_qc_date', String(payload.old_trp_qc_date));
		body.set('old_trp_qc_user', String(payload.old_trp_qc_user));
		body.set('old_trp_qc_comment', String(payload.old_trp_qc_comment));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureRepairQcEditOverlay() {
		var overlay = document.getElementById(REPAIR_QC_EDIT_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = REPAIR_QC_EDIT_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-modal--field-edit-textarea mn-dj-repair-qc-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขข้อมูล QC</span>' +
			'</div>' +
			'<div id="mn-dj-repair-qc-edit-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-qc-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-qc-edit-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-qc-edit-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeRepairQcEditOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnRepairQcEditCtx = null;
	}

	function mnDjOpenRepairQcEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-qc-date') || '';
		var oldDateDisplay = btn.getAttribute('data-qc-date-display') || oldDateInput;
		var oldUser = btn.getAttribute('data-qc-user') || '';
		var oldComment = btn.getAttribute('data-qc-comment') || '';
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}

		var overlay = ensureRepairQcEditOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-qc-edit-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่ QC', value: oldDateDisplay },
			{ label: 'ผู้ QC', value: oldUser },
			{ label: 'Comment QC', value: oldComment }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่ QC', 'mn-dj-qc-edit-date');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-qc-edit-date';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ผู้ QC', 'mn-dj-qc-edit-user');
		var inpUser = document.createElement('input');
		inpUser.type = 'text';
		inpUser.id = 'mn-dj-qc-edit-user';
		inpUser.className = 'mn-modal-field-edit-input';
		inpUser.maxLength = 100;
		inpUser.value = oldUser === '-' ? '' : oldUser;
		bodyEl.appendChild(inpUser);

		mnDjAppendFieldLabel(bodyEl, 'Comment QC', 'mn-dj-qc-edit-comment');
		var taComment = document.createElement('textarea');
		taComment.id = 'mn-dj-qc-edit-comment';
		taComment.className = 'mn-modal-field-edit-input mn-modal-field-edit-textarea';
		taComment.rows = 4;
		taComment.maxLength = 500;
		taComment.value = oldComment;
		bodyEl.appendChild(taComment);

		overlay._mnRepairQcEditCtx = {
			tsId: tsId,
			trpId: trpId,
			oldDateInput: oldDateInput,
			oldUser: oldUser,
			oldComment: oldComment,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-qc-edit-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-qc-edit-save');
		if (cancelBtn && !cancelBtn._mnDjQcEditBound) {
			cancelBtn._mnDjQcEditBound = true;
			cancelBtn.addEventListener('click', function () {
				closeRepairQcEditOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjQcEditBound) {
			saveBtn._mnDjQcEditBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnRepairQcEditCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newUser = String(inpUser.value).trim();
				var newComment = String(taComment.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่ QC', 'warning');
					inpDate.focus();
					return;
				}
				if (newUser === '') {
					mnDjFieldAlert('กรุณาระบุผู้ QC', 'warning');
					inpUser.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostRepairQcEditBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_qc_date: newDate,
					trp_qc_user: newUser,
					trp_qc_comment: newComment,
					old_trp_qc_date: ctx.oldDateInput,
					old_trp_qc_user: ctx.oldUser,
					old_trp_qc_comment: ctx.oldComment
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeRepairQcEditOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjQcEditOverlayClickBound) {
			overlay._mnDjQcEditOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeRepairQcEditOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	var REPAIR_RETURN_OVERLAY_ID = 'mn-dj-repair-return-overlay';

	function mnDjPostRepairReturnBundle(payload) {
		var body = new URLSearchParams();
		body.set('ts_id', String(payload.ts_id));
		body.set('trp_id', String(payload.trp_id));
		body.set('field_key', 'repair_return');
		body.set('trp_send_date', String(payload.trp_send_date));
		body.set('trp_logistics', String(payload.trp_logistics));
		body.set('trp_tracking', String(payload.trp_tracking));
		body.set('trp_sendby', String(payload.trp_sendby));
		body.set('old_trp_send_date', String(payload.old_trp_send_date));
		body.set('old_trp_logistics', String(payload.old_trp_logistics));
		body.set('old_trp_tracking', String(payload.old_trp_tracking));
		body.set('old_trp_sendby', String(payload.old_trp_sendby));

		return fetch('detail_job_field_update', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (res) {
			return res.json().catch(function () {
				return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' };
			});
		});
	}

	function ensureRepairReturnOverlay() {
		var overlay = document.getElementById(REPAIR_RETURN_OVERLAY_ID);
		if (overlay) {
			return overlay;
		}
		overlay = document.createElement('div');
		overlay.id = REPAIR_RETURN_OVERLAY_ID;
		overlay.className = 'mn-modal-overlay';
		overlay.setAttribute('role', 'dialog');
		overlay.setAttribute('aria-modal', 'true');
		overlay.innerHTML =
			'<div class="mn-modal-dialog mn-modal mn-modal--info mn-modal--field-edit mn-dj-repair-return-dialog">' +
			'<div class="mn-modal-header">' +
			'<span class="mn-modal-header__icon" aria-hidden="true"><span class="mn-modal-header__glyph">&#2139;</span></span>' +
			'<span>แก้ไขข้อมูลส่งคืน</span>' +
			'</div>' +
			'<div id="mn-dj-repair-return-body" class="mn-modal-body mn-modal-body--field-edit mn-dj-repair-return-body"></div>' +
			'<div class="mn-modal-footer">' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--secondary" id="mn-dj-repair-return-cancel">ยกเลิก</button>' +
			'<button type="button" class="mn-modal-btn mn-modal-btn--primary" id="mn-dj-repair-return-save">บันทึก</button>' +
			'</div></div>';
		document.body.appendChild(overlay);
		return overlay;
	}

	function closeRepairReturnOverlay(overlay) {
		overlay.classList.remove('mn-modal-overlay--open');
		overlay._mnRepairReturnCtx = null;
	}

	function mnDjOpenRepairReturnEdit(btn) {
		var tsId = parseInt(btn.getAttribute('data-ts-id'), 10) || 0;
		var trpId = parseInt(btn.getAttribute('data-trp-id'), 10) || 0;
		var oldDateInput = btn.getAttribute('data-send-date') || '';
		var oldDateDisplay = btn.getAttribute('data-send-date-display') || oldDateInput;
		var oldLogistics = btn.getAttribute('data-logistics') || '';
		var oldTracking = btn.getAttribute('data-tracking') || '';
		var oldSendby = btn.getAttribute('data-sendby') || '';
		if (tsId <= 0 || trpId <= 0) {
			mnDjFieldAlert('ข้อมูลรายการซ่อมไม่ครบ', 'warning');
			return;
		}
		var logisticsOptions = mnDjGetLogisticsOptions();
		if (!logisticsOptions.length) {
			mnDjFieldAlert('ไม่พบรายการขนส่งในระบบ', 'warning');
			return;
		}

		var overlay = ensureRepairReturnOverlay();
		var bodyEl = document.getElementById('mn-dj-repair-return-body');
		if (!bodyEl) {
			return;
		}
		bodyEl.innerHTML = '';

		var oldTitle = document.createElement('p');
		oldTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--old';
		oldTitle.textContent = 'ข้อมูลเดิม';
		bodyEl.appendChild(oldTitle);
		mnDjAppendOldSummary(bodyEl, [
			{ label: 'วันที่ส่งคืน', value: oldDateDisplay },
			{ label: 'ขนส่ง', value: oldLogistics },
			{ label: 'Tracking', value: oldTracking },
			{ label: 'ผู้ส่ง', value: oldSendby }
		]);

		var newTitle = document.createElement('p');
		newTitle.className = 'mn-dj-bundle-section-title mn-dj-bundle-section-title--new';
		newTitle.textContent = 'ข้อมูลใหม่';
		bodyEl.appendChild(newTitle);

		mnDjAppendFieldLabel(bodyEl, 'วันที่ส่งคืน', 'mn-dj-return-send-date');
		var inpDate = document.createElement('input');
		inpDate.type = 'date';
		inpDate.id = 'mn-dj-return-send-date';
		inpDate.className = 'mn-modal-field-edit-input';
		inpDate.value = oldDateInput;
		bodyEl.appendChild(inpDate);

		mnDjAppendFieldLabel(bodyEl, 'ขนส่ง', 'mn-dj-return-logistics');
		var selLog = document.createElement('select');
		selLog.id = 'mn-dj-return-logistics';
		selLog.className = 'mn-modal-field-edit-input mn-modal-field-edit-select';
		var optEmpty = document.createElement('option');
		optEmpty.value = '';
		optEmpty.textContent = 'ขนส่ง..';
		selLog.appendChild(optEmpty);
		var li;
		var hasLogSelected = false;
		for (li = 0; li < logisticsOptions.length; li++) {
			var optL = document.createElement('option');
			optL.value = logisticsOptions[li];
			optL.textContent = logisticsOptions[li];
			if (logisticsOptions[li] === oldLogistics) {
				optL.selected = true;
				hasLogSelected = true;
			}
			selLog.appendChild(optL);
		}
		if (oldLogistics !== '' && !hasLogSelected) {
			var optCur = document.createElement('option');
			optCur.value = oldLogistics;
			optCur.textContent = oldLogistics;
			optCur.selected = true;
			selLog.insertBefore(optCur, selLog.options[1] || null);
		}
		bodyEl.appendChild(selLog);

		mnDjAppendFieldLabel(bodyEl, 'Tracking', 'mn-dj-return-tracking');
		var inpTrack = document.createElement('input');
		inpTrack.type = 'text';
		inpTrack.id = 'mn-dj-return-tracking';
		inpTrack.className = 'mn-modal-field-edit-input';
		inpTrack.maxLength = 100;
		inpTrack.value = oldTracking;
		bodyEl.appendChild(inpTrack);

		mnDjAppendFieldLabel(bodyEl, 'ผู้ส่ง', 'mn-dj-return-sendby');
		var inpSend = document.createElement('input');
		inpSend.type = 'text';
		inpSend.id = 'mn-dj-return-sendby';
		inpSend.className = 'mn-modal-field-edit-input';
		inpSend.maxLength = 100;
		inpSend.value = oldSendby;
		bodyEl.appendChild(inpSend);

		overlay._mnRepairReturnCtx = {
			tsId: tsId,
			trpId: trpId,
			oldDateInput: oldDateInput,
			oldLogistics: oldLogistics,
			oldTracking: oldTracking,
			oldSendby: oldSendby,
			saving: false
		};

		var cancelBtn = document.getElementById('mn-dj-repair-return-cancel');
		var saveBtn = document.getElementById('mn-dj-repair-return-save');
		if (cancelBtn && !cancelBtn._mnDjReturnBound) {
			cancelBtn._mnDjReturnBound = true;
			cancelBtn.addEventListener('click', function () {
				closeRepairReturnOverlay(overlay);
			});
		}
		if (saveBtn && !saveBtn._mnDjReturnBound) {
			saveBtn._mnDjReturnBound = true;
			saveBtn.addEventListener('click', function () {
				var ctx = overlay._mnRepairReturnCtx;
				if (!ctx || ctx.saving) {
					return;
				}
				var newDate = String(inpDate.value).trim();
				var newLog = String(selLog.value).trim();
				var newTrack = String(inpTrack.value).trim();
				var newSend = String(inpSend.value).trim();
				if (newDate === '') {
					mnDjFieldAlert('กรุณาระบุวันที่ส่งคืน', 'warning');
					inpDate.focus();
					return;
				}
				if (newLog === '') {
					mnDjFieldAlert('กรุณาเลือกขนส่ง', 'warning');
					selLog.focus();
					return;
				}
				if (newSend === '') {
					mnDjFieldAlert('กรุณาระบุผู้ส่ง', 'warning');
					inpSend.focus();
					return;
				}
				ctx.saving = true;
				mnDjPostRepairReturnBundle({
					ts_id: ctx.tsId,
					trp_id: ctx.trpId,
					trp_send_date: newDate,
					trp_logistics: newLog,
					trp_tracking: newTrack,
					trp_sendby: newSend,
					old_trp_send_date: ctx.oldDateInput,
					old_trp_logistics: ctx.oldLogistics,
					old_trp_tracking: ctx.oldTracking,
					old_trp_sendby: ctx.oldSendby
				}).then(function (data) {
					ctx.saving = false;
					if (!data || !data.ok) {
						mnDjFieldAlert((data && data.error) ? data.error : 'บันทึกไม่สำเร็จ', 'danger');
						return;
					}
					closeRepairReturnOverlay(overlay);
					global.location.href = 'detail_job?ts_id=' + encodeURIComponent(String(ctx.tsId));
				}).catch(function () {
					ctx.saving = false;
					mnDjFieldAlert('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
				});
			});
		}

		if (!overlay._mnDjReturnOverlayClickBound) {
			overlay._mnDjReturnOverlayClickBound = true;
			overlay.addEventListener('click', function (e) {
				if (e.target === overlay) {
					closeRepairReturnOverlay(overlay);
				}
			});
		}

		overlay.classList.add('mn-modal-overlay--open');
		setTimeout(function () {
			inpDate.focus();
		}, 50);
	}

	mnDjDomReady(function () {
		document.addEventListener('click', function (e) {
			var t = e.target;
			if (!t) {
				return;
			}
			var returnBtn = t.closest ? t.closest('.mn-dj-repair-return-edit-btn') : null;
			if (returnBtn) {
				e.preventDefault();
				mnDjOpenRepairReturnEdit(returnBtn);
				return;
			}
			var qcEditBtn = t.closest ? t.closest('.mn-dj-repair-qc-edit-btn') : null;
			if (qcEditBtn) {
				e.preventDefault();
				mnDjOpenRepairQcEdit(qcEditBtn);
				return;
			}
			var maBtn = t.closest ? t.closest('.mn-dj-repair-ma-edit-btn') : null;
			if (maBtn) {
				e.preventDefault();
				mnDjOpenRepairMaEdit(maBtn);
				return;
			}
			var qvBtn = t.closest ? t.closest('.mn-dj-repair-qv-edit-btn') : null;
			if (qvBtn) {
				e.preventDefault();
				mnDjOpenRepairQvEdit(qvBtn);
				return;
			}
			var receiveBtn = t.closest ? t.closest('.mn-dj-repair-receive-edit-btn') : null;
			if (receiveBtn) {
				e.preventDefault();
				mnDjOpenRepairReceiveEdit(receiveBtn);
				return;
			}
			var spareBtn = t.closest ? t.closest('.mn-dj-spare-send-edit-btn') : null;
			if (spareBtn) {
				e.preventDefault();
				mnDjOpenSpareSendEdit(spareBtn);
				return;
			}
			var earlySpareBtn = t.closest ? t.closest('.mn-dj-spare-early-return-btn') : null;
			if (earlySpareBtn) {
				e.preventDefault();
				mnDjOpenSpareEarlyReturnEdit(earlySpareBtn);
				return;
			}
			var repairBtn = t.closest ? t.closest('.mn-dj-repair-machine-edit-btn') : null;
			if (!repairBtn) {
				return;
			}
			e.preventDefault();
			mnDjOpenRepairMachineEdit(repairBtn);
		});
	});

	global.mnDjOpenSpareSendEdit = mnDjOpenSpareSendEdit;
	global.mnDjOpenRepairReceiveEdit = mnDjOpenRepairReceiveEdit;
	global.mnDjOpenRepairQvEdit = mnDjOpenRepairQvEdit;
	global.mnDjOpenRepairMaEdit = mnDjOpenRepairMaEdit;
	global.mnDjOpenRepairQcEdit = mnDjOpenRepairQcEdit;
	global.mnDjOpenRepairReturnEdit = mnDjOpenRepairReturnEdit;
})(typeof window !== 'undefined' ? window : this);
