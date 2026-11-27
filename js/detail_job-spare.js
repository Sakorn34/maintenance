/**
 * หน้า detail_job — ปุ่มตามสถานะเครื่องสำรอง (การ์ด S1): เปิดหน้าต่างกรอกข้อมูล แล้ว POST ไป `spare_action`
 * ใช้คลาส modal เดิม (mn-modal-*) จาก mn_modal_assets
 */
(function (global) {
  'use strict';

  var doc = global.document;
  var CFG = global.MN_DETAIL_JOB || {};
  var OVERLAY_ID = 'mn-sp-overlay';

  var REASONS = {
    bounce: ['น้ำท่วม/ภัยพิบัติ', 'ไม่มีคนรับ', 'ที่อยู่ผิด', 'ลูกค้าปฏิเสธรับ'],
    change: ['สำรองเสีย', 'หยิบผิดรุ่น', 'ลูกค้าขอเปลี่ยน'],
    swap: ['สำรองเสีย', 'ลูกค้าขอเปลี่ยน', 'ผิดรุ่น'],
    cancel: ['ลูกค้าไม่ต้องการแล้ว', 'งานยกเลิก']
  };

  var TITLES = {
    send: 'บันทึกส่งเครื่องสำรอง',
    pickup: 'ลูกค้ามารับเครื่องสำรองเอง',
    delivered: 'ยืนยันถึงลูกค้าแล้ว',
    bounce: 'บันทึกพัสดุตีกลับ',
    change: 'เปลี่ยนเครื่องสำรอง',
    swap: 'สลับเครื่องสำรองที่ลูกค้า',
    'return': 'รับเครื่องสำรองคืน',
    cancel: 'ยกเลิกเครื่องสำรอง',
    lost: 'เครื่องสำรองสูญหาย/เสียหาย'
  };

  var NOTES = {
    bounce: 'เครื่องยังผูกกับใบงานนี้ ไม่ว่างในคลัง หลังบันทึกเลือกได้ว่าจะส่งใหม่ / ลูกค้ามารับเอง / เปลี่ยนเครื่อง / ไม่ต้องส่งแล้ว และขึ้นในหน้ารอส่งพัสดุ',
    swap: 'ส่งเครื่องใหม่ไปแทน เครื่องเดิมเปลี่ยนเป็น "รอรับคืน" (ยังไม่ว่างในคลังจนกว่าจะรับคืน)',
    change: 'เครื่องเดิมอยู่กับเรา ยกเลิกแล้วกลับเป็นว่างในคลัง เครื่องใหม่รอส่ง',
    cancel: 'เครื่องกลับเป็นว่างในคลัง',
    lost: 'เครื่องจะไม่กลับเข้าคลัง (สถานะ lost) รอเคลม/ตัดจำหน่าย',
    'return': 'เครื่องกลับเป็นว่างในคลัง ถ้าส่งเครื่องซ่อมคืนแล้วและไม่มีสำรองค้าง งานจะเป็นเสร็จสิ้น'
  };

  function today() {
    var d = new Date();
    var p = function (n) { return (n < 10 ? '0' : '') + n; };
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
  }

  function el(tag, cls, text) {
    var e = doc.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  function overlay() {
    var o = doc.getElementById(OVERLAY_ID);
    if (o) { return o; }
    o = el('div', 'mn-modal-overlay');
    o.id = OVERLAY_ID;
    o.setAttribute('role', 'dialog');
    o.setAttribute('aria-modal', 'true');
    o.innerHTML = '<div class="mn-modal-dialog mn-modal mn-modal--field-edit mn-sp-dialog">'
      + '<div class="mn-modal-header"><span class="mn-sp-dlg-ic"><i class="fa fa-exchange" aria-hidden="true"></i></span><span class="mn-sp-dlg-t"></span></div>'
      + '<div class="mn-modal-body mn-modal-body--field-edit mn-sp-dlg-b"></div>'
      + '<div class="mn-modal-footer"><button type="button" class="mn-modal-btn mn-modal-btn--secondary" data-sp-x>ยกเลิก</button>'
      + '<button type="button" class="mn-modal-btn mn-modal-btn--primary" data-sp-ok>บันทึก</button></div></div>';
    doc.body.appendChild(o);
    o.addEventListener('click', function (e) {
      if (e.target === o || e.target.closest('[data-sp-x]')) { close(); }
    });
    doc.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && o.classList.contains('mn-modal-overlay--open')) { close(); }
    });
    return o;
  }

  function close() {
    var o = doc.getElementById(OVERLAY_ID);
    if (o) { o.classList.remove('mn-modal-overlay--open'); o._ctx = null; }
  }

  function field(body, label, input) {
    var w = el('div', 'mn-sp-f');
    w.appendChild(el('label', 'mn-sp-l', label));
    w.appendChild(input);
    body.appendChild(w);
    return input;
  }

  function input(type, val, ph) {
    var i = el('input', 'mn-modal-field-edit-input');
    i.type = type;
    if (val) { i.value = val; }
    if (ph) { i.placeholder = ph; }
    return i;
  }

  function select(opts, ph) {
    var s = el('select', 'mn-modal-field-edit-input');
    var o0 = el('option', '', ph || 'เลือก');
    o0.value = '';
    s.appendChild(o0);
    opts.forEach(function (v) { var o = el('option', '', v); o.value = v; s.appendChild(o); });
    return s;
  }

  // ปุ่มเหตุผลกดเลือก + "อื่น ๆ" ให้พิมพ์
  function reasons(body, list, label) {
    var w = el('div', 'mn-sp-f');
    w.appendChild(el('label', 'mn-sp-l', label));
    var pills = el('div', 'mn-sp-pills');
    var other = input('text', '', 'ระบุเหตุผล');
    other.hidden = true;
    var state = { value: '' };
    list.concat(['อื่น ๆ']).forEach(function (r) {
      var b = el('button', 'mn-sp-pill', r);
      b.type = 'button';
      b.addEventListener('click', function () {
        pills.querySelectorAll('.mn-sp-pill').forEach(function (x) { x.classList.toggle('is-on', x === b); });
        other.hidden = r !== 'อื่น ๆ';
        state.value = r === 'อื่น ๆ' ? '' : r;
        if (!other.hidden) { other.focus(); }
      });
      pills.appendChild(b);
    });
    w.appendChild(pills);
    w.appendChild(other);
    body.appendChild(w);
    return function () { return other.hidden ? state.value : other.value.trim(); };
  }

  function open(btn) {
    var act = btn.getAttribute('data-sp-act');
    var tsp = btn.getAttribute('data-sp-tsp');
    var sn = btn.getAttribute('data-sp-sn') || '';
    var state = btn.getAttribute('data-sp-state') || '';
    var sec = btn.closest('[data-sp-trp]');
    var o = overlay();
    var body = o.querySelector('.mn-sp-dlg-b');
    var waiting = sn === '' || /^waiting/i.test(sn);
    o.querySelector('.mn-sp-dlg-t').textContent = (act === 'change' && waiting) ? 'เลือก S/N เครื่องสำรอง'
      : (act === 'send' && state === 'bounce') ? 'ส่งเครื่องสำรองใหม่ (หลังตีกลับ)'
      : (act === 'pickup' && state === 'bounce') ? 'ลูกค้ามารับเครื่องสำรองเอง (หลังตีกลับ)'
      : (TITLES[act] || act);
    body.innerHTML = '';
    var head = el('div', 'mn-sp-cur');
    head.innerHTML = '<i class="fa fa-desktop" aria-hidden="true"></i> ';
    head.appendChild(el('b', '', waiting ? 'ยังไม่ระบุ S/N' : sn));
    body.appendChild(head);

    var get = {};
    var rec = String(CFG.logisticRecorder || '').trim();
    if (act === 'send') {
      get.date = field(body, 'วันที่ส่ง', input('date', today()));
      get.logistics = field(body, 'ขนส่ง', select((CFG.logisticsNames || []).filter(function (n) { return n !== 'Customer'; }), 'เลือกขนส่ง'));
      get.tracking = field(body, 'Tracking', input('text', '', 'เลขพัสดุ'));
      get.sendby = field(body, 'ผู้ส่ง', input('text', rec));
    } else if (act === 'pickup') {
      get.date = field(body, 'วันที่ลูกค้ามารับ', input('date', today()));
      get.person = field(body, 'ชื่อคนมารับ', input('text', '', 'เช่น คุณสมชาย (ช่างอาคาร)'));
    } else if (act === 'delivered') {
      get.date = field(body, 'วันที่ถึงลูกค้า', input('date', today()));
    } else if (act === 'bounce') {
      get.reason = reasons(body, REASONS.bounce, 'เหตุผลที่ตีกลับ');
      get.date = field(body, 'วันที่ได้รับพัสดุคืน', input('date', today()));
    } else if (act === 'change' || act === 'swap') {
      var opts = [];
      try { opts = JSON.parse(sec.getAttribute('data-sp-opts') || '[]'); } catch (e) { opts = []; }
      var lbl = 'S/N เครื่องใหม่ (' + (sec.getAttribute('data-sp-product') || '') + ' ที่ว่างในคลัง)';
      get.new_sn = field(body, lbl, select(opts, opts.length ? 'เลือก S/N' : 'ไม่มีเครื่องว่างในคลัง'));
      if (!(act === 'change' && waiting)) {
        get.reason = reasons(body, REASONS[act], 'เหตุผลที่เปลี่ยน');
      }
      if (act === 'change' && state === 'bounce') { body.appendChild(el('p', 'mn-sp-note', NOTES.change)); }
    } else if (act === 'return') {
      get.date = field(body, 'วันที่รับคืน', input('date', today()));
      get.person = field(body, 'ผู้รับ', input('text', rec));
    } else if (act === 'cancel') {
      get.reason = reasons(body, REASONS.cancel, 'เหตุผล');
      get.date = field(body, 'วันที่', input('date', today()));
    } else if (act === 'lost') {
      get.reason = field(body, 'รายละเอียด', input('text', '', 'เช่น พัสดุหายระหว่างส่ง / ลูกค้าทำตกเสียหาย'));
      get.date = field(body, 'วันที่', input('date', today()));
    }
    if (NOTES[act] && act !== 'change') { body.appendChild(el('p', 'mn-sp-note', NOTES[act])); }
    var err = el('p', 'mn-sp-err');
    err.hidden = true;
    body.appendChild(err);

    var ok = o.querySelector('[data-sp-ok]');
    ok.disabled = false;
    ok.onclick = function () {
      var data = new URLSearchParams();
      data.set('tsp_id', tsp);
      data.set('action', act);
      Object.keys(get).forEach(function (k) {
        var v = typeof get[k] === 'function' ? get[k]() : get[k].value;
        data.set(k, String(v || '').trim());
      });
      if (act === 'change' && waiting) { data.set('reason', 'ระบุ S/N'); }
      ok.disabled = true;
      err.hidden = true;
      fetch('spare_action', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: data.toString()
      }).then(function (r) {
        return r.json().catch(function () { return { ok: false, error: 'ตอบกลับจากเซิร์ฟเวอร์ไม่ถูกต้อง' }; });
      }).then(function (res) {
        if (!res || !res.ok) {
          ok.disabled = false;
          err.textContent = (res && res.error) ? res.error : 'บันทึกไม่สำเร็จ';
          err.hidden = false;
          return;
        }
        global.location.reload();
      }).catch(function () {
        ok.disabled = false;
        err.textContent = 'เชื่อมต่อเซิร์ฟเวอร์ไม่ได้';
        err.hidden = false;
      });
    };
    o.classList.add('mn-modal-overlay--open');
    var first = body.querySelector('input:not([hidden]), select');
    if (first && first.type !== 'date') { first.focus(); }
  }

  doc.addEventListener('click', function (e) {
    var b = e.target.closest ? e.target.closest('[data-sp-act]') : null;
    if (b) { e.preventDefault(); open(b); }
  });
})(window);
