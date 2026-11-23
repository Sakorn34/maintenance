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
    symptomId: 0,
    symptomChosen: false,
    parts: [],
    dismissed: {},
    autoTech: '',
    history: null,
    dirty: false,
    saving: false
  };

  var COLORS = {
    green: ['#15803d', '#ecf8f0', '#9fd6b1'], teal: ['#0f766e', '#e6f6f4', '#99d5cd'],
    blue: ['#1d4ed8', '#eaf1fe', '#a9c1f5'], violet: ['#6d28d9', '#f2ecfd', '#c9b3f2'],
    amber: ['#a16207', '#fdf7e3', '#ead58c'], orange: ['#c2410c', '#fff4ec', '#f6c19f'],
    red: ['#b91c1c', '#fdf0f0', '#f0b0b0'], gray: ['#475569', '#f1f4f8', '#c5cfdb']
  };

  var rateSet = {};
  (B.rateOptions || []).forEach(function (r) { rateSet[r] = true; });

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

  function itemTone(item, res) {
    if (!res || !res.result) { return ''; }
    var r = res.result;
    if (r === 'pass') { return 'good'; }
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
    if (!items.length) {
      wrap.innerHTML = '';
      return;
    }
    var html = '';
    var grouped = items.some(function (it) { return it.group_name; });
    var groupCount = {};
    items.forEach(function (it) { groupCount[it.group_id] = (groupCount[it.group_id] || 0) + 1; });
    var lastGroup = null;
    items.forEach(function (it, i) {
      var res = resMap[it.ci_id] || { result: '', note: '' };
      var tone = itemTone(it, res);
      if (grouped && it.group_id !== lastGroup) {
        lastGroup = it.group_id;
        html += '<li class="mn-ar-group" style="animation-delay:' + Math.min(i * 40, 320) + 'ms"><span>'
          + esc(it.group_name || 'อื่น ๆ') + '</span><small>' + groupCount[it.group_id] + ' ข้อ</small></li>';
      }
      html += '<li class="mn-ar-item' + (tone ? ' is-answered is-' + tone : '') + '" data-id="' + it.ci_id
        + '" data-section="' + section + '" style="animation-delay:' + Math.min(i * 40, 320) + 'ms">';
      html += '<div class="mn-ar-item__head"><span class="mn-ar-item__num">' + (i + 1) + '</span>'
        + '<div class="mn-ar-item__text"><div class="mn-ar-item__q">' + esc(it.ci_question) + '</div>'
        + (it.ci_hint ? '<div class="mn-ar-item__hint">' + esc(it.ci_hint) + '</div>' : '')
        + '</div></div>';
      html += '<div class="mn-ar-item__body">' + renderControl(it, res) + '</div>';
      html += '</li>';
    });
    wrap.innerHTML = html;
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
      var ar = it.auto_result || 'na';
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
        + '" data-note="' + (o.has_note ? '1' : '0') + '" aria-pressed="' + (on ? 'true' : 'false') + '"'
        + (c ? ' style="--oc:' + c[0] + ';--oc-soft:' + c[1] + ';--oc-line:' + c[2] + '"' : '') + '>'
        + (multi && i > 0 ? '<i class="fa fa-square-o mn-ar-opt__box" aria-hidden="true"></i><i class="fa fa-check-square mn-ar-opt__tick" aria-hidden="true"></i>' : '')
        + esc(o.text || o.label) + '</button>';
    });
    html += (multi ? '<span class="mn-ar-opts__multi">เลือกได้หลายข้อ</span>' : '') + '</div>';

    if (hasNote) {
      html += '<input type="text" class="mn-ar-input mn-ar-note-in' + (show ? ' mn-ar-reveal' : '') + '" placeholder="ระบุรายละเอียด เช่น ตำแหน่งที่พบ"'
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

  function onItemsClick(e) {
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
    map[id] = { result: sel.join('|'), note: wantNote ? cur.note : '' };
    state.dirty = true;

    li.querySelectorAll('.mn-ar-opt').forEach(function (b) {
      var on = onBtns.indexOf(b) !== -1;
      b.classList.toggle('is-on', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    var noteIn = li.querySelector('.mn-ar-note-in');
    if (noteIn) {
      var showNote = wantNote;
      if (showNote && noteIn.hidden) {
        noteIn.hidden = false;
        restartAnim(noteIn, 'mn-ar-reveal');
        noteIn.focus();
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
          b.classList.remove('is-on');
          b.setAttribute('aria-pressed', 'false');
        });
      } else {
        map[id].result = '';
        out.innerHTML = '';
      }
      refreshItemState(li, item, map[id]);
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

  function loadSymptom(id) {
    var wrap = $('mn_ar_sym_items');
    state.symItems = [];
    state.symRes = {};
    updateProgress();
    if (!id) {
      wrap.innerHTML = '';
      return Promise.resolve();
    }
    wrap.innerHTML = '<li class="mn-ar-skeleton"></li><li class="mn-ar-skeleton"></li>';
    return getJSON('checklist_items', { product: B.product, symptom_id: id, trp_id: B.trpId })
      .then(function (data) {
        if (state.symptomId !== id) { return; }
        state.symItems = data.items || [];
        state.symRes = initResults(state.symItems);
        renderItems(wrap, state.symItems, state.symRes, 'sym');
        updateProgress();
      })
      .catch(function () {
        wrap.innerHTML = '';
        alertMsg('โหลดรายการตรวจตามอาการไม่สำเร็จ');
      });
  }

  function renderSymptoms() {
    var html = '';
    (B.symptoms || []).forEach(function (s) {
      var on = state.symptomChosen && state.symptomId === s.id;
      html += '<button type="button" class="mn-ar-chip' + (on ? ' is-on' : '') + '" role="radio" aria-checked="' + (on ? 'true' : 'false')
        + '" data-sid="' + s.id + '">' + esc(s.name) + '</button>';
    });
    var none = state.symptomChosen && state.symptomId === 0;
    html += '<button type="button" class="mn-ar-chip mn-ar-chip--ghost' + (none ? ' is-on' : '') + '" role="radio" aria-checked="'
      + (none ? 'true' : 'false') + '" data-sid="0">ไม่มีอาการเฉพาะ</button>';
    $('mn_ar_symptoms').innerHTML = html;
  }

  function onSymptomClick(e) {
    var chip = e.target.closest('.mn-ar-chip');
    if (!chip) { return; }
    var sid = parseInt(chip.getAttribute('data-sid'), 10);
    if (state.symptomChosen && state.symptomId === sid) { return; }
    state.symptomId = sid;
    state.symptomChosen = true;
    state.dirty = true;
    renderSymptoms();
    loadSymptom(sid);
  }

  function loadHistory() {
    if (state.history) { return Promise.resolve(state.history); }
    return getJSON('suggest_rates', { product: B.product, trp_id: B.trpId })
      .then(function (d) { state.history = d.top || []; return state.history; })
      .catch(function () { state.history = []; return state.history; });
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
      return m === 0;
    }
    if (step === 3) {
      if (!state.symptomChosen) {
        var chips = $('mn_ar_symptoms');
        restartAnim(chips, 'mn-ar-reveal');
        alertMsg('เลือกอาการที่พบ หรือกด “ไม่มีอาการเฉพาะ”');
        return false;
      }
      return markMissing(state.symItems, state.symRes, $('mn_ar_sym_items')) === 0;
    }
    return true;
  }

  // ---------- summary ----------

  function allChecked() {
    var out = [];
    state.basicItems.forEach(function (it) { out.push({ item: it, res: state.basicRes[it.ci_id] }); });
    if (state.symptomId > 0) {
      state.symItems.forEach(function (it) { out.push({ item: it, res: state.symRes[it.ci_id] }); });
    }
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
    var name = '';
    (B.symptoms || []).forEach(function (s) { if (s.id === state.symptomId) { name = s.name; } });
    return name;
  }

  function buildDescription(findings) {
    var sym = state.symptomId > 0 ? symptomName() : '';
    var texts = findings.map(function (f) { return f.text; });
    if (!texts.length) { return sym ? sym + ' — ตรวจเช็คแล้วไม่พบจุดผิดปกติเพิ่มเติม' : 'ตรวจเช็คแล้วไม่พบจุดผิดปกติ'; }
    return (sym ? sym + ' — ตรวจพบ: ' : 'ตรวจพบ: ') + texts.join(', ');
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
      + '<div class="mn-ar-stat mn-ar-stat--bad"><span class="mn-ar-stat__n">' + bad + '</span><span class="mn-ar-stat__l">พบปัญหา</span></div>'
      + '<div class="mn-ar-stat mn-ar-stat--good"><span class="mn-ar-stat__n">' + sum.good + '</span><span class="mn-ar-stat__l">ปกติ</span></div>'
      + '<div class="mn-ar-stat"><span class="mn-ar-stat__n">' + sum.na + '</span><span class="mn-ar-stat__l">N/A</span></div>'
      + '</div>';
    if (bad) {
      html += '<ul class="mn-ar-findings__list">';
      sum.findings.forEach(function (f, i) {
        html += '<li style="animation-delay:' + (i * 60) + 'ms"><span class="mn-ar-dot mn-ar-dot--' + (f.tone === 'warn' ? 'warn' : 'bad') + '"></span>'
          + '<span><span class="mn-ar-findings__q">' + esc(f.q) + '</span>' + esc(f.text) + '</span></li>';
      });
      html += '</ul>';
    } else {
      html += '<p class="mn-ar-findings__ok"><i class="fa fa-check-circle" aria-hidden="true"></i> ไม่พบจุดผิดปกติจากการตรวจเช็ค</p>';
    }
    $('mn_ar_findings').innerHTML = html;
  }

  function enterSummary() {
    var sum = collectFindings();
    renderFindings(sum);

    var tech = $('mn_ar_tech');
    var desc = buildDescription(sum.findings);
    var cur = tech.value.trim();
    if (cur === '' || cur === state.autoTech) {
      if (cur !== desc) {
        tech.value = desc;
        restartAnim(tech, 'is-flash');
      }
    }
    state.autoTech = desc;

    checklistRates().forEach(function (r) {
      if (rateSet[r] && !state.dismissed[r]) { addPart(r, 'check', true); }
    });
    renderParts();
    loadHistory().then(renderSuggest);
  }

  // ---------- parts ----------

  function hasPart(name) {
    for (var i = 0; i < state.parts.length; i++) {
      if (state.parts[i].name === name) { return true; }
    }
    return false;
  }

  function addPart(name, src, silent) {
    if (!name || hasPart(name)) { return false; }
    if (state.parts.length >= MAX_PARTS) {
      if (!silent) { alertMsg('เพิ่มได้สูงสุด ' + MAX_PARTS + ' รายการ'); }
      return false;
    }
    state.parts.push({ name: name, src: src, fresh: true });
    delete state.dismissed[name];
    state.dirty = true;
    return true;
  }

  var SRC_LABEL = { check: 'จากผลตรวจ', history: 'จากประวัติ', saved: 'บันทึกไว้', manual: '' };

  function renderParts() {
    var html = '';
    state.parts.forEach(function (p, i) {
      html += '<li class="mn-ar-part"' + (p.fresh ? '' : ' style="animation:none"') + ' data-i="' + i + '">'
        + '<span class="mn-ar-part__name">' + esc(p.name) + '</span>'
        + (SRC_LABEL[p.src] ? '<span class="mn-ar-part__src">' + SRC_LABEL[p.src] + '</span>' : '')
        + '<button type="button" class="mn-ar-part__act" data-act="up" aria-label="เลื่อนขึ้น"' + (i === 0 ? ' disabled' : '') + '><i class="fa fa-arrow-up" aria-hidden="true"></i></button>'
        + '<button type="button" class="mn-ar-part__act mn-ar-part__act--del" data-act="del" aria-label="ลบ ' + esc(p.name) + '"><i class="fa fa-times" aria-hidden="true"></i></button>'
        + '</li>';
      p.fresh = false;
    });
    $('mn_ar_parts').innerHTML = html;
    $('mn_ar_parts_count').textContent = state.parts.length + '/' + MAX_PARTS;
    renderSuggest();
  }

  function renderSuggest() {
    var chips = [];
    var seen = {};
    checklistRates().forEach(function (r) {
      if (rateSet[r] && !hasPart(r) && !seen[r]) { seen[r] = true; chips.push({ rate: r, src: 'check', label: 'ผลตรวจ' }); }
    });
    (state.history || []).forEach(function (h) {
      if (rateSet[h.rate] && !hasPart(h.rate) && !seen[h.rate]) { seen[h.rate] = true; chips.push({ rate: h.rate, src: 'history', label: h.count + ' ครั้ง' }); }
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
      return '<li role="option" data-rate="' + esc(r) + '">' + shown + '</li>';
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

  function goTo(step, back, silent) {
    if (step < 1 || step > 4) { return; }
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
    $('mn_ar_stepcount').textContent = step + '/4';

    var prev = $('mn_ar_prev');
    prev.classList.toggle('is-hidden', step === 1);
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
    var tech = $('mn_ar_tech');
    if (tech.value.trim() === '') {
      alertMsg('กรอกอาการที่ประเมินก่อนบันทึก');
      shake(tech);
      return;
    }
    if (!state.parts.length) {
      alertMsg('เพิ่มรายการซ่อมอย่างน้อย 1 รายการ');
      $('mn_ar_part_search').focus();
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
    confirmMsg('บันทึกการประเมิน พร้อมรายการซ่อม ' + state.parts.length + ' รายการ?', doSave);
  }

  function doSave() {
    var btn = $('mn_ar_next');
    state.saving = true;
    btn.classList.add('is-busy');
    btn.querySelector('.fa').className = 'fa fa-circle-o-notch';
    btn.querySelector('.mn-ar-btn__txt').textContent = 'กำลังบันทึก…';

    var jobs = [postJSON('save_checklist', { trp_id: B.trpId, symptom_id: 0, results: state.basicRes })];
    if (state.symptomId > 0) {
      jobs.push(postJSON('save_checklist', { trp_id: B.trpId, symptom_id: state.symptomId, results: state.symRes }));
    }

    Promise.all(jobs).then(function (resps) {
      for (var i = 0; i < resps.length; i++) {
        if (!resps[i] || !resps[i].ok) { throw new Error('save_checklist'); }
      }
      global.removeEventListener('beforeunload', onBeforeUnload);
      global.location.href = 'save_rate?' + buildRateQuery();
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
    var lines = [
      'อาการที่ประเมิน',
      $('mn_ar_tech').value.trim(),
      '',
      'รายการซ่อม (' + state.parts.length + ')'
    ].concat(state.parts.map(function (p, i) { return (i + 1) + '. ' + p.name; }));
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

  function buildRateQuery() {
    var p = {
      trp_id: B.trpId,
      trp_repair_technician: $('mn_ar_tech').value,
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
    return Object.keys(p).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(p[k]);
    }).join('&');
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

  function initForm() {
    var s = B.saved || {};
    $('mn_ar_tech').value = s.tech || '';
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
    syncHeaderOffset();
    global.addEventListener('resize', syncHeaderOffset);
    global.addEventListener('scroll', syncStuck, { passive: true });
    global.addEventListener('beforeunload', onBeforeUnload);

    initForm();
    renderParts();

    if (B.savedSymptomId > 0) {
      state.symptomId = B.savedSymptomId;
      state.symptomChosen = true;
      loadSymptom(B.savedSymptomId);
    }
    renderSymptoms();
    loadBasic();

    ['mn_ar_basic', 'mn_ar_sym_items'].forEach(function (id) {
      $(id).addEventListener('click', onItemsClick);
      $(id).addEventListener('input', onItemsInput);
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

    $('mn_ar_regen').addEventListener('click', function () {
      var tech = $('mn_ar_tech');
      var desc = buildDescription(collectFindings().findings);
      tech.value = desc;
      state.autoTech = desc;
      state.dirty = true;
      restartAnim(this, 'is-spin');
      restartAnim(tech, 'is-flash');
    });

    goTo(1, false, true);
  }

  if (doc.readyState === 'loading') {
    doc.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
}(typeof window !== 'undefined' ? window : this));
