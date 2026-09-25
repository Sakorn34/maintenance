/**
 * วัตถุประสงค์และขอบเขต:
 * Logic ฟอร์มเพิ่มรายการยืม — validation แบบ modal, AJAX ค้นหาบริษัท/ตำบล, นำทางไป `insert_borrow`
 * อ่าน URL จาก `window.MN_ADD_BORROW` (ตั้งใน `add_borrow.php` จาก `includes/add_borrow_endpoints.php`)
 *
 * Flow:
 * โหลดหลัง jQuery + `mn-modal.js` — เรียก `saveBorrow()` จากลิงก์ Save
 */
(function () {
	var EP = window.MN_ADD_BORROW || {};

	/**
	 * Purpose:
	 * แจ้งเตือนแบบ modal กลาง — fallback เป็น `alert` ถ้า modal ยังไม่โหลด
	 *
	 * Parameters สำคัญ:
	 * - message: string
	 * - variant: string optional — `'info'|'success'|'warning'|'danger'`
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

	function urlInsertBorrow() {
		return EP.insertBorrow || 'insert_borrow';
	}

	function urlSearchCusname() {
		return EP.searchCusname || 'search_cusname';
	}

	function urlSearchCusnameSecurity() {
		return EP.searchCusnameSecurity || 'search_cusname_security';
	}

	function urlSearchAddress() {
		return EP.searchAddress || 'search_address';
	}

	/**
	 * Purpose:
	 * ตรวจฟอร์มแล้ว redirect ไป `insert_borrow` พร้อม query string ที่ encode แล้ว
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.saveBorrow = function () {
		if (document.getElementById('b_customer_group1').checked !== true && document.getElementById('b_customer_group2').checked !== true) {
			mnAlert('กรุณาเลือก ประเภทลูกค้า', 'warning');
			document.getElementById('b_customer_group1').focus();
			return;
		}
		if (document.getElementById('b_customer_group1').checked === true && document.getElementById('b_enduser_com').value === '') {
			mnAlert('กรุณาระบุ ชื่อบริษัท Enduser', 'warning');
			document.getElementById('b_enduser_com').focus();
			return;
		}
		if (document.getElementById('b_customer_group2').checked === true) {
			if (document.getElementById('b_enduser_com').value === '') {
				mnAlert('กรุณาระบุ ชื่อบริษัท ผู้ว่าจ้าง', 'warning');
				document.getElementById('b_enduser_com').focus();
				return;
			}
			if (document.getElementById('b_security_com').value === '') {
				mnAlert('กรุณาระบุ ชื่อบริษัท รปภ.', 'warning');
				document.getElementById('b_security_com').focus();
				return;
			}
		}

		if (document.getElementById('b_user_receive').value === '') {
			mnAlert('ไม่พบชื่อผู้รับเรื่องจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
			document.getElementById('b_date').focus();
			return;
		}
		if (document.getElementById('b_borrow_name').value === '') {
			mnAlert('กรุณาระบุ ผู้ยืม', 'warning');
			document.getElementById('b_borrow_name').focus();
			return;
		}
		if (document.getElementById('b_borrow_hardware').value === '') {
			mnAlert('กรุณาระบุ รายละเอียดอุปกรณ์ที่ยืม', 'warning');
			document.getElementById('b_borrow_hardware').focus();
			return;
		}

		var b_status = '';
		if (document.getElementById('b_send_group1').checked === true) {
			b_status = '1-รอส่งพัสดุ';
			if (document.getElementById('b_address1').value === '') {
				mnAlert('กรุณาระบุ เลขที่ ส่งพัสดุ', 'warning');
				document.getElementById('b_address1').focus();
				return;
			}
			if (document.getElementById('b_address2').value === '') {
				mnAlert('กรุณาระบุ ที่อยู่ ส่งพัสดุ', 'warning');
				document.getElementById('b_address2').focus();
				return;
			}
		} else if (document.getElementById('b_send_group2').checked === true) {
			b_status = '2-รอส่งคืน';
		}

		var b_address =
			'ผู้รับ ' +
			document.getElementById('b_address_name').value +
			' ' +
			document.getElementById('b_address_tel').value +
			' ' +
			document.getElementById('b_address1').value +
			document.getElementById('b_address2').value;

		var pairs = [
			['b_date', document.getElementById('b_date').value],
			['b_user_receive', document.getElementById('b_user_receive').value],
			['b_enduser_com', document.getElementById('b_enduser_com').value],
			['b_security_com', document.getElementById('b_security_com').value],
			['b_borrow_name', document.getElementById('b_borrow_name').value],
			['b_borrow_tel', document.getElementById('b_borrow_tel').value],
			['b_borrow_line', document.getElementById('b_borrow_line').value],
			['b_borrow_email', document.getElementById('b_borrow_email').value],
			['b_address', b_address],
			['b_borrow_hardware', document.getElementById('b_borrow_hardware').value],
			['b_status', b_status]
		];
		var qs = pairs
			.map(function (p) {
				return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
			})
			.join('&');
		window.location.href = urlInsertBorrow() + '?' + qs;
	};

	/**
	 * Purpose:
	 * สลับโหมดลูกค้า End user / บริษัท รปภ.
	 *
	 * Parameters สำคัญ:
	 * - mode: string `'enduser'` | `'security'`
	 *
	 * Return value:
	 * - undefined
	 */
	window.add_hideCustomer = function (mode) {
		if (mode === 'enduser') {
			document.getElementById('b_enduser_com').value = '';
			document.getElementById('b_security_com').value = '';
			document.getElementById('b_enduser1').style.display = '';
			document.getElementById('b_enduser2').style.display = 'none';
			document.getElementById('b_enduser_com').style.display = '';
			document.getElementById('b_security').style.display = 'none';
			document.getElementById('b_security_com').style.display = 'none';
		} else if (mode === 'security') {
			document.getElementById('b_enduser_com').value = '';
			document.getElementById('b_security_com').value = '';
			document.getElementById('b_enduser1').style.display = 'none';
			document.getElementById('b_enduser2').style.display = '';
			document.getElementById('b_enduser_com').style.display = '';
			document.getElementById('b_security').style.display = '';
			document.getElementById('b_security_com').style.display = '';
		}
	};

	/**
	 * Purpose:
	 * สลับโหมดส่งพัสดุ / Walk in — เคลียร์ช่องที่อยู่และแสดงแถวที่อยู่เฉพาะโหมดส่งพัสดุ
	 *
	 * Parameters สำคัญ:
	 * - mode: string `'walkin'` | `'parcel'`
	 *
	 * Return value:
	 * - undefined
	 */
	window.add_hideSend = function (mode) {
		document.getElementById('b_address_name').value = '';
		document.getElementById('b_address_tel').value = '';
		document.getElementById('b_address1').value = '';
		document.getElementById('b_address2').value = '';
		if (mode === 'walkin') {
			document.getElementById('row_receive').style.display = '';
			document.getElementById('row_address').style.display = 'none';
		} else if (mode === 'parcel') {
			document.getElementById('row_receive').style.display = '';
			document.getElementById('row_address').style.display = '';
		}
	};

	/**
	 * Purpose:
	 * เติมชื่อผู้รับพัสดุจากชื่อผู้ยืม
	 *
	 * Parameters สำคัญ:
	 * - val: string
	 *
	 * Return value:
	 * - undefined
	 */
	window.autochangeAdd_name = function (val) {
		document.getElementById('b_address_name').value = val;
	};

	/**
	 * Purpose:
	 * เติมเบอร์ผู้รับพัสดุจากเบอร์ผู้ยืม
	 *
	 * Parameters สำคัญ:
	 * - val: string
	 *
	 * Return value:
	 * - undefined
	 */
	window.autochangeAdd_tel = function (val) {
		document.getElementById('b_address_tel').value = val;
	};

	$(document).ready(function () {
		$('.search-box-user input[type="text"]').on('keyup input', function () {
			var inputVal = $(this).val();
			var resultDropdown = $(this).siblings('.result');
			if (inputVal.length) {
				$.get(urlSearchCusname(), { term: inputVal }).done(function (data) {
					resultDropdown.html(data);
				});
			} else {
				resultDropdown.empty();
			}
		});
		$(document).on('click', '.result p', function () {
			$(this).parents('.search-box-user').find('input[type="text"]').val($(this).text());
			$(this).parent('.result').empty();
		});

		$('.search-box-sec input[type="text"]').on('keyup input', function () {
			var inputVal = $(this).val();
			var resultDropdown = $(this).siblings('.result_sec');
			if (inputVal.length) {
				$.get(urlSearchCusnameSecurity(), { term: inputVal }).done(function (data) {
					resultDropdown.html(data);
				});
			} else {
				resultDropdown.empty();
			}
		});
		$(document).on('click', '.result_sec p', function () {
			$(this).parents('.search-box-sec').find('input[type="text"]').val($(this).text());
			$(this).parent('.result_sec').empty();
		});

		$('.search-box-tambon input').on('keyup input', function () {
			var inputVal = $(this).val();
			var resultDropdown = $(this).siblings('.result_tambon');
			if (inputVal.length) {
				$.get(urlSearchAddress(), { term: inputVal }).done(function (data) {
					resultDropdown.html(data);
				});
			} else {
				resultDropdown.empty();
			}
		});
		$(document).on('click', '.result_tambon p', function () {
			$(this).parents('.search-box-tambon').find('input').val($(this).text());
			$(this).parent('.result_tambon').empty();
		});
	});
})();
