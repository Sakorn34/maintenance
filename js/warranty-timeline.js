/**
 * หน้ารับเครื่อง — ประวัติประกันของเครื่อง + เลือกค่าในช่องประกันให้ (warranty_status)
 * อัปเดตเมื่อเปลี่ยน S/N / วันที่ซื้อ / วันที่รับเครื่อง
 * markup: <div id="mn_wr_box" data-trp="" data-product=""></div>, #w_sn, #w_date_buy, #w_date_receive, #w_insurance, #mn_insurance_hint
 */
(function (global) {
  'use strict';

  var doc = global.document;
  var ICON = { new: 'fa-certificate', extended: 'fa-plus-circle', repair: 'fa-wrench' };

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function init() {
    var box = doc.getElementById('mn_wr_box');
    var sn = doc.getElementById('w_sn');
    var buy = doc.getElementById('w_date_buy');
    var rec = doc.getElementById('w_date_receive');
    var ins = doc.getElementById('w_insurance');
    var hint = doc.getElementById('mn_insurance_hint');
    if (!box || !sn || !buy || !ins) { return; }
    var seq = 0;
    var timer = 0;
    var userPicked = box.getAttribute('data-saved') === '1'; // มีค่าที่บันทึกไว้แล้ว ไม่เปลี่ยนให้เองตอนโหลด
    ins.addEventListener('change', function () { userPicked = true; });

    function render(res) {
      var items = res.items || [];
      if (!items.length) {
        box.innerHTML = '<p class="mn-wr__empty"><i class="fa fa-info-circle" aria-hidden="true"></i> ยังไม่มีข้อมูลประกันของเครื่องนี้</p>';
        return;
      }
      box.innerHTML = '<p class="mn-wr__head">ประวัติประกัน (ณ วันรับเครื่อง ' + esc(res.ref) + ')</p><ul class="mn-wr__list">'
        + items.map(function (it) {
          var st = it.active ? '<span class="mn-wr__st is-on">เหลือ ' + it.left + ' วัน</span>'
            : (it.future ? '<span class="mn-wr__st is-next">เริ่ม ' + esc(it.start) + '</span>' : '<span class="mn-wr__st">หมดแล้ว</span>');
          return '<li class="' + (it.active ? 'is-on' : '') + '"><i class="fa ' + (ICON[it.type] || 'fa-shield') + '" aria-hidden="true"></i>'
            + '<span class="mn-wr__txt"><b>' + esc(it.title) + '</b><small>' + esc(it.start) + ' – ' + esc(it.end) + (it.ref ? ' | ' + esc(it.ref) : '') + '</small></span>'
            + st + '</li>';
        }).join('') + '</ul>';
    }

    function load() {
      var s = sn.value.trim();
      // ลิงก์ "ทะเบียนประกันเครื่องนี้" ตาม S/N ที่กรอกอยู่
      var reg = doc.getElementById('mn_wr_reg_link');
      if (reg) { reg.setAttribute('href', 'warranty_registry' + (s ? '?sn=' + encodeURIComponent(s) : '')); }
      if (!s) { box.innerHTML = ''; return; }
      var my = ++seq;
      var q = 'sn=' + encodeURIComponent(s) + '&product=' + encodeURIComponent(box.getAttribute('data-product') || '')
        + '&buy_date=' + encodeURIComponent(buy.value || '') + '&trp_id=' + encodeURIComponent(box.getAttribute('data-trp') || '')
        + '&ref=' + encodeURIComponent(rec && rec.value ? rec.value : '');
      fetch('warranty_status?' + q, { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(function (res) {
          if (my !== seq) { return; }
          render(res);
          // เลือกค่าให้ (ถ้าผู้รับเครื่องยังไม่ได้เลือกเอง)
          if (res.value && !userPicked) { ins.value = res.value; }
          if (hint) { hint.textContent = res.hint || ''; hint.style.display = res.hint ? '' : 'none'; }
        })
        .catch(function () {});
    }

    function later() { clearTimeout(timer); timer = setTimeout(load, 500); }
    // เปลี่ยนวันที่ซื้อ = ข้อมูลประกันเปลี่ยน → คำนวณค่าใหม่ให้ (ยกเลิกค่าที่เลือกเอง)
    buy.addEventListener('change', function () { userPicked = false; later(); });
    if (rec) { rec.addEventListener('change', function () { userPicked = false; later(); }); }
    sn.addEventListener('change', later);
    load();
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); } else { init(); }
}(typeof window !== 'undefined' ? window : this));
