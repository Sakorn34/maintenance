/**
 * เสนอวันที่ซื้อจากหลักฐานในระบบ (buy_date_suggest) ใต้ช่องวันที่ซื้อ — กดรายการเพื่อใส่วันที่
 * markup: <div id="w_buy_suggest" data-trp=""></div> + input#w_date_buy + input#w_sn
 * หลังเลือกเรียก mnUpdateWaitHwCalAgeUse() (คำนวณอายุเครื่อง / แนะนำประกัน) ถ้ามี
 */
(function (global) {
  'use strict';

  var doc = global.document;

  function esc(s) {
    return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  function dmy(ymd) {
    var p = String(ymd || '').split('-');
    return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : ymd;
  }

  function init() {
    var box = doc.getElementById('w_buy_suggest');
    var input = doc.getElementById('w_date_buy');
    var snEl = doc.getElementById('w_sn');
    if (!box || !input || !snEl) { return; }
    var lastSn = null;
    var seq = 0;

    function mark() {
      box.querySelectorAll('[data-date]').forEach(function (b) {
        var on = b.getAttribute('data-date') === input.value;
        b.classList.toggle('is-on', on);
        b.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    // ลิงก์ไปหน้าที่มาของหลักฐาน (เปิดแท็บใหม่ ข้อมูลที่กรอกไม่หาย) — ลิงก์ซ้ำกันแสดงครั้งเดียว
    function srcLinks(sources) {
      var seen = {};
      var links = (sources || []).filter(function (s) {
        if (!s.url || seen[s.url]) { return false; }
        seen[s.url] = true;
        return true;
      });
      if (!links.length) { return ''; }
      return '<div class="mn-bds__src">ดูที่มา: ' + links.map(function (s) {
        return '<a href="' + esc(s.url) + '" target="_blank" rel="noopener" title="เปิดแท็บใหม่"><i class="fa fa-external-link" aria-hidden="true"></i> '
          + esc(s.url_label || s.title) + '</a>';
      }).join(' ') + '</div>';
    }

    function render(res) {
      var items = res.items || [];
      var hints = res.hints || [];
      var off = res.off || [];
      var html = '';
      if (items.length) {
        html += '<p class="mn-bds__head"><i class="fa fa-search" aria-hidden="true"></i> พบหลักฐานวันที่ซื้อ กดเพื่อใช้</p>'
          + items.map(function (it) {
            var n = (it.sources || []).length;
            return '<button type="button" class="mn-bds__item' + (n > 1 ? ' is-multi' : '') + '" data-date="' + esc(it.date) + '" aria-pressed="false">'
              + '<span class="mn-bds__date">' + esc(dmy(it.date)) + '</span>'
              + '<span class="mn-bds__badge">' + (n > 1 ? 'ตรงกัน ' + n + ' แหล่ง' : esc(it.sources[0].title)) + '</span>'
              + '<span class="mn-bds__txt">' + it.sources.map(function (s) {
                return '<small><b>' + esc(s.title) + '</b>' + (s.detail ? ' ' + esc(s.detail) : '') + '</small>';
              }).join('')
              + (it.warn ? '<small class="mn-bds__warn"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i> ' + esc(it.warn) + '</small>' : '')
              + '</span><i class="fa fa-check mn-bds__tick" aria-hidden="true"></i></button>'
              + srcLinks(it.sources);
          }).join('');
      } else {
        html += '<p class="mn-bds__empty"><i class="fa fa-info-circle" aria-hidden="true"></i> ไม่พบหลักฐานวันที่ซื้อในระบบ ตรวจจากใบเสร็จหรือใบส่งของของลูกค้า</p>';
      }
      html += hints.map(function (h) { return '<p class="mn-bds__hint"><i class="fa fa-lightbulb-o" aria-hidden="true"></i> ' + esc(h) + '</p>'; }).join('');
      if (off.length) { html += '<p class="mn-bds__off">ยังค้นจาก ' + esc(off.join(', ')) + ' ไม่ได้</p>'; }
      box.innerHTML = html;
      mark();
    }

    function load(force) {
      var sn = snEl.value.trim();
      if (!sn) {
        lastSn = '';
        box.innerHTML = '<p class="mn-bds__empty"><i class="fa fa-info-circle" aria-hidden="true"></i> กรอก S/N เครื่องซ่อม แล้วระบบจะค้นหาวันที่ซื้อจากหลักฐานในระบบให้</p>';
        return;
      }
      if (sn.length < 5) { return; }
      if (sn === lastSn && !force) { return; }
      lastSn = sn;
      var my = ++seq;
      box.innerHTML = '<p class="mn-bds__loading"><i class="fa fa-circle-o-notch fa-spin" aria-hidden="true"></i> กำลังค้นหาหลักฐานวันที่ซื้อ</p>';
      fetch('buy_date_suggest?sn=' + encodeURIComponent(sn) + '&trp_id=' + encodeURIComponent(box.getAttribute('data-trp') || ''),
        { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
        .then(function (res) { if (my === seq) { render(res); } })
        .catch(function () { if (my === seq) { box.innerHTML = '<p class="mn-bds__empty">ค้นหาวันที่ซื้อไม่สำเร็จ</p>'; } });
    }

    box.addEventListener('click', function (e) {
      var b = e.target.closest('[data-date]');
      if (!b) { return; }
      input.value = b.getAttribute('data-date');
      mark();
      // แจ้งเหมือนผู้ใช้เลือกวันที่เอง: คำนวณอายุเครื่อง (onchange เดิม) + สถานะประกัน (js/warranty-timeline.js)
      input.dispatchEvent(new Event('change', { bubbles: true }));
    });
    input.addEventListener('change', mark);
    // ค้นหาเองเมื่อหยุดพิมพ์ S/N (ไม่ต้องรอคลิกออกจากช่อง)
    var typing = 0;
    snEl.addEventListener('input', function () {
      clearTimeout(typing);
      typing = setTimeout(function () { load(false); }, 700);
    });
    snEl.addEventListener('change', function () { clearTimeout(typing); load(false); });
    load(true);
  }

  if (doc.readyState === 'loading') { doc.addEventListener('DOMContentLoaded', init); } else { init(); }
}(typeof window !== 'undefined' ? window : this));
