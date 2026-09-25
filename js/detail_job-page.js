/**
 * หน้า detail_job — copy tracking / บันทึก comment / แก้ไขหัวใบงาน (ปุ่มส้ม → บันทึก+ยกเลิก) แล้ว POST ไป `update_transac_detail_header`
 * ใช้ mnModalAlert แทน alert เมื่อมี modal กลาง
 */
(function (global) {
	'use strict';

	// ─────────────────────────────────────────────────────────────────
	// helpers
	// ─────────────────────────────────────────────────────────────────

	/**
	 * Purpose:
	 * แจ้งเตือนสั้น ๆ
	 *
	 * Parameters สำคัญ:
	 * - message: string
	 *
	 * Return value:
	 * - undefined
	 */
	function mnDjAlert(message) {
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(String(message), { variant: 'info' });
		} else {
			global.alert(String(message));
		}
	}

	/**
	 * Purpose:
	 * อ่านค่า input ตาม id (ใช้ตอนประกอบฟอร์มบันทึกหัวใบงาน)
	 *
	 * Parameters สำคัญ:
	 * - id: string element id
	 *
	 * Return value:
	 * - string ค่า value หรือสตริงว่าง
	 */
	function mnDjFieldVal(id) {
		var el = document.getElementById(id);
		return el ? String(el.value) : '';
	}

	/** รายการ id ช่องหัวใบงานที่สลับ readonly / snapshot */
	var HEADER_FIELD_IDS = [
		'ts_date',
		'ts_enduser_com',
		'ts_security_com',
		'ts_report_name',
		'ts_report_tel',
		'ts_report_line',
		'ts_report_email',
		'ts_approve_name',
		'ts_approve_tel',
		'ts_approve_line',
		'ts_approve_email',
		'ts_address2',
		'ts_remarks'
	];

	/**
	 * Purpose:
	 * เก็บ snapshot ค่าช่องหัวใบงาน (ใช้ตอนยกเลิกเพื่อคืนค่าเดิม)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - object map id → string ค่า value
	 */
	function mnDjSnapshotHeaderFields() {
		var snap = {};
		var i;
		for (i = 0; i < HEADER_FIELD_IDS.length; i += 1) {
			var id = HEADER_FIELD_IDS[i];
			snap[id] = mnDjFieldVal(id);
		}
		return snap;
	}

	/**
	 * Purpose:
	 * คืนค่าช่องหัวใบงานจาก snapshot
	 *
	 * Parameters สำคัญ:
	 * - snap: object จาก `mnDjSnapshotHeaderFields()`
	 *
	 * Return value:
	 * - undefined
	 */
	function mnDjApplyHeaderSnapshot(snap) {
		var i;
		for (i = 0; i < HEADER_FIELD_IDS.length; i += 1) {
			var id = HEADER_FIELD_IDS[i];
			if (!Object.prototype.hasOwnProperty.call(snap, id)) {
				continue;
			}
			var el = document.getElementById(id);
			if (el) {
				el.value = snap[id];
			}
		}
	}

	/**
	 * Purpose:
	 * ตั้ง readonly ให้ช่องหัวใบงานที่แก้ผ่านปุ่มสลับโหมด
	 *
	 * Parameters สำคัญ:
	 * - ro: boolean true = ล็อกช่อง (อ่านอย่างเดียว)
	 *
	 * Return value:
	 * - undefined
	 */
	function mnDjSetHeaderFieldsReadonly(ro) {
		var i;
		for (i = 0; i < HEADER_FIELD_IDS.length; i += 1) {
			var el = document.getElementById(HEADER_FIELD_IDS[i]);
			if (!el) {
				continue;
			}
			if (ro) {
				el.setAttribute('readonly', 'readonly');
			} else {
				el.removeAttribute('readonly');
			}
		}
	}

	/**
	 * Purpose:
	 * ผูกปุ่มแก้ไข / บันทึก / ยกเลิกหัวใบงาน — แก้ไขเปิดช่องและแสดงบันทึก+ยกเลิก, ยกเลิกคืนค่าและปิดโหมดแก้ไข, บันทึกส่ง POST
	 *
	 * Parameters สำคัญ:
	 * - cfg: object จาก `window.MN_DETAIL_JOB` (tsId, headerEditLocked)
	 *
	 * Return value:
	 * - undefined
	 */
	function mnDjInitHeaderEdit(cfg) {
		if (!cfg || !cfg.tsId || cfg.headerEditLocked) {
			return;
		}
		var wrap = document.getElementById('mn-dj-header-actions-wrap');
		var editBtn = document.getElementById('mn-dj-header-edit-btn');
		var saveBtn = document.getElementById('mn-dj-header-save-btn');
		var cancelBtn = document.getElementById('mn-dj-header-cancel-btn');
		var actionsEl = document.getElementById('mn-dj-header-edit-actions');
		if (!wrap || !editBtn || !saveBtn || !cancelBtn || !actionsEl) {
			return;
		}
		var snapshot = null;

		function enterEditMode() {
			snapshot = mnDjSnapshotHeaderFields();
			mnDjSetHeaderFieldsReadonly(false);
			wrap.classList.add('mn-dj-header-actions-wrap--editing');
			actionsEl.setAttribute('aria-hidden', 'false');
		}

		function leaveEditMode() {
			if (snapshot) {
				mnDjApplyHeaderSnapshot(snapshot);
			}
			mnDjSetHeaderFieldsReadonly(true);
			wrap.classList.remove('mn-dj-header-actions-wrap--editing');
			actionsEl.setAttribute('aria-hidden', 'true');
			snapshot = null;
		}

		function submitHeaderForm() {
			var f = document.createElement('form');
			f.method = 'POST';
			f.action = 'update_transac_detail_header';
			function append(name, value) {
				var inp = document.createElement('input');
				inp.type = 'hidden';
				inp.name = name;
				inp.value = value;
				f.appendChild(inp);
			}
			append('ts_id', String(cfg.tsId));
			append('ts_date', mnDjFieldVal('ts_date'));
			append('ts_enduser_com', mnDjFieldVal('ts_enduser_com'));
			append('ts_security_com', mnDjFieldVal('ts_security_com'));
			append('ts_report_name', mnDjFieldVal('ts_report_name'));
			append('ts_report_tel', mnDjFieldVal('ts_report_tel'));
			append('ts_report_line', mnDjFieldVal('ts_report_line'));
			append('ts_report_email', mnDjFieldVal('ts_report_email'));
			append('ts_approve_name', mnDjFieldVal('ts_approve_name'));
			append('ts_approve_tel', mnDjFieldVal('ts_approve_tel'));
			append('ts_approve_line', mnDjFieldVal('ts_approve_line'));
			append('ts_approve_email', mnDjFieldVal('ts_approve_email'));
			append('ts_address', mnDjFieldVal('ts_address2'));
			append('ts_remarks', mnDjFieldVal('ts_remarks'));
			document.body.appendChild(f);
			f.submit();
		}

		editBtn.addEventListener('click', enterEditMode);
		cancelBtn.addEventListener('click', leaveEditMode);
		saveBtn.addEventListener('click', submitHeaderForm);
	}

	// copy_text / copy_tracking — จาก js/mn-logistics-tracking.js (URL ขนส่งจาก DB)

	/**
	 * Purpose:
	 * นำทางไป update_other_comment เพื่อบันทึก comment แถวซ่อม
	 *
	 * Parameters สำคัญ:
	 * - trpid: int trp_id
	 * - ts_id: int ts_id สำหรับ redirect
	 * - cm_id: string|number suffix id ของ textarea (เช่น 11)
	 *
	 * Return value:
	 * - undefined
	 */
	global.saveComment = function (trpid, ts_id, cm_id) {
		var ta = document.getElementById('trp_other_comment' + cm_id);
		var v = ta ? encodeURIComponent(ta.value) : '';
		global.location.href = 'update_other_comment?trp_id=' + encodeURIComponent(String(trpid))
			+ '&trp_other_comment=' + v
			+ '&ts_id=' + encodeURIComponent(String(ts_id));
	};

	// ─────────────────────────────────────────────────────────────────
	// bootstrap หน้า
	// ─────────────────────────────────────────────────────────────────

	function mnDjDomReady(fn) {
		if (typeof document === 'undefined') {
			return;
		}
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', fn);
		} else {
			fn();
		}
	}

	mnDjDomReady(function () {
		var cfg = global.MN_DETAIL_JOB || {};
		mnDjInitHeaderEdit(cfg);
		if (cfg.flashMessage) {
			mnDjAlert(cfg.flashMessage);
		}
	});
})(typeof window !== 'undefined' ? window : this);
