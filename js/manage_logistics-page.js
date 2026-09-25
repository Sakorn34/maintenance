/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `manage_logistics` — validation ฟอร์มด้วย modal, แสดง flash หลัง redirect
 */
(function (global) {
	'use strict';

	var CFG = global.MN_MANAGE_LOGISTICS || {};

	/**
	 * Purpose:
	 * แจ้งเตือนผู้ใช้ (modal กลาง mn)
	 *
	 * Parameters สำคัญ:
	 * - message: string
	 * - variant: string info|success|warning|danger
	 *
	 * Return value:
	 * - undefined
	 */
	function mnMlgAlert(message, variant) {
		var v = variant || 'info';
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(String(message), { variant: v });
		} else {
			global.alert(String(message));
		}
	}

	/**
	 * Purpose:
	 * validate ฟิลด์ก่อน submit
	 *
	 * Return value:
	 * - bool true เมื่อผ่าน
	 */
	function mnMlgValidateForm() {
		var nameEl = document.getElementById('lc_name');
		var urlEl = document.getElementById('lc_tracking_url');
		var name = nameEl ? String(nameEl.value || '').trim() : '';
		var url = urlEl ? String(urlEl.value || '').trim() : '';
		if (name === '') {
			mnMlgAlert('กรุณากรอกชื่อขนส่ง', 'warning');
			if (nameEl) {
				nameEl.focus();
			}
			return false;
		}
		if (name.length > 100) {
			mnMlgAlert('ชื่อขนส่งยาวเกิน 100 ตัวอักษร', 'warning');
			return false;
		}
		if (url !== '' && !/^https?:\/\//i.test(url)) {
			mnMlgAlert('ลิงก์ตรวจ tracking ต้องขึ้นต้นด้วย http:// หรือ https://', 'warning');
			if (urlEl) {
				urlEl.focus();
			}
			return false;
		}
		if (url.length > 500) {
			mnMlgAlert('ลิงก์ตรวจ tracking ยาวเกิน 500 ตัวอักษร', 'warning');
			return false;
		}
		return true;
	}

	/**
	 * Purpose:
	 * ผูก submit ฟอร์มและแสดง flash จาก query string
	 *
	 * Return value:
	 * - undefined
	 */
	function mnMlgInit() {
		var form = document.getElementById('mn-mlg-form');
		if (form) {
			form.addEventListener('submit', function (ev) {
				if (!mnMlgValidateForm()) {
					ev.preventDefault();
				}
			});
		}
		var flash = CFG.flash || '';
		var msg = CFG.flashMessage || '';
		if (flash === 'ok' && msg) {
			mnMlgAlert(msg, 'success');
		} else if (flash === 'error' && msg) {
			mnMlgAlert(msg, 'danger');
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', mnMlgInit);
	} else {
		mnMlgInit();
	}
})(typeof window !== 'undefined' ? window : this);
