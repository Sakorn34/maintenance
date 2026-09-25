/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `status_logistic_pickup` — validate แล้วยืนยันด้วย modal ก่อน redirect ไป `save_pickup_wait_hardware`
 */

/**
 * Purpose:
 * หลังยืนยัน — ส่ง query ไป `save_pickup_wait_hardware` (โหมดบันทึก logistics pickup)
 *
 * Parameters สำคัญ:
 * - trpId: `trp_id`
 * - idRow: ลำดับแถวบนหน้า
 *
 * Return value:
 * - undefined
 */
function mnStatusLogisticPickupDoSave(trpId, idRow) {
  var d = document.getElementById('trp_date_pickup' + idRow);
  var l = document.getElementById('trp_logistics_pickup' + idRow);
  var t = document.getElementById('trp_tracking_pickup' + idRow);
  var u = document.getElementById('trp_user_inform_logistics' + idRow);
  var q = 'trp_id=' + encodeURIComponent(String(trpId))
    + '&trp_date_pickup=' + encodeURIComponent(d ? d.value : '')
    + '&trp_logistics_pickup=' + encodeURIComponent(l ? l.value : '')
    + '&trp_tracking_pickup=' + encodeURIComponent(t ? t.value : '')
    + '&trp_user_inform_logistics=' + encodeURIComponent(u ? u.value : '');
  window.location = 'save_pickup_wait_hardware?' + q;
}

/**
 * Purpose:
 * ตรวจฟิลด์ แล้วถามยืนยันก่อนบันทึก
 *
 * Parameters สำคัญ:
 * - trpId: `trp_id`
 * - idRow: ลำดับแถว
 *
 * Return value:
 * - undefined
 */
function mnStatusLogisticPickupSave(trpId, idRow) {
  var d = document.getElementById('trp_date_pickup' + idRow);
  var l = document.getElementById('trp_logistics_pickup' + idRow);
  var t = document.getElementById('trp_tracking_pickup' + idRow);
  var u = document.getElementById('trp_user_inform_logistics' + idRow);
  if (!d || d.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('กรุณาระบุ วันที่เข้ารับ', 'warning');
    } else {
      window.alert('กรุณาระบุ วันที่เข้ารับ');
    }
    if (d) {
      d.focus();
    }
    return;
  }
  if (!l || l.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('กรุณาระบุ ขนส่งที่เข้ารับ', 'warning');
    } else {
      window.alert('กรุณาระบุ ขนส่งที่เข้ารับ');
    }
    if (l) {
      l.focus();
    }
    return;
  }
  if (!t || t.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('กรุณาระบุ หมายเลข Tracking', 'warning');
    } else {
      window.alert('กรุณาระบุ หมายเลข Tracking');
    }
    if (t) {
      t.focus();
    }
    return;
  }
  if (!u || u.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
    } else {
      window.alert('ไม่พบชื่อผู้บันทึกจาก session — ติดต่อผู้ดูแลระบบ');
    }
    if (d) {
      d.focus();
    }
    return;
  }
  if (typeof window.mnMaintenanceFlowConfirm === 'function') {
    window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
      mnStatusLogisticPickupDoSave(trpId, idRow);
    });
  } else if (window.confirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!')) {
    mnStatusLogisticPickupDoSave(trpId, idRow);
  }
}
