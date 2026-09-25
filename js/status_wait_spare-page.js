/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมหน้า `status_wait_spare` — filter สินค้า/ค้นหาที่อยู่, Enter ในช่องที่อยู่, บันทึกผ่าน `update_wait_spare` พร้อม modal แทน `alert`
 *
 * Flow:
 * jQuery keyup บน `#search_addr` → `mnStatusWaitSpareSortAddr` → `mnStatusWaitSpareSave` สร้าง query แล้ว redirect
 */

(function ($) {
  'use strict';

  $(document).ready(function () {
    $('#search_addr').on('keyup', function (event) {
      if (event.keyCode === 13) {
        mnStatusWaitSpareSortAddr();
      }
    });
  });
})(typeof jQuery !== 'undefined' ? jQuery : function () {});

/**
 * Purpose:
 * กรองตามสินค้า
 *
 * Parameters สำคัญ:
 * - prod: ค่าจาก select
 *
 * Return value:
 * - undefined
 */
function mnStatusWaitSpareSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_wait_spare';
  } else {
    window.location = 'status_wait_spare?product=' + encodeURIComponent(prod);
  }
}

/**
 * Purpose:
 * กรองตามข้อความที่อยู่ (จากช่องค้นหา)
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnStatusWaitSpareSortAddr() {
  var el = document.getElementById('search_addr');
  var v = el ? String(el.value) : '';
  if (v === 'All' || v === '') {
    window.location = 'status_wait_spare';
  } else {
    window.location = 'status_wait_spare?addr=' + encodeURIComponent(v);
  }
}

/**
 * Purpose:
 * ตรวจวันที่รับคืนและผู้รับคืนแล้ว redirect ไป `update_wait_spare`
 *
 * Parameters สำคัญ:
 * - tspId: `tsp_id`
 * - idRow: ลำดับแถวบนหน้า
 *
 * Return value:
 * - undefined
 */
function mnStatusWaitSpareSave(tspId, idRow) {
  var dateEl = document.getElementById('tsp_datereturn' + idRow);
  var recvEl = document.getElementById('tsp_receiveby' + idRow);
  var snEl = document.getElementById('tsp_sn' + idRow);
  if (!dateEl || dateEl.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('กรุณาระบุ วันที่รับ', 'warning');
    } else {
      window.alert('กรุณาระบุ วันที่รับ');
    }
    if (dateEl) {
      dateEl.focus();
    }
    return;
  }
  if (!recvEl || recvEl.value === '') {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert('ไม่พบชื่อผู้รับคืนจาก session — ติดต่อผู้ดูแลระบบ', 'warning');
    } else {
      window.alert('ไม่พบชื่อผู้รับคืนจาก session — ติดต่อผู้ดูแลระบบ');
    }
    if (dateEl) {
      dateEl.focus();
    }
    return;
  }
  var sn = snEl ? snEl.value : '';
  var q =
    'tsp_id=' +
    encodeURIComponent(String(tspId)) +
    '&tsp_datereturn=' +
    encodeURIComponent(dateEl.value) +
    '&tsp_sn=' +
    encodeURIComponent(sn) +
    '&tsp_receiveby=' +
    encodeURIComponent(recvEl.value);
  window.location = 'update_wait_spare?' + q;
}
