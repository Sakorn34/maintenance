/**
 * วัตถุประสงค์และขอบเขต:
 * ตรวจฟิลด์ก่อนบันทึก QC และนำทางไป `update_wait_qc` พร้อม query string (เทียบ `saveStatus()` ใน `old_system/qc_checklist.php`)
 *
 * Flow:
 * คลิก `#mn-qc-checklist-save` → validate → `mnMaintenanceFlowConfirm` → สร้าง URL → `window.location`
 */

(function (global) {
  'use strict';

  /**
   * Purpose:
   * สลับค่า checkbox แบบ OK/ว่าง (เทียบ `checkbox()` เดิม)
   *
   * Parameters สำคัญ:
   * - value: string ค่าปัจจุบัน
   *
   * Return value:
   * - string ค่าถัดไป
   */
  function mnQcCheckboxToggle(value) {
    return value !== 'OK' ? 'OK' : '';
  }

  /**
   * Purpose:
   * สลับ Yes/No (เทียบ `checkboxYesNo()` เดิม)
   *
   * Parameters สำคัญ:
   * - value: string
   *
   * Return value:
   * - string
   */
  function mnQcCheckboxYesNo(value) {
    return value !== 'Yes' ? 'Yes' : 'No';
  }

  /**
   * Purpose:
   * อ่านค่า element ตาม id
   *
   * Parameters สำคัญ:
   * - id: string
   *
   * Return value:
   * - string
   */
  function mnQcVal(id) {
    var el = global.document.getElementById(id);
    return el ? String(el.value) : '';
  }

  /**
   * Purpose:
   * ตรวจว่า checkbox ถูกติ๊ก (มีค่า OK / Yes ตาม legacy)
   *
   * Parameters สำคัญ:
   * - id: string
   *
   * Return value:
   * - boolean
   */
  function mnQcCheckedOk(id) {
    var el = global.document.getElementById(id);
    if (!el || el.type !== 'checkbox') {
      return false;
    }
    return el.checked === true;
  }

  /**
   * Purpose:
   * แจ้งเตือน validation ผ่าน modal กลาง
   *
   * Parameters สำคัญ:
   * - msg: string
   *
   * Return value:
   * - undefined
   */
  function mnQcWarn(msg) {
    if (typeof global.mnMaintenanceFlowAlert === 'function') {
      global.mnMaintenanceFlowAlert(msg, 'warning');
    } else if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(String(msg), { variant: 'warning' });
    } else {
      global.alert(String(msg));
    }
  }

  /**
   * Purpose:
   * โฟกัส element ตาม id
   *
   * Parameters สำคัญ:
   * - id: string
   *
   * Return value:
   * - undefined
   */
  function mnQcFocus(id) {
    var el = global.document.getElementById(id);
    if (el && typeof el.focus === 'function') {
      el.focus();
    }
  }

  /**
   * Purpose:
   * ตรวจฟิลด์ตามประเภทสินค้า — คืนข้อความ error หรือ null
   *
   * Return value:
   * - string|null
   */
  function mnQcValidate() {
    if (!mnQcVal('trp_qc_date')) {
      mnQcFocus('trp_qc_date');
      return 'กรุณาระบุวันที่ QC';
    }
    if (!mnQcVal('trp_qc_user')) {
      mnQcFocus('trp_qc_date');
      return 'ไม่พบชื่อเจ้าหน้าที่ QC จาก session — ติดต่อผู้ดูแลระบบ';
    }
    var prod = mnQcVal('qc_product_name');
    if (prod === 'bitVisitor Plus' || prod === 'bitVisitor M' || prod === 'bitVisitor Lite') {
      if (!mnQcVal('qc_vusbvolt')) {
        mnQcFocus('qc_vusbvolt');
        return 'กรุณาระบุ การจ่ายไฟ USB';
      }
      if (!mnQcCheckedOk('qc_vledlan')) {
        mnQcFocus('qc_vledlan');
        return 'กรุณาระบุ สถานะไฟ Port Lan';
      }
      if (!mnQcCheckedOk('qc_vsoundlevel')) {
        mnQcFocus('qc_vsoundlevel');
        return 'กรุณาระบุ ระดับเสียงลำโพง';
      }
      if (!mnQcCheckedOk('qc_vcamerafocus')) {
        mnQcFocus('qc_vcamerafocus');
        return 'กรุณาระบุ การโฟกัสกล้อง A';
      }
      if (!mnQcCheckedOk('qc_vledcard')) {
        mnQcFocus('qc_vledcard');
        return 'กรุณาระบุ ไฟถ่ายบัตร';
      }
      if (!mnQcVal('qc_vfirmware')) {
        mnQcFocus('qc_vfirmware');
        return 'กรุณาระบุ FW';
      }
      if (!mnQcVal('qc_vtimestart')) {
        mnQcFocus('qc_vtimestart');
        return 'กรุณาระบุ เวลาเปิดเครื่อง';
      }
      if (!mnQcCheckedOk('qc_vexportlan')) {
        mnQcFocus('qc_vexportlan');
        return 'กรุณาระบุ การส่งออกข้อมูลผ่าน LAN';
      }
      if (!mnQcCheckedOk('qc_vexportusb')) {
        mnQcFocus('qc_vexportusb');
        return 'กรุณาระบุ การส่งออกข้อมูลผ่าน USB';
      }
      if (!mnQcCheckedOk('qc_vtouch')) {
        mnQcFocus('qc_vtouch');
        return 'กรุณาระบุ การ Touch Screen';
      }
      if (!mnQcCheckedOk('qc_vsleepmode')) {
        mnQcFocus('qc_vsleepmode');
        return 'กรุณาระบุ Sleep Mode';
      }
      if (!mnQcVal('qc_vnoopenclose')) {
        mnQcFocus('qc_vnoopenclose');
        return 'กรุณาระบุ จำนวนที่เปิด-ปิด เครื่อง';
      }
      if (!mnQcVal('qc_vinout')) {
        mnQcFocus('qc_vinout');
        return 'กรุณาระบุ จำนวน IN-OUT';
      }
      if (!mnQcVal('qc_vcpuuse') && prod !== 'bitVisitor Lite') {
        mnQcFocus('qc_vcpuuse');
        return 'กรุณาระบุ CPU ที่ใช้';
      }
      if (!mnQcVal('qc_vramuse') && prod !== 'bitVisitor Lite') {
        mnQcFocus('qc_vramuse');
        return 'กรุณาระบุ Ram ที่ใช้';
      }
      if (!mnQcCheckedOk('qc_vtestprint')) {
        mnQcFocus('qc_vtestprint');
        return 'กรุณาระบุ การ Test Print';
      }
    } else if (
      prod === 'bitScan Kit' ||
      prod === 'bitScan Pro' ||
      prod === 'bitVehicle' ||
      prod === 'bitScan Lite' ||
      prod === 'bitScan AL'
    ) {
      if (!mnQcVal('qc_sscan')) {
        mnQcFocus('qc_sscan');
        return 'กรุณาระบุ จำนวนที่ทดสอบ scan';
      }
      if (!mnQcVal('qc_sphasescan')) {
        mnQcFocus('qc_sphasescan');
        return 'กรุณาระบุ ระยะการยิง';
      }
      if (!mnQcCheckedOk('qc_ssound_shake_light')) {
        mnQcFocus('qc_ssound_shake_light');
        return 'กรุณาระบุ การทดสอบ แสง สั่น เสียง';
      }
      if (!mnQcCheckedOk('qc_sloaddata')) {
        mnQcFocus('qc_sloaddata');
        return 'กรุณาระบุ การทดสอบ การโหลดข้อมูล';
      }
    }
    return null;
  }

  /**
   * Purpose:
   * Append query pair ถ้ามีค่า
   *
   * Parameters สำคัญ:
   * - parts: string[]
   * - key: string
   * - id: string element id
   *
   * Return value:
   * - undefined
   */
  function mnQcAppend(parts, key, id) {
    parts.push(key + '=' + encodeURIComponent(mnQcVal(id)));
  }

  /**
   * Purpose:
   * สร้าง query string สำหรับ `update_wait_qc`
   *
   * Parameters สำคัญ:
   * - tablename: string
   *
   * Return value:
   * - string
   */
  function mnQcBuildQuery(tablename) {
    var parts = [];
    parts.push('table=' + encodeURIComponent(tablename));
    mnQcAppend(parts, 'trp_id', 'trp_id');
    mnQcAppend(parts, 'trp_qc_date', 'trp_qc_date');
    mnQcAppend(parts, 'trp_qc_user', 'trp_qc_user');
    mnQcAppend(parts, 'trp_qc_comment', 'qc_comment');
    var prod = mnQcVal('qc_product_name');
    if (prod === 'bitVisitor Plus' || prod === 'bitVisitor M' || prod === 'bitVisitor Lite') {
      mnQcAppend(parts, 'qc_vusbvolt', 'qc_vusbvolt');
      mnQcAppend(parts, 'qc_vledlan', 'qc_vledlan');
      mnQcAppend(parts, 'qc_vcamerafocus', 'qc_vcamerafocus');
      mnQcAppend(parts, 'qc_vledcard', 'qc_vledcard');
      mnQcAppend(parts, 'qc_vsoundlevel', 'qc_vsoundlevel');
      mnQcAppend(parts, 'qc_vfirmware', 'qc_vfirmware');
      mnQcAppend(parts, 'qc_vtimestart', 'qc_vtimestart');
      mnQcAppend(parts, 'qc_vcpuuse', 'qc_vcpuuse');
      mnQcAppend(parts, 'qc_vramuse', 'qc_vramuse');
      mnQcAppend(parts, 'qc_vexportlan', 'qc_vexportlan');
      mnQcAppend(parts, 'qc_vexportusb', 'qc_vexportusb');
      mnQcAppend(parts, 'qc_vtestprint', 'qc_vtestprint');
      mnQcAppend(parts, 'qc_vtouch', 'qc_vtouch');
      mnQcAppend(parts, 'qc_vsleepmode', 'qc_vsleepmode');
      mnQcAppend(parts, 'qc_vnoopenclose', 'qc_vnoopenclose');
      mnQcAppend(parts, 'qc_vinout', 'qc_vinout');
    } else if (
      prod === 'bitScan Kit' ||
      prod === 'bitScan Pro' ||
      prod === 'bitVehicle' ||
      prod === 'bitScan Lite' ||
      prod === 'bitScan AL'
    ) {
      mnQcAppend(parts, 'qc_sscan', 'qc_sscan');
      mnQcAppend(parts, 'qc_sphasescan', 'qc_sphasescan');
      mnQcAppend(parts, 'qc_ssound_shake_light', 'qc_ssound_shake_light');
      mnQcAppend(parts, 'qc_sloaddata', 'qc_sloaddata');
    } else if (prod === 'Printer 57 mm.' || prod === 'Printer 80 mm.') {
      mnQcAppend(parts, 'qc_plogo', 'qc_plogo');
      mnQcAppend(parts, 'qc_psharpness', 'qc_psharpness');
      mnQcAppend(parts, 'qc_psoundprint', 'qc_psoundprint');
      mnQcAppend(parts, 'qc_pdatacable', 'qc_pdatacable');
      mnQcAppend(parts, 'qc_psticker_lock', 'qc_psticker_lock');
    } else if (prod === 'Smart Card') {
      mnQcAppend(parts, 'qc_scled', 'qc_scled');
      mnQcAppend(parts, 'qc_scslotcard', 'qc_scslotcard');
      mnQcAppend(parts, 'qc_screadcard', 'qc_screadcard');
    } else if (prod === 'Magnetic') {
      mnQcAppend(parts, 'qc_mtled', 'qc_mtled');
      mnQcAppend(parts, 'qc_mttrackerror', 'qc_mttrackerror');
      mnQcAppend(parts, 'qc_mtreadcard', 'qc_mtreadcard');
    } else if (prod === 'Scanner') {
      mnQcAppend(parts, 'qc_snledred', 'qc_snledred');
      mnQcAppend(parts, 'qc_snledblue', 'qc_snledblue');
      mnQcAppend(parts, 'qc_snrj45', 'qc_snrj45');
      mnQcAppend(parts, 'qc_snreadqr', 'qc_snreadqr');
    } else if (prod === 'Scanner Wireless') {
      mnQcAppend(parts, 'qc_swcable', 'qc_swcable');
      mnQcAppend(parts, 'qc_swportusb', 'qc_swportusb');
      mnQcAppend(parts, 'qc_swresetbutton', 'qc_swresetbutton');
      mnQcAppend(parts, 'qc_swresetpair', 'qc_swresetpair');
      mnQcAppend(parts, 'qc_swhandheldledrgb', 'qc_swhandheldledrgb');
      mnQcAppend(parts, 'qc_swreseiveledrgb', 'qc_swreseiveledrgb');
      mnQcAppend(parts, 'qc_swreadqr', 'qc_swreadqr');
    } else if (prod === 'bitSupply 1224' || prod === 'bitSupply 125') {
      mnQcAppend(parts, 'qc_bssoundalert', 'qc_bssoundalert');
      mnQcAppend(parts, 'qc_bsledacdc', 'qc_bsledacdc');
      mnQcAppend(parts, 'qc_bspower', 'qc_bspower');
      mnQcAppend(parts, 'qc_bspowerprint', 'qc_bspowerprint');
    } else if (prod === 'Card Cam') {
      mnQcAppend(parts, 'qc_ccled', 'qc_ccled');
      mnQcAppend(parts, 'qc_ccfocus', 'qc_ccfocus');
      mnQcAppend(parts, 'qc_ccacrylic', 'qc_ccacrylic');
    } else if (prod === 'bitStamp') {
      mnQcAppend(parts, 'qc_bstled', 'qc_bstled');
      mnQcAppend(parts, 'qc_bstreadrfid', 'qc_bstreadrfid');
      mnQcAppend(parts, 'qc_bstopenclose', 'qc_bstopenclose');
      mnQcAppend(parts, 'qc_bstsettingmode', 'qc_bstsettingmode');
      mnQcAppend(parts, 'qc_bstusb', 'qc_bstusb');
    } else if (prod === 'A4 Camera') {
      mnQcAppend(parts, 'qc_a4focus', 'qc_a4focus');
      mnQcAppend(parts, 'qc_a4position', 'qc_a4position');
    } else if (prod === 'Keyboard 84Key' || prod === 'Keyboard 128Key') {
      mnQcAppend(parts, 'qc_kbbuttonvalue', 'qc_kbbuttonvalue');
      mnQcAppend(parts, 'qc_kbbuttonok', 'qc_kbbuttonok');
    } else if (prod === 'Mobile') {
      mnQcAppend(parts, 'qc_mbtranferimg', 'qc_mbtranferimg');
      mnQcAppend(parts, 'qc_mbclearcache', 'qc_mbclearcache');
    } else if (prod === 'Router') {
      mnQcAppend(parts, 'qc_rtsetok', 'qc_rtsetok');
    } else if (prod === 'FaceCam Indoor' || prod === 'FaceCam Outdoor') {
      mnQcAppend(parts, 'qc_fcfocus', 'qc_fcfocus');
    } else {
      mnQcAppend(parts, 'qc_checkok', 'qc_checkok');
    }
    return parts.join('&');
  }

  /**
   * Purpose:
   * เลือกชื่อตาราง log ตามสินค้า — สินค้าที่ไม่มีแบบฟอร์มเฉพาะใช้ `logqc_other`
   *
   * Return value:
   * - string
   */
  function mnQcTableName() {
    var prod = mnQcVal('qc_product_name');
    if (prod === 'bitVisitor Plus' || prod === 'bitVisitor M' || prod === 'bitVisitor Lite') {
      return 'logqc_bitVisitor';
    }
    if (
      prod === 'bitScan Kit' ||
      prod === 'bitScan Pro' ||
      prod === 'bitVehicle' ||
      prod === 'bitScan Lite' ||
      prod === 'bitScan AL'
    ) {
      return 'logqc_bitScan';
    }
    if (prod === 'Printer 57 mm.' || prod === 'Printer 80 mm.') {
      return 'logqc_printer';
    }
    if (prod === 'Smart Card') {
      return 'logqc_smartcard';
    }
    if (prod === 'Magnetic') {
      return 'logqc_magnetic';
    }
    if (prod === 'Scanner') {
      return 'logqc_scanner';
    }
    if (prod === 'Scanner Wireless') {
      return 'logqc_scanner_wireless';
    }
    if (prod === 'bitSupply 1224' || prod === 'bitSupply 125') {
      return 'logqc_bitSupply';
    }
    if (prod === 'Card Cam') {
      return 'logqc_cardcam';
    }
    if (prod === 'bitStamp') {
      return 'logqc_bitStamp';
    }
    if (prod === 'A4 Camera') {
      return 'logqc_a4camera';
    }
    if (prod === 'Keyboard 84Key' || prod === 'Keyboard 128Key') {
      return 'logqc_keyboard';
    }
    if (prod === 'Mobile') {
      return 'logqc_mobile';
    }
    if (prod === 'Router') {
      return 'logqc_router';
    }
    if (prod === 'FaceCam Indoor' || prod === 'FaceCam Outdoor') {
      return 'logqc_facecam';
    }
    return 'logqc_other';
  }

  /**
   * Purpose:
   * ผูก checkbox แบบเดิม (toggle ค่าเมื่อคลิก)
   *
   * Return value:
   * - undefined
   */
  function mnQcBindCheckboxToggles() {
    var ids = [
      'qc_vledlan',
      'qc_vcamerafocus',
      'qc_vledcard',
      'qc_vsoundlevel',
      'qc_vtestprint',
      'qc_vexportlan',
      'qc_vexportusb',
      'qc_vtouch',
      'qc_vsleepmode',
      'qc_ssound_shake_light',
      'qc_sloaddata',
      'qc_scled',
      'qc_scslotcard',
      'qc_mtled',
      'qc_mttrackerror',
      'qc_plogo',
      'qc_psharpness',
      'qc_psoundprint',
      'qc_psticker_lock',
      'qc_snledred',
      'qc_snledblue',
      'qc_snrj45',
      'qc_swcable',
      'qc_swportusb',
      'qc_swresetbutton',
      'qc_swresetpair',
      'qc_swhandheldledrgb',
      'qc_swreseiveledrgb',
      'qc_bssoundalert',
      'qc_bsledacdc',
      'qc_bspower',
      'qc_bspowerprint',
      'qc_ccled',
      'qc_ccfocus',
      'qc_bstled',
      'qc_bstreadrfid',
      'qc_bstopenclose',
      'qc_bstsettingmode',
      'qc_bstusb',
      'qc_a4focus',
      'qc_a4position',
      'qc_kbbuttonvalue',
      'qc_kbbuttonok',
      'qc_mbtranferimg',
      'qc_mbclearcache',
      'qc_rtsetok',
      'qc_fcfocus',
      'qc_checkok'
    ];
    var i;
    for (i = 0; i < ids.length; i += 1) {
      (function (id) {
        var el = global.document.getElementById(id);
        if (!el || el.type !== 'checkbox') {
          return;
        }
        el.addEventListener('click', function () {
          el.value = mnQcCheckboxToggle(el.value);
        });
      })(ids[i]);
    }
    var acrylic = global.document.getElementById('qc_ccacrylic');
    if (acrylic && acrylic.type === 'checkbox') {
      acrylic.addEventListener('click', function () {
        acrylic.value = mnQcCheckboxYesNo(acrylic.value);
      });
    }
    var pdata = global.document.getElementById('qc_pdatacable');
    if (pdata && pdata.type === 'checkbox') {
      pdata.addEventListener('click', function () {
        pdata.value = mnQcCheckboxYesNo(pdata.value);
      });
    }
  }

  /**
   * Purpose:
   * บันทึก — validate แล้วยืนยันแล้ว redirect
   *
   * Return value:
   * - undefined
   */
  function mnQcSave() {
    var err = mnQcValidate();
    if (err) {
      mnQcWarn(err);
      return;
    }
    var tablename = mnQcTableName();
    var prod = mnQcVal('qc_product_name');
    var msg = 'คุณต้องการบันทึกข้อมูล ' + prod + ' หรือไม่';
    var go = function () {
      var q = mnQcBuildQuery(tablename);
      global.window.location = 'update_wait_qc?' + q;
    };
    if (typeof global.mnMaintenanceFlowConfirm === 'function') {
      global.mnMaintenanceFlowConfirm(msg, go);
    } else if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm(msg, {
        title: 'ยืนยัน',
        variant: 'info',
        onConfirm: go,
        onCancel: function () {}
      });
    } else if (global.confirm(msg)) {
      go();
    }
  }

  global.addEventListener('load', function () {
    mnQcBindCheckboxToggles();
    var btn = global.document.getElementById('mn-qc-checklist-save');
    if (btn) {
      btn.addEventListener('click', function (ev) {
        if (ev.preventDefault) {
          ev.preventDefault();
        }
        mnQcSave();
      });
    }
  });
})(typeof window !== 'undefined' ? window : this);
