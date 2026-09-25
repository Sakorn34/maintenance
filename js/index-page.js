/**
 * หน้า index — ส่งฟอร์มเมื่อเปลี่ยน select หรือกด Enter ในช่องข้อความ
 */
(function (global) {
	'use strict';

	/**
	 * Purpose:
	 * ผูกเหตุการณ์ฟอร์มค้นหา index (ไม่ auto-submit ทุกตัวอักษร — ใช้ Enter หรือปุ่มค้นหา)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	function mnIndexInitSearchForm() {
		var form = document.getElementById('mn-index-search-form');
		if (!form) {
			return;
		}

		var textIds = ['s_number', 's_company', 's_report', 's_product_sn', 's_spare_sn'];
		var t;
		for (t = 0; t < textIds.length; t += 1) {
			var el = document.getElementById(textIds[t]);
			if (!el) {
				continue;
			}
			el.addEventListener('keydown', function (ev) {
				if (ev.keyCode === 13) {
					ev.preventDefault();
					form.submit();
				}
			});
		}

		var selProd = document.getElementById('s_product');
		if (selProd) {
			selProd.addEventListener('change', function () {
				form.submit();
			});
		}
		var selStat = document.getElementById('s_status');
		if (selStat) {
			selStat.addEventListener('change', function () {
				form.submit();
			});
		}
	}

	if (typeof document !== 'undefined') {
		if (document.readyState === 'loading') {
			document.addEventListener('DOMContentLoaded', mnIndexInitSearchForm);
		} else {
			mnIndexInitSearchForm();
		}
	}
})(typeof window !== 'undefined' ? window : this);
