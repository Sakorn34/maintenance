/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกเครื่องสำรอง / อัปเดตสถานะ — validation ด้วย `mnModalAlert` และสร้าง query string ด้วย `encodeURIComponent`
 *
 * Flow:
 * ลิงก์ Save เรียก `save_spare()` / `status_spare()` จากหน้า `add_spare.php`
 */
(function () {
	/**
	 * Purpose:
	 * แจ้งเตือนแบบ modal — fallback `alert` ถ้า modal ยังไม่โหลด
	 *
	 * Parameters สำคัญ:
	 * - message: string
	 * - variant: string optional
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAlert(message, variant) {
		var v = variant || 'warning';
		if (typeof window.mnModalAlert === 'function') {
			window.mnModalAlert(message, { variant: v });
		} else {
			window.alert(message);
		}
	}

	/**
	 * Purpose:
	 * ตรวจฟอร์มเพิ่ม Spare แล้ว redirect ไป `insert_spare`
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.save_spare = function () {
		if (document.getElementById('sp_product_name').value === '') {
			mnAlert('กรุณาเลือก สินค้า', 'warning');
			document.getElementById('sp_product_name').focus();
			return;
		}
		if (document.getElementById('sp_sn').value === '') {
			mnAlert('กรุณากรอก S/N', 'warning');
			document.getElementById('sp_sn').focus();
			return;
		}

		var product = document.getElementById('sp_product_name').value;
		var model = '';
		if (product === 'Embedded') {
			model = 'Pro';
		} else if (product === 'bitVisitor Plus') {
			model = 'Plus';
		} else if (product === 'bitVisitor M' || product === 'Card Cam') {
			model = 'M';
		} else if (product === 'bitVisitor Lite' || product === 'Printer 57 mm.') {
			model = 'Lite';
		} else if (
			product === 'Printer 80 mm.' ||
			product === 'Scanner Wireless' ||
			product === 'Smart Card' ||
			product === 'Magnetic' ||
			product === 'bitStamp'
		) {
			model = 'Plus-M';
		} else if (product === 'Scanner' || product === 'Adapter' || product === 'Router' || product === 'FaceCam Indoor') {
			model = 'Plus-M-Lite';
		} else if (product === 'bitScan Pro' || product === 'bitScan Kit' || product === 'bitVehicle') {
			model = 'bitScan';
			product = 'bitScan';
		} else if (product === 'Adapter 5V') {
			model = 'Lite';
			product = 'Adapter 5V';
		}

		var pairs = [
			['sp_product_model', model],
			['sp_product_name', product],
			['sp_sn', document.getElementById('sp_sn').value],
			['sp_pickup_year', ''],
			['sp_status', '']
		];
		var qs = pairs
			.map(function (p) {
				return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
			})
			.join('&');
		window.location.href = 'insert_spare?' + qs;
	};

	/**
	 * Purpose:
	 * ตรวจฟอร์มแก้สถานะ Spare แล้ว redirect ไป `update_status_spare`
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.status_spare = function () {
		if (document.getElementById('sp_status').value === '') {
			mnAlert('กรุณาเลือก สถานะ', 'warning');
			document.getElementById('sp_status').focus();
			return;
		}
		if (document.getElementById('sp_sn_edit').value === '') {
			mnAlert('กรุณาระบุ S/N ที่ต้องการแก้ไข', 'warning');
			document.getElementById('sp_sn_edit').focus();
			return;
		}
		var pairs = [
			['sp_sn_edit', document.getElementById('sp_sn_edit').value],
			['sp_status', document.getElementById('sp_status').value]
		];
		var qs = pairs
			.map(function (p) {
				return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
			})
			.join('&');
		window.location.href = 'update_status_spare?' + qs;
	};
})();
