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

/**
 * Purpose:
 * ปิด popup รายการอะไหล่ที่เบิกแล้ว
 *
 * Parameters สำคัญ:
 * - ไม่มี
 *
 * Return value:
 * - undefined
 */
function mnWaitMaPickupPreviewClose() {
  var modal = document.getElementById('mn_wait_ma_pickup_preview_modal');
  if (!modal) {
    return;
  }
  modal.classList.remove('mn-wait-ma-pickup-preview-modal--open');
  modal.setAttribute('aria-hidden', 'true');
}

/**
 * Purpose:
 * แสดงข้อมูลรายการอะไหล่ใน popup
 *
 * Parameters สำคัญ:
 * - data: object จาก API maintenance_inventory_pickup_lines
 *
 * Return value:
 * - undefined
 */
function mnWaitMaPickupPreviewAppendLines(list, lines) {
  var i;
  var line;
  var li;
  var main;
  var name;
  var product;
  var remark;
  var qty;
  if (!list) {
    return;
  }
  lines = lines || [];
  for (i = 0; i < lines.length; i++) {
    line = lines[i];
    li = document.createElement('li');
    li.className = 'mn-wait-ma-pickup-preview-modal__line';
    if (line.line_kind === 'tech') {
      li.className += ' mn-wait-ma-pickup-preview-modal__line--tech';
    }
    main = document.createElement('div');
    main.className = 'mn-wait-ma-pickup-preview-modal__line-main';
    if (line.line_kind === 'tech') {
      var techBadge = document.createElement('span');
      techBadge.className = 'mn-wait-ma-pickup-preview-modal__tech-badge';
      techBadge.textContent = 'คลังช่าง';
      techBadge.title = 'ตัดสต็อกคลังช่างแล้ว';
      main.appendChild(techBadge);
    }
    name = document.createElement('span');
    name.className = 'mn-wait-ma-pickup-preview-modal__line-name';
    name.textContent = line.part_name || '—';
    main.appendChild(name);
    if (line.product) {
      product = document.createElement('span');
      product.className = 'mn-wait-ma-pickup-preview-modal__line-product';
      product.textContent = line.product;
      main.appendChild(product);
    }
    if (line.remarks) {
      remark = document.createElement('span');
      remark.className = 'mn-wait-ma-pickup-preview-modal__line-remark';
      remark.textContent = line.remarks;
      main.appendChild(remark);
    }
    qty = document.createElement('span');
    qty.className = 'mn-wait-ma-pickup-preview-modal__line-qty';
    qty.textContent = line.amount || '—';
    li.appendChild(main);
    li.appendChild(qty);
    list.appendChild(li);
  }
}

function mnWaitMaPickupPreviewRenderDocument(body, doc, sectionClass) {
  var block;
  var head;
  var metaParts;
  var count;
  var list;
  if (!body || !doc) {
    return;
  }
  block = document.createElement('section');
  block.className = 'mn-wait-ma-pickup-preview-modal__doc ' + (sectionClass || '');
  head = document.createElement('div');
  head.className = 'mn-wait-ma-pickup-preview-modal__doc-head';
  metaParts = [];
  if (doc.pre_id) { metaParts.push('เลขเอกสาร ' + doc.pre_id); }
  if (doc.pre_date) { metaParts.push(doc.pre_date); }
  if (doc.pre_type) { metaParts.push(doc.pre_type); }
  if (doc.requested_by) { metaParts.push('ผู้ขอเบิก: ' + doc.requested_by); }
  if (doc.approved_by) { metaParts.push('ผู้อนุมัติจ่าย: ' + doc.approved_by); }
  head.textContent = metaParts.join(' | ');
  block.appendChild(head);
  count = document.createElement('p');
  count.className = 'mn-wait-ma-pickup-preview-modal__count';
  var countText = String(doc.line_count || 0) + ' รายการอะไหล่';
  var techN = typeof doc.line_count_tech === 'number' ? doc.line_count_tech : 0;
  if (techN > 0) {
    var invN = doc.line_count - techN;
    countText += ' (คลังกลาง ' + invN + ' | คลังช่าง ' + techN + ' | ตัดแล้ว)';
  }
  count.textContent = countText;
  block.appendChild(count);
  list = document.createElement('ul');
  list.className = 'mn-wait-ma-pickup-preview-modal__lines';
  block.appendChild(list);
  mnWaitMaPickupPreviewAppendLines(list, doc.lines);
  body.appendChild(block);
}

function mnWaitMaPickupPreviewRender(data) {
  var title = document.getElementById('mn_wait_ma_pickup_preview_title');
  var meta = document.getElementById('mn_wait_ma_pickup_preview_meta');
  var body = document.getElementById('mn_wait_ma_pickup_preview_body');
  var pending;
  var issued;
  var i;
  var remarksEl;
  if (!title || !meta || !body || !data) {
    return;
  }
  body.innerHTML = '';

  if (data.mode === 'machine') {
    title.textContent = data.mtn_key ? 'ใบงาน ' + data.mtn_key : '—';
    pending = data.pending_documents || [];
    issued = data.issued_documents || [];
    meta.textContent =
      'รอคลังจ่าย ' + String(pending.length) + ' เอกสาร | จ่ายแล้ว ' + String(issued.length) + ' เอกสาร';
    if (data.remarks) {
      remarksEl = document.createElement('p');
      remarksEl.className = 'mn-wait-ma-pickup-preview-modal__remarks';
      remarksEl.textContent = data.remarks;
      body.appendChild(remarksEl);
    }
    if (pending.length > 0) {
      remarksEl = document.createElement('h3');
      remarksEl.className =
        'mn-wait-ma-pickup-preview-modal__section-title mn-wait-ma-pickup-preview-modal__section-title--pending';
      remarksEl.textContent = 'รอคลังจ่าย';
      body.appendChild(remarksEl);
      for (i = 0; i < pending.length; i++) {
        mnWaitMaPickupPreviewRenderDocument(body, pending[i], 'mn-wait-ma-pickup-preview-modal__doc--pending');
      }
    }
    if (issued.length > 0) {
      remarksEl = document.createElement('h3');
      remarksEl.className =
        'mn-wait-ma-pickup-preview-modal__section-title mn-wait-ma-pickup-preview-modal__section-title--issued';
      remarksEl.textContent = 'จ่ายแล้ว';
      body.appendChild(remarksEl);
      for (i = 0; i < issued.length; i++) {
        mnWaitMaPickupPreviewRenderDocument(body, issued[i], 'mn-wait-ma-pickup-preview-modal__doc--issued');
      }
    }
    return;
  }

  title.textContent = data.pre_id || '—';
  var metaParts = [];
  if (data.pre_date) { metaParts.push(data.pre_date); }
  if (data.pre_type) { metaParts.push(data.pre_type); }
  if (data.requested_by) { metaParts.push('ผู้ขอเบิก: ' + data.requested_by); }
  if (data.approved_by) { metaParts.push('ผู้อนุมัติจ่าย: ' + data.approved_by); }
  meta.textContent = metaParts.join(' | ');
  if (data.remarks) {
    remarksEl = document.createElement('p');
    remarksEl.className = 'mn-wait-ma-pickup-preview-modal__remarks';
    remarksEl.textContent = data.remarks;
    body.appendChild(remarksEl);
  }
  mnWaitMaPickupPreviewRenderDocument(body, data, '');
}

/**
 * Purpose:
 * เปิด popup และโหลดรายการอะไหล่จาก API
 *
 * Parameters สำคัญ:
 * - mtnKey: เลขใบงาน 7 ตัว
 * - trpSn: S/N ซ่อม
 * - pickupAddUrl: URL เปิด inventory เบิกเพิ่ม
 *
 * Return value:
 * - undefined
 */
function mnWaitMaPickupPreviewOpen(mtnKey, trpSn, pickupAddUrl) {
  var modal = document.getElementById('mn_wait_ma_pickup_preview_modal');
  var loading = document.getElementById('mn_wait_ma_pickup_preview_loading');
  var content = document.getElementById('mn_wait_ma_pickup_preview_content');
  var addLink = document.getElementById('mn_wait_ma_pickup_preview_add');
  var baseUrl = window.MN_WAIT_MA_PICKUP_LINES_URL || 'maintenance_inventory_pickup_lines';
  if (!modal || !loading || !content || !mtnKey || !trpSn) {
    return;
  }
  if (addLink) {
    if (pickupAddUrl) {
      addLink.href = pickupAddUrl;
      addLink.style.display = '';
    } else {
      addLink.href = '#';
      addLink.style.display = 'none';
    }
  }
  modal.classList.add('mn-wait-ma-pickup-preview-modal--open');
  modal.setAttribute('aria-hidden', 'false');
  loading.style.display = 'block';
  content.hidden = true;

  var url = baseUrl + (baseUrl.indexOf('?') >= 0 ? '&' : '?') +
    'mtn_key=' + encodeURIComponent(mtnKey) + '&trp_sn=' + encodeURIComponent(trpSn);
  fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, data: data };
      });
    })
    .then(function (result) {
      if (!result.ok || !result.data || !result.data.ok) {
        var msg = (result.data && result.data.message) ? result.data.message : 'โหลดรายการอะไหล่ไม่สำเร็จ';
        if (typeof window.mnMaintenanceFlowAlert === 'function') {
          window.mnMaintenanceFlowAlert(msg, 'warning');
        }
        mnWaitMaPickupPreviewClose();
        return;
      }
      mnWaitMaPickupPreviewRender(result.data);
      loading.style.display = 'none';
      content.hidden = false;
    })
    .catch(function () {
      if (typeof window.mnMaintenanceFlowAlert === 'function') {
        window.mnMaintenanceFlowAlert('โหลดรายการอะไหล่ไม่สำเร็จ', 'warning');
      }
      mnWaitMaPickupPreviewClose();
    });
}

(function mnWaitMaPickupPreviewInit() {
  function onReady() {
    document.addEventListener('click', function (event) {
      var btn = event.target.closest('button.mn-wait-ma-pickup-badge[data-mtn-key][data-trp-sn]');
      if (btn) {
        event.preventDefault();
        mnWaitMaPickupPreviewOpen(
          btn.getAttribute('data-mtn-key'),
          btn.getAttribute('data-trp-sn'),
          btn.getAttribute('data-pickup-url')
        );
        return;
      }
      if (event.target.closest('[data-mn-pickup-preview-close="1"]')) {
        mnWaitMaPickupPreviewClose();
      }
    });
    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape') {
        mnWaitMaPickupPreviewClose();
      }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
})();
