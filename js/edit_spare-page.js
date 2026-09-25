/**
 * วัตถุประสงค์และขอบเขต:
 * บันทึกฟอร์ม `edit_spare` — เลือก S/N เท่านั้น แล้ว redirect ไป `update_edit_spare`
 * แจ้งเตือนผ่าน `mnMaintenanceFlowAlert` (หลังโหลด `mn-maintenance-flow-modals.js`)
 */

/**
 * Purpose:
 * ตรวจ S/N แล้วส่งไป `update_edit_spare` (ไม่ส่งข้อมูลขนส่ง — บันทึกทีหลังที่ detail_job / status_logistic)
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnEditSpareSubmit() {
  var sn = document.getElementById('tsp_sn');
  if (!sn || sn.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาเลือก S/N เครื่องสำรอง', 'warning');
    if (sn) {
      sn.focus();
    }
    return;
  }
  var q = 'ts_id=' + encodeURIComponent(document.getElementById('ts_id').value)
    + '&trp_id=' + encodeURIComponent(document.getElementById('trp_id').value)
    + '&tsp_product=' + encodeURIComponent(document.getElementById('tsp_product').value)
    + '&tsp_sn=' + encodeURIComponent(sn.value)
    + '&tsp_remarks=' + encodeURIComponent(document.getElementById('tsp_remarks').value);
  var snOld = document.getElementById('tsp_sn_old');
  if (snOld && snOld.value !== '') {
    q += '&tsp_sn_old=' + encodeURIComponent(snOld.value);
  }
  window.location = 'update_edit_spare?' + q;
}
