/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_wait_hardware` — เปลี่ยนสถานะ filter, เปิดหน้าแก้ไขรับเครื่อง (แก้เครื่องสำรองไปที่ `detail_job` จาก PHP)
 * แจ้งเตือน / ยืนยันผ่าน `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm` (หลังโหลด `mn-maintenance-flow-modals.js`)
 *
 * Flow:
 * ฟังก์ชันเรียกจาก inline handler เช่น `sort_company`, `saveStatus`
 */

function mnWaitHwCancelStatus() {
  window.location.reload();
}

/**
 * Purpose:
 * ไปหน้าแก้ไขรอรับเครื่องตาม `trp_id`
 *
 * Parameters สำคัญ:
 * - id: `trp_id`
 * - idRow: เลขแถว (สงวนไว้ให้สอดคล้องเดิม)
 *
 * Return value:
 * - undefined
 */
function mnWaitHwEditStatus(id, idRow) {
  window.location = 'update_wait_hardware?trp_id=' + encodeURIComponent(String(id));
}

/**
 * Purpose:
 * หลังยืนยัน — ปิดการแก้แถวแล้ว redirect ไป `update_wait_hardware` พร้อมพารามิเตอร์บันทึก
 *
 * Parameters สำคัญ:
 * - id: `trp_id`
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitHwSaveStatusAfterConfirm(id, idRow) {
  var elReceive = document.getElementById('w_date_receive' + idRow);
  var elSn = document.getElementById('w_sn' + idRow);
  var elBuy = document.getElementById('w_date_buy' + idRow);
  var rowEl = document.getElementById('row' + idRow);
  if (rowEl) {
    rowEl.style.backgroundColor = 'rgba(240, 240, 240, 0.5)';
  }
  var ids = ['w_date_receive', 'w_sn', 'w_date_buy', 'w_repair_age', 'w_insurance', 'w_repair_inform', 'w_backup'];
  for (var i = 0; i < ids.length; i++) {
    var el = document.getElementById(ids[i] + idRow);
    if (el) {
      el.disabled = true;
    }
  }

  var dateReceive = elReceive.value;
  var sn = elSn.value;
  var dateBuy = elBuy.value;
  var repairAge = document.getElementById('w_repair_age' + idRow) ? document.getElementById('w_repair_age' + idRow).value : '';
  var insurance = document.getElementById('w_insurance' + idRow) ? document.getElementById('w_insurance' + idRow).value : '';
  var repairInform = document.getElementById('w_repair_inform' + idRow) ? document.getElementById('w_repair_inform' + idRow).value : '';

  var maUser = document.getElementById('trp_user_recive_ma' + idRow);
  var q = 'trp_id=' + encodeURIComponent(String(id))
    + '&w_date_receive=' + encodeURIComponent(dateReceive)
    + '&w_sn=' + encodeURIComponent(sn)
    + '&w_date_buy=' + encodeURIComponent(dateBuy)
    + '&w_repair_age=' + encodeURIComponent(repairAge)
    + '&w_insurance=' + encodeURIComponent(insurance)
    + '&w_repair_inform=' + encodeURIComponent(repairInform)
    + '&trp_user_recive_ma=' + encodeURIComponent(maUser ? maUser.value : '');
  window.location = 'save_wait_hardware?' + q;
}

/**
 * Purpose:
 * ตรวจค่าฟิลด์แล้วถามยืนยัน ก่อน redirect ไป `update_wait_hardware` พร้อมพารามิเตอร์บันทึก
 *
 * Parameters สำคัญ:
 * - id: `trp_id`
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitHwSaveStatus(id, idRow) {
  var elReceive = document.getElementById('w_date_receive' + idRow);
  var elSn = document.getElementById('w_sn' + idRow);
  var elBuy = document.getElementById('w_date_buy' + idRow);
  if (!elReceive || !elSn || !elBuy) {
    return;
  }
  if (elReceive.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ วันที่รับซ่อม', 'warning');
    elReceive.focus();
    return;
  }
  if (elSn.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ เลข SN เครื่องซ่อม', 'warning');
    elSn.focus();
    return;
  }
  if (elBuy.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุ วันที่ซื้อ', 'warning');
    elBuy.focus();
    return;
  }
  window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
    mnWaitHwSaveStatusAfterConfirm(id, idRow);
  });
}

/**
 * Purpose:
 * กรองตามบริษัทผ่าน query string
 *
 * Parameters สำคัญ:
 * - com: ค่าจาก select
 *
 * Return value:
 * - undefined
 */
function mnWaitHwSortCompany(com) {
  if (com === 'All' || com === '') {
    window.location = 'status_wait_hardware';
  } else {
    window.location = 'status_wait_hardware?company=' + encodeURIComponent(com);
  }
}

/**
 * Purpose:
 * กรองตามสินค้าผ่าน query string
 *
 * Parameters สำคัญ:
 * - prod: ค่าจาก select
 *
 * Return value:
 * - undefined
 */
function mnWaitHwSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_wait_hardware';
  } else {
    window.location = 'status_wait_hardware?product=' + encodeURIComponent(prod);
  }
}
