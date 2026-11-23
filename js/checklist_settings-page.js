/**
 * หน้า checklist_settings — ตั้งค่าข้อตรวจ อาการ กลุ่มชิ้นส่วน ต่อรุ่นสินค้า
 * ต้องการ: mn-modal.js, window.mnClsInitial
 */
(function (global) {
  'use strict';

  var doc = global.document;
  var API = 'checklist_settings_api';
  var TYPES = [
    { key: 'options', label: 'ปุ่มตัวเลือก' },
    { key: 'passfail', label: 'ผ่าน/ไม่ผ่าน' },
    { key: 'number', label: 'ค่าตัวเลข' },
    { key: 'auto', label: 'อัตโนมัติ' }
  ];
  var AUTO = { buy_date: 'วันที่ซื้อ (อายุเครื่องเกิน 2 ปีไหม)', last_ma: 'ประวัติ MA (MA ล่าสุดเกิน 2 ปีไหม)' };
  var MAX_OPTS = 6;
  var COLORS = {
    green: { name: 'เขียว', ink: '#15803d', soft: '#ecf8f0', line: '#9fd6b1' },
    teal: { name: 'เขียวอมฟ้า', ink: '#0f766e', soft: '#e6f6f4', line: '#99d5cd' },
    blue: { name: 'น้ำเงิน', ink: '#1d4ed8', soft: '#eaf1fe', line: '#a9c1f5' },
    violet: { name: 'ม่วง', ink: '#6d28d9', soft: '#f2ecfd', line: '#c9b3f2' },
    amber: { name: 'เหลือง', ink: '#a16207', soft: '#fdf7e3', line: '#ead58c' },
    orange: { name: 'ส้ม', ink: '#c2410c', soft: '#fff4ec', line: '#f6c19f' },
    red: { name: 'แดง', ink: '#b91c1c', soft: '#fdf0f0', line: '#f0b0b0' },
    gray: { name: 'เทา', ink: '#475569', soft: '#f1f4f8', line: '#c5cfdb' }
  };
  var palOpen = -1;

  function optColor(o, i) { return COLORS[o.color] ? o.color : (i === 0 ? 'green' : (i === 1 ? 'orange' : 'red')); }

  function colorVars(key) {
    var c = COLORS[key];
    return '--oc:' + c.ink + ';--oc-soft:' + c.soft + ';--oc-line:' + c.line;
  }

  var state = {
    products: [],
    product: '',
    data: null,
    tab: 'items',
    cat: 0,
    sel: null,
    draft: null,
    dirty: false,
    history: null
  };

  // ---------- utils ----------

  function $(id) { return doc.getElementById(id); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function alertMsg(msg, variant) {
    if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(msg, { variant: variant || 'warning' });
    } else {
      global.alert(msg);
    }
  }

  function confirmMsg(msg, onOk, opt) {
    opt = opt || {};
    if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm(msg, {
        title: opt.title || 'ยืนยัน', variant: opt.variant || 'warning',
        okLabel: opt.ok || 'ตกลง', cancelLabel: 'ยกเลิก', onConfirm: onOk
      });
    } else if (global.confirm(msg)) {
      onOk();
    }
  }

  var toastTimer = null;
  function toast(msg) {
    var el = $('mn_cls_toast');
    el.textContent = msg;
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-on'); }, 2200);
  }

  function apiGet(params) {
    var q = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(API + '?' + q, { credentials: 'same-origin' }).then(parseJson);
  }

  function apiPost(body) {
    body.product = state.product;
    return fetch(API, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(body)
    }).then(parseJson).then(function (res) {
      if (!res.ok) { throw new Error(res.error || 'บันทึกไม่สำเร็จ'); }
      return res;
    });
  }

  function parseJson(r) {
    if (!r.ok) { throw new Error('เชื่อมต่อเซิร์ฟเวอร์ไม่สำเร็จ (' + r.status + ')'); }
    return r.json();
  }

  function fail(err) { alertMsg(err && err.message ? err.message : 'เกิดข้อผิดพลาด', 'danger'); }

  function guardDirty(next) {
    if (!state.dirty) { next(); return; }
    confirmMsg('ข้อตรวจที่กำลังแก้ยังไม่ได้บันทึก ต้องการทิ้งการแก้ไขหรือไม่', function () {
      state.dirty = false;
      next();
    }, { title: 'ยังไม่ได้บันทึก', ok: 'ทิ้งการแก้ไข' });
  }

  function symName(id) {
    if (!id) { return 'ตรวจพื้นฐาน'; }
    var s = (state.data.symptoms || []).filter(function (x) { return x.id === id; })[0];
    return s ? s.name : '(ไม่พบอาการ)';
  }

  // ---------- products ----------

  function loadProducts() {
    return apiGet({ action: 'products' }).then(function (res) {
      state.products = res.products || [];
      renderProducts();
    });
  }

  function renderProducts() {
    var q = $('mn_cls_search').value.trim().toLowerCase();
    var html = state.products.filter(function (p) {
      return !q || p.name.toLowerCase().indexOf(q) !== -1;
    }).map(function (p) {
      var badge = p.share_from
        ? '<span class="mn-cls-badge mn-cls-badge--share">= ' + esc(p.share_from) + '</span>'
        : '<span class="mn-cls-badge' + (p.items ? ' mn-cls-badge--ok' : '') + '">' + p.items + '</span>';
      return '<li><button type="button" class="mn-cls-prod' + (p.name === state.product ? ' is-on' : '') + '" data-name="' + esc(p.name) + '">'
        + '<span class="mn-cls-prod__name">' + esc(p.name) + '<small>ซ่อม ' + p.repairs.toLocaleString() + ' งาน</small></span>'
        + badge + '</button></li>';
    }).join('');
    $('mn_cls_plist').innerHTML = html || '<li class="mn-cls-muted mn-cls-pad">ไม่พบรุ่นที่ค้นหา</li>';
  }

  function selectProduct(name) {
    guardDirty(function () {
      state.product = name;
      state.cat = 0;
      state.sel = null;
      state.history = null;
      renderProducts();
      if (global.history && global.history.replaceState) {
        global.history.replaceState(null, '', 'checklist_settings?product=' + encodeURIComponent(name));
      }
      reload().then(function () { closeEditor(); });
    });
  }

  function reload() {
    return apiGet({ action: 'load', product: state.product }).then(function (res) {
      if (!res.ok) { throw new Error(res.error); }
      state.data = res.data;
      var p = state.products.filter(function (x) { return x.name === state.product; })[0];
      if (p) {
        p.items = state.data.items.filter(function (i) { return i.enabled; }).length;
        p.share_from = state.data.share_from;
        renderProducts();
      }
      renderMain();
    }).catch(fail);
  }

  // ---------- main ----------

  function renderMain() {
    $('mn_cls_main_empty').hidden = true;
    $('mn_cls_main').hidden = false;
    var d = state.data;
    var p = state.products.filter(function (x) { return x.name === state.product; })[0] || { repairs: 0 };
    $('mn_cls_pname').textContent = d.product;
    $('mn_cls_pmeta').textContent = 'ข้อตรวจ ' + d.items.length + ' ข้อ · อาการ ' + d.symptoms.length
      + ' · กลุ่มชิ้นส่วน ' + d.groups.length + ' · ซ่อม ' + p.repairs.toLocaleString() + ' งาน';
    renderShare();
    doc.querySelectorAll('.mn-cls-tab').forEach(function (t) {
      t.classList.toggle('is-on', t.getAttribute('data-tab') === state.tab);
    });
    doc.querySelectorAll('.mn-cls-pane').forEach(function (pn) {
      pn.hidden = pn.getAttribute('data-pane') !== state.tab;
    });
    if (state.tab === 'items') { renderItemsPane(); }
    if (state.tab === 'setup') { renderSetupPane(); }
    if (state.tab === 'history') { renderHistoryPane(); }
  }

  function renderShare() {
    var d = state.data;
    var opts = '<option value="">— ไม่ใช้ร่วม (ตั้งค่าเอง) —</option>' + state.products.filter(function (p) {
      return p.name !== d.product && !p.share_from;
    }).map(function (p) {
      return '<option value="' + esc(p.name) + '"' + (p.name === d.share_from ? ' selected' : '') + '>' + esc(p.name)
        + (p.items ? ' (' + p.items + ' ข้อ)' : '') + '</option>';
    }).join('');
    $('mn_cls_share').innerHTML = '<label class="mn-cls-share__lbl" for="mn_cls_share_sel"><i class="fa fa-link" aria-hidden="true"></i> ใช้รายการตรวจร่วมกับรุ่น</label>'
      + '<select id="mn_cls_share_sel" class="mn-cls-input mn-cls-input--sm">' + opts + '</select>';
    $('mn_cls_share').classList.toggle('is-active', !!d.share_from);
  }

  function onShareChange(e) {
    var val = e.target.value;
    var prev = state.data.share_from;
    var go = function () {
      apiPost({ action: 'set_share', share_from: val }).then(function () {
        toast(val ? 'ตั้งให้ใช้ร่วมกับ ' + val + ' แล้ว' : 'ยกเลิกการใช้ร่วมแล้ว');
        state.sel = null;
        closeEditor();
        return reload();
      }).catch(function (err) { e.target.value = prev; fail(err); });
    };
    if (val && state.data.items.length) {
      confirmMsg('รุ่นนี้มีข้อตรวจของตัวเอง ' + state.data.items.length + ' ข้อ ถ้าใช้ร่วมกับ ' + val
        + ' ช่างจะเห็นข้อตรวจของ ' + val + ' แทน (ข้อตรวจเดิมยังเก็บไว้ ไม่ถูกลบ)', go, { ok: 'ใช้ร่วม' });
      e.target.value = prev;
      return;
    }
    go();
  }

  // ---------- items pane ----------

  function itemsInCat() {
    return state.data.items.filter(function (i) { return i.symptom_id === state.cat; });
  }

  function typeLabel(it) {
    if (it.type === 'options') { return 'ปุ่ม ' + it.options.length + (it.multi ? ' · หลายค่า' : ''); }
    if (it.type === 'number') { return it.min + '–' + it.max + ' ' + it.unit; }
    if (it.type === 'auto') { return 'อัตโนมัติ'; }
    return 'ผ่าน/ไม่ผ่าน';
  }

  function firstRate(it) {
    if (it.type === 'options') {
      for (var i = 1; i < it.options.length; i++) { if (it.options[i].rate) { return it.options[i].rate; } }
      return '';
    }
    return it.rate_fail;
  }

  function renderItemsPane() {
    var d = state.data;
    var pane = $('mn_cls_pane_items');
    if (d.share_from) {
      pane.innerHTML = '<div class="mn-cls-note"><i class="fa fa-link" aria-hidden="true"></i><div><b>รุ่นนี้ใช้รายการตรวจร่วมกับ ' + esc(d.share_from) + '</b>'
        + '<p>ช่างจะเห็นข้อตรวจของ ' + esc(d.share_from) + ' แก้ไขข้อตรวจได้ที่รุ่นนั้น</p>'
        + '<button type="button" class="mn-cls-btn" data-goto="' + esc(d.share_from) + '">ไปที่ ' + esc(d.share_from) + ' <i class="fa fa-angle-right" aria-hidden="true"></i></button></div></div>';
      return;
    }
    var cats = [{ id: 0, name: 'ตรวจพื้นฐาน' }].concat(d.symptoms);
    var chips = cats.map(function (c) {
      var n = d.items.filter(function (i) { return i.symptom_id === c.id; }).length;
      var on = c.id === state.cat;
      return '<button type="button" class="mn-cls-chip' + (on ? ' is-on' : '') + '" data-cat="' + c.id + '"'
        + (c.id ? ' title="ดับเบิลคลิกเพื่อแก้ชื่อ"' : '') + '>'
        + esc(c.name) + ' <span>' + n + '</span>'
        + (on && c.id ? '<i class="fa fa-pencil mn-cls-chip__edit" data-edit-cat="' + c.id + '" title="แก้ชื่ออาการ" aria-hidden="true"></i>' : '')
        + '</button>';
    }).join('') + '<button type="button" class="mn-cls-chip mn-cls-chip--add" id="mn_cls_add_sym"><i class="fa fa-plus" aria-hidden="true"></i> เพิ่มอาการ</button>';

    var list = itemsInCat();
    var groups = d.groups.concat([{ id: 0, name: 'ไม่ระบุกลุ่ม' }]);
    var body = '';
    groups.forEach(function (g) {
      var gi = list.filter(function (i) { return i.group_id === g.id; })
        .sort(function (a, b) { return a.step - b.step || a.id - b.id; });
      if (!gi.length) { return; }
      body += '<div class="mn-cls-group"><div class="mn-cls-group__head">' + esc(g.name) + ' <small>' + gi.length + ' ข้อ</small></div>'
        + '<ul class="mn-cls-ilist" data-group="' + g.id + '">' + gi.map(function (it) {
          var rate = firstRate(it);
          return '<li class="mn-cls-irow' + (state.sel === it.id ? ' is-on' : '') + (it.enabled ? '' : ' is-off') + '" draggable="true" data-id="' + it.id + '">'
            + '<span class="mn-cls-grip" aria-hidden="true"><i class="fa fa-ellipsis-v"></i><i class="fa fa-ellipsis-v"></i></span>'
            + '<span class="mn-cls-irow__q">' + esc(it.question) + (it.enabled ? '' : ' <em>ปิดใช้งาน</em>') + '</span>'
            + '<span class="mn-cls-pill">' + esc(typeLabel(it)) + '</span>'
            + (rate ? '<span class="mn-cls-pill mn-cls-pill--rate" title="' + esc(rate) + '">' + esc(rate) + '</span>' : '')
            + '</li>';
        }).join('') + '</ul></div>';
    });
    if (!list.length) {
      body = '<div class="mn-cls-empty mn-cls-empty--inline"><i class="fa fa-inbox" aria-hidden="true"></i><p>ยังไม่มีข้อตรวจใน “' + esc(symName(state.cat)) + '”</p></div>';
    }
    pane.innerHTML = '<div class="mn-cls-chips">' + chips + '</div>' + body
      + '<button type="button" class="mn-cls-add" id="mn_cls_add_item"><i class="fa fa-plus" aria-hidden="true"></i> เพิ่มข้อตรวจใน “' + esc(symName(state.cat)) + '”</button>'
      + (list.length > 1 ? '<p class="mn-cls-muted mn-cls-hint">ลากที่ <i class="fa fa-ellipsis-v"></i> เพื่อเรียงลำดับภายในกลุ่ม · เปลี่ยนกลุ่มได้ในแผงแก้ไข</p>' : '');
  }

  function onItemsPaneClick(e) {
    var go = e.target.closest('[data-goto]');
    if (go) { selectProduct(go.getAttribute('data-goto')); return; }
    if (e.target.closest('.mn-cls-chip--edit')) { return; }
    var pen = e.target.closest('[data-edit-cat]');
    if (pen) { startChipEdit(parseInt(pen.getAttribute('data-edit-cat'), 10)); return; }
    if (e.target.closest('#mn_cls_add_sym')) { startChipEdit(0); return; }
    var chip = e.target.closest('.mn-cls-chip[data-cat]');
    if (chip) {
      var cat = parseInt(chip.getAttribute('data-cat'), 10);
      if (cat === state.cat) { return; }
      guardDirty(function () { state.cat = cat; state.sel = null; closeEditor(); renderItemsPane(); });
      return;
    }
    if (e.target.closest('#mn_cls_add_item')) {
      guardDirty(function () { openEditor(null); });
      return;
    }
    var row = e.target.closest('.mn-cls-irow');
    if (row) {
      var id = parseInt(row.getAttribute('data-id'), 10);
      if (id === state.sel) { return; }
      guardDirty(function () {
        openEditor(state.data.items.filter(function (i) { return i.id === id; })[0]);
      });
    }
  }

  // แก้ชื่อ / เพิ่มอาการจากแถบชิป (id = 0 คือเพิ่มใหม่)
  function startChipEdit(id) {
    var pane = $('mn_cls_pane_items');
    var target = id ? pane.querySelector('.mn-cls-chip[data-cat="' + id + '"]') : $('mn_cls_add_sym');
    if (!target) { return; }
    var orig = id ? symName(id) : '';
    var box = doc.createElement('span');
    box.className = 'mn-cls-chip mn-cls-chip--edit';
    box.innerHTML = '<input type="text" class="mn-cls-chip__in" maxlength="100" placeholder="ชื่ออาการ" />'
      + '<span class="mn-cls-chip__keys">Enter บันทึก · Esc ยกเลิก</span>';
    target.parentNode.replaceChild(box, target);
    var input = box.querySelector('input');
    input.value = orig;
    input.style.width = Math.max(8, orig.length + 2) + 'ch';
    input.focus();
    input.select();

    var done = false;
    function finish(save) {
      if (done) { return; }
      done = true;
      var name = input.value.trim();
      if (!save || !name || name === orig) { renderItemsPane(); return; }
      apiPost({ action: 'save_symptom', id: id, name: name }).then(function (res) {
        toast(id ? 'เปลี่ยนชื่ออาการแล้ว' : 'เพิ่มอาการ “' + name + '” แล้ว');
        if (!id) { state.cat = res.id; }
        return reload().then(function () { if (state.draft) { renderEditor(); } });
      }).catch(function (err) { renderItemsPane(); fail(err); });
    }
    input.addEventListener('input', function () { input.style.width = Math.max(8, input.value.length + 2) + 'ch'; });
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') { e.preventDefault(); finish(true); }
      if (e.key === 'Escape') { e.preventDefault(); finish(false); }
    });
    input.addEventListener('blur', function () { finish(true); });
  }

  function onItemsPaneDblClick(e) {
    var chip = e.target.closest('.mn-cls-chip[data-cat]');
    if (!chip) { return; }
    var id = parseInt(chip.getAttribute('data-cat'), 10);
    if (id > 0) { startChipEdit(id); }
  }

  // drag & drop reorder (ภายในกลุ่มเดียวกัน)
  var dragEl = null;

  function onDragStart(e) {
    var row = e.target.closest('.mn-cls-irow');
    if (!row) { return; }
    dragEl = row;
    row.classList.add('is-drag');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', row.getAttribute('data-id'));
  }

  function onDragOver(e) {
    if (!dragEl) { return; }
    var row = e.target.closest('.mn-cls-irow');
    if (!row || row === dragEl || row.parentNode !== dragEl.parentNode) { return; }
    e.preventDefault();
    var rect = row.getBoundingClientRect();
    var after = e.clientY > rect.top + rect.height / 2;
    row.parentNode.insertBefore(dragEl, after ? row.nextSibling : row);
  }

  function onDragEnd() {
    if (!dragEl) { return; }
    dragEl.classList.remove('is-drag');
    dragEl = null;
    var ids = [];
    $('mn_cls_pane_items').querySelectorAll('.mn-cls-irow').forEach(function (r) { ids.push(parseInt(r.getAttribute('data-id'), 10)); });
    var changed = false;
    ids.forEach(function (id, i) {
      var it = state.data.items.filter(function (x) { return x.id === id; })[0];
      if (it && it.step !== i + 1) { it.step = i + 1; changed = true; }
    });
    if (!changed) { return; }
    apiPost({ action: 'reorder_items', ids: ids }).then(function () { toast('เรียงลำดับแล้ว'); }).catch(fail);
  }

  // ---------- setup pane (อาการ / กลุ่ม) ----------

  function namedCard(kind, title, desc, rows, countOf) {
    var html = '<div class="mn-cls-card"><div class="mn-cls-card__head"><h3>' + title + '</h3><p class="mn-cls-muted">' + desc + '</p></div><ul class="mn-cls-nlist">';
    if (kind === 'symptom') {
      html += '<li class="mn-cls-nrow is-fixed"><span class="mn-cls-nrow__no">0</span><span class="mn-cls-nrow__name">ตรวจพื้นฐาน</span>'
        + '<span class="mn-cls-pill">' + countOf(0) + ' ข้อ</span><span class="mn-cls-muted">ตรวจทุกเครื่อง</span></li>';
    }
    rows.forEach(function (r, i) {
      html += '<li class="mn-cls-nrow" data-kind="' + kind + '" data-id="' + r.id + '">'
        + '<span class="mn-cls-nrow__no">' + (i + 1) + '</span>'
        + '<input type="text" class="mn-cls-input mn-cls-input--sm mn-cls-nrow__in" value="' + esc(r.name) + '" data-orig="' + esc(r.name) + '" maxlength="100" aria-label="ชื่อ" />'
        + '<span class="mn-cls-pill">' + countOf(r.id) + ' ข้อ</span>'
        + '<span class="mn-cls-nrow__act">'
        + '<button type="button" class="mn-cls-ibtn" data-act="up" aria-label="เลื่อนขึ้น"' + (i === 0 ? ' disabled' : '') + '><i class="fa fa-arrow-up"></i></button>'
        + '<button type="button" class="mn-cls-ibtn" data-act="down" aria-label="เลื่อนลง"' + (i === rows.length - 1 ? ' disabled' : '') + '><i class="fa fa-arrow-down"></i></button>'
        + '<button type="button" class="mn-cls-ibtn mn-cls-ibtn--del" data-act="del" aria-label="ลบ"><i class="fa fa-trash-o"></i></button>'
        + '</span></li>';
    });
    html += '</ul><form class="mn-cls-nadd" data-kind="' + kind + '"><input type="text" class="mn-cls-input mn-cls-input--sm" placeholder="'
      + (kind === 'symptom' ? 'เพิ่มอาการ เช่น พิมพ์จาง' : 'เพิ่มกลุ่ม เช่น หน้าจอ') + '" maxlength="100" />'
      + '<button type="submit" class="mn-cls-btn"><i class="fa fa-plus" aria-hidden="true"></i> เพิ่ม</button></form></div>';
    return html;
  }

  function renderSetupPane() {
    var d = state.data;
    var pane = $('mn_cls_pane_setup');
    if (d.share_from) {
      pane.innerHTML = '<div class="mn-cls-note"><i class="fa fa-link" aria-hidden="true"></i><div><b>รุ่นนี้ใช้รายการตรวจร่วมกับ ' + esc(d.share_from)
        + '</b><p>อาการและกลุ่มชิ้นส่วนใช้ของรุ่นนั้นด้วย</p></div></div>';
      return;
    }
    pane.innerHTML = '<div class="mn-cls-setup">'
      + namedCard('symptom', 'อาการเสีย', 'ช่างเลือกในขั้น “ตรวจตามอาการ” — แต่ละอาการมีข้อตรวจของตัวเอง', d.symptoms, function (id) {
        return d.items.filter(function (i) { return i.symptom_id === id; }).length;
      })
      + namedCard('group', 'กลุ่มชิ้นส่วน', 'ข้อตรวจในกลุ่มเดียวกันแสดงรวมใต้หัวข้อเดียว เช่น หน้าจอ: ทัช / แสดงผล / ความสว่าง', d.groups, function (id) {
        return d.items.filter(function (i) { return i.group_id === id; }).length;
      })
      + '</div>';
  }

  function saveNamed(kind, id, name, onDone) {
    var g = kind === 'group' ? (state.data.groups.filter(function (x) { return x.id === id; })[0] || {}) : {};
    apiPost({ action: 'save_' + kind, id: id, name: name, note: g.note || '' })
      .then(function () { toast('บันทึกแล้ว'); return reload(); })
      .then(onDone || function () {})
      .catch(function (err) { fail(err); reload(); });
  }

  function onSetupClick(e) {
    var btn = e.target.closest('.mn-cls-ibtn');
    if (!btn) { return; }
    var row = btn.closest('.mn-cls-nrow');
    var kind = row.getAttribute('data-kind');
    var id = parseInt(row.getAttribute('data-id'), 10);
    var listKey = kind === 'symptom' ? 'symptoms' : 'groups';
    var ids = state.data[listKey].map(function (x) { return x.id; });
    var act = btn.getAttribute('data-act');
    if (act === 'up' || act === 'down') {
      var i = ids.indexOf(id);
      var j = act === 'up' ? i - 1 : i + 1;
      if (j < 0 || j >= ids.length) { return; }
      ids[i] = ids[j];
      ids[j] = id;
      apiPost({ action: 'reorder_' + kind + 's', ids: ids }).then(reload).catch(fail);
      return;
    }
    if (act === 'del') {
      var name = row.querySelector('input').getAttribute('data-orig');
      var msg = kind === 'symptom'
        ? 'ลบอาการ “' + name + '” ?'
        : 'ลบกลุ่ม “' + name + '” ? ข้อตรวจในกลุ่มนี้จะย้ายไป “ไม่ระบุกลุ่ม” (ไม่ถูกลบ)';
      confirmMsg(msg, function () {
        apiPost({ action: 'delete_' + kind, id: id }).then(function () {
          toast('ลบแล้ว');
          if (kind === 'symptom' && state.cat === id) { state.cat = 0; }
          return reload();
        }).catch(fail);
      }, { variant: 'danger', ok: 'ลบ' });
    }
  }

  function onSetupChange(e) {
    var input = e.target;
    if (!input.classList.contains('mn-cls-nrow__in')) { return; }
    var row = input.closest('.mn-cls-nrow');
    var name = input.value.trim();
    if (name === input.getAttribute('data-orig')) { return; }
    if (!name) { input.value = input.getAttribute('data-orig'); return; }
    saveNamed(row.getAttribute('data-kind'), parseInt(row.getAttribute('data-id'), 10), name);
  }

  function onSetupSubmit(e) {
    var form = e.target.closest('.mn-cls-nadd');
    if (!form) { return; }
    e.preventDefault();
    var input = form.querySelector('input');
    var name = input.value.trim();
    if (!name) { input.focus(); return; }
    saveNamed(form.getAttribute('data-kind'), 0, name, function () {
      var again = doc.querySelector('.mn-cls-nadd[data-kind="' + form.getAttribute('data-kind') + '"] input');
      if (again) { again.focus(); }
    });
  }

  // ---------- history pane ----------

  function renderHistoryPane() {
    var pane = $('mn_cls_pane_history');
    if (state.history && state.history.product === state.product) {
      pane.innerHTML = historyHtml(state.history.data);
      return;
    }
    pane.innerHTML = '<div class="mn-cls-skel mn-cls-skel--tall"></div><div class="mn-cls-skel mn-cls-skel--tall"></div>';
    var product = state.product;
    apiGet({ action: 'history', product: product }).then(function (res) {
      if (!res.ok) { throw new Error(res.error); }
      state.history = { product: product, data: res.history };
      if (state.tab === 'history' && state.product === product) { pane.innerHTML = historyHtml(res.history); }
    }).catch(fail);
  }

  function pct(n, total) { return total ? Math.round(n * 1000 / total) / 10 + '%' : '–'; }

  function historyHtml(h) {
    if (!h.total) { return '<div class="mn-cls-empty mn-cls-empty--inline"><i class="fa fa-history" aria-hidden="true"></i><p>ยังไม่มีประวัติการประเมินของรุ่นนี้</p></div>'; }
    var max = h.parts.length ? h.parts[0].n : 1;
    var parts = h.parts.map(function (p, i) {
      return '<tr><td class="mn-cls-muted">' + (i + 1) + '</td><td><b>' + esc(p.name) + '</b>'
        + '<div class="mn-cls-bar"><span style="width:' + Math.max(3, Math.round(p.n * 100 / max)) + '%"></span></div></td>'
        + '<td class="mn-cls-num">' + p.n + '</td><td class="mn-cls-num mn-cls-muted">' + pct(p.n, h.total) + '</td>'
        + '<td class="mn-cls-ex">' + esc(p.ex) + '</td></tr>';
    }).join('');
    var combos = h.combos.map(function (c, i) {
      return '<tr><td class="mn-cls-muted">' + (i + 1) + '</td><td>' + c.parts.map(function (x) { return '<span class="mn-cls-pill">' + esc(x) + '</span>'; }).join(' ') + '</td>'
        + '<td class="mn-cls-num">' + c.n + '</td><td class="mn-cls-num mn-cls-muted">' + pct(c.n, h.total) + '</td>'
        + '<td class="mn-cls-ex">' + c.inform.map(function (x) { return '<div>• ' + esc(x.text) + (x.n > 1 ? ' <span class="mn-cls-muted">(' + x.n + ')</span>' : '') + '</div>'; }).join('')
        + c.examples.map(function (x) { return '<div class="mn-cls-ex__tech"><span class="mn-cls-muted">' + esc(x.d) + '</span> ' + esc(x.text) + '</div>'; }).join('') + '</td></tr>';
    }).join('');
    return '<p class="mn-cls-muted">จากงานซ่อมที่มีรายการซ่อม ' + h.total.toLocaleString() + ' งาน · ใช้ประกอบการออกแบบว่าควรตรวจอะไรและแนะนำอะไหล่อะไร</p>'
      + '<h3 class="mn-cls-h3">รายการซ่อม / อะไหล่ที่ใช้บ่อย</h3><div class="mn-cls-tablewrap"><table class="mn-cls-table"><thead><tr><th>#</th><th>รายการซ่อม</th><th>งาน</th><th>%</th><th>ตัวอย่างการประเมิน</th></tr></thead><tbody>' + parts + '</tbody></table></div>'
      + '<h3 class="mn-cls-h3">ชุดรายการซ่อมที่พบบ่อย</h3><div class="mn-cls-tablewrap"><table class="mn-cls-table"><thead><tr><th>#</th><th>ชุดรายการซ่อม</th><th>งาน</th><th>%</th><th>อาการที่แจ้ง / การประเมิน</th></tr></thead><tbody>' + combos + '</tbody></table></div>';
  }

  // ---------- editor ----------

  function blankItem() {
    return {
      id: 0, symptom_id: state.cat, group_id: 0, question: '', hint: '',
      type: 'options',
      multi: false,
      options: [blankOpt('ปกติ'), blankOpt(''), blankOpt('')],
      extra_opt: '', unit: '', min: '', max: '', auto: 'buy_date', rate_fail: '', enabled: true
    };
  }

  function blankOpt(label) { return { label: label, note: false, rate: '', color: '' }; }

  function openEditor(item) {
    state.sel = item ? item.id : 'new';
    state.draft = JSON.parse(JSON.stringify(item || blankItem()));
    palOpen = -1;
    if (state.draft.type === 'options' && state.draft.options.length < 2) {
      state.draft.options.push(blankOpt(''));
    }
    state.dirty = false;
    renderEditor();
    if (state.tab === 'items') { renderItemsPane(); }
    if (!item) {
      var q = $('mn_cls_e_question');
      if (q) { q.focus(); }
    }
  }

  function closeEditor() {
    state.sel = null;
    state.draft = null;
    state.dirty = false;
    $('mn_cls_editor').innerHTML = '<div class="mn-cls-empty"><i class="fa fa-pencil-square-o" aria-hidden="true"></i><p>เลือกข้อตรวจเพื่อแก้ไข หรือกด “เพิ่มข้อตรวจ”</p></div>';
  }

  function rateSelect(name, value, placeholder) {
    var rates = state.data.rates || [];
    var html = '<select class="mn-cls-input mn-cls-input--sm" data-f="' + name + '"><option value="">' + (placeholder || '— ไม่แนะนำอะไหล่ —') + '</option>';
    if (value && rates.indexOf(value) === -1) {
      html += '<option value="' + esc(value) + '" selected>' + esc(value) + ' (ไม่มีในรายการซ่อมของรุ่นนี้)</option>';
    }
    rates.forEach(function (r) { html += '<option value="' + esc(r) + '"' + (r === value ? ' selected' : '') + '>' + esc(r) + '</option>'; });
    return html + '</select>';
  }

  function field(label, inner, hint) {
    return '<div class="mn-cls-field"><label class="mn-cls-label">' + label + '</label>' + inner
      + (hint ? '<p class="mn-cls-fhint">' + hint + '</p>' : '') + '</div>';
  }

  function renderEditor() {
    var d = state.draft;
    var isNew = !d.id;
    var cats = '<option value="0"' + (d.symptom_id === 0 ? ' selected' : '') + '>ตรวจพื้นฐาน (ทุกเครื่อง)</option>'
      + state.data.symptoms.map(function (s) {
        return '<option value="' + s.id + '"' + (s.id === d.symptom_id ? ' selected' : '') + '>อาการ: ' + esc(s.name) + '</option>';
      }).join('');
    var grps = '<option value="0">— ไม่ระบุกลุ่ม —</option>' + state.data.groups.map(function (g) {
      return '<option value="' + g.id + '"' + (g.id === d.group_id ? ' selected' : '') + '>' + esc(g.name) + '</option>';
    }).join('');
    var seg = TYPES.map(function (t) {
      return '<button type="button" class="mn-cls-seg' + (t.key === d.type ? ' is-on' : '') + '" data-type="' + t.key + '">' + t.label + '</button>';
    }).join('');

    var html = '<div class="mn-cls-editor__head"><h2 class="mn-cls-h2">' + (isNew ? 'เพิ่มข้อตรวจ' : 'แก้ไขข้อตรวจ') + '</h2>'
      + '<button type="button" class="mn-cls-ibtn" id="mn_cls_e_close" aria-label="ปิด"><i class="fa fa-times"></i></button></div>'
      + '<form class="mn-cls-form" id="mn_cls_form" autocomplete="off">'
      + '<div class="mn-cls-grid2">' + field('หมวด', '<select class="mn-cls-input mn-cls-input--sm" data-f="symptom_id">' + cats + '</select>')
      + field('กลุ่มชิ้นส่วน', '<select class="mn-cls-input mn-cls-input--sm" data-f="group_id">' + grps + '</select>') + '</div>'
      + field('สิ่งที่ตรวจ (คำถาม)', '<input type="text" class="mn-cls-input" id="mn_cls_e_question" data-f="question" maxlength="300" value="' + esc(d.question) + '" placeholder="เช่น หน้าจอแสดงผลปกติไหม" />')
      + field('วิธีตรวจ / คำแนะนำ', '<input type="text" class="mn-cls-input mn-cls-input--sm" data-f="hint" maxlength="300" value="' + esc(d.hint) + '" placeholder="ข้อความตัวเล็กใต้คำถาม (ไม่บังคับ)" />')
      + field('ประเภทคำตอบ', '<div class="mn-cls-segs">' + seg + '</div>')
      + '<div id="mn_cls_typebody">' + typeBody(d) + '</div>'
      + '<label class="mn-cls-switch"><input type="checkbox" data-f="enabled"' + (d.enabled ? ' checked' : '') + ' /><span>เปิดใช้งาน (ช่างเห็นข้อนี้)</span></label>'
      + '<div class="mn-cls-preview"><div class="mn-cls-label">ตัวอย่างหน้าช่าง</div><div id="mn_cls_preview"></div></div>'
      + '<div class="mn-cls-actions">'
      + (isNew ? '' : '<button type="button" class="mn-cls-btn mn-cls-btn--danger" id="mn_cls_e_delete"><i class="fa fa-trash-o" aria-hidden="true"></i> ลบ</button>')
      + '<span class="mn-cls-actions__sp"></span><span class="mn-cls-dirty" id="mn_cls_dirty"></span>'
      + '<button type="submit" class="mn-cls-btn mn-cls-btn--primary" id="mn_cls_e_save"><i class="fa fa-check" aria-hidden="true"></i> บันทึก</button>'
      + '</div></form>';
    var ed = $('mn_cls_editor');
    ed.innerHTML = html;
    ed.classList.remove('is-enter');
    void ed.offsetWidth;
    ed.classList.add('is-enter');
    renderPreview();
    syncDirty();
  }

  function typeBody(d) {
    if (d.type === 'options') {
      var rows = d.options.map(function (o, i) {
        var key = optColor(o, i);
        return '<li class="mn-cls-opt" data-i="' + i + '">'
          + '<button type="button" class="mn-cls-dot' + (palOpen === i ? ' is-open' : '') + '" data-o-pal="' + i + '" style="' + colorVars(key) + '" title="เปลี่ยนสีปุ่ม (' + COLORS[key].name + ')" aria-label="เปลี่ยนสีปุ่ม" aria-expanded="' + (palOpen === i) + '"></button>'
          + '<div class="mn-cls-opt__main"><input type="text" class="mn-cls-input mn-cls-input--sm" data-o="label" maxlength="100" value="' + esc(o.label) + '" placeholder="' + (i === 0 ? 'ผลปกติ เช่น ใช้งานได้ปกติ' : 'ตัวเลือก ' + (i + 1)) + '" />'
          + (palOpen === i ? palette(o) : '')
          + (i === 0 ? '<p class="mn-cls-fhint">ตัวเลือกแรก = ผลปกติ ไม่แนะนำอะไหล่</p>'
            : '<div class="mn-cls-opt__sub">' + rateSelect('rate', o.rate).replace('data-f="rate"', 'data-o="rate"')
              + '<label class="mn-cls-check"><input type="checkbox" data-o="note"' + (o.note ? ' checked' : '') + ' /> ให้พิมพ์รายละเอียด</label></div>')
          + '</div>'
          + (i > 1 ? '<button type="button" class="mn-cls-ibtn mn-cls-ibtn--del" data-o-del="' + i + '" aria-label="ลบตัวเลือก"><i class="fa fa-times"></i></button>' : '<span class="mn-cls-ibtn-sp"></span>')
          + '</li>';
      }).join('');
      return field('ตัวเลือก → รายการซ่อมที่แนะนำ', '<label class="mn-cls-multi' + (d.multi ? ' is-on' : '') + '"><input type="checkbox" data-f="multi"' + (d.multi ? ' checked' : '') + ' />'
        + '<span><b>เลือกได้หลายค่า</b><small>ช่างกดได้หลายปุ่มพร้อมกัน (ยกเว้นตัวเลือกแรก) และแนะนำรายการซ่อมของทุกปุ่มที่กด</small></span></label>'
        + '<ul class="mn-cls-opts">' + rows + '</ul>'
        + (d.options.length < MAX_OPTS ? '<button type="button" class="mn-cls-add mn-cls-add--sm" id="mn_cls_e_addopt"><i class="fa fa-plus" aria-hidden="true"></i> เพิ่มตัวเลือก</button>' : ''),
        'กดจุดสีหน้าตัวเลือกเพื่อเปลี่ยนสีปุ่ม · ค่าเริ่มต้น: เขียว = ปกติ, ส้ม = ผิดปกติเล็กน้อย, แดง = เสีย');
    }
    if (d.type === 'number') {
      return '<div class="mn-cls-grid3">'
        + field('หน่วย', '<input type="text" class="mn-cls-input mn-cls-input--sm" data-f="unit" maxlength="20" value="' + esc(d.unit) + '" placeholder="เช่น V, LUX" />')
        + field('ค่าต่ำสุด', '<input type="number" step="any" class="mn-cls-input mn-cls-input--sm" data-f="min" value="' + esc(d.min) + '" />')
        + field('ค่าสูงสุด', '<input type="number" step="any" class="mn-cls-input mn-cls-input--sm" data-f="max" value="' + esc(d.max) + '" />')
        + '</div>'
        + field('ปุ่มเสริม (ไม่บังคับ)', '<input type="text" class="mn-cls-input mn-cls-input--sm" data-f="extra_opt" maxlength="100" value="' + esc(d.extra_opt) + '" placeholder="เช่น ไม่สามารถวัดได้" />', 'ให้ช่างกดแทนการกรอกค่า ถือเป็น N/A')
        + field('รายการซ่อมเมื่อนอกเกณฑ์', rateSelect('rate_fail', d.rate_fail));
    }
    if (d.type === 'auto') {
      var a = Object.keys(AUTO).map(function (k) { return '<option value="' + k + '"' + (k === d.auto ? ' selected' : '') + '>' + AUTO[k] + '</option>'; }).join('');
      return field('ข้อมูลอัตโนมัติ', '<select class="mn-cls-input mn-cls-input--sm" data-f="auto">' + a + '</select>', 'ช่างไม่ต้องกด ระบบคำนวณจากข้อมูลใบงาน')
        + field('รายการซ่อมเมื่อไม่ผ่าน', rateSelect('rate_fail', d.rate_fail));
    }
    return field('รายการซ่อมเมื่อไม่ผ่าน', rateSelect('rate_fail', d.rate_fail), 'ช่างเห็นปุ่ม ผ่าน / ไม่ผ่าน / N/A');
  }

  function palette(o) {
    var html = '<div class="mn-cls-pal" role="group" aria-label="สีปุ่ม">';
    Object.keys(COLORS).forEach(function (k) {
      html += '<button type="button" class="mn-cls-pal__sw' + (o.color === k ? ' is-on' : '') + '" data-o-color="' + k + '" style="' + colorVars(k) + '" title="' + COLORS[k].name + '" aria-label="' + COLORS[k].name + '"></button>';
    });
    return html + '<button type="button" class="mn-cls-pal__auto' + (o.color ? '' : ' is-on') + '" data-o-color="">ค่าเริ่มต้น</button></div>';
  }

  function renderPreview() {
    var d = state.draft;
    var body = '';
    if (d.type === 'options') {
      body = d.options.map(function (o, i) {
        if (!o.label) { return ''; }
        return '<span class="mn-cls-pv-opt mn-cls-pv-opt--c" style="' + colorVars(optColor(o, i)) + '">'
          + (d.multi && i > 0 ? '<i class="fa fa-check-square-o" aria-hidden="true"></i> ' : '') + esc(o.label) + '</span>';
      }).join('') + (d.multi ? '<span class="mn-cls-muted mn-cls-pv-note">เลือกได้หลายค่า</span>' : '');
    } else if (d.type === 'number') {
      body = '<span class="mn-cls-pv-in">0</span><span class="mn-cls-muted">' + esc(d.unit || 'หน่วย') + '</span>'
        + (d.extra_opt ? '<span class="mn-cls-pv-opt">' + esc(d.extra_opt) + '</span>' : '');
    } else if (d.type === 'auto') {
      body = '<span class="mn-cls-pv-auto">' + esc(AUTO[d.auto] || '') + '</span><span class="mn-cls-muted">ระบบคำนวณให้</span>';
    } else {
      body = '<span class="mn-cls-pv-opt mn-cls-pv-opt--good">ผ่าน</span><span class="mn-cls-pv-opt mn-cls-pv-opt--bad">ไม่ผ่าน</span><span class="mn-cls-pv-opt">N/A</span>';
    }
    var g = state.data.groups.filter(function (x) { return x.id === d.group_id; })[0];
    $('mn_cls_preview').innerHTML = (g ? '<div class="mn-cls-pv-group">' + esc(g.name) + '</div>' : '')
      + '<div class="mn-cls-pv-card' + (d.enabled ? '' : ' is-off') + '"><div class="mn-cls-pv-q">' + (esc(d.question) || '<span class="mn-cls-muted">คำถาม…</span>') + '</div>'
      + (d.hint ? '<div class="mn-cls-pv-hint">' + esc(d.hint) + '</div>' : '')
      + '<div class="mn-cls-pv-body">' + body + '</div></div>';
  }

  function syncDirty() {
    var el = $('mn_cls_dirty');
    if (el) { el.textContent = state.dirty ? 'ยังไม่ได้บันทึก' : ''; }
  }

  function markDirty() {
    state.dirty = true;
    syncDirty();
    renderPreview();
  }

  function onEditorInput(e) {
    var t = e.target;
    var d = state.draft;
    if (!d) { return; }
    var f = t.getAttribute('data-f');
    var o = t.getAttribute('data-o');
    if (f) {
      if (f === 'enabled') { d.enabled = t.checked; }
      else if (f === 'multi') { d.multi = t.checked; t.closest('.mn-cls-multi').classList.toggle('is-on', t.checked); }
      else if (f === 'symptom_id' || f === 'group_id') { d[f] = parseInt(t.value, 10) || 0; }
      else { d[f] = t.value; }
      markDirty();
    } else if (o) {
      var i = parseInt(t.closest('.mn-cls-opt').getAttribute('data-i'), 10);
      d.options[i][o] = o === 'note' ? t.checked : t.value;
      markDirty();
    }
  }

  function onEditorClick(e) {
    var d = state.draft;
    if (!d) { return; }
    if (e.target.closest('#mn_cls_e_close')) { guardDirty(function () { closeEditor(); renderItemsPane(); }); return; }
    var seg = e.target.closest('.mn-cls-seg');
    if (seg) {
      d.type = seg.getAttribute('data-type');
      if (d.type === 'options' && d.options.length < 2) {
        d.options = [blankOpt('ปกติ'), blankOpt('')];
      }
      palOpen = -1;
      doc.querySelectorAll('.mn-cls-seg').forEach(function (s) { s.classList.toggle('is-on', s === seg); });
      $('mn_cls_typebody').innerHTML = typeBody(d);
      markDirty();
      return;
    }
    var pal = e.target.closest('[data-o-pal]');
    if (pal) {
      var pi = parseInt(pal.getAttribute('data-o-pal'), 10);
      palOpen = palOpen === pi ? -1 : pi;
      $('mn_cls_typebody').innerHTML = typeBody(d);
      return;
    }
    var sw = e.target.closest('[data-o-color]');
    if (sw && palOpen >= 0) {
      d.options[palOpen].color = sw.getAttribute('data-o-color');
      palOpen = -1;
      $('mn_cls_typebody').innerHTML = typeBody(d);
      markDirty();
      return;
    }
    if (e.target.closest('#mn_cls_e_addopt')) {
      palOpen = -1;
      d.options.push(blankOpt(''));
      $('mn_cls_typebody').innerHTML = typeBody(d);
      var ins = $('mn_cls_typebody').querySelectorAll('[data-o="label"]');
      ins[ins.length - 1].focus();
      markDirty();
      return;
    }
    var del = e.target.closest('[data-o-del]');
    if (del) {
      palOpen = -1;
      d.options.splice(parseInt(del.getAttribute('data-o-del'), 10), 1);
      $('mn_cls_typebody').innerHTML = typeBody(d);
      markDirty();
      return;
    }
    if (e.target.closest('#mn_cls_e_delete')) {
      confirmMsg('ลบข้อตรวจ “' + d.question + '” ?', function () {
        apiPost({ action: 'delete_item', id: d.id }).then(function (res) {
          toast(res.disabled ? 'ข้อนี้เคยใช้ในใบงาน ' + res.used + ' งาน จึงปิดใช้งานแทนการลบ' : 'ลบข้อตรวจแล้ว');
          state.dirty = false;
          closeEditor();
          return reload();
        }).catch(fail);
      }, { variant: 'danger', ok: 'ลบ' });
    }
  }

  function validateDraft(d) {
    if (!d.question.trim()) { return 'กรอกสิ่งที่ตรวจ (คำถาม)'; }
    if (d.type === 'options') {
      if (!d.options[0].label.trim()) { return 'กรอกตัวเลือกที่ 1 (ผลปกติ)'; }
      if (d.options.filter(function (o) { return o.label.trim(); }).length < 2) { return 'ปุ่มตัวเลือกต้องมีอย่างน้อย 2 ตัวเลือก'; }
    }
    if (d.type === 'number') {
      if (!String(d.unit).trim()) { return 'กรอกหน่วยของค่าที่วัด'; }
      if (d.min === '' || d.max === '' || isNaN(parseFloat(d.min)) || isNaN(parseFloat(d.max))) { return 'กรอกค่าต่ำสุดและค่าสูงสุด'; }
      if (parseFloat(d.min) > parseFloat(d.max)) { return 'ค่าต่ำสุดต้องไม่มากกว่าค่าสูงสุด'; }
    }
    return '';
  }

  function onEditorSubmit(e) {
    e.preventDefault();
    var d = state.draft;
    var err = validateDraft(d);
    if (err) { alertMsg(err); return; }
    var btn = $('mn_cls_e_save');
    btn.classList.add('is-busy');
    apiPost({ action: 'save_item', item: d }).then(function (res) {
      state.dirty = false;
      state.cat = d.symptom_id;
      toast(d.id ? 'บันทึกการแก้ไขแล้ว' : 'เพิ่มข้อตรวจแล้ว');
      return reload().then(function () {
        var saved = state.data.items.filter(function (i) { return i.id === res.id; })[0];
        if (saved) { openEditor(saved); } else { closeEditor(); }
        var row = doc.querySelector('.mn-cls-irow[data-id="' + res.id + '"]');
        if (row) {
          row.classList.add('is-flash');
          row.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
        }
      });
    }).catch(function (er) {
      btn.classList.remove('is-busy');
      fail(er);
    });
  }

  // ---------- layout ----------

  function syncHeaderOffset() {
    var h = doc.querySelector('.header');
    if (h) { doc.body.style.setProperty('--cls-head', h.offsetHeight + 'px'); }
  }

  // ---------- init ----------

  function init() {
    syncHeaderOffset();
    global.addEventListener('resize', syncHeaderOffset);
    global.addEventListener('beforeunload', function (e) {
      if (state.dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    $('mn_cls_search').addEventListener('input', renderProducts);
    $('mn_cls_plist').addEventListener('click', function (e) {
      var b = e.target.closest('.mn-cls-prod');
      if (b && b.getAttribute('data-name') !== state.product) { selectProduct(b.getAttribute('data-name')); }
    });
    doc.querySelectorAll('.mn-cls-tab').forEach(function (t) {
      t.addEventListener('click', function () {
        state.tab = t.getAttribute('data-tab');
        renderMain();
      });
    });
    $('mn_cls_share').addEventListener('change', onShareChange);

    var items = $('mn_cls_pane_items');
    items.addEventListener('click', onItemsPaneClick);
    items.addEventListener('dblclick', onItemsPaneDblClick);
    items.addEventListener('dragstart', onDragStart);
    items.addEventListener('dragover', onDragOver);
    items.addEventListener('dragend', onDragEnd);

    var setup = $('mn_cls_pane_setup');
    setup.addEventListener('click', onSetupClick);
    setup.addEventListener('change', onSetupChange);
    setup.addEventListener('submit', onSetupSubmit);
    setup.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.target.classList.contains('mn-cls-nrow__in')) { e.preventDefault(); e.target.blur(); }
    });

    var ed = $('mn_cls_editor');
    ed.addEventListener('input', onEditorInput);
    ed.addEventListener('change', onEditorInput);
    ed.addEventListener('click', onEditorClick);
    ed.addEventListener('submit', onEditorSubmit);
    doc.addEventListener('keydown', function (e) {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's' && state.draft) {
        e.preventDefault();
        var form = $('mn_cls_form');
        if (form) { form.requestSubmit ? form.requestSubmit() : onEditorSubmit({ preventDefault: function () {} }); }
      }
    });

    loadProducts().then(function () {
      var init0 = global.mnClsInitial || '';
      var hit = state.products.filter(function (p) { return p.name === init0; })[0];
      if (hit) { selectProduct(hit.name); }
    }).catch(fail);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(typeof window !== 'undefined' ? window : this));
