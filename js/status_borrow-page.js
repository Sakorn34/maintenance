/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `status_borrow` — แสดง/ซ่อนช่องวันที่ส่งคืนเมื่อสถานะ `3-ส่งคืนแล้ว` และบันทึกผ่าน `insert_borrow` ด้วย modal ยืนยัน
 */

(function ($) {
  'use strict';

  /**
   * Purpose:
   * แสดงหรือซ่อน input วันที่ส่งคืนตามค่า `b_status`
   *
   * Parameters สำคัญ:
   * - rowNum: ลำดับแถว (ตรง suffix ของ id)
   *
   * Return value:
   * - undefined
   */
  function mnStatusBorrowToggleReturnDate(rowNum) {
    var sel = document.getElementById('b_status' + rowNum);
    var dt = document.getElementById('b_date_return' + rowNum);
    if (!sel || !dt) {
      return;
    }
    if (sel.value === '3-ส่งคืนแล้ว') {
      dt.style.display = '';
    } else {
      dt.style.display = 'none';
    }
  }

  $(document).ready(function () {
    $('.mn-status-borrow-statussel').each(function () {
      var row = $(this).data('row');
      if (row) {
        mnStatusBorrowToggleReturnDate(row);
      }
    });
    $(document).on('change', '.mn-status-borrow-statussel', function () {
      var row = $(this).data('row');
      if (row) {
        mnStatusBorrowToggleReturnDate(row);
      }
    });
  });
})(typeof jQuery !== 'undefined' ? jQuery : function () {});

/**
 * Purpose:
 * หลังยืนยัน — redirect ไป `insert_borrow` พร้อม query string
 *
 * Parameters สำคัญ:
 * - bId: `b_id`
 * - rowid: ลำดับแถว
 *
 * Return value:
 * - undefined
 */
function mnStatusBorrowDoSave(bId, rowid) {
  var st = document.getElementById('b_status' + rowid);
  var tr = document.getElementById('b_tracking' + rowid);
  var dr = document.getElementById('b_date_return' + rowid);
  var bDateReturn = '';
  if (st && st.value === '3-ส่งคืนแล้ว' && dr) {
    bDateReturn = dr.value;
  }
  var q = 'b_id=' + encodeURIComponent(String(bId))
    + '&b_status=' + encodeURIComponent(st ? st.value : '')
    + '&b_tracking=' + encodeURIComponent(tr ? tr.value : '')
    + '&b_date_return=' + encodeURIComponent(bDateReturn);
  window.location = 'insert_borrow?' + q;
}

/**
 * Purpose:
 * ยืนยันก่อนบันทึกแถวยืม
 *
 * Parameters สำคัญ:
 * - bId: `b_id`
 * - rowid: ลำดับแถว
 *
 * Return value:
 * - undefined
 */
function mnStatusBorrowSave(bId, rowid) {
  if (typeof window.mnMaintenanceFlowConfirm === 'function') {
    window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
      mnStatusBorrowDoSave(bId, rowid);
    });
  } else if (window.confirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!')) {
    mnStatusBorrowDoSave(bId, rowid);
  }
}
