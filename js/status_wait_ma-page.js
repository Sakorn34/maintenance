/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_wait_ma` — filter บริษัท/สินค้า, บันทึกวันที่ซ่อมเสร็จและผู้ซ่อม
 * ใช้ `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm` แทน `alert` / `confirm`
 */

/**
 * Purpose:
 * redirect กรองตามบริษัท
 *
 * Parameters สำคัญ:
 * - com: ค่า select
 *
 * Return value:
 * - undefined
 */
function mnWaitMaSortCompany(com) {
  if (com === 'All' || com === '') {
    window.location = 'status_wait_ma';
  } else {
    window.location = 'status_wait_ma?company=' + encodeURIComponent(com);
  }
}

/**
 * Purpose:
 * redirect กรองตามสินค้า
 *
 * Parameters สำคัญ:
 * - prod: ค่า select
 *
 * Return value:
 * - undefined
 */
function mnWaitMaSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_wait_ma';
  } else {
    window.location = 'status_wait_ma?product=' + encodeURIComponent(prod);
  }
}

/**
 * Purpose:
 * หลังยืนยัน — redirect ไป `update_wait_ma` พร้อมพารามิเตอร์
 *
 * Parameters สำคัญ:
 * - id: trp_id
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitMaSaveStatusAfterConfirm(id, idRow) {
  var elDate = document.getElementById('trp_success_date' + idRow);
  var elUser = document.getElementById('trp_user_ma' + idRow);
  var elFirm = document.getElementById('trp_status_firmma' + idRow);
  if (!elDate || !elUser || !elFirm) {
    return;
  }
  var dataUpdate = 'id_trp=' + encodeURIComponent(String(id))
    + '&trp_success_date=' + encodeURIComponent(elDate.value)
    + '&trp_user_ma=' + encodeURIComponent(elUser.value)
    + '&trp_status_firmma=' + encodeURIComponent(elFirm.value);
  window.location = 'update_wait_ma?' + dataUpdate;
}

/**
 * Purpose:
 * ตรวจวันที่ซ่อมเสร็จและผู้ซ่อม แล้วยืนยันก่อนบันทึก
 *
 * Parameters สำคัญ:
 * - id: trp_id
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitMaSaveStatus(id, idRow) {
  var elDate = document.getElementById('trp_success_date' + idRow);
  var elUser = document.getElementById('trp_user_ma' + idRow);
  if (!elDate || !elUser) {
    return;
  }
  if (elDate.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุวันที่ซ่อมเสร็จ', 'warning');
    elDate.focus();
    return;
  }
  if (elUser.value === '') {
    window.mnMaintenanceFlowAlert('ไม่พบชื่อผู้ซ่อมจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
    elDate.focus();
    return;
  }
  window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
    mnWaitMaSaveStatusAfterConfirm(id, idRow);
  });
}
