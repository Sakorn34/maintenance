/**
 * หน้า status_logistic — แก้ไขแถว logistics / SN สำรอง (logic จาก old_system/status_logistic.php)
 * ใช้ mnModalAlert / mnModalConfirm แทน alert / confirm; URL ปลายทางไม่มีนามสกุล .php
 */
(function (global) {
	'use strict';

	var status_edit = false;
	var temp_snedit = '';

	/**
	 * Purpose:
	 * แสดงข้อความแจ้งเตือน (modal กลางหรือ fallback alert)
	 *
	 * Parameters สำคัญ:
	 * - message: string ข้อความ
	 *
	 * Return value:
	 * - undefined
	 */
	function mnSlAlert(message) {
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(String(message), { variant: 'warning' });
		} else {
			global.alert(String(message));
		}
	}

	/**
	 * Purpose:
	 * แสดง modal ยืนยันก่อนบันทึก
	 *
	 * Parameters สำคัญ:
	 * - message: string ข้อความ
	 * - onOk: function เรียกเมื่อยืนยัน
	 *
	 * Return value:
	 * - undefined
	 */
	function mnSlConfirm(message, onOk) {
		if (typeof global.mnModalConfirm === 'function') {
			global.mnModalConfirm(String(message), {
				title: 'ยืนยัน',
				variant: 'info',
				onConfirm: onOk,
				onCancel: function () {}
			});
		} else if (global.confirm(String(message))) {
			onOk();
		}
	}

	/**
	 * Purpose:
	 * hover แถวตาราง (พื้นหลังชั่วคราว)
	 *
	 * Parameters สำคัญ:
	 * - row: HTMLElement แถว tr
	 * - on: boolean true เมื่อ mouseover
	 *
	 * Return value:
	 * - undefined
	 */
	global.mnStatusLogisticHover = function (row, on) {
		if (!row) {
			return;
		}
		row.style.backgroundColor = on ? '#f0f9ff' : '#fff';
	};

	global.cancelStatus = function () {
		global.location.reload();
	};

	global.editStatus = function (id, IDRow) {
		if (status_edit === true) {
			mnSlAlert('ยกเลิกรายการก่อนหน้า');
			return;
		}
		status_edit = true;
		document.getElementById('iconedit' + IDRow).style.display = 'none';
		document.getElementById('iconcancel' + IDRow).style.display = '';
		document.getElementById('iconsave' + IDRow).style.display = '';

		document.getElementById('logis_date' + IDRow).disabled = false;
		document.getElementById('logis_logistics' + IDRow).disabled = false;
		document.getElementById('logis_tracking' + IDRow).disabled = false;

		var property = document.getElementById('logis_tracking' + IDRow);
		property.style.backgroundColor = 'rgba(238, 130, 238, 0.5)';
	};

	global.saveStatus = function (id, IDRow) {
		if (document.getElementById('logis_logistics' + IDRow).value === '') {
			mnSlAlert('กรุณาเลือกขนส่ง');
			document.getElementById('logis_logistics' + IDRow).focus();
			return;
		}
		if (document.getElementById('logis_tracking' + IDRow).value === '') {
			mnSlAlert('กรุณาระบุหมายเลข Tracking');
			document.getElementById('logis_tracking' + IDRow).focus();
			return;
		}
		if (document.getElementById('logis_sendby' + IDRow).value === '') {
			mnSlAlert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ');
			document.getElementById('logis_logistics' + IDRow).focus();
			return;
		}

		mnSlConfirm('คุณต้องการบันทึกข้อมูลหรือไม่', function () {
			var property = document.getElementById('logis_tracking' + IDRow);
			property.style.backgroundColor = 'rgba(240, 240, 240, 0.5)';

			document.getElementById('logis_date' + IDRow).disabled = true;
			document.getElementById('logis_logistics' + IDRow).disabled = true;
			document.getElementById('logis_tracking' + IDRow).disabled = true;

			var date = document.getElementById('logis_date' + IDRow).value;
			var logistics = document.getElementById('logis_logistics' + IDRow).value;
			var tracking = document.getElementById('logis_tracking' + IDRow).value;
			var sendby = document.getElementById('logis_sendby' + IDRow).value;

			var data_update = 'id=' + encodeURIComponent(id)
				+ '&logis_date=' + encodeURIComponent(date)
				+ '&logis_logistics=' + encodeURIComponent(logistics)
				+ '&logis_tracking=' + encodeURIComponent(tracking)
				+ '&logis_sendby=' + encodeURIComponent(sendby);
			global.location.href = 'update_logistic?' + data_update;
		});
	};

	global.editSNSpare = function (IDRow) {
		if (temp_snedit !== '') {
			document.getElementById('pd_spareNew' + temp_snedit).value = document.getElementById('pd_spareOld' + temp_snedit).value;
			document.getElementById('pd_spareNew' + temp_snedit).disabled = true;
			document.getElementById('editSN' + temp_snedit).style.display = '';
			document.getElementById('cancelSN' + temp_snedit).style.display = 'none';
			document.getElementById('saveSN' + temp_snedit).style.display = 'none';
		}
		temp_snedit = IDRow;
		document.getElementById('pd_spareNew' + IDRow).disabled = false;
		document.getElementById('editSN' + IDRow).style.display = 'none';
		document.getElementById('cancelSN' + IDRow).style.display = '';
		document.getElementById('saveSN' + IDRow).style.display = '';
	};

	global.saveSNSpare = function (tsp_id, IDRow, trp_id) {
		if (document.getElementById('pd_spareOld' + IDRow).value === document.getElementById('pd_spareNew' + IDRow).value) {
			document.getElementById('pd_spareNew' + IDRow).disabled = true;
			document.getElementById('editSN' + IDRow).style.display = '';
			document.getElementById('cancelSN' + IDRow).style.display = 'none';
			document.getElementById('saveSN' + IDRow).style.display = 'none';
		} else {
			var snNew = document.getElementById('pd_spareNew' + IDRow).value;
			var snOld = document.getElementById('pd_spareOld' + IDRow).value;
			var trpEl = document.getElementById('tspTrpId' + IDRow);
			var tsEl = document.getElementById('tspTsId' + IDRow);
			var prodEl = document.getElementById('tspProduct' + IDRow);
			var trp = trp_id != null ? parseInt(trp_id, 10) : 0;
			if (isNaN(trp) || trp <= 0) {
				trp = trpEl ? parseInt(trpEl.value, 10) : 0;
			}
			if (isNaN(trp)) {
				trp = 0;
			}
			var tsId = tsEl ? parseInt(tsEl.value, 10) : 0;
			if (isNaN(tsId)) {
				tsId = 0;
			}
			var product = prodEl ? prodEl.value : '';
			global.location.href = 'update_change_spare?tsp_id=' + encodeURIComponent(tsp_id)
				+ '&trp_id=' + encodeURIComponent(trp)
				+ '&tsp_ts_id=' + encodeURIComponent(tsId)
				+ '&tsp_product=' + encodeURIComponent(product)
				+ '&tsp_snNew=' + encodeURIComponent(snNew)
				+ '&tsp_snOld=' + encodeURIComponent(snOld);
		}
	};

	global.editStatusR = function (id, IDRow) {
		if (status_edit === true) {
			mnSlAlert('ยกเลิกรายการก่อนหน้า');
			return;
		}
		status_edit = true;
		document.getElementById('riconedit' + IDRow).style.display = 'none';
		document.getElementById('riconcancel' + IDRow).style.display = '';
		document.getElementById('riconsave' + IDRow).style.display = '';

		document.getElementById('trp_send_date' + IDRow).disabled = false;
		document.getElementById('trp_logistics' + IDRow).disabled = false;
		document.getElementById('trp_tracking' + IDRow).disabled = false;

		var property = document.getElementById('trp_tracking' + IDRow);
		property.style.backgroundColor = 'rgba(238, 130, 238, 0.5)';
	};

	global.saveStatusR = function (id, IDRow) {
		if (document.getElementById('trp_logistics' + IDRow).value === '') {
			mnSlAlert('กรุณาเลือกขนส่ง');
			document.getElementById('trp_logistics' + IDRow).focus();
			return;
		}
		if (document.getElementById('trp_tracking' + IDRow).value === '') {
			mnSlAlert('กรุณาระบุหมายเลข Tracking');
			document.getElementById('trp_tracking' + IDRow).focus();
			return;
		}
		if (document.getElementById('trp_sendby' + IDRow).value === '') {
			mnSlAlert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ');
			document.getElementById('trp_logistics' + IDRow).focus();
			return;
		}

		mnSlConfirm('คุณต้องการบันทึกข้อมูลหรือไม่', function () {
			var property = document.getElementById('trp_tracking' + IDRow);
			property.style.backgroundColor = 'rgba(240, 240, 240, 0.5)';

			document.getElementById('trp_send_date' + IDRow).disabled = true;
			document.getElementById('trp_logistics' + IDRow).disabled = true;
			document.getElementById('trp_tracking' + IDRow).disabled = true;

			var trp_send_date = document.getElementById('trp_send_date' + IDRow).value;
			var trp_logistics = document.getElementById('trp_logistics' + IDRow).value;
			var trp_tracking = document.getElementById('trp_tracking' + IDRow).value;
			var trp_sendby = document.getElementById('trp_sendby' + IDRow).value;

			var data_update = 'id=' + encodeURIComponent(id)
				+ '&trp_send_date=' + encodeURIComponent(trp_send_date)
				+ '&trp_logistics=' + encodeURIComponent(trp_logistics)
				+ '&trp_tracking=' + encodeURIComponent(trp_tracking)
				+ '&trp_sendby=' + encodeURIComponent(trp_sendby);
			global.location.href = 'update_logistic_repair?' + data_update;
		});
	};
})(typeof window !== 'undefined' ? window : this);
