/**
 * วัตถุประสงค์และขอบเขต:
 * Logic ฟอร์มเปิดใบซ่อม (validation, ส่ง POST ไป insert_job, AJAX ค้นหา, แถว maintenance)
 * อ่าน URL ปลายทางจาก window.MN_ADDJOB (ตั้งค่าใน add_job.php จาก includes/add_job_endpoints.php)
 *
 * Flow:
 * โหลดหลัง jQuery — ฟังก์ชัน save_job() ถูกเรียกจากลิงก์ Save ในหน้า
 */
(function () {
	var EP = window.MN_ADDJOB || {};

	/**
	 * Purpose:
	 * แจ้งเตือนแบบ modal กลาง (mnModalAlert) แทน alert — fallback เป็น alert ถ้ายังไม่โหลด mn-modal.js
	 *
	 * Parameters สำคัญ:
	 * - message: string
	 * - variant: string 'info'|'success'|'warning'|'danger' (ค่าเริ่มต้น warning)
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

	function urlInsertJob() {
		return EP.insertJob || 'insert_job';
	}

	/**
	 * Purpose:
	 * อ่านค่า input/select ตาม id — คืน string ว่างเมื่อไม่มี element
	 *
	 * Parameters สำคัญ:
	 * - elementId: string id ของ DOM element
	 *
	 * Return value:
	 * - string
	 */
	function fieldValue(elementId) {
		var el = document.getElementById(elementId);
		return el ? String(el.value) : '';
	}

	/**
	 * Purpose:
	 * ส่งพารามิเตอร์ไป insert_job ด้วย POST (รองรับ `&` และอักขระพิเศษในข้อความ — ไม่พึ่ง query string)
	 *
	 * Parameters สำคัญ:
	 * - params: object คีย์ => ค่า string
	 *
	 * Return value:
	 * - undefined
	 */
	function mnAddJobSubmitInsertJob(params) {
		var form = document.createElement('form');
		form.method = 'POST';
		form.action = urlInsertJob();
		form.style.display = 'none';
		var key;
		for (key in params) {
			if (!Object.prototype.hasOwnProperty.call(params, key)) {
				continue;
			}
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = key;
			input.value = params[key] == null ? '' : String(params[key]);
			form.appendChild(input);
		}
		document.body.appendChild(form);
		form.submit();
	}

	/**
	 * Purpose:
	 * รวม product@sn@cause สำหรับพารามิเตอร์ ts_mtnN
	 *
	 * Parameters สำคัญ:
	 * - rowNum: number 1–8
	 *
	 * Return value:
	 * - string
	 */
	function mtnParam(rowNum) {
		return fieldValue('pd_mtn' + rowNum) + '@' + fieldValue('sn_mtn' + rowNum) + '@' + fieldValue('cause_mtn' + rowNum);
	}

	/**
	 * Purpose:
	 * รวม spare SN สำหรับพารามิเตอร์ ts_spN
	 *
	 * Parameters สำคัญ:
	 * - rowNum: number 1–8
	 *
	 * Return value:
	 * - string
	 */
	function spParam(rowNum) {
		return fieldValue('sn_sp' + rowNum) + '@' + fieldValue('sn_spAT' + rowNum);
	}

	/**
	 * Purpose:
	 * สร้าง object พารามิเตอร์เครื่องซ่อม/สำรอง ts_mtn1–8 และ ts_sp1–8
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - object
	 */
	function buildEquipmentParams() {
		var params = {};
		var i;
		for (i = 1; i <= 8; i++) {
			params['ts_mtn' + i] = mtnParam(i);
			params['ts_sp' + i] = spParam(i);
		}
		return params;
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

	function urlListSnSpare() {
		return EP.listSnSpare || 'list_sn_spare';
	}

	function urlListExistingTransac() {
		return EP.listExistingTransac || 'add_job_list_existing_transac';
	}

	/**
	 * Purpose:
	 * คืนโหมดเปิดงานปัจจุบัน (new | existing)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - string
	 */
	function getAddJobMode() {
		var el = document.getElementById('add_job_mode');
		return el && el.value === 'existing' ? 'existing' : 'new';
	}

	/**
	 * Purpose:
	 * สลับการแสดงส่วน Transac / หมายเหตุ ตามโหมด
	 *
	 * Parameters สำคัญ:
	 * - mode: string 'new' | 'existing'
	 *
	 * Return value:
	 * - undefined
	 */
	function applyAddJobModeUi(mode) {
		var isExisting = mode === 'existing';
		var block = document.getElementById('mn-addjob-transac-block');
		if (block) {
			block.style.display = isExisting ? 'none' : '';
		}
		var remarks = document.getElementById('mn-addjob-remarks-block');
		if (remarks) {
			remarks.style.display = isExisting ? 'none' : '';
		}
		var summary = document.getElementById('mn-addjob-existing-summary');
		var tsIdEl = document.getElementById('existing_ts_id');
		var hasPick = tsIdEl && String(tsIdEl.value).trim() !== '';
		if (summary) {
			summary.style.display = isExisting && hasPick ? '' : 'none';
		}
	}

	/**
	 * Purpose:
	 * อัปเดตข้อความสรุปงานที่เลือก (โหมดเพิ่มอุปกรณ์เดิม)
	 *
	 * Parameters สำคัญ:
	 * - item: object|null { ts_mtn_number, ts_enduser_com, ts_security_com }
	 *
	 * Return value:
	 * - undefined
	 */
	function updateExistingSummary(item) {
		var textEl = document.getElementById('mn-addjob-existing-summary-text');
		if (!textEl) {
			return;
		}
		if (!item) {
			textEl.textContent = '';
			return;
		}
		var parts = [];
		if (item.ts_mtn_number) {
			parts.push('เลขที่ ' + item.ts_mtn_number);
		}
		if (item.ts_enduser_com) {
			parts.push(item.ts_enduser_com);
		}
		if (item.ts_security_com) {
			parts.push(item.ts_security_com);
		}
		textEl.textContent = parts.join(' — ');
	}

	/**
	 * Purpose:
	 * escape ข้อความก่อนใส่ HTML ใน modal
	 *
	 * Parameters สำคัญ:
	 * - s: string
	 *
	 * Return value:
	 * - string
	 */
	function escapeHtml(s) {
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;');
	}

	/**
	 * Purpose:
	 * โหลดรายการงานซ่อมที่เลือกเพิ่มเครื่องได้ลงตารางใน modal
	 *
	 * Parameters สำคัญ:
	 * - term: string คำค้น (optional)
	 *
	 * Return value:
	 * - undefined
	 */
	function loadExistingTransacList(term) {
		var tbody = document.getElementById('mn-addjob-pick-tbody');
		var emptyEl = document.getElementById('mn-addjob-pick-empty');
		if (!tbody) {
			return;
		}
		tbody.innerHTML = '<tr><td colspan="5">กำลังโหลด...</td></tr>';
		if (emptyEl) {
			emptyEl.style.display = 'none';
		}
		$.getJSON(urlListExistingTransac(), { term: term || '' })
			.done(function (data) {
				var items = data && data.items ? data.items : [];
				if (!items.length) {
					tbody.innerHTML = '';
					if (emptyEl) {
						emptyEl.style.display = '';
					}
					return;
				}
				var html = '';
				for (var i = 0; i < items.length; i++) {
					var it = items[i];
					html += '<tr>' +
						'<td>' + escapeHtml(it.ts_mtn_number || '') + '</td>' +
						'<td>' + escapeHtml(it.ts_enduser_com || '') + '</td>' +
						'<td>' + escapeHtml(it.ts_security_com || '') + '</td>' +
						'<td>' + escapeHtml(it.ts_date || '') + '</td>' +
						'<td><button type="button" class="mn-addjob-pick-select-btn" data-ts-id="' + escapeHtml(String(it.ts_id)) + '"' +
						' data-mtn="' + escapeHtml(it.ts_mtn_number || '') + '"' +
						' data-enduser="' + escapeHtml(it.ts_enduser_com || '') + '"' +
						' data-security="' + escapeHtml(it.ts_security_com || '') + '">เลือก</button></td></tr>';
				}
				tbody.innerHTML = html;
			})
			.fail(function () {
				tbody.innerHTML = '';
				mnAlert('โหลดรายการงานไม่สำเร็จ', 'danger');
			});
	}

	/**
	 * Purpose:
	 * เปิด modal เลือกงานซ่อมเดิม
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined
	 */
	window.mnAddJobOpenPickTransacModal = function () {
		var overlay = document.getElementById('mn-addjob-pick-transac');
		if (!overlay) {
			return;
		}
		overlay.style.display = '';
		overlay.setAttribute('aria-hidden', 'false');
		var termEl = document.getElementById('mn-addjob-pick-term');
		loadExistingTransacList(termEl ? termEl.value : '');
	};

	/**
	 * Purpose:
	 * ปิด modal เลือกงาน — ถ้ายังไม่เลือกและ revertIfEmpty ให้กลับโหมดใหม่
	 *
	 * Parameters สำคัญ:
	 * - revertIfEmpty: boolean
	 *
	 * Return value:
	 * - undefined
	 */
	function closePickTransacModal(revertIfEmpty) {
		var overlay = document.getElementById('mn-addjob-pick-transac');
		if (overlay) {
			overlay.style.display = 'none';
			overlay.setAttribute('aria-hidden', 'true');
		}
		if (revertIfEmpty) {
			var tsIdEl = document.getElementById('existing_ts_id');
			if (!tsIdEl || String(tsIdEl.value).trim() === '') {
				document.getElementById('add_job_mode').value = 'new';
				var radioNew = document.getElementById('add_job_mode_new');
				if (radioNew) {
					radioNew.checked = true;
				}
				applyAddJobModeUi('new');
			}
		}
	}

	/**
	 * Purpose:
	 * เรียกเมื่อเปลี่ยน radio โหมดเปิดงาน
	 *
	 * Parameters สำคัญ:
	 * - mode: string 'new' | 'existing'
	 *
	 * Return value:
	 * - undefined
	 */
	window.mnAddJobOnModeChange = function (mode) {
		var modeEl = document.getElementById('add_job_mode');
		if (modeEl) {
			modeEl.value = mode === 'existing' ? 'existing' : 'new';
		}
		if (mode === 'existing') {
			applyAddJobModeUi('existing');
			var tsIdEl = document.getElementById('existing_ts_id');
			if (!tsIdEl || String(tsIdEl.value).trim() === '') {
				window.mnAddJobOpenPickTransacModal();
			}
		} else {
			var existingId = document.getElementById('existing_ts_id');
			if (existingId) {
				existingId.value = '';
			}
			updateExistingSummary(null);
			applyAddJobModeUi('new');
		}
	};

	/**
	 * Purpose:
	 * ตรวจฟอร์มแล้วส่ง POST ไป insert_job (ไม่ใช้ query string — รองรับ `&` ในชื่อ/ที่อยู่)
	 *
	 * Parameters สำคัญ:
	 * - ไม่มี
	 *
	 * Return value:
	 * - undefined (หยุดด้วย return ภายในหรือ redirect)
	 */
	window.save_job = function () {
		var isExistingMode = getAddJobMode() === 'existing';

		if (isExistingMode) {
			var existingTsId = document.getElementById('existing_ts_id');
			if (!existingTsId || String(existingTsId.value).trim() === '') {
				mnAlert('กรุณาเลือกงานซ่อมเดิม', 'warning');
				window.mnAddJobOpenPickTransacModal();
				return;
			}
		} else if (document.getElementById('ts_customer_group1').checked !== true && document.getElementById('ts_customer_group2').checked !== true) {
			mnAlert('กรุณาเลือก ประเภทลูกค้า', 'warning');
			document.getElementById('ts_customer_group1').focus();
			return;
		} else if (document.getElementById('ts_customer_group1').checked === true && document.getElementById('ts_enduser_com').value === '') {
			mnAlert('กรุณาระบุ ชื่อบริษัท Enduser', 'warning');
			document.getElementById('ts_enduser_com').focus();
			return;
		} else if (document.getElementById('ts_customer_group2').checked === true) {
			if (document.getElementById('ts_enduser_com').value === '') {
				mnAlert('กรุณาระบุ ชื่อบริษัท ผู้ว่าจ้าง', 'warning');
				document.getElementById('ts_enduser_com').focus();
				return;
			}
			if (document.getElementById('ts_security_com').value === '') {
				mnAlert('กรุณาระบุ ชื่อบริษัท รปภ.', 'warning');
				document.getElementById('ts_enduser_com').focus();
				return;
			}
		}

		if (!isExistingMode && document.getElementById('ts_user_recive').value === '') {
			mnAlert('กรุณาเลือก ผู้รับเรื่อง', 'warning');
			document.getElementById('ts_user_recive').focus();
			return;
		}
		if (!isExistingMode && document.getElementById('ts_report_name').value === '') {
			mnAlert('กรุณาระบุ ผู้แจ้งเรื่อง', 'warning');
			document.getElementById('ts_report_name').focus();
			return;
		}
		if (document.getElementById('pd_mtn1').value === '') {
			mnAlert('กรุณาระบุ Product', 'warning');
			document.getElementById('pd_mtn1').focus();
			return;
		}
		if (document.getElementById('cause_mtn1').value === '') {
			mnAlert('กรุณาระบุ อาการที่แจ้งซ่อม', 'warning');
			document.getElementById('cause_mtn1').focus();
			return;
		}
		if (!isExistingMode && document.getElementById('ts_radio_address1').checked === false && document.getElementById('ts_radio_address2').checked === false) {
			mnAlert('กรุณาระบุ หน่วยงานที่จัดส่ง', 'warning');
			document.getElementById('ts_radio_address1').focus();
			return;
		}

		var equipmentParams = buildEquipmentParams();

		if (isExistingMode) {
			var existingParams = {
				add_job_mode: 'existing',
				existing_ts_id: fieldValue('existing_ts_id'),
				ts_user_recive: fieldValue('ts_user_recive')
			};
			var ek;
			for (ek in equipmentParams) {
				if (Object.prototype.hasOwnProperty.call(equipmentParams, ek)) {
					existingParams[ek] = equipmentParams[ek];
				}
			}
			mnAddJobSubmitInsertJob(existingParams);
			return;
		}

		var nameSend = '';
		if (document.getElementById('ts_radio_address1').checked === true) {
			nameSend = str_replace(fieldValue('ts_enduser_com'));
			if (fieldValue('ts_security_com') !== '') {
				nameSend = nameSend + ' (' + str_replace(fieldValue('ts_security_com')) + ')';
			}
		} else if (document.getElementById('ts_radio_address2').checked === true) {
			nameSend = str_replace(fieldValue('ts_security_com'));
			if (fieldValue('ts_enduser_com') !== '') {
				nameSend = nameSend + ' (หน่วยงาน ' + str_replace(fieldValue('ts_enduser_com')) + ')';
			}
		}

		var insertParams = {
			add_job_mode: 'new',
			ts_date: fieldValue('ts_date'),
			ts_user_recive: fieldValue('ts_user_recive'),
			ts_enduser_com: fieldValue('ts_enduser_com'),
			ts_security_com: fieldValue('ts_security_com'),
			ts_report_name: fieldValue('ts_report_name'),
			ts_report_tel: fieldValue('ts_report_tel'),
			ts_report_line: fieldValue('ts_report_line'),
			ts_report_email: fieldValue('ts_report_email'),
			ts_address: 'ผู้รับ ' + fieldValue('ts_address_name') + ' ' + fieldValue('ts_address_tel') + ' ' +
				nameSend + ' ' + fieldValue('ts_address1') + fieldValue('ts_address2'),
			ts_approve_name: fieldValue('ts_approve_name'),
			ts_approve_tel: fieldValue('ts_approve_tel'),
			ts_approve_line: fieldValue('ts_approve_line'),
			ts_approve_email: fieldValue('ts_approve_email'),
			ts_remarks: fieldValue('ts_remarks')
		};
		var pk;
		for (pk in equipmentParams) {
			if (Object.prototype.hasOwnProperty.call(equipmentParams, pk)) {
				insertParams[pk] = equipmentParams[pk];
			}
		}
		mnAddJobSubmitInsertJob(insertParams);
	};

	/**
	 * Purpose:
	 * ตัดคำนำหน้า/คำทั่วไปในชื่อบริษัทก่อนนำไปประกอบที่อยู่จัดส่ง
	 *
	 * Parameters สำคัญ:
	 * - text: string ชื่อบริษัทดิบ
	 *
	 * Return value:
	 * - string หลัง replace
	 */
	window.str_replace = function (text) {
		text = text.replace('บริษัท ', '').replace('รักษาความปลอดภัย', '').replace('จำกัด', '').replace('บริษัท', '').replace('บจก', '');
		text = text.replace('มหาชน', '').replace('ประเทศไทย', '').replace('()', '').replace('Co.,Ltd', '').replace('Company Limited.', '').replace('co.,Ltd.', '').replace('.', '');
		return text;
	};

	$(document.getElementById('user_company')).ready(function () {
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
	});

	$(document.getElementById('security_company')).ready(function () {
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
	});

	$(document.getElementById('s_tambon')).ready(function () {
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

	function bindPdMtnChange(pdId, spareRowId, snSpId, snSpAtId) {
		$(document.getElementById(pdId)).ready(function () {
			$('select#' + pdId).change(function () {
				if ($(this).val() === 'bitSupply 125' || $(this).val() === 'bitSupply 1224') {
					document.getElementById(spareRowId).style.display = '';
				} else {
					document.getElementById(spareRowId).style.display = 'none';
					document.getElementById(snSpAtId).value = '';
				}
				$.get(urlListSnSpare(), { pd_mtn: $(this).val() }, function (data) {
					$('select#' + snSpId).html(data);
					$('select#' + snSpAtId).html(data);
				});
			});
		});
	}

	bindPdMtnChange('pd_mtn1', 'p_spAT1', 'sn_sp1', 'sn_spAT1');
	bindPdMtnChange('pd_mtn2', 'p_spAT2', 'sn_sp2', 'sn_spAT2');
	bindPdMtnChange('pd_mtn3', 'p_spAT3', 'sn_sp3', 'sn_spAT3');
	bindPdMtnChange('pd_mtn4', 'p_spAT4', 'sn_sp4', 'sn_spAT4');
	bindPdMtnChange('pd_mtn5', 'p_spAT5', 'sn_sp5', 'sn_spAT5');
	bindPdMtnChange('pd_mtn6', 'p_spAT6', 'sn_sp6', 'sn_spAT6');
	bindPdMtnChange('pd_mtn7', 'p_spAT7', 'sn_sp7', 'sn_spAT7');
	bindPdMtnChange('pd_mtn8', 'p_spAT8', 'sn_sp8', 'sn_spAT8');

	/**
	 * Purpose:
	 * สลับโหมดลูกค้า Enduser / Security — แสดงช่องและ radio ที่อยู่จัดส่งให้สอดคล้อง
	 *
	 * Parameters สำคัญ:
	 * - mode: string "enduser" | "security"
	 *
	 * Return value:
	 * - undefined
	 */
	window.add_hideCustomer = function (mode) {
		if (mode === 'enduser') {
			document.getElementById('ts_enduser_com').value = '';
			document.getElementById('ts_security_com').value = '';
			document.getElementById('lb_enduser1').style.display = '';
			document.getElementById('lb_enduser2').style.display = 'none';
			document.getElementById('ts_enduser_com').style.display = '';
			document.getElementById('lb_security').style.display = 'none';
			document.getElementById('ts_security_com').style.display = 'none';
			document.getElementById('at_site').style.display = '';
			document.getElementById('at_security').style.display = 'none';
			document.getElementById('ts_radio_address1').checked = true;
			document.getElementById('ts_radio_address2').checked = false;
		} else if (mode === 'security') {
			document.getElementById('ts_enduser_com').value = '';
			document.getElementById('ts_security_com').value = '';
			document.getElementById('lb_enduser1').style.display = 'none';
			document.getElementById('lb_enduser2').style.display = '';
			document.getElementById('ts_enduser_com').style.display = '';
			document.getElementById('lb_security').style.display = '';
			document.getElementById('ts_security_com').style.display = '';
			document.getElementById('at_site').style.display = '';
			document.getElementById('at_security').style.display = '';
			document.getElementById('ts_radio_address1').checked = false;
			document.getElementById('ts_radio_address2').checked = false;
		}
	};

	$(document).ready(function () {
		var max_fields = 10;
		var wrapper = $('.container_maintenance');
		var add_button = $('.add_form_field');
		var x = 1;
		$(add_button).click(function (e) {
			e.preventDefault();
			if (x < max_fields) {
				x++;
				$(wrapper).append('<div><input type="text" name="mytext[]"/><a href="#" class="delete">Delete</a></div>');
			} else {
				mnAlert('You Reached the limits', 'info');
			}
		});
		$(wrapper).on('click', '.delete', function (e) {
			e.preventDefault();
			$(this).parent('div').remove();
			x--;
		});
	});

	var r_ma = 1;

	/**
	 * Purpose:
	 * เพิ่ม/ลดแถวรายการซ่อม (สูงสุด 8 แถว)
	 *
	 * Parameters สำคัญ:
	 * - mode: string "add" | อื่นๆ สำหรับลบแถว
	 *
	 * Return value:
	 * - undefined
	 */
	window.addListMaintenance = function (mode) {
		if (r_ma >= 8 && mode === 'add') {
			mnAlert('เพิ่มได้ไม่เกิน 8 รายการ', 'warning');
			return;
		}
		if (mode === 'add') {
			if (r_ma >= 1 && (document.getElementById('pd_mtn' + r_ma).value === '' || document.getElementById('cause_mtn' + r_ma).value === '')) {
				mnAlert('กรุณากรอกข้อมูลก่อนหน้าให้ครบถ้วน', 'warning');
				document.getElementById('cause_mtn' + r_ma).focus();
				return;
			}
			r_ma += 1;
			if (r_ma >= 8) {
				r_ma = 8;
			}
			document.getElementById('r_mt' + r_ma).style.display = '';
			document.getElementById('r_mt2' + r_ma).style.display = '';
			document.getElementById('pd_mtn' + r_ma).value = '';
			document.getElementById('sn_mtn' + r_ma).value = '';
			document.getElementById('cause_mtn' + r_ma).value = '';
			document.getElementById('sn_sp' + r_ma).value = '';
		} else {
			if (r_ma <= 1) {
				r_ma = 1;
				document.getElementById('pd_mtn' + r_ma).value = '';
				document.getElementById('sn_mtn' + r_ma).value = '';
				document.getElementById('cause_mtn' + r_ma).value = '';
				document.getElementById('sn_sp' + r_ma).value = '';
				var selectBox = document.getElementById('sn_sp' + r_ma);
				selectBox.innerHTML = '';
				return;
			}
			document.getElementById('r_mt' + r_ma).style.display = 'none';
			document.getElementById('r_mt2' + r_ma).style.display = 'none';
			document.getElementById('pd_mtn' + r_ma).value = '';
			document.getElementById('sn_mtn' + r_ma).value = '';
			document.getElementById('cause_mtn' + r_ma).value = '';
			document.getElementById('sn_sp' + r_ma).value = '';
			var selectBox2 = document.getElementById('sn_sp' + r_ma);
			selectBox2.innerHTML = '';
			if (r_ma >= 2) {
				r_ma -= 1;
			}
		}
	};

	/**
	 * Purpose:
	 * เติมชื่อผู้รับจาก radio ที่อยู่
	 *
	 * Parameters สำคัญ:
	 * - val: string
	 *
	 * Return value:
	 * - undefined
	 */
	window.autochangeAdd_name = function (val) {
		document.getElementById('ts_address_name').value = val;
	};

	/**
	 * Purpose:
	 * เติมเบอร์ผู้รับจาก radio ที่อยู่
	 *
	 * Parameters สำคัญ:
	 * - val: string
	 *
	 * Return value:
	 * - undefined
	 */
	window.autochangeAdd_tel = function (val) {
		document.getElementById('ts_address_tel').value = val;
	};

	/**
	 * Purpose:
	 * ตรวจ S/N ตัวแรกต้องเป็น A-Z หรือ 0-9
	 *
	 * Parameters สำคัญ:
	 * - e: event จาก input (ใช้ e.target)
	 *
	 * Return value:
	 * - undefined
	 */
	window.checkInputFirst_Vowel = function (e) {
		var strValue = e.target.value;
		if (strValue.length === 1) {
			if ((strValue.charCodeAt(0) >= 65 && strValue.charCodeAt(0) <= 90) || (strValue.charCodeAt(0) >= 48 && strValue.charCodeAt(0) <= 57)) {
				return;
			}
			mnAlert('S/N ต้องขึ้นต้นด้วย [A-Z, 0-9] เท่านั้น!!!', 'warning');
			document.getElementById(e.target.id).value = '';
		}
	};

	$(document).ready(function () {
		$('#mn-addjob-pick-close').on('click', function () {
			closePickTransacModal(true);
		});
		$('#mn-addjob-pick-search-btn').on('click', function () {
			var termEl = document.getElementById('mn-addjob-pick-term');
			loadExistingTransacList(termEl ? termEl.value : '');
		});
		$('#mn-addjob-pick-term').on('keypress', function (e) {
			if (e.which === 13) {
				e.preventDefault();
				loadExistingTransacList(this.value);
			}
		});
		$(document).on('click', '.mn-addjob-pick-select-btn', function () {
			var btn = this;
			var tsId = btn.getAttribute('data-ts-id');
			document.getElementById('existing_ts_id').value = tsId || '';
			updateExistingSummary({
				ts_mtn_number: btn.getAttribute('data-mtn') || '',
				ts_enduser_com: btn.getAttribute('data-enduser') || '',
				ts_security_com: btn.getAttribute('data-security') || ''
			});
			applyAddJobModeUi('existing');
			closePickTransacModal(false);
		});
		$('#mn-addjob-change-existing').on('click', function (e) {
			e.preventDefault();
			window.mnAddJobOpenPickTransacModal();
		});
		$('#mn-addjob-pick-transac').on('click', function (e) {
			if (e.target === this) {
				closePickTransacModal(true);
			}
		});
	});
})();
