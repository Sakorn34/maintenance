/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มประเมินหน้า `update_rate` — ตรวจลำดับรายการซ่อม, ยืนยันก่อนบันทึก, ส่ง GET ไป `save_rate` (encodeURIComponent)
 *
 * Flow:
 * `mnUpdateRateListRate` จาก onchange select; `mnUpdateRateSaveStatus` จากลิงก์บันทึก
 */
(function (global) {
  'use strict';

  /**
   * Purpose:
   * แจ้งเตือนแบบ modal กลาง (fallback `alert`)
   *
   * Parameters สำคัญ:
   * - message: string ข้อความ
   *
   * Return value:
   * - undefined
   */
  function mnUrAlert(message) {
    if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(String(message), { variant: 'warning' });
    } else {
      global.alert(String(message));
    }
  }

  /**
   * Purpose:
   * ยืนยันก่อนบันทึก (แทน `confirm`)
   *
   * Parameters สำคัญ:
   * - message: string
   * - onOk: function เรียกเมื่อยืนยัน
   *
   * Return value:
   * - undefined
   */
  function mnUrConfirm(message, onOk) {
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
   * บังคับเลือกรายการซ่อมตามลำดับ (เทียบ `listRate` เดิม)
   *
   * Parameters สำคัญ:
   * - id: string|number เลขลำดับ select (2–10)
   *
   * Return value:
   * - undefined
   */
  function mnUpdateRateListRate(id) {
    var tId = parseInt(String(id), 10);
    var before = tId - 1;
    var arrId = String(before);
    var prevEl = document.getElementById('rate_list' + arrId);
    var curEl = document.getElementById('rate_list' + String(id));
    if (!prevEl || !curEl) {
      return;
    }
    var x = prevEl.options[prevEl.selectedIndex]
      ? prevEl.options[prevEl.selectedIndex].text
      : '';
    if (String(x) === 'รายการซ่อม..') {
      mnUrAlert('กรุณาเลือก รายการซ่อมเรียงตามลำดับ');
      curEl.selectedIndex = 0;
      prevEl.focus();
    }
  }

  /**
   * Purpose:
   * ไปหน้าแก้ไขประเมิน (signature เดิมจากระบบเก่า)
   *
   * Parameters สำคัญ:
   * - trpId: รหัสแถวซ่อม
   * - idRow: สงวนไว้ให้สอดคล้อง signature เดิม
   *
   * Return value:
   * - undefined
   */
  function mnUpdateRateEditStatus(trpId, idRow) {
    global.location.href = 'update_rate?trp_id=' + encodeURIComponent(String(trpId));
  }

  /**
   * Purpose:
   * ตรวจฟอร์มแล้ว redirect ไป `save_rate` พร้อม query string
   *
   * Parameters สำคัญ:
   * - trpId: รหัสแถวซ่อม
   * - idRow: สงวนไว้ให้สอดคล้อง signature เดิม
   *
   * Return value:
   * - undefined
   */
  function mnUpdateRateSaveStatus(trpId, idRow) {
    var techEl = document.getElementById('trp_repair_technician');
    var r1 = document.getElementById('rate_list1');
    var userEl = document.getElementById('trp_user_rate');
    if (!techEl || !r1 || !userEl) {
      return;
    }
    if (String(techEl.value).trim() === '') {
      mnUrAlert('กรุณาประเมินอาการซ่อม');
      techEl.focus();
      return;
    }
    if (String(r1.value).trim() === '') {
      mnUrAlert('กรุณาระบุรายการซ่อม');
      r1.focus();
      return;
    }
    if (String(userEl.value).trim() === '') {
      mnUrAlert('ไม่พบชื่อผู้ประเมินจาก session — ติดต่อผู้ดูแลระบบ');
      if (r1) {
        r1.focus();
      }
      return;
    }

    mnUrConfirm('คุณต้องการบันทึกข้อมูลหรือไม่', function () {
      var trpClean = '';
      var trpRepeatSolder = '';
      var trpSpray = '';
      var c1 = document.getElementById('trp_clean');
      var c2 = document.getElementById('trp_repeat_solder');
      var c3 = document.getElementById('trp_spray');
      if (c1 && c1.checked) {
        trpClean = String(c1.value);
      }
      if (c2 && c2.checked) {
        trpRepeatSolder = String(c2.value);
      }
      if (c3 && c3.checked) {
        trpSpray = String(c3.value);
      }

      function gv(elId) {
        var el = document.getElementById(elId);
        return el ? String(el.value) : '';
      }

      var parts = [];
      parts.push('trp_id=' + encodeURIComponent(String(trpId)));
      parts.push('trp_repair_technician=' + encodeURIComponent(gv('trp_repair_technician')));
      parts.push('trp_rate_date=' + encodeURIComponent(gv('trp_rate_date')));
      parts.push('trp_clean=' + encodeURIComponent(trpClean));
      parts.push('trp_repeat_solder=' + encodeURIComponent(trpRepeatSolder));
      parts.push('trp_spray=' + encodeURIComponent(trpSpray));
      parts.push('trp_fw=' + encodeURIComponent(gv('trp_fw')));
      var i;
      for (i = 1; i <= 10; i += 1) {
        parts.push('trp_repair_no' + i + '=' + encodeURIComponent(gv('rate_list' + i)));
      }
      parts.push('trp_repair_remarks=' + encodeURIComponent(gv('trp_repair_remarks')));
      parts.push('trp_user_rate=' + encodeURIComponent(gv('trp_user_rate')));
      parts.push('trp_insurance=' + encodeURIComponent(gv('trp_insurance')));
      parts.push('trp_status_job=' + encodeURIComponent(gv('trp_status_job')));

      global.location.href = 'save_rate?' + parts.join('&');
    });
  }

  global.mnUpdateRateListRate = mnUpdateRateListRate;
  global.mnUpdateRateEditStatus = mnUpdateRateEditStatus;
  global.mnUpdateRateSaveStatus = mnUpdateRateSaveStatus;
}(typeof window !== 'undefined' ? window : this));
