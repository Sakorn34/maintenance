/**
 * หน้าต่างดูรูปขนาดใหญ่ (ใช้ร่วมหลายหน้า: ประเมิน / แผงประวัติซ่อม / รอเสนอราคา)
 * - mnPhotoLightbox(items, start)  items = [{ url, caption }]
 * - ผูกอัตโนมัติ: กล่องที่มี data-mn-photos='[{url,caption},...]' + ลูกที่มี data-mn-photo="ลำดับ"
 * ปุ่ม: Esc ปิด · ลูกศรซ้าย/ขวา เลื่อนรูป · คลิกพื้นหลังปิด
 */
(function (global) {
  'use strict';

  var doc = global.document;
  var box = null;
  var list = [];
  var idx = 0;
  var lastFocus = null;

  function build() {
    if (box) { return; }
    box = doc.createElement('div');
    box.className = 'mn-plb';
    box.hidden = true;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.setAttribute('aria-label', 'ดูรูป');
    box.innerHTML = '<button type="button" class="mn-plb__close" aria-label="ปิด"><i class="fa fa-times" aria-hidden="true"></i></button>'
      + '<button type="button" class="mn-plb__nav mn-plb__nav--prev" aria-label="รูปก่อนหน้า"><i class="fa fa-angle-left" aria-hidden="true"></i></button>'
      + '<figure class="mn-plb__fig"><img alt="" /><figcaption></figcaption></figure>'
      + '<button type="button" class="mn-plb__nav mn-plb__nav--next" aria-label="รูปถัดไป"><i class="fa fa-angle-right" aria-hidden="true"></i></button>';
    doc.body.appendChild(box);
    box.addEventListener('click', function (e) {
      if (e.target.closest('.mn-plb__close') || e.target === box) { close(); return; }
      if (e.target.closest('.mn-plb__nav--prev')) { step(-1); }
      if (e.target.closest('.mn-plb__nav--next')) { step(1); }
    });
    doc.addEventListener('keydown', function (e) {
      if (box.hidden) { return; }
      if (e.key === 'Escape') { close(); }
      if (e.key === 'ArrowLeft') { step(-1); }
      if (e.key === 'ArrowRight') { step(1); }
    });
  }

  function show() {
    var p = list[idx] || {};
    box.querySelector('img').src = p.url || '';
    box.querySelector('figcaption').textContent = (p.caption || '') + (list.length > 1 ? '  (' + (idx + 1) + '/' + list.length + ')' : '');
    box.querySelectorAll('.mn-plb__nav').forEach(function (b) { b.hidden = list.length < 2; });
  }

  function step(d) {
    idx = (idx + d + list.length) % list.length;
    show();
  }

  function close() {
    if (!box || box.hidden) { return; }
    box.hidden = true;
    doc.body.classList.remove('mn-plb-open');
    if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
  }

  global.mnPhotoLightbox = function (items, start) {
    if (!items || !items.length) { return; }
    build();
    list = items;
    idx = Math.min(Math.max(0, start || 0), items.length - 1);
    lastFocus = doc.activeElement;
    show();
    box.hidden = false;
    doc.body.classList.add('mn-plb-open');
    box.querySelector('.mn-plb__close').focus();
  };

  doc.addEventListener('click', function (e) {
    var a = e.target.closest ? e.target.closest('[data-mn-photo]') : null;
    if (!a) { return; }
    var wrap = a.closest('[data-mn-photos]');
    if (!wrap) { return; }
    e.preventDefault();
    var items = [];
    try { items = JSON.parse(wrap.getAttribute('data-mn-photos') || '[]'); } catch (err) { items = []; }
    global.mnPhotoLightbox(items, parseInt(a.getAttribute('data-mn-photo'), 10) || 0);
  });
})(window);
