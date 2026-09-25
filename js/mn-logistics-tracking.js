/**
 * คลิกเลข tracking — copy ไป clipboard แล้วแสดง modal (ปุ่มเปิดหน้าเช็คพัสดุ + ตกลงปิด)
 */
(function (global) {
	'use strict';

	/**
	 * Purpose:
	 * copy สตริงลง clipboard
	 *
	 * Parameters สำคัญ:
	 * - text: string เลข tracking
	 *
	 * Return value:
	 * - undefined
	 */
	function mnCopyStringToClipboard(text) {
		var trackVal = String(text || '');
		if (global.navigator && global.navigator.clipboard && typeof global.navigator.clipboard.writeText === 'function') {
			global.navigator.clipboard.writeText(trackVal).catch(function () {
				// ignore
			});
			return;
		}
		var ta = document.createElement('textarea');
		ta.value = trackVal;
		ta.setAttribute('readonly', 'readonly');
		ta.style.position = 'fixed';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try {
			document.execCommand('copy');
		} catch (e1) {
			// ignore
		}
		document.body.removeChild(ta);
	}

	/**
	 * Purpose:
	 * อ่าน map ชื่อขนส่ง → URL จาก `window.MN_LOGISTICS_TRACKING` (ส่งจาก PHP / DB)
	 *
	 * Return value:
	 * - object<string,string>
	 */
	function mnGetLogisticsTrackingMap() {
		var raw = global.MN_LOGISTICS_TRACKING;
		if (!raw || typeof raw !== 'object') {
			return {};
		}
		return raw;
	}

	/**
	 * Purpose:
	 * หา URL track จากชื่อขนส่ง (ตรงตัวหรือไม่สนตัวพิมพ์เล็กใหญ่)
	 *
	 * Parameters สำคัญ:
	 * - logistic: string ชื่อขนส่ง
	 *
	 * Return value:
	 * - string URL หรือ '' ถ้าไม่มี mapping
	 */
	function mnResolveTrackingUrl(logistic) {
		var log = String(logistic || '').trim();
		if (log === '') {
			return '';
		}
		var map = mnGetLogisticsTrackingMap();
		if (map[log] && String(map[log]) !== '') {
			return String(map[log]);
		}
		var keys = Object.keys(map);
		var lower = log.toLowerCase();
		var i;
		for (i = 0; i < keys.length; i++) {
			if (String(keys[i]).toLowerCase() === lower && map[keys[i]]) {
				return String(map[keys[i]]);
			}
		}

		return '';
	}

	/**
	 * Purpose:
	 * แสดง modal แจ้ง copy สำเร็จ — มีปุ่มเปิดหน้าเช็คพัสดุ (ถ้ามี URL) และปุ่มตกลงปิด popup
	 *
	 * Parameters สำคัญ:
	 * - logistic: string ชื่อขนส่ง
	 * - trackVal: string เลข tracking ที่ copy แล้ว
	 *
	 * Return value:
	 * - undefined
	 */
	function mnTrackingCopySuccessModal(logistic, trackVal) {
		var logName = String(logistic || '').trim();
		var track = String(trackVal || '').trim();
		var url = mnResolveTrackingUrl(logistic);
		var msg = 'คัดลอกเลขพัสดุแล้ว';
		if (logName !== '') {
			msg += '\n\nขนส่ง: ' + logName;
		}
		if (track !== '') {
			msg += '\nTracking: ' + track;
		}
		var opts = {
			variant: 'success',
			title: 'คัดลอกสำเร็จ'
		};
		if (url !== '') {
			opts.extraAction = {
				label: 'เปิดหน้าเว็บเช็คพัสดุ',
				onClick: function () {
					global.open(url, '_blank', 'noopener,noreferrer');
				}
			};
		}
		if (typeof global.mnModalAlert === 'function') {
			global.mnModalAlert(msg, opts);
		} else {
			global.alert(msg);
			if (url !== '' && global.confirm('เปิดหน้าเว็บเช็คพัสดุ?')) {
				global.open(url, '_blank', 'noopener,noreferrer');
			}
		}
	}

	/**
	 * Purpose:
	 * copy เลข tracking แล้วแสดง modal (ไม่ใช้ input — สำหรับ job_process)
	 *
	 * Parameters สำคัญ:
	 * - tracking: string เลข tracking
	 * - logistic: string ชื่อขนส่ง
	 *
	 * Return value:
	 * - undefined
	 */
	global.copy_tracking = function (tracking, logistic) {
		var trackVal = String(tracking || '');
		mnCopyStringToClipboard(trackVal);
		mnTrackingCopySuccessModal(logistic, trackVal);
	};

	/**
	 * Purpose:
	 * copy จาก input tracking แล้วแสดง modal
	 *
	 * Parameters สำคัญ:
	 * - id: string id ของ input ที่มีเลข tracking
	 * - logistic: string ชื่อขนส่ง
	 *
	 * Return value:
	 * - undefined
	 */
	global.copy_text = function (id, logistic) {
		var el = document.getElementById(id);
		if (!el) {
			return;
		}
		var trackVal = String(el.value || '');
		mnCopyStringToClipboard(trackVal);
		mnTrackingCopySuccessModal(logistic, trackVal);
	};
})(typeof window !== 'undefined' ? window : this);
