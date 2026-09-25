/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_rate` — เปลี่ยน filter ผ่าน query string และเปิดหน้าแก้ไขประเมิน (`update_rate`)
 *
 * Flow:
 * ฟังก์ชันเรียกจาก inline handler บน `<select>` และลิงก์แก้ไขแถว
 */

/**
 * Purpose:
 * รีโหลดหน้าเมื่อยกเลิก (สงวนไว้ให้สอดคล้อง flow เดิม)
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnStatusRateCancel() {
  window.location.reload();
}

/**
 * Purpose:
 * ไปหน้าแก้ไขประเมินตาม `trp_id`
 *
 * Parameters สำคัญ:
 * - id: `trp_id`
 * - idRow: index แถว (สงวนไว้ให้สอดคล้อง signature เดิม)
 *
 * Return value:
 * - undefined
 */
function mnStatusRateEditStatus(id, idRow) {
  window.location = 'update_rate?trp_id=' + encodeURIComponent(String(id));
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
function mnStatusRateSortCompany(com) {
  if (com === 'All' || com === '') {
    window.location = 'status_rate';
  } else {
    window.location = 'status_rate?company=' + encodeURIComponent(com);
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
function mnStatusRateSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_rate';
  } else {
    window.location = 'status_rate?product=' + encodeURIComponent(prod);
  }
}

/**
 * Purpose:
 * กรองตามประเภทประกัน (ค่า short) คงพารามิเตอร์สินค้าจาก hidden
 *
 * Parameters สำคัญ:
 * - insurance: ค่าจาก select (`All` | `insurance` | `exp_insurance` | `ma_insurance`)
 *
 * Return value:
 * - undefined
 */
function mnStatusRateSortInsurance(insurance) {
  var prodEl = document.getElementById('mn_rate_txt_head_product');
  var prod = prodEl ? prodEl.value : '';
  if (insurance === 'All' || insurance === '') {
    window.location = 'status_rate';
  } else {
    window.location = 'status_rate?trp_insurance=' + encodeURIComponent(insurance)
      + '&product=' + encodeURIComponent(prod);
  }
}
