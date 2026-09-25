/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_wait_qc` — กรองรายการตามสินค้าและชื่อบริษัท/ผู้ติดต่อ (พารามิเตอร์ `company`) ผ่าน select แล้ว redirect ทันที
 */

/**
 * Purpose:
 * ประกอบ query string จากสินค้าและชื่อที่เลือกใน select แล้ว redirect ไปหน้าเดียวกัน
 *
 * Parameters สำคัญ:
 * - prod: ค่า select สินค้า (ว่างหรือ All = ไม่กรองสินค้า)
 *
 * Return value:
 * - undefined
 */
function mnWaitQcSortProduct(prod) {
  var el = document.getElementById('mn_wait_qc_company');
  var company = el && el.value ? el.value.replace(/^\s+|\s+$/g, '') : '';
  var parts = [];
  if (prod && prod !== 'All' && prod !== '') {
    parts.push('product=' + encodeURIComponent(prod));
  }
  if (company !== '') {
    parts.push('company=' + encodeURIComponent(company));
  }
  window.location = 'status_wait_qc' + (parts.length ? '?' + parts.join('&') : '');
}
