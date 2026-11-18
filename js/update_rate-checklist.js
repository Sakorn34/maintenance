/**
 * Checklist ตรวจสอบก่อนประเมินในหน้า update_rate
 * Section พื้นฐาน (symptom_id=0) โหลดทันที; section อาการโหลดเมื่อเลือก dropdown
 * ต้องการ: jQuery, window.mnClTrpId, window.mnClProduct, window.mnClSavedSymptomId
 */

(function (global) {
  'use strict';

  var trpId   = global.mnClTrpId   || 0;
  var product = global.mnClProduct || '';

  var basicResults   = {}; // ci_id → {result, note}
  var currentResults = {}; // ci_id → {result, note}
  var basicItems     = []; // items array จาก loadBasicItems (ใช้ buildAutoDescription)

  function escHtml(s) {
    return String(s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;')
      .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function showAlert(msg, variant) {
    if (typeof global.mnModalAlert === 'function') {
      global.mnModalAlert(msg, { variant: variant || 'info' });
    }
  }

  // ---- load ----

  function loadBasicItems() {
    if (!product) { return; }
    global.jQuery.getJSON('checklist_items', { product: product, symptom_id: 0, trp_id: trpId })
    .done(function (data) {
      var items = data.items || [];
      basicItems   = items;
      basicResults = {};
      for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (item.ci_auto_source && item.auto_result) {
          // auto items: ใช้ผลที่คำนวณมา เว้นแต่มีผลบันทึกไว้แล้ว
          basicResults[item.ci_id] = {
            result: item.saved_result || item.auto_result,
            note:   item.saved_note  || item.auto_detail || ''
          };
        } else if (item.saved_result !== '') {
          basicResults[item.ci_id] = { result: item.saved_result, note: item.saved_note || '' };
        }
      }
      renderItems(items, 'mn_cl_basic_wrap', 'basic', basicResults);
    })
    .fail(function () { showAlert('โหลดการตรวจพื้นฐานไม่สำเร็จ กรุณา reload หน้า', 'warning'); });
  }

  function loadSymptomItems(symptomId) {
    if (!symptomId || !product) {
      renderItems([], 'mn_cl_items_wrap', 'symptom', currentResults);
      return;
    }
    global.jQuery.getJSON('checklist_items', { product: product, symptom_id: symptomId, trp_id: trpId })
    .done(function (data) {
      var items = data.items || [];
      currentResults = {};
      for (var i = 0; i < items.length; i++) {
        var item = items[i];
        if (item.saved_result !== '') {
          currentResults[item.ci_id] = { result: item.saved_result, note: item.saved_note || '' };
        }
      }
      renderItems(items, 'mn_cl_items_wrap', 'symptom', currentResults);
    })
    .fail(function () { showAlert('โหลด checklist ไม่สำเร็จ กรุณา reload หน้า', 'warning'); });
  }

  // ---- render ----

  function renderItems(items, wrapId, section, resultsMap) {
    var wrap = document.getElementById(wrapId);
    if (!wrap) { return; }
    if (!items || items.length === 0) { wrap.innerHTML = ''; return; }

    var html = '<ul class="mn-cl-items">';
    for (var i = 0; i < items.length; i++) {
      var item  = items[i];
      var saved = resultsMap[item.ci_id] || { result: '', note: '' };

      var liClass = 'mn-cl-item' + (item.ci_auto_source ? ' mn-cl-item--auto' : '');
      html += '<li class="' + liClass + '" data-id="' + item.ci_id + '">';
      html += '<span class="mn-cl-item__step">' + item.ci_step + '.</span>';
      html += '<div class="mn-cl-item__body">';
      html += '<span class="mn-cl-item__q">' + escHtml(item.ci_question) + '</span>';

      if (item.ci_hint) {
        html += '<span class="mn-cl-item__hint">' + escHtml(item.ci_hint) + '</span>';
      }

      // --- ส่วนปุ่มหรือ auto badge ---
      if (item.ci_auto_source) {
        var ar  = item.auto_result || 'na';
        var lbl = escHtml(item.auto_label  || '');
        var det = item.auto_detail ? ' <small class="mn-cl-auto-detail">' + escHtml(item.auto_detail) + '</small>' : '';
        html += '<span class="mn-cl-auto-result mn-cl-auto-result--' + ar + '">' + lbl + det + '</span>';

      } else if (item.ci_opts && item.ci_opts.length > 0) {
        html += renderCustomBtns(item, saved, section);

      } else {
        // default pass / fail / N/A
        html += '<span class="mn-cl-item__btns">';
        html += defaultBtn(item.ci_id, 'pass', 'ผ่าน',    saved.result, section, 'mn-cl-btn--pass');
        html += defaultBtn(item.ci_id, 'fail', 'ไม่ผ่าน', saved.result, section, 'mn-cl-btn--fail');
        html += defaultBtn(item.ci_id, 'na',   'N/A',     saved.result, section, 'mn-cl-btn--na');
        html += '</span>';
      }

      // --- number input (ci_unit) ---
      if (item.ci_unit) {
        var minAttr = (item.ci_val_min !== null && item.ci_val_min !== undefined)
          ? ' data-val-min="' + item.ci_val_min + '"' : '';
        var maxAttr = (item.ci_val_max !== null && item.ci_val_max !== undefined)
          ? ' data-val-max="' + item.ci_val_max + '"' : '';

        var numVal   = '';
        var badgeCls = '';
        var badgeTxt = '';
        if (saved.note && item.ci_val_min !== null) {
          var numParsed = parseFloat(saved.note);
          if (!isNaN(numParsed)) {
            numVal   = saved.note;
            var isPass = (numParsed >= item.ci_val_min && numParsed <= item.ci_val_max);
            badgeCls = isPass ? ' mn-cl-val-badge--pass' : ' mn-cl-val-badge--fail';
            badgeTxt = isPass ? 'ผ่าน ✓' : 'ไม่ผ่าน ✗';
          }
        }
        html += '<span class="mn-cl-item__val">'
          + '<input type="number" class="mn-cl-val-input" data-id="' + item.ci_id
          + '" data-section="' + section + '"' + minAttr + maxAttr
          + ' placeholder="0" value="' + escHtml(numVal) + '" min="0">'
          + ' <span class="mn-cl-val-unit">' + escHtml(item.ci_unit) + '</span>'
          + ' <span class="mn-cl-val-badge' + badgeCls + '" id="val-badge-' + item.ci_id + '">' + badgeTxt + '</span>'
          + '</span>';
      }

      // --- inline note input สำหรับ option ที่มี :note ---
      if (item.ci_opts) {
        for (var j = 0; j < item.ci_opts.length; j++) {
          if (item.ci_opts[j].has_note) {
            var showNote  = (saved.result === item.ci_opts[j].label);
            var noteStyle = showNote ? '' : 'display:none';
            var noteVal   = showNote ? escHtml(saved.note || '') : '';
            html += '<span class="mn-cl-item__opt-note" id="opt-note-' + item.ci_id + '" style="' + noteStyle + '">'
              + '<input type="text" class="mn-cl-opt-note-input" data-id="' + item.ci_id
              + '" data-section="' + section + '" placeholder="ระบุรายละเอียด..." value="' + noteVal + '">'
              + '</span>';
            break;
          }
        }
      }

      html += '</div>';
      html += '</li>';
    }
    html += '</ul>';
    wrap.innerHTML = html;

    wrap.querySelectorAll('.mn-cl-btn').forEach(function (b) { b.addEventListener('click', onBtnClick); });
    wrap.querySelectorAll('.mn-cl-val-input').forEach(function (b) { b.addEventListener('input', onValInput); });
    wrap.querySelectorAll('.mn-cl-opt-note-input').forEach(function (b) { b.addEventListener('input', onNoteInput); });
  }

  function renderCustomBtns(item, saved, section) {
    var opts = item.ci_opts;
    var html = '<span class="mn-cl-item__btns">';
    for (var i = 0; i < opts.length; i++) {
      var opt    = opts[i];
      var active = (saved.result === opt.label) ? ' mn-cl-btn--active' : '';
      var cls;
      // items ที่มี ci_unit + single option = N/A style (เช่น LUX → ไม่สามารถวัดได้)
      if (item.ci_unit && opts.length === 1) {
        cls = 'mn-cl-btn--na';
      } else {
        cls = i === 0 ? 'mn-cl-btn--good' : (i === 1 ? 'mn-cl-btn--warn' : 'mn-cl-btn--bad');
      }
      html += '<button type="button" class="mn-cl-btn ' + cls + active
        + '" data-id="' + item.ci_id
        + '" data-result="' + escHtml(opt.label)
        + '" data-has-note="' + (opt.has_note ? '1' : '0')
        + '" data-section="' + section + '">' + escHtml(opt.label) + '</button>';
    }
    html += '</span>';
    return html;
  }

  function defaultBtn(ciId, result, label, savedResult, section, extraCls) {
    var active = (savedResult === result) ? ' mn-cl-btn--active' : '';
    return '<button type="button" class="mn-cl-btn ' + extraCls + active
      + '" data-id="' + ciId + '" data-result="' + result
      + '" data-section="' + section + '">' + label + '</button>';
  }

  // ---- event handlers ----

  function onBtnClick(e) {
    var btn      = e.currentTarget;
    var id       = parseInt(btn.getAttribute('data-id'), 10);
    var result   = btn.getAttribute('data-result');
    var hasNote  = btn.getAttribute('data-has-note') === '1';
    var section  = btn.getAttribute('data-section');
    var map      = (section === 'basic') ? basicResults : currentResults;

    var existingNote = (map[id] && map[id].note) ? map[id].note : '';

    if (map[id] && map[id].result === result) {
      // toggle off
      map[id] = { result: '', note: '' };
      result  = '';
    } else {
      map[id] = { result: result, note: hasNote ? existingNote : '' };
    }

    // อัปเดต active state
    var btnsSpan = btn.parentNode;
    btnsSpan.querySelectorAll('.mn-cl-btn').forEach(function (b) {
      if (result !== '' && b.getAttribute('data-result') === result) {
        b.classList.add('mn-cl-btn--active');
      } else {
        b.classList.remove('mn-cl-btn--active');
      }
    });

    var li = btn.closest('li');
    if (!li) { return; }

    // show/hide note input
    var noteSpan = li.querySelector('.mn-cl-item__opt-note');
    if (noteSpan) {
      if (hasNote && result !== '') {
        noteSpan.style.display = '';
      } else {
        noteSpan.style.display = 'none';
        var noteInput = noteSpan.querySelector('.mn-cl-opt-note-input');
        if (noteInput) { noteInput.value = ''; }
        if (map[id]) { map[id].note = ''; }
      }
    }

    // ถ้า item นี้มี number input (LUX) ให้ clear เมื่อเลือก option
    if (result !== '') {
      var valInput = li.querySelector('.mn-cl-val-input');
      if (valInput) {
        valInput.value = '';
        var badge = li.querySelector('.mn-cl-val-badge');
        if (badge) { badge.textContent = ''; badge.className = 'mn-cl-val-badge'; }
        if (map[id]) { map[id].note = ''; }
      }
    }
  }

  function onValInput(e) {
    var input   = e.currentTarget;
    var id      = parseInt(input.getAttribute('data-id'), 10);
    var section = input.getAttribute('data-section');
    var map     = (section === 'basic') ? basicResults : currentResults;
    var val     = input.value.trim();

    if (!map[id]) { map[id] = { result: '', note: '' }; }
    map[id].note = val;

    var minAttr = input.getAttribute('data-val-min');
    var maxAttr = input.getAttribute('data-val-max');
    var li      = input.closest('li');
    var badge   = li ? li.querySelector('.mn-cl-val-badge') : null;

    if (minAttr !== null && maxAttr !== null) {
      if (val !== '') {
        var num        = parseFloat(val);
        var autoResult = (!isNaN(num) && num >= parseFloat(minAttr) && num <= parseFloat(maxAttr)) ? 'pass' : 'fail';
        map[id].result = autoResult;
        if (badge) {
          badge.textContent = autoResult === 'pass' ? 'ผ่าน ✓' : 'ไม่ผ่าน ✗';
          badge.className   = 'mn-cl-val-badge mn-cl-val-badge--' + autoResult;
        }
        // deactivate custom option buttons (เช่น ไม่สามารถวัดได้)
        if (li) {
          li.querySelectorAll('.mn-cl-btn').forEach(function (b) { b.classList.remove('mn-cl-btn--active'); });
        }
      } else {
        map[id].result = '';
        if (badge) { badge.textContent = ''; badge.className = 'mn-cl-val-badge'; }
      }
    }
  }

  function onNoteInput(e) {
    var input   = e.currentTarget;
    var id      = parseInt(input.getAttribute('data-id'), 10);
    var section = input.getAttribute('data-section');
    var map     = (section === 'basic') ? basicResults : currentResults;
    if (!map[id]) { map[id] = { result: '', note: '' }; }
    map[id].note = input.value;
  }

  // ---- save ----

  function onSaveClick() {
    var symptomSel = document.getElementById('mn_cl_symptom');
    var symptomId  = symptomSel ? parseInt(symptomSel.value, 10) : 0;

    global.jQuery.ajax({
      url: 'save_checklist', type: 'POST',
      contentType: 'application/json', dataType: 'json',
      data: JSON.stringify({ trp_id: trpId, symptom_id: 0, results: basicResults })
    })
    .done(function (basicResp) {
      var basicRates = (basicResp && basicResp.ok) ? (basicResp.suggested_rates || []) : [];

      if (symptomId > 0) {
        global.jQuery.ajax({
          url: 'save_checklist', type: 'POST',
          contentType: 'application/json', dataType: 'json',
          data: JSON.stringify({ trp_id: trpId, symptom_id: symptomId, results: currentResults })
        })
        .done(function (sympResp) {
          var sympRates = (sympResp && sympResp.ok) ? (sympResp.suggested_rates || []) : [];
          var allRates  = mergeRates(basicRates, sympRates);
          var filled    = fillRateDropdowns(allRates);
          autoFillTechnicianField(symptomSel);
          fetchAndFillHistoricalRates(allRates, function (extra) {
            var total = filled + extra;
            showAlert(total > 0
              ? 'บันทึก Checklist สำเร็จ — เติมรายการประเมิน ' + total + ' รายการแล้ว'
              : 'บันทึก Checklist สำเร็จ', 'info');
          });
        })
        .fail(function () { showAlert('บันทึกไม่สำเร็จ กรุณาลองใหม่', 'warning'); });
      } else {
        var filled = fillRateDropdowns(basicRates);
        autoFillTechnicianField(symptomSel);
        fetchAndFillHistoricalRates(basicRates, function (extra) {
          var total = filled + extra;
          showAlert(total > 0
            ? 'บันทึก Checklist สำเร็จ — เติมรายการประเมิน ' + total + ' รายการแล้ว'
            : 'บันทึก Checklist สำเร็จ', 'info');
        });
      }
    })
    .fail(function () { showAlert('บันทึกไม่สำเร็จ กรุณาลองใหม่', 'warning'); });
  }

  function mergeRates(a, b) {
    var seen = {}, out = [], all = a.concat(b);
    for (var i = 0; i < all.length; i++) {
      if (!seen[all[i]]) { seen[all[i]] = true; out.push(all[i]); }
    }
    return out;
  }

  function fillRateDropdowns(rates) {
    if (!rates || rates.length === 0) { return 0; }
    var filled = 0, ri = 0;
    for (var i = 1; i <= 10 && ri < rates.length; i++) {
      var sel = document.getElementById('rate_list' + i);
      if (!sel || sel.value !== '') { continue; }
      var opts = sel.options;
      for (var j = 0; j < opts.length; j++) {
        if (opts[j].text === rates[ri] || opts[j].value === rates[ri]) {
          sel.value = opts[j].value; filled++; ri++; break;
        }
      }
    }
    return filled;
  }

  function buildAutoDescription(symptomSel) {
    var symptomText = '';
    if (symptomSel) {
      var idx = symptomSel.selectedIndex;
      if (idx >= 0 && symptomSel.options[idx]) { symptomText = symptomSel.options[idx].text.trim(); }
    }
    var findings = [];
    for (var i = 0; i < basicItems.length; i++) {
      var item = basicItems[i];
      var r    = basicResults[item.ci_id];
      if (!r || !r.result) { continue; }
      var result = r.result;
      var note   = r.note || '';
      if (result === 'pass' || result === 'na') { continue; }
      if (item.ci_opts && item.ci_opts.length > 0 && result === item.ci_opts[0].label) { continue; }
      var text;
      if (item.ci_auto_source) {
        text = note || (item.auto_label || result);
      } else {
        text = result;
        if (note) { text += ' (' + note + ')'; }
      }
      if (text) { findings.push(text); }
    }
    if (!symptomText && findings.length === 0) { return ''; }
    if (findings.length === 0) { return symptomText; }
    var prefix = symptomText ? symptomText + ' — ตรวจพบ: ' : 'ตรวจพบ: ';
    return prefix + findings.join(', ');
  }

  function autoFillTechnicianField(symptomSel) {
    var techEl = document.getElementById('trp_repair_technician');
    if (!techEl || techEl.value !== '') { return; }
    var desc = buildAutoDescription(symptomSel);
    if (desc) { techEl.value = desc; }
  }

  function fetchAndFillHistoricalRates(alreadyFilled, callback) {
    if (!product) { callback(0); return; }
    global.jQuery.getJSON('suggest_rates', { product: product, trp_id: trpId })
    .done(function (data) {
      var topRates = (data.top_rates || []).filter(function (r) {
        return alreadyFilled.indexOf(r) === -1;
      });
      var extra = fillRateDropdowns(topRates);
      callback(extra);
    })
    .fail(function () { callback(0); });
  }

  // ---- init ----

  global.addEventListener('load', function () {
    loadBasicItems();

    var symptomSel = document.getElementById('mn_cl_symptom');
    if (!symptomSel) { return; }

    symptomSel.addEventListener('change', function () {
      var val = parseInt(this.value, 10);
      currentResults = {};
      if (val > 0) { loadSymptomItems(val); }
      else { renderItems([], 'mn_cl_items_wrap', 'symptom', currentResults); }
    });

    var saveBtn = document.getElementById('mn_cl_save_btn');
    if (saveBtn) { saveBtn.addEventListener('click', onSaveClick); }

    var savedId = global.mnClSavedSymptomId || 0;
    if (savedId > 0) {
      symptomSel.value = String(savedId);
      loadSymptomItems(savedId);
    }
  });

})(typeof window !== 'undefined' ? window : this);
