/**
 * หน้า assess_repair — ตรวจเช็คและประเมินเครื่องแบบทีละขั้น
 * ต้องการ: window.mnAr (จาก PHP), mn-modal.js
 */
(function (global) {
  'use strict';

  var B = global.mnAr || {};
  var doc = global.document;
  var MAX_PARTS = 10;
  var STEP_LABELS = { 1: 'เริ่มตรวจ', 2: 'ถัดไป', 3: 'สรุปผล', 4: B.demo ? 'ดูผลที่จะบันทึก' : 'บันทึกการประเมิน' };
  // โหมดทดลอง: ไม่มีใบงานให้คำนวณ จึงให้กดเลือกผลของข้ออัตโนมัติเอง
  var DEMO_AUTO = {
    buy_date: { pass: 'อายุไม่เกิน 2 ปี', fail: 'อายุเกิน 2 ปี' },
    last_ma: { pass: 'MA ล่าสุดไม่เกิน 2 ปี', fail: 'MA เกิน 2 ปี / ไม่พบ MA', na: 'N/A (เครื่องไม่ถึง 2 ปี)' }
  };

  var state = {
    step: 1,
    maxStep: 1,
    basicItems: [],
    basicRes: {},
    symItems: [],
    symRes: {},
    symptomIds: [],
    symCache: {},
    symLoading: {},
    symptomChosen: false,
    parts: [],
    dismissed: {},
    dirty: false,
    saving: false,
    focus: -1
  };

  var COLORS = {
    green: ['#15803d', '#ecf8f0', '#9fd6b1'], teal: ['#0f766e', '#e6f6f4', '#99d5cd'],
    blue: ['#1d4ed8', '#eaf1fe', '#a9c1f5'], violet: ['#6d28d9', '#f2ecfd', '#c9b3f2'],
    amber: ['#a16207', '#fdf7e3', '#ead58c'], orange: ['#c2410c', '#fff4ec', '#f6c19f'],
    red: ['#b91c1c', '#fdf0f0', '#f0b0b0'], gray: ['#475569', '#f1f4f8', '#c5cfdb']
  };

  var rateSet = {};
  (B.rateOptions || []).forEach(function (r) { rateSet[r] = true; });

  // ชุดอะไหล่: ชื่อรายการชุด → รายการย่อยที่รวมอยู่แล้ว (ถ้ามีรายการชุดในใบงาน ไม่ต้องส่งรายการย่อยแยก)
  var BUNDLES = {};
  (B.bundles || []).forEach(function (b) { BUNDLES[b.bundle] = b.members || []; });

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

  function confirmMsg(msg, onOk) {
    if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm(msg, { title: 'ยืนยันการบันทึก', variant: 'info', onConfirm: onOk, okLabel: 'บันทึก', cancelLabel: 'ยกเลิก' });
    } else if (global.confirm(msg)) {
      onOk();
    }
  }

  function getJSON(url, params) {
    var q = Object.keys(params).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(url + '?' + q, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function (r) {
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  function postJSON(url, body) {
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (r.status === 401) { throw new Error('auth'); }
      if (!r.ok) { throw new Error('HTTP ' + r.status); }
      return r.json();
    });
  }

  function restartAnim(el, cls) {
    el.classList.remove(cls);
    void el.offsetWidth;
    el.classList.add(cls);
  }

  // ---------- item logic ----------

  function hasThreshold(item) {
    return item.ci_val_min !== null && item.ci_val_min !== undefined;
  }

  function optTone(item, idx) {
    if (item.ci_unit && item.ci_opts.length === 1) { return 'na'; }
    return idx === 0 ? 'good' : (idx === 1 ? 'warn' : 'bad');
  }

  // ข้อแบบเลือกได้หลายค่าเก็บผลเป็น "ตัวเลือกA|ตัวเลือกB"
  function picked(item, res) {
    if (!res || !res.result) { return []; }
    return item.ci_multi ? res.result.split('|') : [res.result];
  }

  function optIndex(item, label) {
    for (var i = 0; i < (item.ci_opts || []).length; i++) {
      if (item.ci_opts[i].label === label) { return i; }
    }
    return -1;
  }

  // อายุเครื่อง / MA ล่าสุด 1 ปี 10 เดือน ถึงก่อนครบ 2 ปี (server ส่ง auto_near) — สีส้ม แนะนำพิจารณา MA
  function isNear(item, res) {
    return !!(item && item.ci_auto_source && item.auto_near && res && res.result === 'pass');
  }

  // รายการซ่อมที่แนะนำเมื่อใกล้ครบ 2 ปี (ยังไม่ใส่ในใบงานเอง ช่างกดเพิ่มจากปุ่มแนะนำ)
  function nearRate(item, res) {
    return isNear(item, res) ? (item.ci_rate_on_fail || '').trim() : '';
  }

  function autoTone(item, res) {
    if (res.result === 'fail') { return 'bad'; }
    return isNear(item, res) ? 'warn' : 'good';
  }

  function itemTone(item, res) {
    if (!res || !res.result) { return ''; }
    var r = res.result;
    if (r === 'pass') { return isNear(item, res) ? 'warn' : 'good'; }
    if (r === 'fail') { return 'bad'; }
    if (r === 'na') { return 'na'; }
    var worst = -1;
    picked(item, res).forEach(function (label) { worst = Math.max(worst, optIndex(item, label)); });
    return worst >= 0 ? optTone(item, worst) : '';
  }

  function isBad(item, res) {
    if (!res || !res.result) { return false; }
    if (item.ci_opts && !hasThreshold(item)) {
      var first = item.ci_opts[0].label;
      return picked(item, res).some(function (l) { return l !== first && l !== 'pass' && l !== 'na'; });
    }
    return res.result === 'fail';
  }

  function isAnswered(item, res) {
    return !!(res && res.result);
  }

  function initResults(items) {
    var map = {};
    items.forEach(function (it) {
      if (it.ci_auto_source && it.auto_result) {
        map[it.ci_id] = { result: it.saved_result || it.auto_result, note: it.saved_note || it.auto_detail || '' };
      } else if (it.saved_result) {
        map[it.ci_id] = { result: it.saved_result, note: it.saved_note || '' };
      }
    });
    return map;
  }

  // ---------- render items ----------

  function renderItems(wrap, items, resMap, section) {
    wrap.innerHTML = itemsHtml(items, resMap, section);
  }

  function itemsHtml(items, resMap, section) {
    var html = '';
    var grouped = items.some(function (it) { return it.group_name; });
    var groupCount = {};
    items.forEach(function (it) { groupCount[it.group_id] = (groupCount[it.group_id] || 0) + 1; });
    var lastGroup = null;
    var okIds = function (list) {
      return list.filter(function (x) { return !x.ci_auto_source && !x.ci_unit; }).map(function (x) { return x.ci_id; });
    };
    var allOkBtn = function (ids) {
      return ids.length > 1
        ? '<button type="button" class="mn-ar-allok" data-section="' + section + '" data-allok="' + ids.join(',') + '" title="ตอบข้อที่ยังไม่ได้ตอบในกลุ่มนี้ว่าปกติ"><i class="fa fa-check" aria-hidden="true"></i> ปกติทั้งหมด</button>'
        : '';
    };
    if (!grouped) {
      var all = okIds(items);
      if (all.length > 1) { html += '<li class="mn-ar-group mn-ar-group--plain"><span>ข้อตรวจ</span><small>' + items.length + ' ข้อ</small>' + allOkBtn(all) + '</li>'; }
    }
    items.forEach(function (it, i) {
      var res = resMap[it.ci_id] || { result: '', note: '' };
      var tone = itemTone(it, res);
      if (grouped && it.group_id !== lastGroup) {
        lastGroup = it.group_id;
        html += '<li class="mn-ar-group" style="animation-delay:' + Math.min(i * 40, 320) + 'ms"><span>'
          + esc(it.group_name || 'อื่น ๆ') + '</span><small>' + groupCount[it.group_id] + ' ข้อ</small>'
          + allOkBtn(okIds(items.filter(function (x) { return x.group_id === it.group_id; }))) + '</li>';
      }
      html += '<li class="mn-ar-item' + (tone ? ' is-answered is-' + tone : '') + '" data-id="' + it.ci_id
        + '" data-section="' + section + '" style="animation-delay:' + Math.min(i * 40, 320) + 'ms">';
      html += '<div class="mn-ar-item__head"><span class="mn-ar-item__num">' + (i + 1) + '</span>'
        + '<div class="mn-ar-item__text"><div class="mn-ar-item__q">' + esc(it.ci_question) + '</div>'
        + (it.ci_hint ? '<div class="mn-ar-item__hint">' + esc(it.ci_hint) + '</div>' : '')
        + '</div></div>';
      html += '<div class="mn-ar-item__body' + (it.ci_unit && !it.ci_auto_source ? ' mn-ar-item__body--num' : '') + '">' + renderControl(it, res) + '</div>';
      html += '<div class="mn-ar-photos" data-ci="' + it.ci_id + '">' + photoStripInner(it.ci_id) + '</div>';
      html += '</li>';
    });
    return html;
  }

  function renderControl(it, res) {
    var html = '';
    if (it.ci_auto_source && B.demo) {
      var labels = DEMO_AUTO[it.ci_auto_source] || { pass: 'ผ่าน', fail: 'ไม่ผ่าน' };
      html = '<div class="mn-ar-opts" role="group">';
      Object.keys(labels).forEach(function (k) {
        var on = res.result === k;
        html += '<button type="button" class="mn-ar-opt mn-ar-opt--' + (k === 'pass' ? 'good' : (k === 'fail' ? 'bad' : 'na')) + (on ? ' is-on' : '')
          + '" data-result="' + k + '" data-note="0" aria-pressed="' + on + '">' + esc(labels[k]) + '</button>';
      });
      return html + '<span class="mn-ar-auto__tag">งานจริงระบบคำนวณให้</span></div>';
    }
    if (it.ci_auto_source) {
      var ar = isNear(it, res) ? 'near' : (it.auto_result || 'na');
      return '<div><span class="mn-ar-auto mn-ar-auto--' + esc(ar) + '">' + esc(it.auto_label || '')
        + (it.auto_detail ? ' <small>' + esc(it.auto_detail) + '</small>' : '') + '</span>'
        + ' <span class="mn-ar-auto__tag">ระบบคำนวณให้</span></div>';
    }

    if (it.ci_unit) {
      var val = '';
      var resTxt = '';
      if (res.note !== '' && hasThreshold(it) && !isNaN(parseFloat(res.note)) && (res.result === 'pass' || res.result === 'fail')) {
        val = res.note;
        resTxt = measureLabel(res.result);
      }
      html += '<div class="mn-ar-measure"><input type="number" inputmode="decimal" class="mn-ar-input mn-ar-measure__in" min="0" placeholder="0" value="'
        + esc(val) + '" aria-label="' + esc(it.ci_question) + '" /><span class="mn-ar-measure__unit">' + esc(it.ci_unit)
        + '</span><span class="mn-ar-measure__res">' + resTxt + '</span></div>';
    }

    var opts = it.ci_opts || [
      { label: 'pass', text: 'ผ่าน', tone: 'good' },
      { label: 'fail', text: 'ไม่ผ่าน', tone: 'bad' },
      { label: 'na', text: 'N/A', tone: 'na' }
    ];
    var sel = picked(it, res);
    var multi = !!it.ci_multi;
    html += '<div class="mn-ar-opts' + (multi ? ' mn-ar-opts--multi' : '') + '" role="group">';
    var hasNote = false;
    var show = false;
    opts.forEach(function (o, i) {
      var tone = o.tone || optTone(it, i);
      var on = sel.indexOf(o.label) !== -1;
      var c = COLORS[o.color];
      if (o.has_note) { hasNote = true; if (on) { show = true; } }
      html += '<button type="button" class="mn-ar-opt mn-ar-opt--' + tone + (c ? ' mn-ar-opt--c' : '') + (on ? ' is-on' : '') + '" data-result="' + esc(o.label)
        + '" data-note="' + (o.has_note ? '1' : '0') + '" data-note-def="' + esc(o.note_default || '') + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
        + (c ? ' style="--oc:' + c[0] + ';--oc-soft:' + c[1] + ';--oc-line:' + c[2] + '"' : '') + '>'
        + (multi && i > 0 ? '<i class="fa fa-square-o mn-ar-opt__box" aria-hidden="true"></i><i class="fa fa-check-square mn-ar-opt__tick" aria-hidden="true"></i>' : '')
        + esc(o.text || o.label) + '</button>';
    });
    html += (multi ? '<span class="mn-ar-opts__multi">เลือกได้หลายข้อ</span>' : '') + '</div>';

    if (hasNote) {
      html += '<input type="text" class="mn-ar-input mn-ar-note-in' + (show ? ' mn-ar-reveal' : '') + '" placeholder="ระบุรายละเอียด เช่น ตำแหน่งที่พบ" aria-label="รายละเอียด: ' + esc(it.ci_question) + '"'
        + ' value="' + esc(show ? res.note : '') + '"' + (show ? '' : ' hidden') + ' />';
    }
    return html;
  }

  function measureLabel(result) {
    return result === 'pass'
      ? '<span class="mn-ar-measure__res--pass"><i class="fa fa-check" aria-hidden="true"></i> ผ่านเกณฑ์</span>'
      : '<span class="mn-ar-measure__res--fail"><i class="fa fa-times" aria-hidden="true"></i> นอกเกณฑ์</span>';
  }

  function findItem(section, id) {
    var list = section === 'basic' ? state.basicItems : state.symItems;
    for (var i = 0; i < list.length; i++) {
      if (list[i].ci_id === id) { return list[i]; }
    }
    return null;
  }

  function resMapFor(section) {
    return section === 'basic' ? state.basicRes : state.symRes;
  }

  function refreshItemState(li, item, res) {
    var prevTone = (li.className.match(/is-(good|warn|bad|na)\b/) || [])[1] || '';
    var tone = itemTone(item, res);
    li.classList.remove('is-good', 'is-warn', 'is-bad', 'is-na', 'is-missing');
    li.classList.toggle('is-answered', !!tone || isAnswered(item, res));
    if (tone) { li.classList.add('is-' + tone); }
    if (tone && tone !== prevTone) { restartAnim(li.querySelector('.mn-ar-item__num'), 'is-pop'); }
    updateProgress();
  }

  // ใส่ค่าเริ่มต้นของช่องรายละเอียด เมื่อช่องว่าง หรือยังเป็นค่าเริ่มต้นของตัวเลือกอื่นที่ช่างยังไม่ได้แก้
  function noteWithDefault(item, note, onBtns, clicked) {
    var defs = (item.ci_opts || []).map(function (o) { return o.note_default || ''; });
    if (note !== '' && defs.indexOf(note) === -1) { return note; }
    var src = onBtns.indexOf(clicked) !== -1 && clicked.getAttribute('data-note-def') ? clicked : null;
    if (!src) {
      src = onBtns.filter(function (b) { return b.getAttribute('data-note-def'); })[0] || null;
    }
    return src ? src.getAttribute('data-note-def') : note;
  }

  // กลุ่มของตัวเลือก (0 = เลือกร่วมกับข้ออื่นได้) — ตัวเลือกกลุ่มเดียวกันเลือกได้ทีละข้อ
  function optGroup(item, label) {
    var i = optIndex(item, label);
    return i > 0 && item.ci_opts[i] ? (parseInt(item.ci_opts[i].group, 10) || 0) : 0;
  }

  // ตอบ "ปกติ" ให้ข้อที่ยังไม่ได้ตอบ (ข้อที่ตอบแล้วไม่ถูกแตะ · ข้อค่าตัวเลข/อัตโนมัติไม่รวม)
  function fillNormal(btn) {
    var section = btn.getAttribute('data-section') === 'basic' ? 'basic' : 'sym';
    var map = resMapFor(section);
    var n = 0;
    btn.getAttribute('data-allok').split(',').forEach(function (s) {
      var id = parseInt(s, 10);
      var it = findItem(section, id);
      if (!it || isAnswered(it, map[id])) { return; }
      map[id] = { result: it.ci_opts && it.ci_opts.length ? it.ci_opts[0].label : 'pass', note: '' };
      n++;
    });
    if (!n) { return; }
    state.dirty = true;
    if (section === 'basic') { renderItems($('mn_ar_basic'), state.basicItems, state.basicRes, 'basic'); } else { renderSymItems(); }
    updateProgress();
    queueDraft();
  }

  function onItemsClick(e) {
    var allOk = e.target.closest('.mn-ar-allok');
    if (allOk) { fillNormal(allOk); return; }
    var btn = e.target.closest('.mn-ar-opt');
    if (!btn) { return; }
    var li = btn.closest('.mn-ar-item');
    var section = li.getAttribute('data-section');
    var id = parseInt(li.getAttribute('data-id'), 10);
    var item = findItem(section, id);
    var map = resMapFor(section);
    var result = btn.getAttribute('data-result');
    var cur = map[id] || { result: '', note: '' };
    var sel = picked(item, cur);

    if (item.ci_multi) {
      var first = item.ci_opts[0].label;
      if (result === first) {
        sel = sel.indexOf(first) !== -1 ? [] : [first];
      } else {
        sel = sel.filter(function (l) { return l !== first; });
        if (sel.indexOf(result) !== -1) {
          sel = sel.filter(function (l) { return l !== result; });
        } else {
          sel.push(result);
          var grp = optGroup(item, result);
          if (grp) { sel = sel.filter(function (l) { return l === result || optGroup(item, l) !== grp; }); }
        }
        sel.sort(function (a, b) { return optIndex(item, a) - optIndex(item, b); });
      }
    } else {
      sel = cur.result === result ? [] : [result];
    }
    var onBtns = [];
    li.querySelectorAll('.mn-ar-opt').forEach(function (b) {
      if (sel.indexOf(b.getAttribute('data-result')) !== -1) { onBtns.push(b); }
    });
    var wantNote = onBtns.some(function (b) { return b.getAttribute('data-note') === '1'; });
    map[id] = { result: sel.join('|'), note: wantNote ? noteWithDefault(item, cur.note, onBtns, btn) : '' };
    refreshPhotos(id);
    state.dirty = true;

    li.querySelectorAll('.mn-ar-opt').forEach(function (b) {
      var on = onBtns.indexOf(b) !== -1;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    var noteIn = li.querySelector('.mn-ar-note-in');
    if (noteIn) {
      var showNote = wantNote;
      if (showNote && noteIn.value !== map[id].note) {
        noteIn.value = map[id].note;
      }
      if (showNote && noteIn.hidden) {
        noteIn.hidden = false;
        restartAnim(noteIn, 'mn-ar-reveal');
        noteIn.focus();
        noteIn.setSelectionRange(noteIn.value.length, noteIn.value.length);
      } else if (!showNote) {
        noteIn.hidden = true;
        noteIn.value = '';
      }
    }

    var measure = li.querySelector('.mn-ar-measure__in');
    if (measure && map[id].result !== '') {
      measure.value = '';
      li.querySelector('.mn-ar-measure__res').innerHTML = '';
    }

    refreshItemState(li, item, map[id]);
  }

  function onItemsInput(e) {
    var el = e.target;
    var li = el.closest('.mn-ar-item');
    if (!li) { return; }
    var section = li.getAttribute('data-section');
    var id = parseInt(li.getAttribute('data-id'), 10);
    var item = findItem(section, id);
    var map = resMapFor(section);
    if (!map[id]) { map[id] = { result: '', note: '' }; }
    state.dirty = true;

    if (el.classList.contains('mn-ar-note-in')) {
      map[id].note = el.value;
      return;
    }
    if (el.classList.contains('mn-ar-measure__in')) {
      var v = el.value.trim();
      var out = li.querySelector('.mn-ar-measure__res');
      map[id].note = v;
      if (v !== '' && hasThreshold(item)) {
        var n = parseFloat(v);
        var max = item.ci_val_max !== null && item.ci_val_max !== undefined ? item.ci_val_max : Infinity;
        map[id].result = (!isNaN(n) && n >= item.ci_val_min && n <= max) ? 'pass' : 'fail';
        out.innerHTML = measureLabel(map[id].result);
        li.querySelectorAll('.mn-ar-opt').forEach(function (b) {
          var on = b.getAttribute('data-result') === map[id].result;
          b.classList.toggle('is-on', on);
          b.setAttribute('aria-pressed', on ? 'true' : 'false');
        });
      } else {
        map[id].result = '';
        out.innerHTML = '';
        li.querySelectorAll('.mn-ar-opt').forEach(function (b) {
          b.classList.remove('is-on');
          b.setAttribute('aria-pressed', 'false');
        });
      }
      refreshItemState(li, item, map[id]);
      refreshPhotos(id);
    }
  }

  function countAnswered(items, map) {
    var n = 0;
    items.forEach(function (it) { if (isAnswered(it, map[it.ci_id])) { n++; } });
    return n;
  }

  function updateProgress() {
    var bp = $('mn_ar_basic_progress');
    if (state.basicItems.length) {
      bp.innerHTML = 'ตอบแล้ว <b>' + countAnswered(state.basicItems, state.basicRes) + '</b>/' + state.basicItems.length;
    }
    var sp = $('mn_ar_sym_progress');
    sp.innerHTML = state.symItems.length
      ? 'ตอบแล้ว <b>' + countAnswered(state.symItems, state.symRes) + '</b>/' + state.symItems.length
      : '';
  }

  // ---------- loading ----------

  function loadBasic() {
    return getJSON('checklist_items', { product: B.product, symptom_id: 0, trp_id: B.trpId })
      .then(function (data) {
        state.basicItems = data.items || [];
        state.basicRes = initResults(state.basicItems);
        renderItems($('mn_ar_basic'), state.basicItems, state.basicRes, 'basic');
        updateProgress();
      })
      .catch(function () {
        $('mn_ar_basic').innerHTML = '';
        alertMsg('โหลดรายการตรวจพื้นฐานไม่สำเร็จ ลอง reload หน้าอีกครั้ง');
      });
  }

  // ---------- อาการ (เลือกได้หลายอาการ — แต่ละอาการมีข้อตรวจของตัวเอง) ----------

  function symOrder(sid) {
    var list = B.symptoms || [];
    for (var i = 0; i < list.length; i++) { if (list[i].id === sid) { return i; } }
    return 999;
  }

  function symName(sid) {
    var s = (B.symptoms || [])[symOrder(sid)];
    return s ? s.name : '';
  }

  function rebuildSymItems() {
    state.symItems = [];
    state.symptomIds.forEach(function (sid) { state.symItems = state.symItems.concat(state.symCache[sid] || []); });
  }

  function renderSymItems() {
    var multi = state.symptomIds.length > 1;
    var html = '';
    state.symptomIds.forEach(function (sid) {
      if (multi) {
        html += '<li class="mn-ar-symhead"><span>' + esc(symName(sid)) + '</span>'
          + (state.symCache[sid] ? '<small>' + state.symCache[sid].length + ' ข้อ</small>' : '') + '</li>';
      }
      if (!state.symCache[sid]) {
        html += '<li class="mn-ar-skeleton"></li><li class="mn-ar-skeleton"></li>';
        return;
      }
      if (multi && !state.symCache[sid].length) {
        html += '<li class="mn-ar-symempty">อาการนี้ยังไม่มีข้อตรวจ</li>';
      }
      html += itemsHtml(state.symCache[sid], state.symRes, 'sym');
    });
    $('mn_ar_sym_items').innerHTML = html;
    updateProgress();
  }

  function ensureSymptom(sid) {
    if (state.symCache[sid] || state.symLoading[sid]) { return; }
    state.symLoading[sid] = true;
    getJSON('checklist_items', { product: B.product, symptom_id: sid, trp_id: B.trpId })
      .then(function (data) {
        var items = data.items || [];
        state.symCache[sid] = items;
        var init = initResults(items);
        Object.keys(init).forEach(function (k) { if (!state.symRes[k]) { state.symRes[k] = init[k]; } });
      })
      .catch(function () {
        state.symptomIds = state.symptomIds.filter(function (x) { return x !== sid; });
        state.symptomChosen = state.symptomIds.length > 0;
        renderSymptoms();
        alertMsg('โหลดรายการตรวจของอาการ “' + symName(sid) + '” ไม่สำเร็จ');
      })
      .then(function () {
        delete state.symLoading[sid];
        rebuildSymItems();
        renderSymItems();
      });
  }

  function renderSymptoms() {
    var html = '';
    (B.symptoms || []).forEach(function (s) {
      var on = state.symptomIds.indexOf(s.id) !== -1;
      html += '<button type="button" class="mn-ar-chip' + (on ? ' is-on' : '') + '" role="checkbox" aria-checked="' + (on ? 'true' : 'false')
        + '" data-sid="' + s.id + '">' + (on ? '<i class="fa fa-check mn-ar-chip__tick" aria-hidden="true"></i>' : '') + esc(s.name) + '</button>';
    });
    var none = state.symptomChosen && !state.symptomIds.length;
    html += '<button type="button" class="mn-ar-chip mn-ar-chip--ghost' + (none ? ' is-on' : '') + '" role="checkbox" aria-checked="'
      + (none ? 'true' : 'false') + '" data-sid="0">ไม่มีอาการเฉพาะ</button>';
    $('mn_ar_symptoms').innerHTML = html;
    var cnt = $('mn_ar_sym_count');
    if (cnt) { cnt.textContent = state.symptomIds.length > 1 ? 'เลือก ' + state.symptomIds.length + ' อาการ' : ''; }
  }

  function onSymptomClick(e) {
    var chip = e.target.closest('.mn-ar-chip');
    if (!chip) { return; }
    var sid = parseInt(chip.getAttribute('data-sid'), 10);
    if (sid === 0) {
      if (state.symptomChosen && !state.symptomIds.length) { return; }
      state.symptomIds = [];
      state.symptomChosen = true;
    } else {
      var i = state.symptomIds.indexOf(sid);
      if (i === -1) { state.symptomIds.push(sid); } else { state.symptomIds.splice(i, 1); }
      state.symptomIds.sort(function (a, b) { return symOrder(a) - symOrder(b); });
      state.symptomChosen = state.symptomIds.length > 0;
    }
    state.dirty = true;
    renderSymptoms();
    state.symptomIds.forEach(ensureSymptom);
    rebuildSymItems();
    renderSymItems();
  }

  // ---------- validation ----------

  function markMissing(items, map, wrap) {
    var first = null;
    var missing = 0;
    items.forEach(function (it) {
      if (isAnswered(it, map[it.ci_id])) { return; }
      missing++;
      var li = wrap.querySelector('.mn-ar-item[data-id="' + it.ci_id + '"]');
      if (li) {
        restartAnim(li, 'is-missing');
        if (!first) { first = li; }
      }
    });
    if (first) { first.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    return missing;
  }

  function canLeave(step) {
    if (step === 2) {
      if (!state.basicItems.length) { return false; }
      var m = markMissing(state.basicItems, state.basicRes, $('mn_ar_basic'));
      return m === 0 && warnMissingPhotos(missingPhotos(state.basicItems, state.basicRes), $('mn_ar_basic'));
    }
    if (step === 3) {
      if (!state.symptomChosen) {
        var chips = $('mn_ar_symptoms');
        restartAnim(chips, 'mn-ar-reveal');
        alertMsg('เลือกอาการที่พบ (เลือกได้หลายอาการ) หรือกด “ไม่มีอาการเฉพาะ”');
        return false;
      }
      if (Object.keys(state.symLoading).length) {
        alertMsg('กำลังโหลดข้อตรวจของอาการ รอสักครู่');
        return false;
      }
      return markMissing(state.symItems, state.symRes, $('mn_ar_sym_items')) === 0
        && warnMissingPhotos(missingPhotos(state.symItems, state.symRes), $('mn_ar_sym_items'));
    }
    return true;
  }

  // ---------- summary ----------

  function allChecked() {
    var out = [];
    state.basicItems.forEach(function (it) { out.push({ item: it, res: state.basicRes[it.ci_id] }); });
    state.symItems.forEach(function (it) { out.push({ item: it, res: state.symRes[it.ci_id] }); });
    return out;
  }

  function findingText(item, res) {
    var note = (res.note || '').trim();
    if (item.ci_auto_source && B.demo) { return ((DEMO_AUTO[item.ci_auto_source] || {})[res.result]) || res.result; }
    if (item.ci_auto_source) { return note || item.auto_label || res.result; }
    if (item.ci_unit && hasThreshold(item) && res.result === 'fail') {
      return item.ci_question + ' ' + note + ' ' + item.ci_unit + ' (นอกเกณฑ์ ' + item.ci_val_min + '–' + (item.ci_val_max !== null && item.ci_val_max !== undefined ? item.ci_val_max : '') + ')';
    }
    if (res.result === 'fail') { return item.ci_question + ' — ไม่ผ่าน'; }
    var first = item.ci_opts && item.ci_opts.length ? item.ci_opts[0].label : '';
    var labels = picked(item, res).filter(function (l) { return !item.ci_multi || l !== first; });
    return labels.join(', ') + (note ? ' (' + note + ')' : '');
  }

  function symptomName() {
    return state.symptomIds.map(symName).filter(function (n) { return n; }).join(', ');
  }

  function collectFindings() {
    var findings = [];
    var good = 0;
    var na = 0;
    allChecked().forEach(function (c) {
      var tone = itemTone(c.item, c.res);
      if (tone === 'good') { good++; return; }
      if (tone === 'na' || !tone) { na++; return; }
      findings.push({ tone: tone, q: c.item.ci_question, text: findingText(c.item, c.res), item: c.item, res: c.res });
    });
    return { findings: findings, good: good, na: na };
  }

  function ratesFor(item, res) {
    if (!isBad(item, res)) { return []; }
    if (item.ci_opts && !hasThreshold(item)) {
      var out = [];
      picked(item, res).forEach(function (label) {
        var i = optIndex(item, label);
        var r = i > 0 ? (item.ci_opts[i].rate || '').trim() : '';
        if (r) { out.push(r); }
      });
      return out;
    }
    var f = (item.ci_rate_on_fail || '').trim();
    return f ? [f] : [];
  }

  function checklistRates() {
    var seen = {};
    var out = [];
    allChecked().forEach(function (c) {
      ratesFor(c.item, c.res).forEach(function (rate) {
        if (!seen[rate]) {
          seen[rate] = true;
          out.push(rate);
        }
      });
    });
    return out;
  }

  function renderFindings(sum) {
    var bad = sum.findings.length;
    var html = '<div class="mn-ar-findings__head">'
      + '<div class="mn-ar-stat mn-ar-stat--bad' + (bad ? '' : ' is-zero') + '"><span class="mn-ar-stat__n">' + bad + '</span><span class="mn-ar-stat__l"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> พบปัญหา</span></div>'
      + '<div class="mn-ar-stat mn-ar-stat--good"><span class="mn-ar-stat__n">' + sum.good + '</span><span class="mn-ar-stat__l"><i class="fa fa-check" aria-hidden="true"></i> ปกติ</span></div>'
      + '<div class="mn-ar-stat"><span class="mn-ar-stat__n">' + sum.na + '</span><span class="mn-ar-stat__l"><i class="fa fa-minus" aria-hidden="true"></i> N/A</span></div>'
      + '</div>';
    $('mn_ar_findings').innerHTML = html;
  }

  function enterSummary() {
    renderFindings(collectFindings());
    state.step = 4;
    checklistRates().forEach(function (r) {
      if (rateSet[r] && !state.dismissed[r]) { addPart(r, 'check', true); }
    });
    renderParts();
  }

  // ---------- สรุปการประเมิน (ข้อความที่ส่งต่อฝ่ายเสนอราคา) ----------
  // หัวข้อหลัก = กลุ่มชิ้นส่วน | หัวข้อย่อย = ข้อที่ตรวจ: ผล (ส่วนที่เสียขึ้นก่อน) | "เสนอ:" ท้ายกลุ่ม
  // ปิดท้ายด้วยสรุปรายการที่เสนอเปลี่ยน/ซ่อมทั้งหมด | ความเห็นช่างต่อท้ายหลัง "//"

  var COMMENT_SEP = '\n// ';
  var SUB = '   - ';

  // วลีของข้อมูลอัตโนมัติ ให้อ่านเหมือนที่ช่างเขียน
  var AUTO_PHRASE = {
    buy_date: { pass: 'ยังไม่เกิน 2 ปี', near: 'ใกล้ครบ 2 ปี', fail: 'เกิน 2 ปี' },
    last_ma: { pass: 'เคยเปลี่ยนอะไหล่พื้นฐานไปยังไม่เกิน 2 ปี', near: 'เปลี่ยนอะไหล่พื้นฐานล่าสุดใกล้ครบ 2 ปี', fail: 'เคยเปลี่ยนอะไหล่พื้นฐานเกิน 2 ปี' }
  };

  // ใกล้ครบ 2 ปีและยังไม่มีรายการ MA ในใบงาน → " แนะนำพิจารณา MA …"
  function nearAdvice(item, res) {
    var r = nearRate(item, res);
    return r && !hasPart(r) && !coveredBy(r) ? ' แนะนำพิจารณา ' + r : '';
  }

  // อายุจากรายละเอียดอัตโนมัติ: "อายุ 1 ปี 11 เดือน (ซื้อ …)" / "MA ล่าสุด … (1 ปี 11 เดือน ที่แล้ว)"
  function autoAge(item, res) {
    var d = (res.note || '').trim() || (item.auto_detail || '');
    var m = d.match(/อายุ\s+([^()]+?)\s*\(/) || d.match(/\(([^()]+?)\s*ที่แล้ว\)/);
    return m ? m[1].trim() : '';
  }

  function rangeText(item) {
    var max = item.ci_val_max;
    return (max !== null && max !== undefined) ? item.ci_val_min + '-' + max : 'ตั้งแต่ ' + item.ci_val_min;
  }

  function autoPhrase(item, res) {
    var src = item.ci_auto_source;
    if (res.result === 'na') { return ''; }
    var base = (AUTO_PHRASE[src] || {})[isNear(item, res) ? 'near' : res.result] || '';
    if (B.demo) { return base || ((DEMO_AUTO[src] || {})[res.result]) || ''; }
    var detail = (res.note || '').trim() || (item.auto_detail || '');
    if (!detail) { return base && !/ไม่พบ|ไม่ถูกต้อง|ไม่ทราบ/.test(item.auto_label || '') ? base : (item.auto_label || base); }
    return (base || item.auto_label || '') + ' (' + detail.replace(/\s*\((.*)\)\s*$/, ' $1') + ')';
  }

  // ผลของข้อตรวจหนึ่งข้อ → { label: ชื่อข้อ, text: ผล }
  function itemLine(it, res, tone) {
    var q = it.ci_question;
    var note = (res.note || '').trim();
    if (it.ci_auto_source) { return { label: q, text: autoPhrase(it, res) + nearAdvice(it, res) }; }
    if (it.ci_unit && hasThreshold(it) && note !== '' && !isNaN(parseFloat(note)) && (res.result === 'pass' || res.result === 'fail')) {
      var n = parseFloat(note);
      var where = res.result === 'pass' ? 'อยู่ในค่ามาตรฐาน' : (n < it.ci_val_min ? 'ต่ำกว่าค่ามาตรฐาน' : 'สูงกว่าค่ามาตรฐาน');
      return { label: q, text: 'วัดได้ ' + note + ' ' + it.ci_unit + ' ' + where + ' (' + rangeText(it) + ' ' + it.ci_unit + ')' };
    }
    if (tone === 'na' || !tone) {
      var naLabel = it.ci_opts && res.result && res.result !== 'na' ? picked(it, res).join(' ') : '';
      return { label: q, text: naLabel || 'ไม่ได้ตรวจ (N/A)' };
    }
    if (!it.ci_opts) { return { label: q, text: (res.result === 'pass' ? 'ปกติ' : 'ไม่ผ่าน') + (note ? ' ' + note : '') }; }
    var first = it.ci_opts[0].label;
    var labels = picked(it, res);
    if (tone !== 'good') { labels = labels.filter(function (l) { return l !== first; }); }
    return { label: q, text: labels.join(' และ ') + (note ? ' - ' + note : '') };
  }

  var TONE_INK = { good: '#15803d', warn: '#c2410c', bad: '#b91c1c', na: '#64748b' };

  // สีของผลแต่ละข้อ = สีเดียวกับปุ่มที่ช่างกดในขั้นตรวจ (ตัวเลือกที่แย่ที่สุดถ้าเลือกหลายค่า)
  function lineColor(it, res, tone) {
    if (it.ci_opts && !it.ci_auto_source && !(it.ci_unit && hasThreshold(it) && (res.result === 'pass' || res.result === 'fail'))) {
      var worst = -1;
      picked(it, res).forEach(function (l) { var i = optIndex(it, l); if (i > worst) { worst = i; } });
      if (worst >= 0) {
        var o = it.ci_opts[worst];
        if (COLORS[o.color]) { return COLORS[o.color][0]; }
        tone = o.tone || optTone(it, worst);
      }
    }
    return TONE_INK[tone] || TONE_INK.na;
  }

  // รายการซ่อมจริงในใบงานที่มาจากข้อนี้ (ถ้าถูกรวมในชุดจะชี้ไปที่รายการชุด)
  function partsForItem(it, res) {
    var out = [];
    var near = nearRate(it, res);
    ratesFor(it, res).concat(near ? [near] : []).forEach(function (r) {
      if (hasPart(r)) { pushOnce(out, r); return; }
      var cover = coveredBy(r);
      if (cover) { pushOnce(out, cover); }
    });
    return out;
  }

  function pushOnce(list, v) { if (v && list.indexOf(v) === -1) { list.push(v); } }

  function buildReport() {
    var groups = [];
    var byKey = {};
    var from = {};
    allChecked().forEach(function (c) {
      var it = c.item;
      var res = c.res || { result: '', note: '' };
      var key = it.group_id ? 'g' + it.group_id : 'none';
      var g = byKey[key];
      if (!g) {
        g = byKey[key] = { name: it.group_id ? (it.group_name || 'อื่น ๆ') : 'อื่น ๆ', bad: [], good: [], parts: [], seen: {} };
        groups.push(g);
      }
      var tone = it.ci_auto_source ? autoTone(it, res) : itemTone(it, res);
      var line = itemLine(it, res, tone);
      line.ci = it.ci_id;
      if (!line.text) { return; }
      var k = line.label + '|' + line.text;
      if (g.seen[k]) { return; }
      g.seen[k] = true;
      line.bad = tone === 'warn' || tone === 'bad';
      line.color = lineColor(it, res, tone);
      (line.bad ? g.bad : g.good).push(line);
      partsForItem(it, res).forEach(function (p) {
        pushOnce(g.parts, p);
        from[p] = from[p] || [];
        pushOnce(from[p], g.name);
      });
    });
    var ordered = groups.filter(function (g) { return g.bad.length; }).concat(groups.filter(function (g) { return !g.bad.length && g.good.length; }));
    var parts = state.parts.map(function (p) {
      return { name: p.name, from: from[p.name] || [], members: BUNDLES[p.name] || null };
    });
    return { groups: ordered.map(function (g) { return { name: g.name, lines: g.bad.concat(g.good), parts: g.parts }; }), parts: parts };
  }

  function partLine(p) {
    return p.name
      + (p.from.length ? ' (จาก ' + p.from.join(', ') + ')' : ' (ช่างเพิ่มเอง)');
  }

  function reportText(rep) {
    var out = [];
    rep.groups.forEach(function (g, i) {
      out.push((i + 1) + ') ' + g.name);
      g.lines.forEach(function (l) { out.push(SUB + l.label + ': ' + l.text); });
      if (g.parts.length) { out.push('   เสนอ: ' + g.parts.join(', ')); }
    });
    out.push('');
    if (rep.parts.length) {
      out.push('สรุปรายการที่เสนอเปลี่ยน/ซ่อม ' + rep.parts.length + ' รายการ');
      rep.parts.forEach(function (p) { out.push(SUB + partLine(p)); });
    } else {
      out.push('สรุป: ไม่เสนอเปลี่ยนอะไหล่');
    }
    return out.join('\n');
  }

  function composeTech() {
    var comment = $('mn_ar_tech').value.trim();
    return reportText(buildReport()) + (comment ? COMMENT_SEP + comment : '');
  }

  function renderReport() {
    var box = $('mn_ar_report');
    if (!box) { return; }
    var rep = buildReport();
    if (!rep.groups.length) { box.innerHTML = ''; return; }
    var html = '<ol class="mn-ar-report__list">' + rep.groups.map(function (g) {
      return '<li><b class="mn-ar-report__g">' + esc(g.name) + '</b><ul class="mn-ar-report__sub">'
        + g.lines.map(function (l) {
          var pics = photosOf(l.ci).length;
          return '<li style="--rc:' + l.color + '"' + (l.bad ? ' class="is-bad"' : '') + '><span>' + esc(l.label) + ':</span> ' + esc(l.text)
            + (pics ? ' <button type="button" class="mn-ar-report__pic" data-ci="' + l.ci + '" aria-label="ดูรูป ' + pics + ' รูป"><i class="fa fa-camera" aria-hidden="true"></i> ' + pics + '</button>' : '') + '</li>';
        }).join('') + '</ul>'
        + (g.parts.length ? '<div class="mn-ar-report__prop"><i class="fa fa-wrench" aria-hidden="true"></i> เสนอ: ' + esc(g.parts.join(', ')) + '</div>' : '')
        + '</li>';
    }).join('') + '</ol>';
    html += '<div class="mn-ar-report__sum"><b>' + (rep.parts.length ? 'สรุปรายการที่เสนอเปลี่ยน/ซ่อม ' + rep.parts.length + ' รายการ' : 'สรุป: ไม่เสนอเปลี่ยนอะไหล่') + '</b>'
      + (rep.parts.length ? '<ul>' + rep.parts.map(function (p) { return '<li>' + esc(partLine(p)) + '</li>'; }).join('') + '</ul>' : '') + '</div>';
    box.innerHTML = html;
  }

  // ---------- ร่าง "ความเห็นช่าง" = เหตุผลการเสนอเปลี่ยนอะไหล่ (กระชับแบบที่ช่างเขียน แก้ไขต่อได้) ----------

  // วลีสั้นของข้อมูลอัตโนมัติ แบบที่ช่างเขียน
  var AUTO_SHORT = {
    buy_date: { fail: 'เครื่องอายุเกิน 2 ปี', near: 'เครื่องอายุใกล้ครบ 2 ปี', pass: 'เครื่องอายุยังไม่เกิน 2 ปี' },
    last_ma: { fail: 'เปลี่ยนอะไหล่พื้นฐานล่าสุดเกิน 2 ปี', near: 'เปลี่ยนอะไหล่พื้นฐานล่าสุดใกล้ครบ 2 ปี', pass: 'เปลี่ยนอะไหล่พื้นฐานล่าสุดยังไม่เกิน 2 ปี' }
  };

  // สิ่งที่ตรวจพบแบบสั้น เช่น "การ TouchScreen กดไม่ติดทั้งจอ", "ความสว่างจอ 39 LUX ต่ำกว่ามาตรฐาน (180-420)"
  // ประธานของประโยค: ชื่อข้อที่ซ้ำกันหลายกลุ่ม (เช่น "ลักษณะกายภาพ", "การเชื่อมต่อ") ใช้ชื่อกลุ่มแทน เช่น "HUB / USB", "กล้อง"
  function findingSubject(it, qCount) {
    return qCount[it.ci_question] > 1 && it.group_name ? it.group_name : it.ci_question;
  }

  // รายละเอียดที่พิมพ์เป็นประโยคเต็มอยู่แล้ว (มีคำของประธานอยู่ในนั้น) ใช้ตามที่พิมพ์ ไม่เติมประธานซ้ำ
  function noteHasSubject(note, subject) {
    return subject.split(/[\s/]+/).some(function (w) { return w.length >= 3 && w !== 'การ' && note.indexOf(w) !== -1; });
  }

  function shortFinding(it, res, tone, qCount) {
    var note = (res.note || '').trim();
    if (it.ci_auto_source) {
      if (isNear(it, res)) {
        var age = autoAge(it, res);
        return ((AUTO_SHORT[it.ci_auto_source] || {}).near || autoPhrase(it, res)) + (age ? ' (' + age + ')' : '') + nearAdvice(it, res);
      }
      return (AUTO_SHORT[it.ci_auto_source] || {})[res.result] || autoPhrase(it, res);
    }
    if (it.ci_unit && hasThreshold(it) && note !== '' && !isNaN(parseFloat(note)) && res.result === 'fail') {
      var low = parseFloat(note) < it.ci_val_min;
      return it.ci_question + ' ' + note + ' ' + it.ci_unit + (low ? ' ต่ำกว่ามาตรฐาน' : ' สูงกว่ามาตรฐาน') + ' (' + rangeText(it) + ')';
    }
    var subject = findingSubject(it, qCount);
    if (!it.ci_opts) { return subject + ' ไม่ผ่าน' + (note ? ' ' + note : ''); }
    if (note && noteHasSubject(note, subject)) { return note; }
    var first = it.ci_opts[0].label;
    var labels = picked(it, res).filter(function (l) { return tone === 'good' || l !== first; });
    return subject + ' ' + (note || labels.join(' และ '));
  }

  // "เสนอเปลี่ยน" สำหรับอะไหล่ที่ต้องเปลี่ยนชิ้น · "เสนอ" สำหรับงานบริการ/MA/ซื้อใหม่
  var SERVICE_RE = /^(MA\b|ทำความสะอาด|ปรับ|ซ่อม|บัดกรี|ฉีด|ล้าง|เคลือบ|กู้|ตรวจ|ติดตั้ง|อัพ|อัป|ลค\.|Write|Up\b|Update|Upgrade|Reset|Format|Config|ตั้งค่า)/i;
  function proposeVerb(part) {
    return SERVICE_RE.test(part.trim()) || part.indexOf('ซื้อใหม่') !== -1 ? 'เสนอ' : 'เสนอเปลี่ยน';
  }

  // ความเห็นช่างแบบกระชับ: ข้อละหัวข้อ "หัวข้อ: สิ่งที่พบ, สิ่งที่พบ เสนอเปลี่ยน อะไหล่"
  // เฉพาะสิ่งที่ผิดปกติ (ส่วนที่ปกติไม่ต้องเขียน) ข้อตรวจในหัวข้อเดียวกันรวมเป็นข้อเดียว ขึ้นต้นด้วยชื่อหัวข้อ
  function rationaleText() {
    var topics = [];
    var byTopic = {};
    var partTopic = {};
    var partSaid = {};
    allChecked().forEach(function (c) {
      var it = c.item;
      var res = c.res || { result: '', note: '' };
      var tone = it.ci_auto_source ? autoTone(it, res) : itemTone(it, res);
      if (tone !== 'warn' && tone !== 'bad') { return; }
      // ในหัวข้อใช้ชื่อข้อเป็นประธานเสมอ (ชื่อหัวข้อขึ้นต้นอยู่แล้ว)
      var desc = shortFinding(it, res, tone, {});
      if (!desc) { return; }
      var name = it.group_id && it.group_name ? it.group_name : it.ci_question;
      if (name === it.ci_question && desc.indexOf(name) === 0) { desc = desc.slice(name.length).trim() || desc; }
      var t = byTopic[name];
      if (!t) {
        t = byTopic[name] = { name: name, found: [], parts: [] };
        topics.push(t);
      }
      pushOnce(t.found, desc);
      partsForItem(it, res).forEach(function (p) {
        // อะไหล่ที่มาจากหลายหัวข้อ เขียนไว้ที่หัวข้อแรกที่พบ
        if (!partTopic[p]) { partTopic[p] = t; pushOnce(t.parts, p); }
        partSaid[p] = (partSaid[p] || '') + ' ' + desc;
      });
    });
    var reasons = B.rateReasons || {};
    var propose = function (p) {
      var why = (reasons[p] || '').trim();
      // ประโยคที่พิมพ์ไว้บอก "เสนอ…" แล้ว (เช่น "…เสนอเปลี่ยนจอใหม่") ไม่ต่อท้ายซ้ำ
      if ((partSaid[p] || '').indexOf('เสนอ') !== -1) { return why ? '(' + why + ')' : ''; }
      return proposeVerb(p) + ' ' + p + (why ? ' (' + why + ')' : '');
    };
    var inUse = {};
    state.parts.forEach(function (p) { inUse[p.name] = true; });
    var points = topics.map(function (t) {
      var props = t.parts.filter(function (p) { return inUse[p]; }).map(propose).filter(function (x) { return x; });
      // รายละเอียดหลายข้อลงท้าย "เสนอ…" เหมือนกัน (เช่น เสนอเปลี่ยนจอใหม่) เหลือไว้ท้ายสุดครั้งเดียว
      var tail = function (s) { var m = s.match(/\s(เสนอ\S.*)$/); return m ? m[1] : ''; };
      var found = t.found.map(function (s, i) {
        var tl = tail(s);
        var later = tl && t.found.slice(i + 1).some(function (x) { return tail(x) === tl; });
        return later ? s.slice(0, s.length - tl.length).trim() : s;
      });
      return t.name + ': ' + found.join(', ') + (props.length ? ' ' + props.join(', ') : '');
    });
    // รายการซ่อมที่เพิ่มเอง (ไม่ได้มาจากผลตรวจ)
    state.parts.forEach(function (p) {
      if (!partTopic[p.name]) { var s = propose(p.name); if (s) { points.push(s); } }
    });
    if (!points.length) { return ''; }
    return points.map(function (p, i) { return (i + 1) + ') ' + p; }).join('\n');
  }

  var autoComment = '';

  // เติมร่างให้เองเมื่อช่องว่าง หรือยังเป็นร่างเดิมที่ช่างไม่ได้แก้ · force = กดปุ่มร่างใหม่
  function syncComment(force) {
    var el = $('mn_ar_tech');
    if (!el) { return; }
    var cur = el.value.trim();
    if (!force && cur !== '' && cur !== autoComment.trim()) { return; }
    var txt = rationaleText();
    if (el.value !== txt) {
      el.value = txt;
      restartAnim(el, 'is-flash');
    }
    autoComment = txt;
  }

  // เปิดใบงานที่เคยบันทึกจากหน้านี้: ดึงเฉพาะความเห็นช่าง (หลัง "//") กลับเข้าช่อง | ใบงานเก่าที่พิมพ์เอง ใส่ทั้งข้อความไว้ไม่ให้หาย
  function commentFromSaved(t) {
    t = String(t || '');
    if (!B.checklistSaved) { return t; }
    var k = t.indexOf(COMMENT_SEP);
    return k === -1 ? '' : t.slice(k + COMMENT_SEP.length);
  }

  // ---------- parts ----------

  function hasPart(name) {
    for (var i = 0; i < state.parts.length; i++) {
      if (state.parts[i].name === name) { return true; }
    }
    return false;
  }

  // รายการชุดในใบงานที่รวมรายการนี้ไว้แล้ว ('' = ไม่มี)
  function coveredBy(name) {
    for (var i = 0; i < state.parts.length; i++) {
      var p = state.parts[i].name;
      if (p !== name && BUNDLES[p] && BUNDLES[p].indexOf(name) !== -1) { return p; }
    }
    return '';
  }

  function addPart(name, src, silent) {
    if (!name || hasPart(name)) { return false; }
    var cover = coveredBy(name);
    if (cover) {
      if (!silent) { alertMsg('“' + name + '” รวมอยู่ใน “' + cover + '” แล้ว ไม่ต้องเพิ่มแยก', 'info'); }
      return false;
    }
    var members = BUNDLES[name];
    var keep = members ? state.parts.filter(function (p) { return members.indexOf(p.name) === -1; }) : state.parts;
    if (keep.length >= MAX_PARTS) {
      if (!silent) { alertMsg('เพิ่มได้สูงสุด ' + MAX_PARTS + ' รายการ'); }
      return false;
    }
    state.parts = keep;
    state.parts.push({ name: name, src: src, fresh: true });
    delete state.dismissed[name];
    state.dirty = true;
    return true;
  }

  var SRC_LABEL = { check: 'จากผลตรวจ', history: 'จากประวัติ', saved: 'บันทึกไว้', manual: '' };

  function renderParts() {
    var html = '';
    var found = {};
    checklistRates().forEach(function (r) { found[r] = true; });
    state.parts.forEach(function (p, i) {
      var members = BUNDLES[p.name];
      var sub = members && members.length
        ? '<small class="mn-ar-part__sub"><i class="fa fa-cubes" aria-hidden="true"></i> รวมในชุด: ' + members.map(function (m) {
          return found[m] ? '<b title="พบจากผลตรวจ">' + esc(m) + '</b>' : esc(m);
        }).join(', ') + '</small>'
        : '';
      html += '<li class="mn-ar-part' + (members ? ' mn-ar-part--bundle' : '') + '"' + (p.fresh ? '' : ' style="animation:none"') + ' data-i="' + i + '">'
        + '<span class="mn-ar-part__name">' + esc(p.name) + sub + '</span>'
        + (SRC_LABEL[p.src] ? '<span class="mn-ar-part__src">' + SRC_LABEL[p.src] + '</span>' : '')
        + '<button type="button" class="mn-ar-part__act" data-act="up" aria-label="เลื่อนขึ้น"' + (i === 0 ? ' disabled' : '') + '><i class="fa fa-arrow-up" aria-hidden="true"></i></button>'
        + '<button type="button" class="mn-ar-part__act mn-ar-part__act--del" data-act="del" aria-label="ลบ ' + esc(p.name) + '"><i class="fa fa-times" aria-hidden="true"></i></button>'
        + '</li>';
      p.fresh = false;
    });
    $('mn_ar_parts').innerHTML = html;
    $('mn_ar_parts_count').textContent = state.parts.length + '/' + MAX_PARTS;
    renderSuggest();
    renderReport();
    if (state.step === 4) { syncComment(false); }
  }

  function renderSuggest() {
    var chips = [];
    var seen = {};
    checklistRates().forEach(function (r) {
      if (rateSet[r] && !hasPart(r) && !coveredBy(r) && !seen[r]) { seen[r] = true; chips.push({ rate: r, src: 'check', label: 'ผลตรวจ' }); }
    });
    // ใกล้ครบ 2 ปี: แนะนำให้พิจารณา (ไม่ใส่ในใบงานเอง)
    allChecked().forEach(function (c) {
      var r = nearRate(c.item, c.res);
      if (r && rateSet[r] && !hasPart(r) && !coveredBy(r) && !seen[r]) { seen[r] = true; chips.push({ rate: r, src: 'check', label: 'ใกล้ครบ 2 ปี' }); }
    });
    chips = chips.slice(0, 8);
    var wrap = $('mn_ar_suggest_wrap');
    wrap.classList.toggle('is-empty', chips.length === 0);
    $('mn_ar_suggest').innerHTML = chips.map(function (c) {
      return '<button type="button" class="mn-ar-chip" data-rate="' + esc(c.rate) + '" data-src="' + c.src + '">'
        + '<i class="fa fa-plus mn-ar-chip__plus" aria-hidden="true"></i>' + esc(c.rate)
        + '<span class="mn-ar-chip__n">' + esc(c.label) + '</span></button>';
    }).join('');
  }

  function onPartsClick(e) {
    var btn = e.target.closest('.mn-ar-part__act');
    if (!btn) { return; }
    var li = btn.closest('.mn-ar-part');
    var i = parseInt(li.getAttribute('data-i'), 10);
    if (btn.getAttribute('data-act') === 'up' && i > 0) {
      var tmp = state.parts[i - 1];
      state.parts[i - 1] = state.parts[i];
      state.parts[i] = tmp;
      state.dirty = true;
      renderParts();
      return;
    }
    if (btn.getAttribute('data-act') === 'del') {
      li.classList.add('is-leaving');
      setTimeout(function () {
        var removed = state.parts.splice(i, 1)[0];
        if (removed) { state.dismissed[removed.name] = true; }
        // เอารายการชุดออก → ส่งรายการย่อยแยกตามผลตรวจแทน
        if (removed && BUNDLES[removed.name]) {
          checklistRates().forEach(function (r) {
            if (rateSet[r] && !state.dismissed[r] && BUNDLES[removed.name].indexOf(r) !== -1) { addPart(r, 'check', true); }
          });
        }
        state.dirty = true;
        renderParts();
      }, 200);
    }
  }

  function onSuggestClick(e) {
    var chip = e.target.closest('.mn-ar-chip');
    if (!chip) { return; }
    if (addPart(chip.getAttribute('data-rate'), chip.getAttribute('data-src'))) { renderParts(); }
  }

  // ---------- search ----------

  var searchHl = -1;

  function renderSearch() {
    var input = $('mn_ar_part_search');
    var list = $('mn_ar_part_results');
    var q = input.value.trim().toLowerCase();
    searchHl = -1;
    if (q === '') {
      list.innerHTML = '';
      return;
    }
    var hits = (B.rateOptions || []).filter(function (r) {
      return !hasPart(r) && r.toLowerCase().indexOf(q) !== -1;
    }).slice(0, 30);
    if (!hits.length) {
      list.innerHTML = '<li class="is-empty">ไม่พบรายการที่ตรงกับ “' + esc(input.value.trim()) + '”</li>';
      return;
    }
    list.innerHTML = hits.map(function (r) {
      var at = r.toLowerCase().indexOf(q);
      var shown = esc(r.slice(0, at)) + '<mark>' + esc(r.slice(at, at + q.length)) + '</mark>' + esc(r.slice(at + q.length));
      var cover = coveredBy(r);
      return '<li role="option" data-rate="' + esc(r) + '"' + (cover ? ' class="is-covered"' : '') + '>' + shown
        + (cover ? '<small class="mn-ar-covered">อยู่ในชุด ' + esc(cover) + '</small>' : '') + '</li>';
    }).join('');
  }

  function pickSearch(rate) {
    if (addPart(rate, 'manual')) { renderParts(); }
    var input = $('mn_ar_part_search');
    input.value = '';
    $('mn_ar_part_results').innerHTML = '';
    input.focus();
  }

  function onSearchKey(e) {
    var items = $('mn_ar_part_results').querySelectorAll('li[data-rate]');
    if (!items.length) { return; }
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      searchHl = (searchHl + (e.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
      items.forEach(function (li, i) { li.classList.toggle('is-hl', i === searchHl); });
      items[searchHl].scrollIntoView({ block: 'nearest' });
    } else if (e.key === 'Enter') {
      e.preventDefault();
      pickSearch(items[searchHl >= 0 ? searchHl : 0].getAttribute('data-rate'));
    } else if (e.key === 'Escape') {
      $('mn_ar_part_results').innerHTML = '';
    }
  }

  // ---------- steps ----------

  // จอกว้าง (มีแผงข้อมูลเครื่องด้านซ้าย — css .mn-ar-side แสดงที่ 1180px ขึ้นไป) ข้ามขั้น "ข้อมูลเครื่อง" เริ่มที่ตรวจพื้นฐาน
  var WIDE = global.matchMedia ? global.matchMedia('(min-width: 1180px)') : null;
  function firstStep() { return WIDE && WIDE.matches ? 2 : 1; }

  function goTo(step, back, silent) {
    if (step < firstStep() || step > 4) { return; }
    // มือถือที่สแกน QR ตามหน้าจอ: ขั้นสรุปผล = รูปประกอบเพิ่มเติม
    if (step === 4) { setFocus(0); } else if (step === 1) { setFocus(-1); }
    state.step = step;
    if (step > state.maxStep) { state.maxStep = step; }

    doc.querySelectorAll('.mn-ar-step').forEach(function (s) {
      var on = parseInt(s.getAttribute('data-step'), 10) === step;
      s.classList.toggle('is-active', on);
      s.classList.toggle('is-back', on && !!back);
    });
    doc.querySelectorAll('.mn-ar-steps__seg').forEach(function (seg) {
      var n = parseInt(seg.getAttribute('data-step'), 10);
      seg.classList.toggle('is-active', n === step);
      seg.classList.toggle('is-done', n < step || (n <= state.maxStep && n !== step));
    });
    var first = firstStep();
    $('mn_ar_stepcount').textContent = (step - first + 1) + '/' + (4 - first + 1);

    var prev = $('mn_ar_prev');
    prev.classList.toggle('is-hidden', step === first);
    var next = $('mn_ar_next');
    next.querySelector('.mn-ar-btn__txt').textContent = STEP_LABELS[step];
    next.classList.toggle('is-final', step === 4);
    next.querySelector('.fa').className = 'fa ' + (step === 4 ? 'fa-check' : 'fa-angle-right');

    if (step === 4) { enterSummary(); }
    if (!silent) { scrollToStage(); }
  }

  function onNext() {
    if (state.saving) { return; }
    if (state.step === 4) {
      save();
      return;
    }
    if (!canLeave(state.step)) { return; }
    goTo(state.step + 1, false);
  }

  function onStepperClick(e) {
    var seg = e.target.closest('.mn-ar-steps__seg');
    if (!seg) { return; }
    var n = parseInt(seg.getAttribute('data-step'), 10);
    if (n === state.step) { return; }
    if (n < state.step) {
      goTo(n, true);
      return;
    }
    for (var s = state.step; s < n; s++) {
      if (s >= state.maxStep || !canLeave(s)) { return; }
    }
    goTo(n, false);
  }

  // ---------- save ----------

  function shake(el) {
    restartAnim(el, 'is-flash');
    el.focus();
  }

  function save() {
    if (state.photos.some(function (p) { return p.pending; })) {
      alertMsg('รอให้อัปโหลดรูปเสร็จก่อนบันทึก');
      return;
    }
    if (!warnMissingPhotos(missingPhotos(state.basicItems, state.basicRes), $('mn_ar_basic'))
      || !warnMissingPhotos(missingPhotos(state.symItems, state.symRes), $('mn_ar_sym_items'))) {
      return;
    }
    if (B.demo) {
      showDemoResult();
      return;
    }
    if (!B.userRate) {
      alertMsg('ไม่พบชื่อผู้ประเมินจาก session — ติดต่อผู้ดูแลระบบ');
      return;
    }
    confirmMsg(state.parts.length
      ? 'บันทึกการประเมิน พร้อมรายการซ่อม ' + state.parts.length + ' รายการ?'
      : 'บันทึกการประเมิน โดยไม่มีรายการซ่อม?', doSave);
  }

  function doSave() {
    var btn = $('mn_ar_next');
    state.saving = true;
    btn.classList.add('is-busy');
    btn.querySelector('.fa').className = 'fa fa-circle-o-notch';
    btn.querySelector('.mn-ar-btn__txt').textContent = 'กำลังบันทึก…';

    // keep_symptoms: อาการที่ไม่ได้เลือกแล้ว (เคยบันทึกไว้) จะถูกลบผลตรวจออก
    var jobs = [postJSON('save_checklist', { trp_id: B.trpId, symptom_id: 0, results: state.basicRes, keep_symptoms: state.symptomIds })];
    state.symptomIds.forEach(function (sid) {
      var res = {};
      (state.symCache[sid] || []).forEach(function (it) { if (state.symRes[it.ci_id]) { res[it.ci_id] = state.symRes[it.ci_id]; } });
      jobs.push(postJSON('save_checklist', { trp_id: B.trpId, symptom_id: sid, results: res }));
    });

    Promise.all(jobs).then(function (resps) {
      for (var i = 0; i < resps.length; i++) {
        if (!resps[i] || !resps[i].ok) { throw new Error('save_checklist'); }
      }
      global.removeEventListener('beforeunload', onBeforeUnload);
      clearDraft();
      revokeLinkOnSave();
      postRate();
    }).catch(function (err) {
      state.saving = false;
      btn.classList.remove('is-busy');
      btn.querySelector('.fa').className = 'fa fa-check';
      btn.querySelector('.mn-ar-btn__txt').textContent = STEP_LABELS[4];
      alertMsg(err && err.message === 'auth'
        ? 'หมดเวลาเข้าสู่ระบบ — ล็อกอินใหม่ในแท็บอื่นแล้วกลับมากดบันทึกอีกครั้ง (ผลตรวจในหน้านี้ยังอยู่)'
        : 'บันทึกผลตรวจเช็คไม่สำเร็จ ลองใหม่อีกครั้ง', 'danger');
    });
  }

  function showDemoResult() {
    var extra = ['trp_clean', 'trp_repeat_solder', 'trp_spray'].map(checkVal).filter(function (v) { return v; })
      .map(function (v) { return v.trim(); });
    var lines = [composeTech()];
    if (extra.length) { lines.push('', 'งานเพิ่มเติม: ' + extra.join(', ')); }
    if ($('trp_fw').value.trim()) { lines.push('UP FW: ' + $('trp_fw').value.trim()); }
    if ($('trp_repair_remarks').value.trim()) { lines.push('หมายเหตุ: ' + $('trp_repair_remarks').value.trim()); }
    lines.push('', 'โหมดทดลอง — ข้อมูลนี้ไม่ได้บันทึกที่ใด');
    if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm(lines.join('\n'), {
        title: 'ผลที่จะบันทึกลงใบงาน', variant: 'info', okLabel: 'ทดลองใหม่', cancelLabel: 'ปิด',
        onConfirm: function () { global.location.reload(); }
      });
    } else {
      global.alert(lines.join('\n'));
    }
  }

  function checkVal(id) {
    var el = $(id);
    return el && el.checked ? el.value : '';
  }

  // ส่งแบบ POST (ข้อความสรุปยาว ส่งทาง URL อาจเกินขนาดที่ server รับ)
  function postRate() {
    var p = rateParams();
    var form = doc.createElement('form');
    form.method = 'post';
    form.action = 'save_rate';
    form.style.display = 'none';
    Object.keys(p).forEach(function (k) {
      var inp = doc.createElement('input');
      inp.type = 'hidden';
      inp.name = k;
      inp.value = p[k];
      form.appendChild(inp);
    });
    doc.body.appendChild(form);
    form.submit();
  }

  function rateParams() {
    var p = {
      trp_id: B.trpId,
      trp_repair_technician: composeTech(),
      trp_rate_date: $('trp_rate_date').value,
      trp_clean: checkVal('trp_clean'),
      trp_repeat_solder: checkVal('trp_repeat_solder'),
      trp_spray: checkVal('trp_spray'),
      trp_fw: $('trp_fw').value
    };
    for (var i = 1; i <= MAX_PARTS; i++) {
      p['trp_repair_no' + i] = state.parts[i - 1] ? state.parts[i - 1].name : '';
    }
    p.trp_repair_remarks = $('trp_repair_remarks').value;
    p.trp_user_rate = B.userRate || '';
    p.trp_insurance = B.insurance || '';
    p.trp_status_job = B.statusJob || '';
    return p;
  }

  function onBeforeUnload(e) {
    if (state.dirty && !state.saving && !B.demo) {
      e.preventDefault();
      e.returnValue = '';
    }
  }

  // ---------- layout helpers ----------

  function syncHeaderOffset() {
    var h = doc.querySelector('.header');
    if (h) { doc.body.style.setProperty('--ar-head', h.offsetHeight + 'px'); }
  }

  function stickPx() {
    var top = doc.querySelector('.mn-ar-top');
    return top ? parseFloat(global.getComputedStyle(top).top) || 0 : 0;
  }

  function syncStuck() {
    var top = doc.querySelector('.mn-ar-top');
    if (top) { top.classList.toggle('is-stuck', global.scrollY > 0 && top.getBoundingClientRect().top <= stickPx() + 0.5); }
  }

  function scrollToStage() {
    var target = $('mn_ar').getBoundingClientRect().top + global.scrollY - stickPx();
    if (global.scrollY > target) { global.scrollTo({ top: target, behavior: 'smooth' }); }
  }

  // ---------- init ----------

  // ---------- ร่างคำตอบ (กันงานหายเมื่อรีเฟรช/ปิดแท็บ) + กัน session หมดอายุ ----------

  var DRAFT_KEY = 'mn_ar_draft_' + B.trpId;
  var DRAFT_DAYS = 7;
  var draftReady = false;
  var draftTimer = null;

  function saveDraft() {
    if (B.demo || !draftReady || !state.dirty || state.saving) { return; }
    var d = {
      at: Date.now(),
      basicRes: state.basicRes,
      symRes: state.symRes,
      symptomIds: state.symptomIds,
      symptomChosen: state.symptomChosen,
      parts: state.parts.map(function (p) { return { name: p.name, src: p.src }; }),
      dismissed: state.dismissed,
      tech: $('mn_ar_tech').value,
      fw: $('trp_fw').value,
      remarks: $('trp_repair_remarks').value,
      rateDate: $('trp_rate_date').value,
      clean: $('trp_clean').checked,
      solder: $('trp_repeat_solder').checked,
      spray: $('trp_spray').checked
    };
    try { global.localStorage.setItem(DRAFT_KEY, JSON.stringify(d)); } catch (err) { /* พื้นที่เต็ม/ปิด storage */ }
  }

  function queueDraft() {
    clearTimeout(draftTimer);
    draftTimer = setTimeout(saveDraft, 400);
  }

  function clearDraft() {
    clearTimeout(draftTimer);
    try { global.localStorage.removeItem(DRAFT_KEY); } catch (err) { /* ignore */ }
  }

  function readDraft() {
    if (B.demo) { return null; }
    try {
      var d = JSON.parse(global.localStorage.getItem(DRAFT_KEY) || 'null');
      if (!d || !d.at || Date.now() - d.at > DRAFT_DAYS * 86400000) { clearDraft(); return null; }
      return d;
    } catch (err) {
      return null;
    }
  }

  function applyDraft(d) {
    Object.keys(d.basicRes || {}).forEach(function (k) { state.basicRes[k] = d.basicRes[k]; });
    renderItems($('mn_ar_basic'), state.basicItems, state.basicRes, 'basic');
    state.symRes = d.symRes || {};
    state.symptomIds = (d.symptomIds || []).slice();
    state.symptomChosen = !!d.symptomChosen;
    state.symptomIds.forEach(ensureSymptom);
    rebuildSymItems();
    renderSymItems();
    renderSymptoms();
    state.parts = (d.parts || []).map(function (p) { return { name: p.name, src: p.src, fresh: false }; });
    state.dismissed = d.dismissed || {};
    $('mn_ar_tech').value = d.tech || '';
    $('trp_fw').value = d.fw || '';
    $('trp_repair_remarks').value = d.remarks || '';
    if (d.rateDate) { $('trp_rate_date').value = d.rateDate; }
    [['trp_clean', d.clean], ['trp_repeat_solder', d.solder], ['trp_spray', d.spray]].forEach(function (x) {
      var cb = $(x[0]);
      cb.checked = !!x[1];
      cb.closest('.mn-ar-toggle').classList.toggle('is-on', cb.checked);
    });
    renderParts();
    updateProgress();
    syncMore();
    state.dirty = true;
  }

  // เปิดส่วน “เพิ่มเติม” ให้เองเมื่อมีข้อมูล UP FW / หมายเหตุอยู่แล้ว
  function syncMore() {
    var more = doc.querySelector('.mn-ar-more');
    if (more && ($('trp_fw').value.trim() || $('trp_repair_remarks').value.trim())) { more.open = true; }
  }

  function offerDraft() {
    var d = readDraft();
    if (!d) { draftReady = true; return; }
    var when = new Date(d.at);
    var pad = function (n) { return (n < 10 ? '0' : '') + n; };
    var txt = pad(when.getDate()) + '/' + pad(when.getMonth() + 1) + '/' + when.getFullYear() + ' ' + pad(when.getHours()) + ':' + pad(when.getMinutes());
    var done = function (use) {
      if (use) { applyDraft(d); } else { clearDraft(); }
      draftReady = true;
    };
    if (typeof global.mnModalConfirm !== 'function') { done(global.confirm('พบผลตรวจที่ยังไม่ได้บันทึก (' + txt + ') ใช้ต่อไหม?')); return; }
    global.mnModalConfirm('พบผลตรวจของใบงานนี้ที่ทำค้างไว้แต่ยังไม่ได้บันทึก (ล่าสุด ' + txt + ')\n\nใช้ต่อจากที่ค้างไว้ไหม? ถ้าเริ่มใหม่ ร่างนี้จะถูกลบ', {
      title: 'มีงานที่ทำค้างไว้', variant: 'info', okLabel: 'ใช้ต่อ', cancelLabel: 'เริ่มใหม่',
      onConfirm: function () { done(true); },
      onCancel: function () { done(false); }
    });
  }

  // ---------- รูปประกอบ (ย่อในเบราว์เซอร์ แล้วอัปโหลด | โหมดทดลองแสดงในเครื่องอย่างเดียว) ----------

  var photoSeq = 0;
  state.photos = (B.photos || []).map(photoFromServer);

  function photoFromServer(o) {
    return { key: 'p' + o.id, id: o.id, ci: o.ci, by: o.by || '', at: o.at || '', drive: !!o.drive,
      url: 'checklist_photo?id=' + o.id + '&s=f', thumb: 'checklist_photo?id=' + o.id + '&s=t' };
  }

  function photosOf(ci) {
    return state.photos.filter(function (p) { return p.ci === ci && !p.pending; });
  }

  // การแนบรูปตามคำตอบที่เลือก (ตั้งในหน้าตั้งค่า): 0 ไม่แนบ / 1 แนบได้ / 2 ต้องแนบ
  function photoMode(it, res) {
    if (!it || it.ci_auto_source || !res || !res.result) { return 0; }
    if (it.ci_opts && !hasThreshold(it)) {
      var m = 0;
      picked(it, res).forEach(function (l) {
        var i = optIndex(it, l);
        if (i >= 0) { m = Math.max(m, parseInt(it.ci_opts[i].photo, 10) || 0); }
      });
      return m;
    }
    if (res.result === 'fail') { return it.ci_photo_fail === undefined ? 1 : (parseInt(it.ci_photo_fail, 10) || 0); }
    return 0;
  }

  function itemPhotoMode(ci) {
    var it = findItem('basic', ci);
    var map = state.basicRes;
    if (!it) { it = findItem('sym', ci); map = state.symRes; }
    return it ? photoMode(it, map[ci]) : 0;
  }

  // ข้อที่ต้องแนบรูปแต่ยังไม่มีรูป
  function missingPhotos(items, map) {
    return items.filter(function (it) { return photoMode(it, map[it.ci_id]) === 2 && !photosOf(it.ci_id).length; });
  }

  function warnMissingPhotos(list, wrap) {
    if (!list.length) { return true; }
    var li = wrap.querySelector('.mn-ar-item[data-id="' + list[0].ci_id + '"]');
    if (li) {
      restartAnim(li, 'is-missing');
      li.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
    alertMsg('ข้อ “' + list[0].ci_question + '” ต้องแนบรูป' + (list.length > 1 ? ' (และอีก ' + (list.length - 1) + ' ข้อ)' : ''));
    return false;
  }

  function photoStripInner(ci) {
    var list = state.photos.filter(function (p) { return p.ci === ci; });
    var mode = ci ? itemPhotoMode(ci) : 1;
    if (!mode && !list.length) { return ''; }
    var need = mode === 2 && !list.some(function (p) { return !p.pending; });
    return list.map(function (p) {
      return '<span class="mn-ar-photo' + (p.pending ? ' is-pending' : '') + '" data-key="' + p.key + '">'
        + (p.pending
          ? '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>'
          : '<img src="' + esc(p.thumb) + '" alt="รูปประกอบ" loading="lazy" /><button type="button" class="mn-ar-photo__del" aria-label="ลบรูป"><i class="fa fa-times" aria-hidden="true"></i></button>'
            + (p.drive ? '<span class="mn-photo-drive" title="ขึ้น Google Drive แล้ว" aria-label="ขึ้น Google Drive แล้ว"><i class="fa fa-check" aria-hidden="true"></i></span>' : ''))
        + '</span>';
    }).join('')
      + (mode ? '<label class="mn-ar-photo-add' + (list.length ? ' is-compact' : '') + (need ? ' is-req' : '') + '" title="แนบรูป (ถ่ายหรือเลือกจากเครื่อง)">'
        + '<input type="file" accept="image/*" multiple data-ci="' + ci + '" hidden />'
        + '<i class="fa fa-camera" aria-hidden="true"></i><span>' + (list.length ? 'เพิ่ม' : (need ? 'ต้องแนบรูป' : 'แนบรูป')) + '</span></label>' : '')
      + (mode && !B.demo ? '<button type="button" class="mn-ar-photo-qr' + (list.length ? ' is-compact' : '') + (need ? ' is-req' : '') + '" data-qr="' + ci + '" title="ถ่ายรูปข้อนี้ด้วยมือถือ (สแกน QR รูปเข้าข้อนี้ทันที)">'
        + '<i class="fa fa-qrcode" aria-hidden="true"></i><span>มือถือ</span></button>' : '');
  }

  function refreshPhotos(ci) {
    doc.querySelectorAll('.mn-ar-photos[data-ci="' + ci + '"]').forEach(function (el) { el.innerHTML = photoStripInner(ci); });
    var zip = $('mn_ar_zip');
    if (zip) { zip.hidden = !state.photos.some(function (p) { return p.id && !p.pending; }); }
    renderReport();
  }

  function loadImage(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
      img.src = url;
    });
  }

  // ย่อรูปให้ด้านยาวไม่เกิน max แล้วบีบเป็น JPEG (ข้อมูล GPS/EXIF หายไปด้วย)
  function resizeImage(file, max, quality) {
    var src = global.createImageBitmap
      ? global.createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () { return loadImage(file); })
      : loadImage(file);
    return src.then(function (img) {
      var w = img.width;
      var h = img.height;
      var s = Math.min(1, max / Math.max(w, h));
      var c = doc.createElement('canvas');
      c.width = Math.max(1, Math.round(w * s));
      c.height = Math.max(1, Math.round(h * s));
      var ctx = c.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, c.width, c.height);
      ctx.drawImage(img, 0, 0, c.width, c.height);
      return new Promise(function (resolve, reject) {
        c.toBlob(function (b) { if (b) { resolve(b); } else { reject(new Error('decode')); } }, 'image/jpeg', quality);
      });
    });
  }

  function addPhotos(ci, files) {
    var list = Array.prototype.slice.call(files || []);
    if (list.length > 10) {
      alertMsg('แนบได้ครั้งละไม่เกิน 10 รูป');
      list = list.slice(0, 10);
    }
    list.forEach(function (file) {
      if (file.type && file.type.indexOf('image/') !== 0) {
        alertMsg('ไฟล์ “' + file.name + '” ไม่ใช่รูป');
        return;
      }
      var p = { key: 'tmp' + (++photoSeq), ci: ci, pending: true };
      state.photos.push(p);
      refreshPhotos(ci);
      Promise.all([resizeImage(file, 1600, 0.82), resizeImage(file, 320, 0.72)]).then(function (blobs) {
        if (B.demo) {
          p.url = URL.createObjectURL(blobs[0]);
          p.thumb = URL.createObjectURL(blobs[1]);
          p.pending = false;
          return null;
        }
        var fd = new FormData();
        fd.append('action', 'upload');
        fd.append('trp_id', B.trpId);
        fd.append('ci_id', ci);
        fd.append('photo', blobs[0], 'photo.jpg');
        fd.append('thumb', blobs[1], 'thumb.jpg');
        return fetch('checklist_photo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function (r) {
            if (r.status === 401) { throw new Error('auth'); }
            if (!r.ok) { throw new Error('อัปโหลดไม่สำเร็จ (' + r.status + ')'); }
            return r.json();
          })
          .then(function (res) {
            if (!res.ok) { throw new Error(res.error || 'อัปโหลดไม่สำเร็จ'); }
            var s = photoFromServer(res.photo);
            Object.keys(s).forEach(function (k) { p[k] = s[k]; });
            p.pending = false;
            // ระหว่างรอผล การดึงรายการรูป (QR มือถือ) อาจเพิ่มรูปเดียวกันมาแล้ว
            state.photos = state.photos.filter(function (x) { return x === p || x.id !== p.id; });
            checkDriveSoon();
          });
      }).catch(function (err) {
        state.photos = state.photos.filter(function (x) { return x !== p; });
        var m = err && err.message;
        alertMsg(m === 'auth' ? 'หมดเวลาเข้าสู่ระบบ — ล็อกอินใหม่ในแท็บอื่นแล้วแนบรูปอีกครั้ง'
          : (m === 'decode' ? 'เปิดไฟล์รูปนี้ไม่ได้ (เช่น HEIC) ลองถ่ายใหม่ หรือแปลงเป็น JPG ก่อน' : (m || 'อัปโหลดไม่สำเร็จ')), 'danger');
      }).then(function () { refreshPhotos(ci); });
    });
  }

  function findPhoto(key) {
    for (var i = 0; i < state.photos.length; i++) { if (state.photos[i].key === key) { return state.photos[i]; } }
    return null;
  }

  function deletePhoto(key) {
    var p = findPhoto(key);
    if (!p) { return; }
    var go = function () {
      var done = function () {
        state.photos = state.photos.filter(function (x) { return x !== p; });
        refreshPhotos(p.ci);
      };
      if (B.demo) { done(); return; }
      var fd = new FormData();
      fd.append('action', 'delete');
      fd.append('trp_id', B.trpId);
      fd.append('id', p.id);
      fetch('checklist_photo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (!res.ok) { throw new Error(res.error); } done(); })
        .catch(function (err) { alertMsg((err && err.message) || 'ลบรูปไม่สำเร็จ', 'danger'); });
    };
    if (typeof global.mnModalConfirm === 'function') {
      global.mnModalConfirm('ลบรูปนี้ออกจากใบงาน?', { title: 'ลบรูป', variant: 'danger', okLabel: 'ลบรูป', cancelLabel: 'ยกเลิก', onConfirm: go });
    } else if (global.confirm('ลบรูปนี้ออกจากใบงาน?')) {
      go();
    }
  }

  function photoTitle(ci) {
    if (!ci) { return 'รูปประกอบเพิ่มเติม'; }
    var it = findItem('basic', ci) || findItem('sym', ci);
    return it ? it.ci_question : 'รูปประกอบ';
  }

  // ดูรูปขนาดใหญ่ — ใช้หน้าต่างกลาง js/mn-photo-lightbox.js (เลื่อนดูรูปอื่นของข้อเดียวกัน)
  function openLightbox(ci, key) {
    var list = photosOf(ci);
    if (!list.length || typeof global.mnPhotoLightbox !== 'function') { return; }
    var start = 0;
    list.forEach(function (p, i) { if (p.key === key) { start = i; } });
    global.mnPhotoLightbox(list.map(function (p) {
      return { url: p.url, download: B.demo || !p.id ? '' : 'checklist_photo?id=' + p.id + '&s=f&dl=1', caption: photoTitle(ci) + (p.by ? '  | ' + p.by : '') };
    }), start);
  }

  function onPhotoClick(e) {
    var qr = e.target.closest('[data-qr]');
    if (qr) { openQr(parseInt(qr.getAttribute('data-qr'), 10) || 0); return; }
    var del = e.target.closest('.mn-ar-photo__del');
    if (del) { deletePhoto(del.closest('.mn-ar-photo').getAttribute('data-key')); return; }
    var img = e.target.closest('.mn-ar-photo img');
    if (img) {
      var ph = findPhoto(img.closest('.mn-ar-photo').getAttribute('data-key'));
      if (ph) { openLightbox(ph.ci, ph.key); }
      return;
    }
    var pic = e.target.closest('.mn-ar-report__pic');
    if (pic) { openLightbox(parseInt(pic.getAttribute('data-ci'), 10) || 0); }
  }

  function onPhotoInput(e) {
    var t = e.target;
    if (t.type !== 'file' || !t.hasAttribute('data-ci')) { return; }
    addPhotos(parseInt(t.getAttribute('data-ci'), 10) || 0, t.files);
    t.value = '';
  }

  // ---------- ถ่ายรูปด้วยมือถือ (QR — photo_upload ไม่ต้องล็อกอิน) ----------
  // QR ประจำข้อ: ?t=&ci= รูปเข้าข้อนั้นทันที | QR ตามหน้าจอ: ?t= มือถือเปลี่ยนข้อตามที่ตอบอยู่บนหน้านี้
  // ระหว่างลิงก์เปิดอยู่ หน้านี้ดึงรายการรูปทุก 4 วินาที รูปจากมือถือจึงขึ้นเอง

  var link = B.photoLink || null;
  var linkTimer = 0;
  var pollTimer = 0;
  var qrBox = null;
  var qrCi = null;
  var toastTimer = 0;

  function linkLive() { return !!(link && link.expires > Date.now()); }

  // ข้อที่มือถือควรแสดง: ข้อที่คำตอบแนบรูปได้ + ข้อที่มีรูปแล้ว (mode 2 = ต้องแนบรูป)
  function linkItems() {
    var out = [];
    allChecked().forEach(function (c) {
      var m = photoMode(c.item, c.res);
      if (m || photosOf(c.item.ci_id).length) { out.push({ ci: c.item.ci_id, mode: m }); }
    });
    return out;
  }

  function photoPost(fd) {
    return fetch('checklist_photo', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
      .then(function (r) {
        if (r.status === 401) { throw new Error('auth'); }
        if (!r.ok) { throw new Error('HTTP ' + r.status); }
        return r.json();
      });
  }

  function linkForm(action, extra) {
    var fd = new FormData();
    fd.append('action', action);
    fd.append('trp_id', B.trpId);
    Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
    return fd;
  }

  function ensureLink(renew) {
    if (!renew && linkLive() && link.expires - Date.now() > 30 * 60 * 1000) { return Promise.resolve(link); }
    return photoPost(linkForm('link_create', { renew: renew ? 1 : 0, focus: state.focus, items: JSON.stringify(linkItems()) }))
      .then(function (res) {
        if (!res.ok) { throw new Error(res.error || 'สร้างลิงก์ไม่สำเร็จ'); }
        link = res.link;
        startPoll();
        return link;
      });
  }

  function queueLinkSync() {
    if (B.demo || !linkLive()) { return; }
    clearTimeout(linkTimer);
    linkTimer = setTimeout(function () {
      if (!linkLive()) { return; }
      photoPost(linkForm('link_state', { token: link.token, focus: state.focus, items: JSON.stringify(linkItems()) })).catch(function () {});
    }, 600);
  }

  function setFocus(ci) {
    if (state.focus === ci) { return; }
    state.focus = ci;
    queueLinkSync();
  }

  function onItemFocus(e) {
    var li = e.target.closest('.mn-ar-item[data-id]');
    if (!li) { return; }
    var ci = parseInt(li.getAttribute('data-id'), 10) || 0;
    if (ci && itemPhotoMode(ci)) { setFocus(ci); }
    queueLinkSync();
  }

  function revokeLinkOnSave() {
    if (B.demo || !link) { return; }
    var fd = linkForm('link_revoke');
    if (global.navigator.sendBeacon) { global.navigator.sendBeacon('checklist_photo', fd); } else { photoPost(fd).catch(function () {}); }
    link = null;
  }

  function startPoll() {
    updateDot();
    if (pollTimer || B.demo) { return; }
    pollTimer = setInterval(function () {
      if (!linkLive()) {
        clearInterval(pollTimer);
        pollTimer = 0;
        updateDot();
        if (qrBox && !qrBox.hidden) { renderQr(); }
        return;
      }
      if (doc.hidden) { return; }
      getJSON('checklist_photo', { action: 'list', trp_id: B.trpId })
        .then(function (res) { if (res && res.ok) { mergePhotos(res.photos || []); } })
        .catch(function () {});
    }, 4000);
  }

  // รวมรายการรูปจาก server (รูปที่มือถือส่ง / ลบ / ย้ายข้อ) เข้ากับหน้านี้
  function mergePhotos(list) {
    var byId = {};
    list.forEach(function (o) { byId[o.id] = o; });
    var changed = {};
    var news = 0;
    state.photos = state.photos.filter(function (p) {
      if (p.pending || !p.id) { return true; }
      var o = byId[p.id];
      if (!o) { changed[p.ci] = true; return false; }
      if (o.ci !== p.ci) { changed[p.ci] = true; changed[o.ci] = true; p.ci = o.ci; }
      if (!!o.drive !== !!p.drive) { p.drive = !!o.drive; changed[p.ci] = true; }
      delete byId[p.id];
      return true;
    });
    Object.keys(byId).forEach(function (id) {
      var p = photoFromServer(byId[id]);
      state.photos.push(p);
      changed[p.ci] = true;
      news++;
    });
    var keys = Object.keys(changed);
    if (!keys.length) { return; }
    keys.forEach(function (ci) { refreshPhotos(parseInt(ci, 10) || 0); });
    queueLinkSync();
    if (news) { arToast('รับรูปจากมือถือแล้ว ' + news + ' รูป'); }
    if (qrBox && !qrBox.hidden) { renderQr(); }
  }

  // รูปที่แนบจากคอม: server ส่งขึ้น Drive หลังตอบกลับ จึงถามสถานะซ้ำอีก 2-3 ครั้งเพื่อขึ้นติ๊กถูก
  var driveChecks = [];
  function checkDriveSoon() {
    if (B.demo || pollTimer) { return; }
    driveChecks.forEach(clearTimeout);
    driveChecks = [5000, 15000, 40000].map(function (ms) {
      return setTimeout(function () {
        if (!state.photos.some(function (p) { return p.id && !p.drive; })) { return; }
        getJSON('checklist_photo', { action: 'list', trp_id: B.trpId })
          .then(function (res) { if (res && res.ok) { mergePhotos(res.photos || []); } })
          .catch(function () {});
      }, ms);
    });
  }

  function arToast(msg) {
    var el = $('mn_ar_toast');
    if (!el) {
      el = doc.createElement('div');
      el.id = 'mn_ar_toast';
      el.className = 'mn-ar-toast';
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      doc.body.appendChild(el);
    }
    el.textContent = msg;
    el.classList.add('is-on');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.classList.remove('is-on'); }, 3000);
  }

  function updateDot() {
    var dot = $('mn_ar_mobile_dot');
    if (dot) { dot.hidden = !linkLive(); }
  }

  function linkUrl(ci) {
    var base = global.location.href.replace(/[?#].*$/, '').replace(/[^\/]*$/, '');
    return base + 'photo_upload?t=' + link.token + (ci === null ? '' : '&ci=' + ci);
  }

  function qrImg(text) {
    if (typeof global.qrcode !== 'function') {
      return '<p class="mn-ar-qr__err">โหลดตัวสร้าง QR ไม่สำเร็จ ใช้ปุ่มคัดลอกลิงก์ส่งเข้ามือถือแทน</p>';
    }
    var q = global.qrcode(0, 'M');
    q.addData(text);
    q.make();
    return '<img src="' + q.createDataURL(6, 2) + '" alt="QR เปิดหน้าถ่ายรูปบนมือถือ" />';
  }

  function buildQr() {
    if (qrBox) { return; }
    qrBox = doc.createElement('div');
    qrBox.className = 'mn-ar-qr';
    qrBox.hidden = true;
    qrBox.setAttribute('role', 'dialog');
    qrBox.setAttribute('aria-modal', 'true');
    qrBox.setAttribute('aria-labelledby', 'mn_ar_qr_title');
    qrBox.innerHTML = '<div class="mn-ar-qr__panel">'
      + '<button type="button" class="mn-ar-qr__x" data-qa="close" aria-label="ปิด"><i class="fa fa-times" aria-hidden="true"></i></button>'
      + '<p class="mn-ar-qr__kicker"></p>'
      + '<h2 class="mn-ar-qr__title" id="mn_ar_qr_title"></h2>'
      + '<p class="mn-ar-qr__sub"></p>'
      + '<div class="mn-ar-qr__code"></div>'
      + '<p class="mn-ar-qr__exp"></p>'
      + '<p class="mn-ar-qr__got"></p>'
      + '<p class="mn-ar-qr__warn" hidden><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> หน้านี้เปิดจาก localhost มือถือเปิดลิงก์นี้ไม่ได้ ใช้งานจริงบน server</p>'
      + '<div class="mn-ar-qr__btns">'
      + '<button type="button" class="mn-ar-qr__btn" data-qa="copy"><i class="fa fa-link" aria-hidden="true"></i> <span>คัดลอกลิงก์</span></button>'
      + '<button type="button" class="mn-ar-qr__btn" data-qa="renew"><i class="fa fa-refresh" aria-hidden="true"></i> สร้าง QR ใหม่</button>'
      + '<button type="button" class="mn-ar-qr__btn is-danger" data-qa="revoke"><i class="fa fa-ban" aria-hidden="true"></i> ปิดลิงก์มือถือ</button>'
      + '</div></div>';
    doc.body.appendChild(qrBox);
    qrBox.addEventListener('click', onQrClick);
    doc.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !qrBox.hidden) { closeQr(); } });
  }

  function closeQr() {
    qrBox.hidden = true;
    doc.body.classList.remove('mn-ar-qr-open');
  }

  function renderQr(loading) {
    var it = qrCi ? (findItem('basic', qrCi) || findItem('sym', qrCi)) : null;
    var kicker = '';
    var title = 'ถ่ายรูปด้วยมือถือ';
    var sub = 'สแกนครั้งเดียว มือถือจะเปลี่ยนข้อตามที่กดตอบบนหน้าจอนี้ (เลือกข้อเองในมือถือก็ได้)';
    if (qrCi === 0) {
      title = 'รูปประกอบเพิ่มเติม';
      sub = 'สแกนแล้วถ่ายได้เลย รูปเข้าหัวข้อนี้';
    } else if (qrCi) {
      kicker = it && it.group_name ? it.group_name : '';
      title = photoTitle(qrCi);
      sub = 'สแกนแล้วถ่ายได้เลย รูปเข้าข้อนี้ทันที';
    }
    var kick = qrBox.querySelector('.mn-ar-qr__kicker');
    kick.textContent = kicker;
    kick.hidden = !kicker;
    qrBox.querySelector('#mn_ar_qr_title').textContent = title;
    qrBox.querySelector('.mn-ar-qr__sub').textContent = sub;
    var code = qrBox.querySelector('.mn-ar-qr__code');
    var live = linkLive();
    if (loading) {
      code.innerHTML = '<i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i>';
    } else if (live) {
      code.innerHTML = qrImg(linkUrl(qrCi));
    } else {
      code.innerHTML = '<p class="mn-ar-qr__err">ลิงก์มือถือปิดแล้ว กด “สร้าง QR ใหม่” เพื่อใช้ต่อ</p>';
    }
    qrBox.querySelector('.mn-ar-qr__exp').textContent = live && !loading ? 'ใช้ได้ถึง ' + link.expiresText + ' น. หรือจนกว่าจะบันทึกการประเมิน' : '';
    var n = qrCi === null ? 0 : photosOf(qrCi).length;
    qrBox.querySelector('.mn-ar-qr__got').textContent = qrCi !== null && n ? 'ข้อนี้มีรูปแล้ว ' + n + ' รูป' : '';
    qrBox.querySelector('.mn-ar-qr__warn').hidden = !/^(localhost|127\.|\[::1\]|::1$)/.test(global.location.hostname);
    qrBox.querySelector('[data-qa="copy"]').disabled = !live;
    qrBox.querySelector('[data-qa="revoke"]').hidden = !live;
  }

  function openQr(ci) {
    if (B.demo) { alertMsg('โหมดทดลองไม่มีใบงาน จึงอัปรูปจากมือถือไม่ได้'); return; }
    qrCi = ci;
    buildQr();
    qrBox.hidden = false;
    doc.body.classList.add('mn-ar-qr-open');
    if (ci !== null && ci > 0) { setFocus(ci); }
    renderQr(true);
    qrBox.querySelector('[data-qa="close"]').focus();
    ensureLink(false).then(function () {
      renderQr();
      queueLinkSync();
    }).catch(function (err) {
      renderQr();
      qrBox.querySelector('.mn-ar-qr__code').innerHTML = '<p class="mn-ar-qr__err">'
        + esc(err && err.message === 'auth' ? 'หมดเวลาเข้าสู่ระบบ ล็อกอินใหม่ในแท็บอื่นแล้วลองอีกครั้ง' : ((err && err.message) || 'สร้างลิงก์ไม่สำเร็จ')) + '</p>';
    });
  }

  function onQrClick(e) {
    if (e.target === qrBox) { closeQr(); return; }
    var b = e.target.closest('[data-qa]');
    if (!b) { return; }
    var act = b.getAttribute('data-qa');
    if (act === 'close') { closeQr(); }
    if (act === 'copy' && linkLive()) {
      var url = linkUrl(qrCi);
      var done = function () {
        b.querySelector('span').textContent = 'คัดลอกแล้ว';
        setTimeout(function () { b.querySelector('span').textContent = 'คัดลอกลิงก์'; }, 1800);
      };
      if (global.navigator.clipboard && global.navigator.clipboard.writeText) {
        global.navigator.clipboard.writeText(url).then(done).catch(function () { global.prompt('คัดลอกลิงก์นี้', url); });
      } else {
        global.prompt('คัดลอกลิงก์นี้', url);
      }
    }
    if (act === 'renew') {
      renderQr(true);
      ensureLink(true).then(function () { renderQr(); queueLinkSync(); arToast('สร้าง QR ใหม่แล้ว QR เดิมใช้ไม่ได้'); })
        .catch(function (err) { renderQr(); alertMsg((err && err.message) || 'สร้างลิงก์ไม่สำเร็จ', 'danger'); });
    }
    if (act === 'revoke') {
      photoPost(linkForm('link_revoke')).then(function () {
        link = null;
        updateDot();
        renderQr();
        arToast('ปิดลิงก์มือถือแล้ว');
      }).catch(function () { alertMsg('ปิดลิงก์ไม่สำเร็จ', 'danger'); });
    }
  }

  function initForm() {
    var s = B.saved || {};
    $('mn_ar_tech').value = commentFromSaved(s.tech);
    $('trp_fw').value = s.fw || '';
    $('trp_repair_remarks').value = s.remarks || '';
    $('trp_clean').checked = !!s.clean;
    $('trp_repeat_solder').checked = !!s.solder;
    $('trp_spray').checked = !!s.spray;
    (s.parts || []).forEach(function (p) { addPart(p, 'saved', true); });
    state.parts.forEach(function (p) { p.fresh = false; });
    state.dirty = false;

    doc.querySelectorAll('.mn-ar-toggle').forEach(function (lbl) {
      var cb = lbl.querySelector('input');
      lbl.classList.toggle('is-on', cb.checked);
      cb.addEventListener('change', function () {
        lbl.classList.toggle('is-on', cb.checked);
        state.dirty = true;
      });
    });
    ['mn_ar_tech', 'trp_fw', 'trp_repair_remarks', 'trp_rate_date'].forEach(function (id) {
      $(id).addEventListener('input', function () { state.dirty = true; });
    });
  }

  function init() {
    var regen = $('mn_ar_regen_comment');
    if (regen) {
      regen.addEventListener('click', function () {
        var go = function () { syncComment(true); state.dirty = true; queueDraft(); };
        var el = $('mn_ar_tech');
        if (el.value.trim() !== '' && el.value.trim() !== autoComment.trim() && typeof global.mnModalConfirm === 'function') {
          global.mnModalConfirm('ข้อความในช่องความเห็นช่างที่แก้ไว้จะถูกแทนที่ด้วยร่างใหม่จากผลตรวจ', { title: 'ร่างใหม่จากผลตรวจ', variant: 'warning', okLabel: 'ร่างใหม่', cancelLabel: 'ยกเลิก', onConfirm: go });
          return;
        }
        go();
      });
    }
    syncHeaderOffset();
    global.addEventListener('resize', syncHeaderOffset);
    global.addEventListener('scroll', syncStuck, { passive: true });
    global.addEventListener('beforeunload', onBeforeUnload);

    initForm();
    refreshPhotos(0);
    $('mn_ar').addEventListener('click', onPhotoClick);
    $('mn_ar').addEventListener('change', onPhotoInput);
    syncMore();
    renderParts();

    var savedSyms = B.savedSymptomIds || (B.savedSymptomId > 0 ? [B.savedSymptomId] : []);
    if (savedSyms.length) {
      state.symptomIds = savedSyms.slice().sort(function (a, b) { return symOrder(a) - symOrder(b); });
      state.symptomChosen = true;
      state.symptomIds.forEach(ensureSymptom);
      renderSymItems();
    }
    renderSymptoms();
    loadBasic().then(offerDraft).then(function () {
      if (linkLive()) { startPoll(); queueLinkSync(); }
    });
    var mobileBtn = $('mn_ar_mobile');
    if (mobileBtn) { mobileBtn.addEventListener('click', function () { openQr(null); }); }

    // เก็บร่างทุกครั้งที่ช่างกด/พิมพ์ และส่งสัญญาณทุก 5 นาที กัน session หมดอายุระหว่างตรวจ
    ['click', 'input', 'change'].forEach(function (ev) { $('mn_ar').addEventListener(ev, queueDraft); });
    setInterval(function () { getJSON('checklist_settings_api', { action: 'ping' }).catch(function () {}); }, 5 * 60 * 1000);

    ['mn_ar_basic', 'mn_ar_sym_items'].forEach(function (id) {
      $(id).addEventListener('click', onItemsClick);
      $(id).addEventListener('input', onItemsInput);
      // หลังตอบข้อ: บอกมือถือ (QR ตามหน้าจอ) ว่ากำลังตรวจข้อไหน
      $(id).addEventListener('click', onItemFocus);
      $(id).addEventListener('input', onItemFocus);
    });
    $('mn_ar_symptoms').addEventListener('click', onSymptomClick);
    $('mn_ar_parts').addEventListener('click', onPartsClick);
    $('mn_ar_suggest').addEventListener('click', onSuggestClick);
    $('mn_ar_steps').addEventListener('click', onStepperClick);
    $('mn_ar_next').addEventListener('click', onNext);
    $('mn_ar_prev').addEventListener('click', function () { goTo(state.step - 1, true); });

    var search = $('mn_ar_part_search');
    search.addEventListener('input', renderSearch);
    search.addEventListener('keydown', onSearchKey);
    search.addEventListener('blur', function () {
      setTimeout(function () { $('mn_ar_part_results').innerHTML = ''; }, 180);
    });
    $('mn_ar_part_results').addEventListener('mousedown', function (e) {
      var li = e.target.closest('li[data-rate]');
      if (li) {
        e.preventDefault();
        pickSearch(li.getAttribute('data-rate'));
      }
    });

    var demoSel = $('mn_ar_demo_product');
    if (demoSel) {
      demoSel.addEventListener('change', function () {
        global.location.href = 'checklist_try?product=' + encodeURIComponent(demoSel.value);
      });
    }


    goTo(firstStep(), false, true);
    // ย่อ/ขยายหน้าต่างข้ามขนาดจอกว้าง: อยู่ขั้นข้อมูลเครื่องแล้วจอกว้างขึ้น → ไปตรวจพื้นฐาน, ปรับตัวนับขั้น
    if (WIDE) {
      var onWide = function () { goTo(Math.max(state.step, firstStep()), false, true); };
      if (WIDE.addEventListener) { WIDE.addEventListener('change', onWide); } else if (WIDE.addListener) { WIDE.addListener(onWide); }
    }
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(typeof window !== 'undefined' ? window : this));
