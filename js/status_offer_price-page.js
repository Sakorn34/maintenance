/**
 * วัตถุประสงค์และขอบเขต:
 * พฤติกรรมฝั่งเบราว์เซอร์ของหน้า `status_offer_price` — กรองผ่าน query string (การกรอก QV ย้ายไป `edit_offer_price`)
 *
 * Flow:
 * ฟังก์ชันเรียกจาก inline handler บน `<select>` หัวตาราง
 */

/**
 * Purpose:
 * กรองตามบริษัท
 *
 * Parameters สำคัญ:
 * - com: ค่าจาก select
 *
 * Return value:
 * - undefined
 */
function mnStatusOfferPriceSortCompany(com) {
  if (com === 'All' || com === '') {
    window.location = 'status_offer_price';
  } else {
    window.location = 'status_offer_price?company=' + encodeURIComponent(com);
  }
}

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
function mnStatusOfferPriceSortProduct(prod) {
  if (prod === 'All' || prod === '') {
    window.location = 'status_offer_price';
  } else {
    window.location = 'status_offer_price?product=' + encodeURIComponent(prod);
  }
}
