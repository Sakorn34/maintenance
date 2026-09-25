/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกประวัติติดตาม — validate แล้ว redirect `save_tracking_history`
 * แจ้งเตือน / ยืนยันผ่าน `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm`
 */

/**
 * Purpose:
 * หลังยืนยัน — ส่ง query ไป `save_tracking_history`
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnTrackingHistoryDoSave() {
  var nextDue = document.getElementById('th_date_nextdue');
  var det = document.getElementById('th_detail');
  var usr = document.getElementById('th_user');
  var q = 'th_ts_id=' + encodeURIComponent(document.getElementById('th_ts_id').value)
    + '&th_trp_id=' + encodeURIComponent(document.getElementById('th_trp_id').value)
    + '&th_tsp_id=' + encodeURIComponent(document.getElementById('th_tsp_id').value)
    + '&th_date_tracking=' + encodeURIComponent(document.getElementById('th_date_tracking').value)
    + '&th_date_nextdue=' + encodeURIComponent(nextDue.value)
    + '&th_status_job=' + encodeURIComponent(document.getElementById('th_status_job').value)
    + '&th_detail=' + encodeURIComponent(det.value)
    + '&th_user=' + encodeURIComponent(usr.value);
  window.location = 'save_tracking_history?' + q;
}

/**
 * Purpose:
 * ตรวจค่าแล้วถามยืนยัน ก่อนส่งไป `save_tracking_history`
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnTrackingHistorySave() {
  var nextDue = document.getElementById('th_date_nextdue');
  var det = document.getElementById('th_detail');
  var usr = document.getElementById('th_user');
  if (!nextDue || nextDue.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ วันที่ติดตามถัดไป', 'warning');
    if (nextDue) {
      nextDue.focus();
    }
    return;
  }
  if (!det || det.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ รายละเอียด', 'warning');
    if (det) {
      det.focus();
    }
    return;
  }
  if (!usr || usr.value === '') {
    window.mnMaintenanceFlowAlert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
    if (det) {
      det.focus();
    }
    return;
  }
  window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
    mnTrackingHistoryDoSave();
  });
}
