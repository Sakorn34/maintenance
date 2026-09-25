/**
 * วัตถุประสงค์และขอบเขต:
 * กรองหน้า `status_onsite` ตามที่อยู่หรือ product — สร้าง URL ด้วย `encodeURIComponent`
 */
(function () {
	/**
	 * Purpose:
	 * นำทางตามค่า `#search_addr` (Enter หรือเรียกจากภายนอก)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.sort_addr = function () {
		var el = document.getElementById('search_addr');
		if (!el) {
			return;
		}
		var v = el.value;
		if (v === 'All' || v === '') {
			window.location.href = 'status_onsite';
		} else {
			window.location.href = 'status_onsite?addr=' + encodeURIComponent(v);
		}
	};

	/**
	 * Purpose:
	 * นำทางตาม product ที่เลือกใน dropdown
	 *
	 * Parameters สำคัญ:
	 * - prod: string
	 *
	 * Return value:
	 * - undefined
	 */
	window.sort_product = function (prod) {
		if (prod === 'All' || prod === '') {
			window.location.href = 'status_onsite';
		} else {
			window.location.href = 'status_onsite?product=' + encodeURIComponent(prod);
		}
	};

	if (typeof window.jQuery !== 'undefined') {
		jQuery(document).ready(function ($) {
			$('#search_addr').keyup(function (event) {
				if (event.keyCode === 13) {
					sort_addr();
				}
			});
		});
	}
})();
