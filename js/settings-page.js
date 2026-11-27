/**
 * หน้า settings — สถานะ Google Drive (checklist_photo?action=drive_check) + ปุ่มส่งรูปค้าง / ตั้งชื่อไฟล์ใหม่
 */
(function (global) {
  'use strict';

  var doc = global.document;

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function getJSON(q) {
    return fetch('checklist_photo?' + q, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }

  function row(ok, label, detail) {
    return '<li class="' + (ok ? 'is-ok' : 'is-bad') + '"><i class="fa ' + (ok ? 'fa-check-circle' : 'fa-times-circle') + '" aria-hidden="true"></i>'
      + '<span><b>' + esc(label) + '</b>' + (detail ? '<small>' + esc(detail) + '</small>' : '') + '</span></li>';
  }

  // ตัวเลขรวมด้านบน (รูปทั้งหมด / ขึ้น Drive แล้ว / พื้นที่บน server)
  function renderStats(st) {
    if (!st) { return; }
    var set = function (k, txt) { var el = doc.querySelector('[data-stat="' + k + '"]'); if (el) { el.textContent = txt; } };
    set('n', Number(st.n || 0).toLocaleString('en-US'));
    set('on_drive', Number(st.on_drive || 0).toLocaleString('en-US'));
    set('bytes', (Number(st.bytes || 0) / 1048576).toFixed(1) + ' MB');
  }

  function renderCheck(box, c) {
    renderStats(c.stats);
    if (!c.enabled) {
      box.innerHTML = '<ul class="mn-set__status">' + row(false, 'ยังไม่ได้เปิดใช้ Google Drive', 'รูปเก็บบน server อย่างเดียว (storage/photos)') + '</ul>';
      return;
    }
    box.innerHTML = '<ul class="mn-set__status">'
      + row(c.ok, c.ok ? 'เชื่อมต่อ Google Drive แล้ว' : 'เชื่อมต่อ Google Drive ไม่ได้',
        c.ok ? 'โฟลเดอร์ ' + (c.folder || '') + (c.shared_drive ? ' (Shared Drive)' : '') : (c.error || 'ตรวจการตั้งค่า'))
      + row((c.pending || 0) === 0, 'รูปรอส่งขึ้น Drive ' + (c.pending || 0) + ' รูป', (c.pending || 0) ? 'กด "ส่งรูปที่ค้างขึ้น Drive"' : '')
      + row((c.trash_pending || 0) === 0, 'ไฟล์รอลบบน Drive ' + (c.trash_pending || 0) + ' ไฟล์', (c.trash_pending || 0) ? 'ระบบลองลบซ้ำเอง หรือกด "ส่งรูปที่ค้างขึ้น Drive"' : '')
      + '</ul>';
  }

  function check() {
    var box = doc.getElementById('mn_set_drive');
    if (!box) { return Promise.resolve(); }
    box.innerHTML = '<p class="mn-set__muted"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังตรวจการเชื่อมต่อ Google Drive</p>';
    return getJSON('action=drive_check').then(function (c) { renderCheck(box, c); })
      .catch(function () { box.innerHTML = '<ul class="mn-set__status">' + row(false, 'ตรวจสถานะไม่สำเร็จ', 'ลองโหลดหน้าใหม่') + '</ul>'; });
  }

  function busy(btn, on, text) {
    btn.disabled = on;
    if (on) { btn.setAttribute('data-label', btn.innerHTML); btn.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> ' + esc(text); }
    else if (btn.getAttribute('data-label')) { btn.innerHTML = btn.getAttribute('data-label'); }
  }

  function note(msg, bad) {
    var box = doc.getElementById('mn_set_drive');
    var p = doc.createElement('p');
    p.className = 'mn-set__note' + (bad ? ' is-bad' : '');
    p.textContent = msg;
    box.appendChild(p);
  }

  function push(btn) {
    busy(btn, true, 'กำลังส่ง');
    getJSON('action=drive_push').then(function (r) {
      busy(btn, false);
      return check().then(function () {
        note(r.error ? 'ส่งไม่สำเร็จ: ' + r.error : 'ส่งแล้ว ' + (r.sent || 0) + ' รูป เหลือค้าง ' + (r.pending || 0) + ' รูป'
          + (r.pruned ? ' | ลบโฟลเดอร์ว่างบน Drive ' + r.pruned + ' โฟลเดอร์' : ''), !!r.error);
      });
    }).catch(function () { busy(btn, false); note('ส่งไม่สำเร็จ', true); });
  }

  function rename(btn) {
    var total = 0;
    var errors = 0;
    busy(btn, true, 'กำลังตั้งชื่อ');
    var step = function (q) {
      return getJSON(q).then(function (r) {
        total += r.renamed || 0;
        errors += (r.errors || []).length;
        if (r.ok === false && r.error) { throw new Error(r.error); }
        if (r.next) { return step(r.next.replace(/^checklist_photo\?/, '')); }
        return null;
      });
    };
    step('action=drive_rename').then(function () {
      busy(btn, false);
      note('ตั้งชื่อไฟล์ใหม่แล้ว ' + total + ' ไฟล์' + (errors ? ' ไม่สำเร็จ ' + errors + ' ไฟล์' : ''), errors > 0);
    }).catch(function (e) { busy(btn, false); note((e && e.message) || 'ตั้งชื่อไม่สำเร็จ', true); });
  }

  // ลบรูปจากรายการใบงานที่มีรูป: ลบบน server + ย้ายไฟล์บน Drive ลงถังขยะ (checklist_photo action=delete)
  function removeThumb(btn) {
    var ph = btn.closest('.mn-pj__ph');
    var strip = btn.closest('[data-mn-photos]');
    var li = btn.closest('.mn-pj__row');
    var idx = Array.prototype.indexOf.call(strip.querySelectorAll('.mn-pj__ph'), ph);
    var items = [];
    try { items = JSON.parse(strip.getAttribute('data-mn-photos') || '[]'); } catch (err) { items = []; }
    items.splice(idx, 1);
    strip.setAttribute('data-mn-photos', JSON.stringify(items));
    ph.parentNode.removeChild(ph);
    Array.prototype.forEach.call(strip.querySelectorAll('[data-mn-photo]'), function (b, i) { b.setAttribute('data-mn-photo', String(i)); });
    var left = strip.querySelectorAll('.mn-pj__ph').length;
    if (left === 0) {
      var list = li.parentNode;
      list.removeChild(li);
      var cnt = doc.querySelector('.mn-pj__count');
      if (cnt) {
        var total = Math.max(0, (parseInt(cnt.getAttribute('data-total'), 10) || 1) - 1);
        cnt.setAttribute('data-total', String(total));
        cnt.textContent = total.toLocaleString('en-US') + ' ใบงาน';
      }
      if (!list.querySelector('.mn-pj__row')) {
        var empty = doc.querySelector('.mn-pj__empty');
        if (empty) { empty.hidden = false; }
      }
      return;
    }
    var n = li.querySelector('.mn-pj__n');
    if (n) { n.textContent = left + ' รูป'; }
  }

  function delPhoto(btn) {
    var onDrive = btn.getAttribute('data-on-drive') === '1';
    var msg = 'ลบรูป "' + (btn.getAttribute('data-cap') || '') + '" ออกจากใบงาน\n\n'
      + (onDrive ? 'ไฟล์บน Google Drive ย้ายลงถังขยะพร้อมกัน (กู้คืนจากถังขยะ Drive ได้ภายใน 30 วัน)' : 'รูปนี้ยังไม่ขึ้น Drive ลบจาก server อย่างเดียว');
    var go = function () {
      btn.disabled = true;
      btn.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>';
      var fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', btn.getAttribute('data-del-photo'));
      fd.append('trp_id', btn.getAttribute('data-trp'));
      fetch('checklist_photo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(function (r) {
          if (!r.ok) { throw new Error(r.error || 'ลบรูปไม่สำเร็จ'); }
          removeThumb(btn);
          check();
        })
        .catch(function (e) {
          btn.disabled = false;
          btn.innerHTML = '<i class="fa fa-trash" aria-hidden="true"></i>';
          var t = (e && e.message) || 'ลบรูปไม่สำเร็จ';
          if (typeof global.mnModalAlert === 'function') { global.mnModalAlert(t, { variant: 'danger' }); } else { global.alert(t); }
        });
    };
    if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm(msg, { title: 'ลบรูป', variant: 'danger', okLabel: 'ลบรูป', cancelLabel: 'ยกเลิก', onConfirm: go });
    } else if (global.confirm(msg)) {
      go();
    }
  }

  function init() {
    check();
    doc.addEventListener('click', function (e) {
      var d = e.target.closest('[data-del-photo]');
      if (d && !d.disabled) { delPhoto(d); return; }
      var b = e.target.closest('[data-drive]');
      if (!b || b.disabled) { return; }
      var act = b.getAttribute('data-drive');
      if (act === 'check') { check(); }
      if (act === 'push') { push(b); }
      if (act === 'rename') { rename(b); }
    });
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); } else { init(); }
}(typeof window !== 'undefined' ? window : this));
