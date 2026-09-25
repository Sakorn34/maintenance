/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_wait_firmQV` — filter, ค้นหา QV, บันทึกอนุมัติ (แก้ไขใบเสนอราคาไปหน้า `detail_job` จาก PHP)
 * ใช้ `mnMaintenanceFlowAlert` / `mnMaintenanceFlowConfirm` แทน `alert` / `confirm`
 *
 * Flow:
 * document ready → bind search_qv autocomplete → handler จาก inline `onclick` / `onchange`
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
function mnWaitFirmQvSortCompany(com) {
  if (com === 'All' || com === '') {
    window.location = 'status_wait_firmQV';
  } else {
    window.location = 'status_wait_firmQV?company=' + encodeURIComponent(com);
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
function mnWaitFirmQvSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_wait_firmQV';
  } else {
    window.location = 'status_wait_firmQV?product=' + encodeURIComponent(prod);
  }
}

/**
 * Purpose:
 * redirect กรองตามเลข QV จากช่องค้นหา
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnWaitFirmQvSortQv() {
  var el = document.getElementById('search_qv');
  if (!el) {
    return;
  }
  var v = el.value;
  if (v === 'All' || v === '') {
    window.location = 'status_wait_firmQV';
  } else {
    window.location = 'status_wait_firmQV?qv=' + encodeURIComponent(v);
  }
}

/**
 * Purpose:
 * หลังยืนยัน — redirect ไป `update_wait_firmQV` พร้อมพารามิเตอร์บันทึกอนุมัติ
 *
 * Parameters สำคัญ:
 * - id: trp_id
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitFirmQvSaveStatusAfterConfirm(id, idRow) {
  var elDate = document.getElementById('trp_firm_qv_date' + idRow);
  var elBackup = document.getElementById('trp_backup' + idRow);
  var elFirm = document.getElementById('trp_status_firmma' + idRow);
  if (!elDate || !elBackup || !elFirm) {
    return;
  }
  var dataUpdate = 'id_trp=' + encodeURIComponent(String(id))
    + '&trp_firm_qv_date=' + encodeURIComponent(elDate.value)
    + '&trp_backup=' + encodeURIComponent(elBackup.value)
    + '&trp_status_firmma=' + encodeURIComponent(elFirm.value);
  window.location = 'update_wait_firmQV?' + dataUpdate;
}

/**
 * Purpose:
 * ตรวจวันที่อนุมัติแล้วยืนยันก่อนบันทึก
 *
 * Parameters สำคัญ:
 * - id: trp_id
 * - idRow: index แถว
 *
 * Return value:
 * - undefined
 */
function mnWaitFirmQvSaveStatus(id, idRow) {
  var elDate = document.getElementById('trp_firm_qv_date' + idRow);
  if (!elDate) {
    return;
  }
  if (elDate.value === '') {
    window.mnMaintenanceFlowAlert('กรุณาระบุวันที่ อนุมัติ', 'warning');
    elDate.focus();
    return;
  }
  window.mnMaintenanceFlowConfirm('คุณต้องการบันทึกข้อมูลหรือไม่!!!', function () {
    mnWaitFirmQvSaveStatusAfterConfirm(id, idRow);
  });
}

(function ($) {
  'use strict';
  $(function () {
    $('.search-box-qv input[type="text"]').on('keyup input', function () {
      var inputVal = $(this).val();
      var resultDropdown = $(this).siblings('.result');
      if (inputVal.length) {
        $.get('search_qv', { term: inputVal }).done(function (data) {
          resultDropdown.html(data);
        });
      } else {
        resultDropdown.empty();
      }
    });
    $(document).on('click', '.result p', function () {
      $(this).parents('.search-box-qv').find('input[type="text"]').val($(this).text());
      $(this).parent('.result').empty();
      mnWaitFirmQvSortQv();
    });
    $('#search_qv').on('keyup', function (event) {
      if (event.keyCode === 13) {
        mnWaitFirmQvSortQv();
      }
    });
  });
}(jQuery));
