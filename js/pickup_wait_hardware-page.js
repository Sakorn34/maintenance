/**
 * วัตถุประสงค์และขอบเขต:
 * ฟอร์มแจ้ง pickup wait hardware — validate แล้ว redirect `save_pickup_wait_hardware`
 * แจ้งเตือน / ยืนยันผ่าน `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm`
 */

/**
 * Purpose:
 * หลังยืนยัน — สร้างข้อความ `trp_contact_pickup` แล้วไป `save_pickup_wait_hardware`
 *
 * Parameters สำคัญ:
 * - id: `trp_id` (สงวนไว้ให้สอดคล้องเดิม)
 *
 * Return value:
 * - undefined
 */
function mnPickupWaitHwDoSave(id) {
  var due = document.getElementById('trp_duedate_pickup');
  var cust = document.getElementById('trp_contact_customer');
  var tel = document.getElementById('trp_contact_tel');
  var user = document.getElementById('trp_user_inform');
  var remarks = document.getElementById('trp_remarks') ? document.getElementById('trp_remarks').value : '';
  var detail = 'วันที่นัด : ' + due.value + '<br>' + cust.value + '<br>' + tel.value + '<br>' + remarks + '<br>ผู้แจ้ง : ' + user.value;
  var trpId = document.getElementById('trp_id').value;
  var q = 'trp_id=' + encodeURIComponent(trpId)
    + '&trp_contact_pickup=' + encodeURIComponent(detail)
    + '&trp_user_inform=' + encodeURIComponent(user.value);
  window.location = 'save_pickup_wait_hardware?' + q;
}

/**
 * Purpose:
 * ตรวจค่าแล้วถามยืนยัน ก่อนส่งไป `save_pickup_wait_hardware`
 *
 * Parameters สำคัญ:
 * - id: `trp_id` (สงวนไว้ให้สอดคล้องเดิม)
 *
 * Return value:
 * - undefined
 */
function mnPickupWaitHwSaveInform(id) {
  var due = document.getElementById('trp_duedate_pickup');
  var cust = document.getElementById('trp_contact_customer');
  var tel = document.getElementById('trp_contact_tel');
  var user = document.getElementById('trp_user_inform');
  if (!due || due.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ วันเข้ารับพัสดุ', 'warning');
    if (due) {
      due.focus();
    }
    return;
  }
  if (!cust || cust.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ ชื่อคนติดต่อหน้างาน', 'warning');
    if (cust) {
      cust.focus();
    }
    return;
  }
  if (!tel || tel.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ เบอร์โทร', 'warning');
    if (tel) {
      tel.focus();
    }
    return;
  }
  if (!user || user.value === '') {
    window.mnMaintenanceFlowAlert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
    if (due) {
      due.focus();
    }
    return;
  }
  window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
    mnPickupWaitHwDoSave(id);
  });
}
