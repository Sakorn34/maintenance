/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฟอร์มบันทึกรับเครื่องซ่อม (`update_wait_hardware`) — validate แล้ว redirect ไป `save_wait_hardware`
 * แจ้งเตือน / ยืนยันผ่าน `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm` จาก `mn-maintenance-flow-modals.js` (โหลดหลัง `mn-modal.js`)
 */

(function (global) {
  'use strict';

  var mnUpdateWaitHwAllowedInsurance = [
    'อยู่ในประกัน',
    'หมดประกัน',
    'อยู่ในประกันซ่อม'
  ];

  /**
   * Purpose:
   * ตรวจว่าเลือกประกันแล้ว (ห้ามว่างหรือค่านอกรายการ)
   *
   * Parameters สำคัญ:
   * - value: string ค่าจาก `#w_insurance`
   *
   * Return value:
   * - boolean
   */
  function mnUpdateWaitHwInsuranceValid(value) {
    return mnUpdateWaitHwAllowedInsurance.indexOf(String(value)) !== -1;
  }

  /**
   * Purpose:
   * ตรวจฟิลด์แล้วนำทางไป `save_wait_hardware` พร้อม query string (เรียกหลังผู้ใช้ยืนยันแล้ว)
   *
   * Parameters สำคัญ:
   * - id: number `trp_id`
   *
   * Return value:
   * - undefined
   */
  function mnUpdateWaitHwDoSave(id) {
    var wDateReceive = document.getElementById('w_date_receive');
    var wSn = document.getElementById('w_sn');
    var wDateBuy = document.getElementById('w_date_buy');
    var maUser = document.getElementById('trp_user_recive_ma');
    if (!wDateReceive || wDateReceive.value === '') {
      global.mnMaintenanceFlowAlert('กรุณาระบุ วันที่รับซ่อม', 'warning');
      if (wDateReceive) {
        wDateReceive.focus();
      }
      return;
    }
    if (!wSn || wSn.value === '') {
      global.mnMaintenanceFlowAlert('กรุณาระบุ เลข SN เครื่องซ่อม', 'warning');
      if (wSn) {
        wSn.focus();
      }
      return;
    }
    if (!wDateBuy || wDateBuy.value === '') {
      global.mnMaintenanceFlowAlert('กรุณาระบุ วันที่ซื้อ', 'warning');
      if (wDateBuy) {
        wDateBuy.focus();
      }
      return;
    }
    if (!maUser || maUser.value === '') {
      global.mnMaintenanceFlowAlert('ไม่พบชื่อผู้รับเครื่องจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
      if (wDateReceive) {
        wDateReceive.focus();
      }
      return;
    }
    var wRepairAge = document.getElementById('w_repair_age');
    var wInsurance = document.getElementById('w_insurance');
    if (!wInsurance || !mnUpdateWaitHwInsuranceValid(wInsurance.value)) {
      global.mnMaintenanceFlowAlert('กรุณาเลือก ประกัน', 'warning');
      if (wInsurance) {
        wInsurance.focus();
      }
      return;
    }
    var wRepairInform = document.getElementById('w_repair_inform');
    var q = 'trp_id=' + encodeURIComponent(String(id))
      + '&w_date_receive=' + encodeURIComponent(wDateReceive.value)
      + '&w_sn=' + encodeURIComponent(wSn.value)
      + '&w_date_buy=' + encodeURIComponent(wDateBuy.value)
      + '&w_repair_age=' + encodeURIComponent(wRepairAge ? wRepairAge.value : '')
      + '&w_insurance=' + encodeURIComponent(wInsurance ? wInsurance.value : '')
      + '&w_repair_inform=' + encodeURIComponent(wRepairInform ? wRepairInform.value : '')
      + '&trp_user_recive_ma=' + encodeURIComponent(maUser.value);
    global.location = 'save_wait_hardware?' + q;
  }

  /**
   * Purpose:
   * เปิด modal ยืนยันก่อน แล้วตรวจค่าและไป `save_wait_hardware` (เรียกจากปุ่มบันทึกในหน้า)
   *
   * Parameters สำคัญ:
   * - id: number `trp_id`
   *
   * Return value:
   * - undefined
   */
  global.mnUpdateWaitHwSaveStatus = function (id) {
    global.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
      mnUpdateWaitHwDoSave(id);
    });
  };

  /**
   * Purpose:
   * คำนวณสถานะประกันจากวันที่ซื้อ + window.mnWarrantyMonths แล้วเซ็ต dropdown + hint
   * ไม่ทำงานถ้า mnRepairWarrantyActive = true (ใช้ค่าที่ PHP pre-select ไว้)
   *
   * Parameters สำคัญ:
   * - buyDateStr: string Y-m-d
   *
   * Return value:
   * - undefined
   */
  function mnUpdateWaitHwSuggestFromBuyDate(buyDateStr) {
    var months = global.mnWarrantyMonths;
    if (!months || months < 1 || !buyDateStr) {
      return;
    }
    var parts = buyDateStr.split('-');
    if (parts.length !== 3) {
      return;
    }
    var buyDate = new Date(
      parseInt(parts[0], 10),
      parseInt(parts[1], 10) - 1,
      parseInt(parts[2], 10)
    );
    if (isNaN(buyDate.getTime())) {
      return;
    }
    var expiryDate = new Date(buyDate);
    expiryDate.setMonth(expiryDate.getMonth() + months);
    var today = new Date();
    today.setHours(0, 0, 0, 0);
    var suggested = (today <= expiryDate) ? 'อยู่ในประกัน' : 'หมดประกัน';
    var ins = document.getElementById('w_insurance');
    if (ins) {
      ins.value = suggested;
    }
    var hintEl = document.getElementById('mn_insurance_hint');
    if (hintEl) {
      var diffMs = expiryDate.getTime() - today.getTime();
      var diffDays = Math.round(diffMs / 86400000);
      if (suggested === 'อยู่ในประกัน') {
        hintEl.textContent = 'อยู่ในประกัน (เหลือประมาณ ' + diffDays + ' วัน)';
      } else {
        hintEl.textContent = 'หมดประกันแล้ว ' + Math.abs(diffDays) + ' วัน';
      }
      hintEl.style.display = '';
    }
  }

  /**
   * Purpose:
   * เรียก AJAX คำนวณอายุเครื่องจากวันที่ซื้อ แล้วแนะนำสถานะประกันจาก warranty months
   *
   * Parameters สำคัญ:
   * - ไม่มี
   *
   * Return value:
   * - undefined
   */
  global.mnUpdateWaitHwCalAgeUse = function () {
    var inputEl = document.getElementById('w_date_buy');
    if (!inputEl || !global.jQuery) {
      return;
    }
    var inputVal = inputEl.value;
    global.jQuery.get('cal_lifetime', { term: inputVal })
      .done(function (data) {
        var ageEl = document.getElementById('w_repair_age');
        if (ageEl) {
          ageEl.value = data;
        }
        // สถานะประกันคำนวณจากทะเบียนประกัน (เครื่องใหม่ / งานซ่อม / ซื้อเพิ่ม) ใน js/warranty-timeline.js แทน
        // mnUpdateWaitHwSuggestFromBuyDate (คิดจากเดือนตามรุ่นอย่างเดียว) เลิกใช้แล้ว
      })
      .fail(function () {
        global.mnMaintenanceFlowAlert('ไม่สามารถคำนวณอายุเครื่องได้ กรุณาลองใหม่', 'danger');
      });
  };

  /**
   * Purpose:
   * ตรวจ S/N ตัวแรกต้องเป็น A–Z, a–z หรือ 0–9
   *
   * Parameters สำคัญ:
   * - e: event จาก keypress
   *
   * Return value:
   * - undefined
   */
  global.mnUpdateWaitHwCheckSnFirstChar = function (e) {
    var strValue = e.target.value;
    if (strValue.length === 1) {
      var c = strValue.charCodeAt(0);
      if ((c >= 65 && c <= 90) || (c >= 48 && c <= 57) || (c >= 97 && c <= 122)) {
        return;
      }
      global.mnMaintenanceFlowAlert('S/N ต้องขึ้นต้นด้วย [A-Z, 0-9, a-z] เท่านั้น!!!', 'warning');
      e.target.value = '';
    }
  };

  global.addEventListener('load', function () {
    if (!/[?&]err=insurance(?:&|$)/.test(global.location.search)) {
      return;
    }
    if (typeof global.mnMaintenanceFlowAlert === 'function') {
      global.mnMaintenanceFlowAlert('กรุณาเลือก ประกัน', 'warning');
    }
  });
})(typeof window !== 'undefined' ? window : this);
