/**
 * maintenance — แก้ไขที่อยู่จัดส่ง (Transac.ts_address) ผ่าน modal + POST `update_transac_address`
 * ใช้ร่วมกับ `status_logistic.php`, `detail_job.php` (โหลดหลัง `mn-modal.js`)
 */
(function (global) {
	'use strict';

	/**
	 * Purpose:
	 * แจ้งเตือนสั้น ๆ ก่อน/ระหว่างแก้ที่อยู่
	 *
	 * Parameters สำคัญ:
	 * - message: string ข้อความ
	 *
	 * Return value:
	 * - undefined
	 */
	function mnTaAlert(message) {
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(String(message), { variant: 'warning' });
		} else {
			global.alert(String(message));
		}
	}

	/**
	 * Purpose:
	 * ส่ง POST ไป `update_transac_address` พร้อม scope / หน้ากลับ
	 *
	 * Parameters สำคัญ:
	 * - tsId: int รหัส Transac
	 * - addressText: string ค่า ts_address
	 * - opts: { scope?: string, returnTarget?: string } — scope `logistic` (ค่า default) หรือ `detail`; returnTarget `status_logistic` หรือ `detail_job`
	 *
	 * Return value:
	 * - undefined
	 */
	function submitTransacAddress(tsId, addressText, opts) {
		opts = opts || {};
		var scope = opts.scope === 'detail' ? 'detail' : 'logistic';
		var ret = opts.returnTarget === 'detail_job' ? 'detail_job' : 'status_logistic';
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = 'update_transac_address';
		var i1 = document.createElement('input');
		i1.type = 'hidden';
		i1.name = 'ts_id';
		i1.value = String(tsId);
		var i2 = document.createElement('input');
		i2.type = 'hidden';
		i2.name = 'ts_address';
		i2.value = addressText != null ? String(addressText) : '';
		var i3 = document.createElement('input');
		i3.type = 'hidden';
		i3.name = 'mn_address_scope';
		i3.value = scope;
		var i4 = document.createElement('input');
		i4.type = 'hidden';
		i4.name = 'mn_return';
		i4.value = ret;
		form.appendChild(i1);
		form.appendChild(i2);
		form.appendChild(i3);
		form.appendChild(i4);
		document.body.appendChild(form);
		form.submit();
	}

	/**
	 * Purpose:
	 * เปิด modal แก้ไข ts_address แล้วบันทึกผ่าน POST
	 *
	 * Parameters สำคัญ:
	 * - tsId: int รหัส Transac
	 * - initialAddress: string ค่า ts_address จากฐานข้อมูล
	 * - opts: optional — `{ scope: 'detail', returnTarget: 'detail_job' }` สำหรับหน้า detail_job; ถ้าไม่ส่งใช้โหมดรอส่งพัสดุ
	 *
	 * Return value:
	 * - undefined
	 */
	global.editAddress = function (tsId, initialAddress, opts) {
		opts = opts || {};
		var scope = opts.scope === 'detail' ? 'detail' : 'logistic';
		var returnTarget = opts.returnTarget === 'detail_job' ? 'detail_job' : 'status_logistic';
		if (!tsId) {
			mnTaAlert('ไม่พบรหัสใบงาน');
			return;
		}
		var init = initialAddress != null ? String(initialAddress) : '';
		if (typeof global.mnModalPrompt !== 'function') {
			var v = global.prompt('แก้ไขที่อยู่จัดส่ง (ts_address)', init);
			if (v === null) {
				return;
			}
			submitTransacAddress(tsId, v, { scope: scope, returnTarget: returnTarget });
			return;
		}
		global.mnModalPrompt({
			title: 'แก้ไขที่อยู่จัดส่ง',
			hint: 'บันทึกลง Transac.ts_address',
			initialValue: init,
			rows: 8,
			fieldLabel: 'ที่อยู่จัดส่ง',
			okLabel: 'บันทึก',
			variant: 'info',
			onConfirm: function (value) {
				submitTransacAddress(tsId, value, { scope: scope, returnTarget: returnTarget });
			}
		});
	};
})(typeof window !== 'undefined' ? window : this);
