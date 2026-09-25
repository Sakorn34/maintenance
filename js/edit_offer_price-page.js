/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `edit_offer_price` — mask ราคา/QV, ยืนยันแล้ว redirect ไป `update_offer_price` (encodeURIComponent); หน้ารายการส่งผ่าน `data-mn-edit-qv-return-page` บน `body`
 *
 * Flow:
 * `mnEditOfferPriceAutoMask` / `mnEditOfferPriceAutoMaskQV` จาก event keyup; `mnEditOfferPriceSave` จากลิงก์บันทึก
 */
(function (global) {
  'use strict';

  /**
   * Purpose:
   * อ่าน slug หน้ารายการสำหรับพารามิเตอร์ `page` ใน `update_offer_price` — ค่า default `status_offer_price`; ตั้ง `data-mn-edit-qv-return-page` บน `body` ของ `edit_offer_price`
   *
   * Parameters สำคัญ:
   * - ไม่มี
   *
   * Return value:
   * - string slug ไม่มี `.php`
   */
  function mnEditOfferPriceGetReturnPage() {
    var b = global.document ? global.document.body : null;
    var raw = b && b.getAttribute('data-mn-edit-qv-return-page');
    if (raw !== null && String(raw).replace(/^\s+|\s+$/g, '') !== '') {
      return String(raw).replace(/^\s+|\s+$/g, '');
    }
    return 'status_offer_price';
  }

  /**
   * Purpose:
   * แจ้งเตือนแบบ modal กลาง (fallback `alert`)
   *
   * Parameters สำคัญ:
   * - message: string
   *
   * Return value:
   * - undefined
   */
  function mnEopAlert(message) {
    if (typeof global.mnMaintenanceFlowAlert === 'function') {
      global.mnMaintenanceFlowAlert(String(message), 'warning');
    } else if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(String(message), { variant: 'warning' });
    } else {
      global.alert(String(message));
    }
  }

  /**
   * Purpose:
   * ยืนยันก่อนบันทึก
   *
   * Parameters สำคัญ:
   * - message: string
   * - onOk: function
   *
   * Return value:
   * - undefined
   */
  function mnEopConfirm(message, onOk) {
    if (typeof global.mnMaintenanceFlowConfirm === 'function') {
      global.mnMaintenanceFlowConfirm(String(message), onOk);
    } else if (typeof global.mnModalConfirm === 'function') {
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
   * จัดรูปแบบตัวเลขราคาแบบมีคอมมา (ย้ายจาก `status_offer_price-page.js`)
   *
   * Parameters สำคัญ:
   * - obj: HTMLInputElement
   * - evt: KeyboardEvent
   *
   * Return value:
   * - undefined
   */
  function mnEditOfferPriceAutoMask(obj, evt) {
    var k = evt.keyCode;
    if (!((k >= 48 && k <= 57) || (k >= 65 && k <= 90) || (k >= 97 && k <= 122))) {
      return;
    }
    var text;
    if (obj.value.indexOf('.') !== -1) {
      text = obj.value;
    } else {
      text = obj.value.replace(/,/g, '');
    }
    var returnText;
    var i;
    if (text.indexOf('.') !== -1) {
      obj.value = text;
    } else if (text.length === 3) {
      obj.value = text;
    } else if (text.length === 4) {
      returnText = '';
      for (i = 0; i < text.length; i += 1) {
        if (i === 1) {
          returnText += ',';
          returnText += text.charAt(i);
        } else {
          returnText += text.charAt(i);
        }
      }
      obj.value = returnText;
    } else if (text.length === 5) {
      returnText = '';
      for (i = 0; i < text.length; i += 1) {
        if (i === 2) {
          returnText += ',';
          returnText += text.charAt(i);
        } else {
          returnText += text.charAt(i);
        }
      }
      obj.value = returnText;
    } else if (text.length >= 6) {
      returnText = '';
      for (i = 0; i < 5; i += 1) {
        if (i === 2) {
          returnText += ',';
          returnText += text.charAt(i);
        } else {
          returnText += text.charAt(i);
        }
      }
      obj.value = returnText;
    }
  }

  /**
   * Purpose:
   * จัดรูปแบบเลข QV แบบมีขีด
   *
   * Parameters สำคัญ:
   * - obj: HTMLInputElement
   * - evt: KeyboardEvent
   *
   * Return value:
   * - undefined
   */
  function mnEditOfferPriceAutoMaskQV(obj, evt) {
    var k = evt.keyCode;
    if (!((k >= 48 && k <= 57) || (k >= 65 && k <= 90) || (k >= 97 && k <= 122))) {
      return;
    }
    var text = obj.value.replace(/-/g, '');
    var returnText = '';
    var i;
    for (i = 0; i < text.length; i += 1) {
      if (i === 3) {
        returnText += text.charAt(i);
        returnText += '-';
      } else {
        returnText += text.charAt(i);
      }
    }
    obj.value = returnText;
  }

  /**
   * Purpose:
   * ตรวจค่าแล้ว redirect ไป `update_offer_price` พร้อม query string
   *
   * Parameters สำคัญ:
   * - trpId: number รหัสแถวซ่อม
   *
   * Return value:
   * - undefined
   */
  function mnEditOfferPriceSave(trpId) {
    var sendEl = document.getElementById('trp_qv_send');
    var priceEl = document.getElementById('trp_qv_price');
    var numEl = document.getElementById('trp_qv_number');
    var dateEl = document.getElementById('trp_qv_date');
    if (!sendEl || sendEl.value === '') {
      mnEopAlert('กรุณาระบุ รูปแบบการส่ง');
      if (sendEl) {
        sendEl.focus();
      }
      return;
    }
    if (!priceEl || priceEl.value === '') {
      mnEopAlert('กรุณาระบุค่าซ่อม');
      if (priceEl) {
        priceEl.focus();
      }
      return;
    }
    if (!numEl || numEl.value === '') {
      mnEopAlert('กรุณาระบุหมายเลขใบเสนอราคา');
      if (numEl) {
        numEl.focus();
      }
      return;
    }
    mnEopConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
      var q =
        'id_trp=' +
        encodeURIComponent(String(trpId)) +
        '&trp_qv_number=' +
        encodeURIComponent(String(numEl.value)) +
        '&trp_qv_date=' +
        encodeURIComponent(dateEl ? String(dateEl.value) : '') +
        '&trp_qv_send=' +
        encodeURIComponent(String(sendEl.value)) +
        '&trp_qv_price=' +
        encodeURIComponent(String(priceEl.value)) +
        '&page=' +
        encodeURIComponent(mnEditOfferPriceGetReturnPage());
      global.location.href = 'update_offer_price?' + q;
    });
  }

  global.mnEditOfferPriceAutoMask = mnEditOfferPriceAutoMask;
  global.mnEditOfferPriceAutoMaskQV = mnEditOfferPriceAutoMaskQV;
  global.mnEditOfferPriceSave = mnEditOfferPriceSave;
}(typeof window !== 'undefined' ? window : this));
