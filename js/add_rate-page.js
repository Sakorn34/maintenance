/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มรายการซ่อม (`add_rate`) — validation ด้วย modal, สลับช่อง product ใหม่, สร้าง URL ไป `insert_rate` ด้วย `encodeURIComponent`
 * เลือกอุปกรณ์ใน `#r_product` จะดึงรายการ rate แสดงใน `#mn-add-rate-catalog-live` ผ่าน `add_rate_rates_json` (ไม่รีเฟรชหน้า)
 */
(function () {
	var EP = window.MN_ADD_RATE || {};

	function mnAlert(message, variant) {
		var v = variant || 'warning';
		if (typeof window.mnModalAlert === 'function') {
			window.mnModalAlert(message, { variant: v });
		} else {
			window.alert(message);
		}
	}

	function urlInsertRate() {
		return EP.insertRate || 'insert_rate';
	}

	/**
	 * Purpose:
	 * path ของ API JSON รายการ rate ต่ออุปกรณ์ (อ่านจาก `MN_ADD_RATE.ratesJson`)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - string path ไม่มีนามสกุล `.php`
	 */
	function urlRatesJson() {
		return EP.ratesJson || 'add_rate_rates_json';
	}

	function mnAddRateClearNode(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
	}

	/**
	 * Purpose:
	 * แสดงข้อความ hint ใน `#mn-add-rate-catalog-live` จาก `data-hint`
	 *
	 * Parameters สำคัญ:
	 * - HTMLElement root: `#mn-add-rate-catalog-live`
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddRateShowHint(root) {
		mnAddRateClearNode(root);
		var p = document.createElement('p');
		p.className = 'mn-add-rate-hint';
		p.textContent = root.getAttribute('data-hint') || '';
		root.appendChild(p);
	}

	/**
	 * Purpose:
	 * แสดงการ์ดรายการซ่อม (ชื่ออุปกรณ์ + list) ใน root — สร้าง DOM ด้วย `textContent` ก XSS
	 *
	 * Parameters สำคัญ:
	 * - HTMLElement root: container ว่างหรือจะถูก clear ก่อน
	 * - string product: ชื่ออุปกรณ์
	 * - Array rates: รายการ string
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddRateRenderCard(root, product, rates) {
		mnAddRateClearNode(root);
		var grid = document.createElement('div');
		grid.className = 'mn-add-rate-catalog-grid mn-add-rate-catalog-grid--single mn-add-rate-catalog-grid--live';
		var art = document.createElement('article');
		art.className = 'mn-add-rate-product-card';
		var head = document.createElement('header');
		head.className = 'mn-add-rate-product-card__head';
		head.textContent = product;
		var ul = document.createElement('ul');
		ul.className = 'mn-add-rate-rate-list';
		var list = rates && rates.length ? rates : [];
		if (list.length === 0) {
			var li0 = document.createElement('li');
			li0.className = 'mn-add-rate-rate-list__empty';
			li0.textContent = 'ไม่มีรายการซ่อมสำหรับอุปกรณ์นี้';
			ul.appendChild(li0);
		} else {
			for (var i = 0; i < list.length; i++) {
				var li = document.createElement('li');
				li.textContent = String(list[i]);
				ul.appendChild(li);
			}
		}
		art.appendChild(head);
		art.appendChild(ul);
		grid.appendChild(art);
		root.appendChild(grid);
	}

	/**
	 * Purpose:
	 * คืนค่า preview ตาม JSON จากหน้าแรกเมื่อมี `?catalog_product=` (ใช้เมื่อล้างค่าใน `#r_product`)
	 *
	 * Parameters สำคัญ:
	 * - HTMLElement root: `#mn-add-rate-catalog-live`
	 *
	 * Return value:
	 * - boolean ว่า restore สำเร็จหรือไม่
	 */
	function mnAddRateTryRestoreInitialCatalog(root) {
		var sc = document.getElementById('mn-add-rate-initial-catalog');
		if (!sc) {
			return false;
		}
		try {
			var o = JSON.parse(sc.textContent || '{}');
			if (o && o.product) {
				var prod = String(o.product);
				mnAddRateRenderCard(root, prod, o.rates || []);
				return true;
			}
		} catch (ignore) {}
		return false;
	}

	/**
	 * Purpose:
	 * แสดง hint เมื่ออยู่โหมดพิมพ์ชื่ออุปกรณ์ใหม่ (ไม่มีรายการใน Rate จนกว่าจะบันทึก)
	 *
	 * Parameters สำคัญ:
	 * - HTMLElement root: `#mn-add-rate-catalog-live`
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddRateShowNewProductHint(root) {
		mnAddRateClearNode(root);
		var p = document.createElement('p');
		p.className = 'mn-add-rate-hint';
		p.textContent = 'โหมดเพิ่มชื่ออุปกรณ์ใหม่ — ยังไม่มีรายการใน Rate จนกว่าจะบันทึก';
		root.appendChild(p);
	}

	/**
	 * Purpose:
	 * เรียก API แล้ว render รายการ rate ลงใน root
	 *
	 * Parameters สำคัญ:
	 * - string product: ชื่ออุปกรณ์ (ไม่ว่าง)
	 * - HTMLElement root: `#mn-add-rate-catalog-live`
	 * - Function onDone: เรียกหลังจบ (สำเร็จหรือล้มเหลว)
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddRateFetchRates(product, root, onDone) {
		root.classList.add('mn-add-rate-catalog-live--loading');
		var u = urlRatesJson() + '?product=' + encodeURIComponent(product);
		fetch(u, {
			method: 'GET',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' }
		})
			.then(function (res) {
				if (!res.ok) {
					throw new Error('HTTP ' + res.status);
				}
				return res.json();
			})
			.then(function (data) {
				if (!data || !data.ok) {
					throw new Error('bad payload');
				}
				var pName = data.product ? String(data.product) : product;
				mnAddRateRenderCard(root, pName, data.rates || []);
			})
			.catch(function () {
				mnAlert('โหลดรายการซ่อมไม่สำเร็จ กรุณาลองใหม่', 'danger');
			})
			.finally(function () {
				root.classList.remove('mn-add-rate-catalog-live--loading');
				if (typeof onDone === 'function') {
					onDone();
				}
			});
	}

	/**
	 * Purpose:
	 * อ่าน `#r_product` / `#r_productNew` แล้วอัปเดต preview ด้านล่างให้สอดคล้อง
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddRateRefreshPreviewFromForm() {
		var root = document.getElementById('mn-add-rate-catalog-live');
		if (!root) {
			return;
		}
		var sel = document.getElementById('r_product');
		var inputNew = document.getElementById('r_productNew');
		if (!sel || !inputNew) {
			return;
		}
		if (sel.style.display === 'none') {
			mnAddRateShowNewProductHint(root);
			return;
		}
		var v = (sel.value || '').trim();
		if (v === '') {
			if (!mnAddRateTryRestoreInitialCatalog(root)) {
				mnAddRateShowHint(root);
			}
			return;
		}
		mnAddRateFetchRates(v, root);
	}

	function initAddRatePage() {
		var rp = document.getElementById('r_product');
		if (rp) {
			rp.addEventListener('change', function () {
				mnAddRateRefreshPreviewFromForm();
			});
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initAddRatePage);
	} else {
		initAddRatePage();
	}

	/**
	 * Purpose:
	 * สลับระหว่างเลือก product จาก list กับพิมพ์ชื่อใหม่
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.add_product = function () {
		var sel = document.getElementById('r_product');
		if (sel.style.display === '' || sel.style.display === 'block') {
			document.getElementById('a_add').style.display = 'none';
			document.getElementById('a_cancel').style.display = '';
			sel.style.display = 'none';
			document.getElementById('r_productNew').value = '';
			document.getElementById('r_productNew').style.display = '';
			document.getElementById('r_productNew').focus();
		}
		mnAddRateRefreshPreviewFromForm();
	};

	/**
	 * Purpose:
	 * ตรวจฟอร์มแล้ว redirect ไป `insert_rate`
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.save_rate = function () {
		if (document.getElementById('r_product').value === '' && document.getElementById('r_productNew').value === '') {
			mnAlert('กรุณาเลือก สินค้า', 'warning');
			document.getElementById('r_product').focus();
			return;
		}
		if (document.getElementById('r_rate').value === '') {
			mnAlert('กรุณาระบุ รายการซ่อม', 'warning');
			document.getElementById('r_rate').focus();
			return;
		}
		var product = '';
		if (document.getElementById('r_productNew').value !== '') {
			product = document.getElementById('r_productNew').value;
		} else {
			product = document.getElementById('r_product').value;
		}
		var rate = document.getElementById('r_rate').value.replace(/&/g, '-');
		var pairs = [
			['r_rate', rate],
			['r_product', product],
			['r_price', '']
		];
		var qs = pairs
			.map(function (p) {
				return encodeURIComponent(p[0]) + '=' + encodeURIComponent(p[1]);
			})
			.join('&');
		window.location.href = urlInsertRate() + '?' + qs;
	};
})();
