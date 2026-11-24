/**
 * หน้า photo_upload — มือถือถ่าย/อัปรูปเข้าใบงานผ่าน QR (ไม่ต้องล็อกอิน)
 * - QR ประจำข้อ (mnPu.ci เป็นตัวเลข): รูปเข้าข้อนั้นทันที
 * - QR ตามหน้าจอ (mnPu.ci = null): เปลี่ยนข้อตามที่หน้าประเมินบนคอมเปิดอยู่ เลือกข้อเองได้
 * ต้องการ: window.mnPu (จาก PHP), mn-photo-lightbox.js
 */
(function (global) {
  'use strict';

  var P = global.mnPu || {};
  var doc = global.document;
  var URL_API = 'photo_upload';
  var pinned = P.ci !== null && P.ci !== undefined;

  var st = {
    items: [],
    photos: [],
    queue: [],
    focus: -1,
    follow: !pinned,
    current: pinned ? P.ci : null,
    view: 'item',
    moving: 0,
    expired: false,
    loaded: false,
    sig: ''
  };
  var seq = 0;
  var busy = false;

  function $(id) { return doc.getElementById(id); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function api(params) {
    var q = Object.keys(params).map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(URL_API + '?t=' + encodeURIComponent(P.token) + '&' + q, { credentials: 'omit', cache: 'no-store', referrerPolicy: 'no-referrer' })
      .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
  }

  function post(fd) {
    fd.append('t', P.token);
    return fetch(URL_API, { method: 'POST', body: fd, credentials: 'omit', referrerPolicy: 'no-referrer' })
      .then(function (r) { if (!r.ok) { throw new Error('ส่งไม่สำเร็จ (' + r.status + ')'); } return r.json(); })
      .then(function (res) {
        if (res && res.expired) { expire(res.error); throw new Error(res.error); }
        return res;
      });
  }

  function imgUrl(id, size, dl) {
    return URL_API + '?t=' + encodeURIComponent(P.token) + '&img=' + id + '&s=' + size + (dl ? '&dl=1' : '');
  }

  var toastTimer = 0;
  function toast(msg, bad) {
    var el = $('mn_pu_toast');
    el.textContent = msg;
    el.className = 'mn-pu-toast is-on' + (bad ? ' is-bad' : '');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.className = 'mn-pu-toast'; }, 2600);
  }

  // ---------- ข้อมูล ----------

  function itemOf(ci) {
    for (var i = 0; i < st.items.length; i++) { if (st.items[i].ci === ci) { return st.items[i]; } }
    return null;
  }

  function titleOf(ci) {
    var it = itemOf(ci);
    return it ? it.title : (ci ? 'ข้อตรวจ' : 'รูปประกอบเพิ่มเติม');
  }

  function photosOf(ci) {
    return st.photos.filter(function (p) { return p.ci === ci; });
  }

  function queueOf(ci) {
    return st.queue.filter(function (q) { return q.ci === ci; });
  }

  function expire(msg) {
    if (st.expired) { return; }
    st.expired = true;
    closeSheet();
    $('mn_pu_body').innerHTML = '<div class="mn-pu-msg"><i class="fa fa-chain-broken" aria-hidden="true"></i>'
      + '<h1>' + esc(msg || 'ลิงก์หมดอายุแล้ว') + '</h1><p>เปิดหน้าประเมินบนคอม แล้วกดปุ่ม QR เพื่อสร้างลิงก์ใหม่</p></div>';
    $('mn_pu_exp').textContent = '';
  }

  function load() {
    if (st.expired) { return Promise.resolve(); }
    return api({ action: 'state', ci: pinned ? P.ci : 0 }).then(function (res) {
      if (!res.ok) { if (res.expired) { expire(res.error); } return; }
      var prevFocus = st.focus;
      st.items = res.items || [];
      st.photos = res.photos || [];
      st.focus = typeof res.focus === 'number' ? res.focus : -1;
      $('mn_pu_exp').textContent = res.expiresText ? 'ใช้ได้ถึง ' + res.expiresText + ' น.' : '';
      if (st.follow) {
        var next = st.focus >= 0 ? st.focus : null;
        if (next !== st.current) {
          st.current = next;
          if (st.loaded && next !== null && st.view === 'item' && prevFocus !== st.focus) { toast('เปลี่ยนเป็นข้อ: ' + titleOf(next)); }
        }
      }
      st.loaded = true;
      var sig = JSON.stringify([st.items, st.photos, st.current, st.follow, st.view]);
      if (sig !== st.sig) { render(); }
    }).catch(function () {});
  }

  // ---------- แสดงผล ----------

  function tileHtml(p) {
    return '<button type="button" class="mn-pu-tile" data-photo="' + p.id + '" aria-label="จัดการรูปนี้">'
      + '<img src="' + esc(imgUrl(p.id, 't')) + '" alt="" loading="lazy" />'
      + (p.drive ? '<span class="mn-photo-drive" title="ขึ้น Google Drive แล้ว"><i class="fa fa-check" aria-hidden="true"></i></span>' : '')
      + '</button>';
  }

  function queueTileHtml(q) {
    if (q.state === 'fail') {
      return '<button type="button" class="mn-pu-tile is-fail" data-retry="' + q.key + '"><i class="fa fa-refresh" aria-hidden="true"></i><small>ส่งไม่สำเร็จ แตะเพื่อลองใหม่</small></button>';
    }
    return '<span class="mn-pu-tile is-pending"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i><small>' + (q.state === 'send' ? 'กำลังส่ง' : 'รอส่ง') + '</small></span>';
  }

  function modeBar() {
    if (pinned && !st.follow && st.current === P.ci) {
      return '<div class="mn-pu-mode"><span class="mn-pu-chip"><i class="fa fa-qrcode" aria-hidden="true"></i> QR ของข้อนี้</span></div>';
    }
    if (st.follow) {
      return '<div class="mn-pu-mode"><span class="mn-pu-chip is-live"><i class="fa fa-desktop" aria-hidden="true"></i> ตามหน้าจอคอม</span></div>';
    }
    return '<div class="mn-pu-mode"><button type="button" class="mn-pu-chip is-btn" data-act="follow"><i class="fa fa-desktop" aria-hidden="true"></i> กลับไปตามหน้าจอคอม</button></div>';
  }

  function renderItem() {
    var ci = st.current;
    var it = itemOf(ci);
    var list = photosOf(ci);
    var q = queueOf(ci);
    var need = it && it.mode === 2 && !list.length;
    var others = st.items.length - 1;
    return modeBar()
      + '<section class="mn-pu-item">'
      + (it && it.group ? '<p class="mn-pu-item__group">' + esc(it.group) + '</p>' : '')
      + '<h1 class="mn-pu-item__title">' + esc(titleOf(ci)) + '</h1>'
      + (need ? '<p class="mn-pu-item__req"><i class="fa fa-exclamation-circle" aria-hidden="true"></i> ข้อนี้ต้องแนบรูป</p>' : '')
      + '<div class="mn-pu-actions">'
      + '<button type="button" class="mn-pu-btn mn-pu-btn--primary" data-act="cam"><i class="fa fa-camera" aria-hidden="true"></i> ถ่ายรูป</button>'
      + '<button type="button" class="mn-pu-btn" data-act="lib"><i class="fa fa-picture-o" aria-hidden="true"></i> คลังรูป</button>'
      + '</div>'
      + (list.length || q.length
        ? '<div class="mn-pu-grid">' + list.map(tileHtml).join('') + q.map(queueTileHtml).join('') + '</div>'
          + '<p class="mn-pu-hint">แตะรูปเพื่อดู ดาวน์โหลด ย้ายข้อ หรือลบ</p>'
        : '<p class="mn-pu-empty">ยังไม่มีรูปในข้อนี้</p>')
      + '</section>'
      + (others > 0 ? '<button type="button" class="mn-pu-other" data-act="list"><i class="fa fa-list-ul" aria-hidden="true"></i> ข้ออื่น (' + others + ' ข้อ)</button>' : '');
  }

  function rowHtml(it, act) {
    var n = photosOf(it.ci).length + queueOf(it.ci).length;
    var need = it.mode === 2 && !photosOf(it.ci).length;
    var cur = act === 'pick' && it.ci === st.current;
    return '<li><button type="button" class="mn-pu-row' + (cur ? ' is-cur' : '') + '" data-' + act + '="' + it.ci + '">'
      + '<span class="mn-pu-row__txt">' + (it.group ? '<small>' + esc(it.group) + '</small>' : '') + esc(it.title) + '</span>'
      + (need ? '<span class="mn-pu-badge is-req">ต้องแนบ</span>' : (n ? '<span class="mn-pu-badge">' + n + ' รูป</span>' : ''))
      + '<i class="fa fa-angle-right" aria-hidden="true"></i></button></li>';
  }

  function sortedItems() {
    // ข้อที่ต้องแนบรูปแต่ยังไม่มีรูปขึ้นก่อน รูปประกอบเพิ่มเติมอยู่ท้ายสุด
    return st.items.slice().sort(function (a, b) {
      var ra = a.mode === 2 && !photosOf(a.ci).length ? 0 : 1;
      var rb = b.mode === 2 && !photosOf(b.ci).length ? 0 : 1;
      if (!a.ci) { ra = 2; }
      if (!b.ci) { rb = 2; }
      return ra - rb;
    });
  }

  function renderList() {
    var head = st.current === null
      ? '<p class="mn-pu-lead"><i class="fa fa-desktop" aria-hidden="true"></i> เปิดข้อที่จะถ่ายบนหน้าจอคอม มือถือจะเปลี่ยนตาม หรือเลือกข้อเองด้านล่าง</p>'
      : '<div class="mn-pu-listhead"><button type="button" class="mn-pu-back" data-act="back"><i class="fa fa-angle-left" aria-hidden="true"></i> กลับ</button><span>เลือกข้อที่จะถ่าย</span></div>';
    return head + '<ul class="mn-pu-list">' + sortedItems().map(function (it) { return rowHtml(it, 'pick'); }).join('') + '</ul>';
  }

  function renderMove() {
    var p = null;
    st.photos.forEach(function (x) { if (x.id === st.moving) { p = x; } });
    if (!p) { st.view = 'item'; return renderItem(); }
    return '<div class="mn-pu-listhead"><button type="button" class="mn-pu-back" data-act="back"><i class="fa fa-angle-left" aria-hidden="true"></i> ยกเลิก</button><span>ย้ายรูปไปข้อไหน</span></div>'
      + '<div class="mn-pu-moving"><img src="' + esc(imgUrl(p.id, 't')) + '" alt="" /><span>จากข้อ: ' + esc(titleOf(p.ci)) + '</span></div>'
      + '<ul class="mn-pu-list">' + sortedItems().filter(function (it) { return it.ci !== p.ci; }).map(function (it) { return rowHtml(it, 'moveto'); }).join('') + '</ul>';
  }

  function render() {
    if (st.expired) { return; }
    st.sig = JSON.stringify([st.items, st.photos, st.current, st.follow, st.view]);
    var html;
    if (st.view === 'move') { html = renderMove(); }
    else if (st.view === 'list' || st.current === null) { html = renderList(); }
    else { html = renderItem(); }
    $('mn_pu_body').innerHTML = html;
  }

  // ---------- เมนูรูป (แตะรูป) ----------

  var sheet = null;
  var sheetPhoto = 0;

  function openSheet(id) {
    sheetPhoto = id;
    if (!sheet) {
      sheet = doc.createElement('div');
      sheet.className = 'mn-pu-sheet';
      sheet.innerHTML = '<div class="mn-pu-sheet__panel" role="dialog" aria-modal="true" aria-label="จัดการรูป">'
        + '<img class="mn-pu-sheet__img" alt="" />'
        + '<button type="button" class="mn-pu-sheet__btn" data-sh="view"><i class="fa fa-search-plus" aria-hidden="true"></i> ดูรูปใหญ่</button>'
        + '<a class="mn-pu-sheet__btn" data-sh="dl" download><i class="fa fa-download" aria-hidden="true"></i> ดาวน์โหลด</a>'
        + '<button type="button" class="mn-pu-sheet__btn" data-sh="move"><i class="fa fa-exchange" aria-hidden="true"></i> ย้ายไปข้ออื่น</button>'
        + '<button type="button" class="mn-pu-sheet__btn is-danger" data-sh="del"><i class="fa fa-trash-o" aria-hidden="true"></i> ลบรูป</button>'
        + '<button type="button" class="mn-pu-sheet__btn is-cancel" data-sh="close">ยกเลิก</button>'
        + '</div>';
      doc.body.appendChild(sheet);
      sheet.addEventListener('click', onSheetClick);
    }
    sheet.querySelector('.mn-pu-sheet__img').src = imgUrl(id, 't');
    sheet.querySelector('[data-sh="dl"]').setAttribute('href', imgUrl(id, 'f', true));
    sheet.querySelector('[data-sh="move"]').hidden = st.items.length < 2;
    sheet.querySelector('[data-sh="del"]').innerHTML = '<i class="fa fa-trash-o" aria-hidden="true"></i> ลบรูป';
    sheet.querySelector('[data-sh="del"]').removeAttribute('data-confirm');
    sheet.classList.add('is-on');
  }

  function closeSheet() {
    if (sheet) { sheet.classList.remove('is-on'); }
  }

  function onSheetClick(e) {
    if (e.target === sheet) { closeSheet(); return; }
    var b = e.target.closest('[data-sh]');
    if (!b) { return; }
    var act = b.getAttribute('data-sh');
    if (act === 'close') { closeSheet(); }
    if (act === 'dl') { setTimeout(closeSheet, 300); }
    if (act === 'view') {
      closeSheet();
      var ph = null;
      st.photos.forEach(function (x) { if (x.id === sheetPhoto) { ph = x; } });
      if (!ph || typeof global.mnPhotoLightbox !== 'function') { return; }
      var list = photosOf(ph.ci);
      var start = 0;
      list.forEach(function (x, i) { if (x.id === ph.id) { start = i; } });
      global.mnPhotoLightbox(list.map(function (x) {
        return { url: imgUrl(x.id, 'f'), download: imgUrl(x.id, 'f', true), caption: titleOf(x.ci) };
      }), start);
    }
    if (act === 'move') {
      closeSheet();
      st.moving = sheetPhoto;
      st.view = 'move';
      render();
      global.scrollTo(0, 0);
    }
    if (act === 'del') {
      // แตะสองครั้งเพื่อยืนยัน (กันพลาด) — ไม่ใช้ confirm() ของเบราว์เซอร์เพราะบางแอปสแกน QR ปิดไว้
      if (!b.hasAttribute('data-confirm')) {
        b.setAttribute('data-confirm', '1');
        b.innerHTML = '<i class="fa fa-trash-o" aria-hidden="true"></i> แตะอีกครั้งเพื่อลบ';
        return;
      }
      var id = sheetPhoto;
      closeSheet();
      var fd = new FormData();
      fd.append('action', 'delete');
      fd.append('id', id);
      post(fd).then(function (res) {
        if (!res.ok) { throw new Error(res.error || 'ลบรูปไม่สำเร็จ'); }
        st.photos = st.photos.filter(function (x) { return x.id !== id; });
        render();
        toast('ลบรูปแล้ว');
      }).catch(function (err) { toast((err && err.message) || 'ลบรูปไม่สำเร็จ', true); });
    }
  }

  function moveTo(ci) {
    var id = st.moving;
    var fd = new FormData();
    fd.append('action', 'move');
    fd.append('id', id);
    fd.append('ci_id', ci);
    st.view = 'item';
    post(fd).then(function (res) {
      if (!res.ok) { throw new Error(res.error || 'ย้ายรูปไม่สำเร็จ'); }
      st.photos.forEach(function (x) { if (x.id === id) { x.ci = ci; } });
      render();
      toast('ย้ายไปข้อ: ' + titleOf(ci));
    }).catch(function (err) { render(); toast((err && err.message) || 'ย้ายรูปไม่สำเร็จ', true); });
  }

  // ---------- อัปโหลด (ย่อรูปในมือถือก่อนส่ง — ส่งทีละรูป ลองใหม่เองเมื่อเน็ตสะดุด) ----------

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
      img.src = url;
    });
  }

  function resizeImage(file, max, quality) {
    var src = global.createImageBitmap
      ? global.createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return loadImage(file); })
      : loadImage(file);
    return src.then(function (img) {
      var s = Math.min(1, max / Math.max(img.width, img.height));
      var c = doc.createElement('canvas');
      c.width = Math.max(1, Math.round(img.width * s));
      c.height = Math.max(1, Math.round(img.height * s));
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(img, 0, 0, c.width, c.height);
      return new Promise(function (resolve, reject) {
        c.toBlob(function (b) { if (b) { resolve(b); } else { reject(new Error('decode')); } }, 'image/jpeg', quality);
      });
    });
  }

  function addFiles(files) {
    var ci = st.current;
    if (ci === null) { return; }
    var list = Array.prototype.slice.call(files || []).slice(0, 10);
    list.forEach(function (file) {
      if (file.type && file.type.indexOf('image/') !== 0) { toast('ไฟล์นี้ไม่ใช่รูป', true); return; }
      st.queue.push({ key: 'q' + (++seq), ci: ci, file: file, state: 'wait', tries: 0 });
    });
    render();
    pump();
  }

  function sendOne(q) {
    var blobs = q.blobs ? Promise.resolve(q.blobs) : Promise.all([resizeImage(q.file, 1600, 0.82), resizeImage(q.file, 320, 0.72)]);
    return blobs.then(function (b) {
      q.blobs = b;
      var fd = new FormData();
      fd.append('action', 'upload');
      fd.append('ci_id', q.ci);
      fd.append('photo', b[0], 'photo.jpg');
      fd.append('thumb', b[1], 'thumb.jpg');
      return post(fd);
    }).then(function (res) {
      if (!res.ok) { var e = new Error(res.error || 'ส่งไม่สำเร็จ'); e.final = true; throw e; }
      return res.photo;
    });
  }

  function pump() {
    if (busy || st.expired) { return; }
    var q = st.queue.filter(function (x) { return x.state === 'wait'; })[0];
    if (!q) { return; }
    busy = true;
    q.state = 'send';
    render();
    sendOne(q).then(function (photo) {
      st.queue = st.queue.filter(function (x) { return x !== q; });
      if (photo && !st.photos.some(function (x) { return x.id === photo.id; })) { st.photos.push({ id: photo.id, ci: photo.ci, drive: false }); }
      toast('ส่งรูปแล้ว');
    }).catch(function (err) {
      var m = err && err.message;
      if (m === 'decode') {
        st.queue = st.queue.filter(function (x) { return x !== q; });
        toast('เปิดไฟล์รูปนี้ไม่ได้ ลองถ่ายใหม่', true);
      } else if (!err.final && q.tries < 2) {
        q.tries++;
        q.state = 'wait';
        return new Promise(function (r) { setTimeout(r, 2000); });
      } else {
        q.state = 'fail';
        toast(m || 'ส่งรูปไม่สำเร็จ', true);
      }
      return null;
    }).then(function () {
      busy = false;
      render();
      pump();
    });
  }

  // ---------- เหตุการณ์ ----------

  function onBodyClick(e) {
    var t = e.target;
    var a = t.closest('[data-act]');
    if (a) {
      var act = a.getAttribute('data-act');
      if (act === 'cam') { $('mn_pu_cam').click(); }
      if (act === 'lib') { $('mn_pu_lib').click(); }
      if (act === 'list') { st.view = 'list'; render(); global.scrollTo(0, 0); }
      if (act === 'back') { st.view = 'item'; render(); }
      if (act === 'follow') {
        st.follow = true;
        st.current = st.focus >= 0 ? st.focus : null;
        st.view = 'item';
        render();
      }
      return;
    }
    var pick = t.closest('[data-pick]');
    if (pick) {
      st.current = parseInt(pick.getAttribute('data-pick'), 10) || 0;
      // เลือกข้อเองในโหมดตามหน้าจอ → หยุดตามคอมจนกดกลับ
      if (!pinned || st.current !== P.ci) { st.follow = false; }
      st.view = 'item';
      render();
      global.scrollTo(0, 0);
      return;
    }
    var mv = t.closest('[data-moveto]');
    if (mv) { moveTo(parseInt(mv.getAttribute('data-moveto'), 10) || 0); return; }
    var tile = t.closest('[data-photo]');
    if (tile) { openSheet(parseInt(tile.getAttribute('data-photo'), 10)); return; }
    var retry = t.closest('[data-retry]');
    if (retry) {
      var key = retry.getAttribute('data-retry');
      st.queue.forEach(function (q) { if (q.key === key) { q.state = 'wait'; q.tries = 0; } });
      render();
      pump();
    }
  }

  function onFile(e) {
    addFiles(e.target.files);
    e.target.value = '';
  }

  function init() {
    if (!P.token) { return; }
    $('mn_pu_body').addEventListener('click', onBodyClick);
    $('mn_pu_cam').addEventListener('change', onFile);
    $('mn_pu_lib').addEventListener('change', onFile);
    load();
    setInterval(function () { if (!doc.hidden) { load(); } }, 3000);
    doc.addEventListener('visibilitychange', function () { if (!doc.hidden) { load(); } });
    global.addEventListener('beforeunload', function (e) {
      if (st.queue.some(function (q) { return q.state !== 'fail'; })) { e.preventDefault(); e.returnValue = ''; }
    });
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); } else { init(); }
}(typeof window !== 'undefined' ? window : this));
