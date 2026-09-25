/**
 * หน้า job_process — คัดลอกข้อความสถานะส่งลูกค้า + modal ต่ออายุเครื่องสำรอง (รอเครื่องซ่อม / เสนอราคาแล้ว / รออนุมัติซ่อม / รอซ่อม / รอเครื่องสำรอง)
 */
(function (global) {
	'use strict';

	function mnJpNotify(msg, variant, title) {
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(msg, {
				variant: variant || 'success',
				title: title || (variant === 'warning' ? 'คำแนะนำ' : 'สำเร็จ'),
			});
		} else if (typeof global.alert === 'function') {
			global.alert(msg);
		}
	}

	function mnJpFallbackCopy(el) {
		el.focus();
		el.select();

		try {
			return document.execCommand('copy');
		} catch (e2) {
			return false;
		}
	}

	/**
	 * Purpose:
	 * เขียนข้อความลงคลิปบอร์ด
	 *
	 * Parameters สำคัญ:
	 * - string text: ข้อความ
	 *
	 * Return value:
	 * - Promise พร้อม true/false
	 */
	function mnJpClipboardWrite(text) {
		if (
			global.navigator &&
			global.navigator.clipboard &&
			typeof global.navigator.clipboard.writeText === 'function'
		) {
			return global.navigator.clipboard.writeText(text).then(function () {
				return true;
			});
		}

		try {
			var ta = document.createElement('textarea');
			ta.value = text;
			ta.style.position = 'fixed';
			ta.style.opacity = '0';

			document.body.appendChild(ta);
			ta.focus();
			ta.select();

			var ok = document.execCommand('copy');
			document.body.removeChild(ta);

			return global.Promise.resolve(ok);
		} catch (e) {
			return global.Promise.resolve(false);
		}
	}

	/**
	 * Purpose:
	 * อ่าน config จาก `#mn-jp-activate-config`
	 *
	 * Return value:
	 * - object
	 */
	function mnJpActivateReadConfig() {
		var el = document.getElementById('mn-jp-activate-config');
		if (!el || !el.textContent) {
			return {};
		}
		try {
			return JSON.parse(el.textContent);
		} catch (e1) {
			return {};
		}
	}

	/**
	 * Purpose:
	 * แปลง Y-m-d เป็น d/m/Y สำหรับแสดงผล
	 *
	 * Parameters สำคัญ:
	 * - string ymd: วันที่ Y-m-d
	 *
	 * Return value:
	 * - string
	 */
	function mnJpFormatDateDisplayTh(ymd) {
		var s = String(ymd || '').trim();
		if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
			return s;
		}
		var p = s.split('-');

		return p[2] + '/' + p[1] + '/' + p[0];
	}

	/**
	 * Purpose:
	 * บวกจำนวนวันลงวันที่ Y-m-d
	 *
	 * Parameters สำคัญ:
	 * - string ymd: วันที่เริ่ม
	 * - number days: จำนวนวัน
	 *
	 * Return value:
	 * - string Y-m-d หรือ '' เมื่อไม่ถูกต้อง
	 */
	function mnJpAddDaysYmd(ymd, days) {
		var s = String(ymd || '').trim();
		if (!/^\d{4}-\d{2}-\d{2}$/.test(s)) {
			return '';
		}
		var n = parseInt(days, 10);
		if (isNaN(n) || n < 0) {
			n = 0;
		}
		var parts = s.split('-');
		var dt = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
		if (isNaN(dt.getTime())) {
			return '';
		}
		dt.setDate(dt.getDate() + n);
		var y = dt.getFullYear();
		var m = String(dt.getMonth() + 1);
		var d = String(dt.getDate());
		if (m.length < 2) {
			m = '0' + m;
		}
		if (d.length < 2) {
			d = '0' + d;
		}

		return y + '-' + m + '-' + d;
	}

	/**
	 * Purpose:
	 * ตั้งค่าช่องวันหมดอายุตาม config (อัตโนมัติหรือให้ผู้ใช้เลือก)
	 *
	 * Parameters สำคัญ:
	 * - object cfg: จาก `MN_JP_ACTIVATE` / JSON config
	 *
	 * Return value:
	 * - undefined
	 */
	function mnJpActivateApplyExpireField(cfg) {
		var expireInput = document.getElementById('mn-jp-activate-expire');
		var hintEl = document.getElementById('mn-jp-activate-expire-hint');
		var flowEl = document.getElementById('mn-jp-activate-flow');
		var titleEl = document.getElementById('mn-jp-activate-title');
		if (!expireInput) {
			return;
		}

		var flow = String((cfg && cfg.flow) || (flowEl ? flowEl.value : '') || 'inapprove').trim();
		if (flowEl) {
			flowEl.value = flow;
		}
		if (titleEl) {
			if (flow === 'wait_spare') {
				titleEl.textContent = 'ขอต่ออายุเครื่องสำรอง (รอเครื่องสำรอง)';
			} else if (flow === 'wait_hardware') {
				titleEl.textContent = 'ขอต่ออายุเครื่องสำรอง (รอเครื่องซ่อม)';
			} else if (flow === 'offer_price') {
				titleEl.textContent = 'ขอต่ออายุเครื่องสำรอง (เสนอราคาแล้ว)';
			} else if (flow === 'wait_repair') {
				titleEl.textContent = 'ขอต่ออายุเครื่องสำรอง (รอซ่อม)';
			} else {
				titleEl.textContent = 'เพิ่มรหัสต่ออายุเครื่องสำรอง';
			}
		}

		if (flow === 'offer_price') {
			var activateOp = String(cfg.activate_date_ymd || '').trim();
			var suggestedOp = String(cfg.suggested_expire_ymd || '').trim();
			var periodOp = parseInt(cfg.period_wait_check, 10);
			if (isNaN(periodOp)) {
				periodOp = 0;
			}
			expireInput.removeAttribute('max');
			if (activateOp !== '') {
				expireInput.setAttribute('min', activateOp);
			} else {
				expireInput.removeAttribute('min');
			}
			expireInput.value = suggestedOp;
			expireInput.readOnly = true;
			if (hintEl) {
				var prevOp = cfg.prev_expire_ymd
					? mnJpFormatDateDisplayTh(cfg.prev_expire_ymd)
					: '—';
				var actOp = activateOp ? mnJpFormatDateDisplayTh(activateOp) : '—';
				var expOp = suggestedOp ? mnJpFormatDateDisplayTh(suggestedOp) : '—';
				hintEl.textContent =
					'ระบบคำนวณอัตโนมัติ: หมดอายุก่อนหน้า ' +
					prevOp +
					' → เริ่มต่ออายุ ' +
					actOp +
					' + ' +
					periodOp +
					' วัน (period_wait_check) = หมดอายุ ' +
					expOp;
			}

			return;
		}

		if (flow === 'wait_repair') {
			var activateWr = String(cfg.activate_date_ymd || '').trim();
			var suggestedWr = String(cfg.suggested_expire_ymd || '').trim();
			var periodWr = parseInt(cfg.period_wait_qc, 10);
			if (isNaN(periodWr)) {
				periodWr = 0;
			}
			expireInput.removeAttribute('max');
			if (activateWr !== '') {
				expireInput.setAttribute('min', activateWr);
			} else {
				expireInput.removeAttribute('min');
			}
			expireInput.value = suggestedWr;
			expireInput.readOnly = true;
			if (hintEl) {
				var prevWr = cfg.prev_expire_ymd
					? mnJpFormatDateDisplayTh(cfg.prev_expire_ymd)
					: '—';
				var actWr = activateWr ? mnJpFormatDateDisplayTh(activateWr) : '—';
				var expWr = suggestedWr ? mnJpFormatDateDisplayTh(suggestedWr) : '—';
				hintEl.textContent =
					'ระบบคำนวณอัตโนมัติ: หมดอายุก่อนหน้า ' +
					prevWr +
					' → เริ่มต่ออายุ ' +
					actWr +
					' + ' +
					periodWr +
					' วัน (priod_wait_qc) = หมดอายุ ' +
					expWr;
			}

			return;
		}

		if (flow === 'wait_spare') {
			var activateYmd = String(cfg.activate_date_ymd || '').trim();
			var suggestedWs = String(cfg.suggested_expire_ymd || '').trim();
			var periodWs = parseInt(cfg.period_wait_spare, 10);
			if (isNaN(periodWs)) {
				periodWs = 0;
			}
			expireInput.removeAttribute('max');
			if (activateYmd !== '') {
				expireInput.setAttribute('min', activateYmd);
			} else {
				expireInput.removeAttribute('min');
			}
			expireInput.value = suggestedWs;
			expireInput.readOnly = true;
			if (hintEl) {
				var prevWs = cfg.prev_expire_ymd
					? mnJpFormatDateDisplayTh(cfg.prev_expire_ymd)
					: '—';
				var actWs = activateYmd ? mnJpFormatDateDisplayTh(activateYmd) : '—';
				var expWs = suggestedWs ? mnJpFormatDateDisplayTh(suggestedWs) : '—';
				hintEl.textContent =
					'ระบบคำนวณอัตโนมัติ: หมดอายุก่อนหน้า ' +
					prevWs +
					' → เริ่มต่ออายุ ' +
					actWs +
					' + ' +
					periodWs +
					' วัน (priod_wait_spare) = หมดอายุ ' +
					expWs;
			}

			return;
		}

		if (flow === 'wait_hardware') {
			var activateHw = String(cfg.activate_date_ymd || '').trim();
			var maxExpireHw = String(cfg.max_expire_ymd || '').trim();
			var suggestedHw = String(cfg.suggested_expire_ymd || maxExpireHw).trim();
			var maxDaysHw = parseInt(cfg.max_extension_days, 10);
			if (isNaN(maxDaysHw) || maxDaysHw < 1) {
				maxDaysHw = 7;
			}
			if (activateHw !== '') {
				expireInput.setAttribute('min', activateHw);
			} else {
				expireInput.removeAttribute('min');
			}
			if (maxExpireHw !== '') {
				expireInput.setAttribute('max', maxExpireHw);
			} else {
				expireInput.removeAttribute('max');
			}
			expireInput.readOnly = false;
			expireInput.value = suggestedHw;
			if (hintEl) {
				var actHwLabel = activateHw ? mnJpFormatDateDisplayTh(activateHw) : '—';
				var maxHwLabel = maxExpireHw ? mnJpFormatDateDisplayTh(maxExpireHw) : '—';
				hintEl.textContent =
					'วันที่ขอ ' +
					actHwLabel +
					' — เลือกวันหมดอายุได้ไม่เกิน ' +
					maxDaysHw +
					' วัน (สูงสุด ' +
					maxHwLabel +
					')';
			}

			return;
		}

		var baseYmd = String(cfg.base_date_ymd || cfg.suggested_expire_ymd || '').trim();
		var suggested = String(cfg.suggested_expire_ymd || baseYmd).trim();
		var period = parseInt(cfg.period_wait_approve, 10);
		if (isNaN(period)) {
			period = 0;
		}
		var auto = !!cfg.expire_auto || period > 0;

		expireInput.removeAttribute('max');
		if (baseYmd !== '') {
			expireInput.setAttribute('min', baseYmd);
		} else {
			expireInput.removeAttribute('min');
		}

		if (auto && period > 0) {
			var computed = mnJpAddDaysYmd(baseYmd, period);
			if (computed !== '') {
				suggested = computed;
			}
			expireInput.value = suggested;
			expireInput.readOnly = true;
			if (hintEl) {
				var baseLabel = mnJpFormatDateDisplayTh(baseYmd);
				hintEl.textContent =
					'ระบบคำนวณอัตโนมัติจากวันฐาน ' +
					baseLabel +
					' + ' +
					period +
					' วัน (ตั้งค่า priod_wait_approve)';
			}
		} else {
			expireInput.readOnly = false;
			expireInput.value = '';
			if (hintEl) {
				if (cfg.prev_expire_ymd) {
					hintEl.textContent =
						'อ้างอิงวันหมดอายุก่อนหน้า ' +
						mnJpFormatDateDisplayTh(cfg.prev_expire_ymd) +
						' — กรุณาเลือกวันที่หมดอายุใหม่';
				} else {
					hintEl.textContent = 'กรุณาเลือกวันที่หมดอายุเครื่องสำรอง';
				}
			}

			return;
		}
	}

	/**
	 * Purpose:
	 * ผูกปุ่มคัดลอกหลัง DOM พร้อม
	 *
	 * Return value:
	 * - undefined
	 */
	function mnJpShareInit() {
		var plainEl = document.getElementById('mn-jp-share-plain');
		var copyBtn = document.getElementById('mn-jp-share-copy');

		if (!plainEl || !copyBtn) {
			return;
		}

		var raw = '';
		if (typeof plainEl.value === 'string' && plainEl.value !== '') {
			raw = plainEl.value;
		} else {
			raw = plainEl.innerHTML.replace(/^\s+|\s+$/g, '');
		}

		if (raw === '') {
			return;
		}

		copyBtn.onclick = function () {
			function after(ok) {
				if (ok) {
					mnJpNotify(
						'คัดลอกข้อความแล้ว — เปิด LINE แล้ววาง (แชทถึงบุคคลหรือกลุ่ม) ได้ทันที',
						'success'
					);
					return;
				}

				if (mnJpFallbackCopy(plainEl)) {
					mnJpNotify('คัดลอกข้อความแล้ว', 'success');

					return;
				}

				mnJpNotify(
					'เบราว์เซอร์ไม่รองรับการคัดลอกอัตโนมัติ — เลือกที่ช่องข้อความแล้วกด Ctrl+C',
					'warning'
				);
			}

			var p = mnJpClipboardWrite(raw);
			if (p && typeof p.then === 'function') {
				p.then(after).catch(function () {
					after(mnJpFallbackCopy(plainEl));
				});
			}
		};
	}

	/**
	 * Purpose:
	 * เปิด/ปิด modal ต่ออายุเครื่องสำรอง
	 *
	 * Parameters สำคัญ:
	 * - boolean open: true = แสดง
	 *
	 * Return value:
	 * - undefined
	 */
	function mnJpActivateSetOpen(open) {
		var overlay = document.getElementById('mn-jp-activate-overlay');
		if (!overlay) {
			return;
		}
		if (open) {
			overlay.removeAttribute('hidden');
			overlay.classList.add('mn-modal-overlay--open');
			mnJpActivateApplyExpireField(mnJpActivateReadConfig());
			var nameEl = document.getElementById('mn-jp-activate-name');
			if (nameEl) {
				nameEl.focus();
			}
		} else {
			overlay.classList.remove('mn-modal-overlay--open');
			overlay.setAttribute('hidden', 'hidden');
		}
	}

	/**
	 * Purpose:
	 * ผูกปุ่ม + และฟอร์มบันทึกต่ออายุเครื่องสำรอง
	 *
	 * Return value:
	 * - undefined
	 */
	function mnJpActivateInit() {
		var addBtn = document.getElementById('mn-jp-activate-add');
		var overlay = document.getElementById('mn-jp-activate-overlay');
		var form = document.getElementById('mn-jp-activate-form');
		var cancelBtn = document.getElementById('mn-jp-activate-cancel');
		var expireInput = document.getElementById('mn-jp-activate-expire');

		if (!addBtn || !overlay || !form) {
			return;
		}

		addBtn.addEventListener('click', function () {
			mnJpActivateSetOpen(true);
		});

		if (cancelBtn) {
			cancelBtn.addEventListener('click', function () {
				mnJpActivateSetOpen(false);
			});
		}

		overlay.addEventListener('click', function (ev) {
			if (ev.target === overlay) {
				mnJpActivateSetOpen(false);
			}
		});

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();

			var cfg = mnJpActivateReadConfig();
			var flow = String((cfg && cfg.flow) || '').trim();
			var expireEl = document.getElementById('mn-jp-activate-expire');
			var autoExpireFlows = ['wait_spare', 'wait_hardware', 'offer_price', 'wait_repair'];
			var isAutoExpireFlow = autoExpireFlows.indexOf(flow) >= 0;
			if (expireEl && !isAutoExpireFlow && !expireEl.readOnly && String(expireEl.value || '').trim() === '') {
				mnJpNotify('กรุณาเลือกวันที่หมดอายุเครื่องสำรอง', 'warning', 'คำเตือน');
				expireEl.focus();

				return;
			}
			if (
				isAutoExpireFlow &&
				expireEl &&
				String(expireEl.value || '').trim() === '' &&
				flow !== 'wait_hardware'
			) {
				mnJpNotify('ไม่สามารถคำนวณวันหมดอายุได้ — ตรวจสอบประวัติต่ออายุก่อนหน้า', 'warning', 'คำเตือน');

				return;
			}
			if (flow === 'wait_hardware' && expireEl && String(expireEl.value || '').trim() === '') {
				mnJpNotify('กรุณาเลือกวันที่หมดอายุเครื่องสำรอง', 'warning', 'คำเตือน');
				expireEl.focus();

				return;
			}

			var submitBtn = document.getElementById('mn-jp-activate-submit');
			if (submitBtn) {
				submitBtn.disabled = true;
			}

			var body = new FormData(form);

			fetch('save_job_process_activate_spare', {
				method: 'POST',
				body: body,
				credentials: 'same-origin',
			})
				.then(function (res) {
					return res.json();
				})
				.then(function (data) {
					if (data && data.ok) {
						mnJpNotify(data.message || 'บันทึกแล้ว', 'success', 'สำเร็จ');
						global.location.reload();
						return;
					}
					mnJpNotify(
						(data && data.message) || 'บันทึกไม่สำเร็จ',
						'danger',
						'ไม่สำเร็จ'
					);
				})
				.catch(function () {
					mnJpNotify('ไม่สามารถติดต่อเซิร์ฟเวอร์ได้', 'danger', 'ข้อผิดพลาด');
				})
				.finally(function () {
					if (submitBtn) {
						submitBtn.disabled = false;
					}
				});
		});
	}

	function mnJpPageInit() {
		mnJpShareInit();
		mnJpActivateInit();
	}

	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', mnJpPageInit);
		} else {
			mnJpPageInit();
		}
	}
})(typeof window !== 'undefined' ? window : this);
