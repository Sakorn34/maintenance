/**
 * วัตถุประสงค์และขอบเขต:
 * ฟังก์ชันร่วมสำหรับ flow หน้าย่อย (wait hardware, pickup, tracking, edit spare, คิวส่งลิงก์ลูกค้า ฯลฯ) — เรียก `mnModalAlert` / `mnModalConfirm` แทน `alert` / `confirm`
 * ต้องโหลด **หลัง** `js/mn-modal.js` (ผ่าน `includes/mn_modal_assets.php`) และ **ก่อน** สคริปต์ `*-page.js` ของแต่ละหน้า
 *
 * Flow:
 * หน้า PHP ใส่ `mn_modal_assets.php` แล้ว `<script src="js/mn-maintenance-flow-modals.js">` แล้วค่อยสคริปต์หน้า
 */

(function (global) {
  'use strict';

  /**
   * Purpose:
   * แสดงข้อความแจ้งเตือนแบบ modal กลางหรือ fallback `alert`
   *
   * Parameters สำคัญ:
   * - message: string ข้อความ
   * - variant: string `'info'|'success'|'warning'|'danger'` (ค่าเริ่มต้น `warning`)
   *
   * Return value:
   * - undefined
   */
  global.mnMaintenanceFlowAlert = function (message, variant) {
    var v = variant || 'warning';
    if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(String(message), { variant: v });
    } else {
      global.alert(String(message));
    }
  };

  /**
   * Purpose:
   * ถามยืนยันแบบ modal หรือ fallback `confirm` แล้วเรียก `onOk` เมื่อผู้ใช้ยืนยัน
   *
   * Parameters สำคัญ:
   * - message: string ข้อความ
   * - onOk: function เรียกเมื่อยืนยัน
   *
   * Return value:
   * - undefined
   */
  global.mnMaintenanceFlowConfirm = function (message, onOk) {
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
  };
})(typeof window !== 'undefined' ? window : this);
