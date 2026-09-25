/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `repair_dashboard` — อ่าน JSON จาก `#mn-repair-dashboard-json` แล้วสร้างกราฟ Chart.js (แท่ง / doughnut)
 * กรณีไม่มีข้อมูลแสดงข้อความแทนกราฟว่าง; ปุ่ม «ปีนี้» / «เดือนนี้» ตั้งช่วงวันที่แล้วส่งฟอร์ม
 *
 * Flow:
 * DOMContentLoaded → parse JSON → กราฟที่มีข้อมูล → bind ปุ่มช่วงด่วน
 */
(function () {
  'use strict';

  /**
   * Purpose:
   * อ่าน payload JSON จาก script element
   *
   * Parameters สำคัญ:
   * - ไม่มี
   *
   * Return value:
   * - object หรือ null
   */
  function mnRepairDashReadPayload() {
    var el = document.getElementById('mn-repair-dashboard-json');
    if (!el || !el.textContent) {
      return null;
    }
    try {
      return JSON.parse(el.textContent);
    } catch (e) {
      return null;
    }
  }

  /**
   * Purpose:
   * ซ่อน canvas แล้วแสดงข้อความเมื่อไม่มีข้อมูลกราฟ (ไม่สร้างแท่งหลอก)
   *
   * Parameters สำคัญ:
   * - canvasId: string
   * - message: string ข้อความภาษาไทย
   *
   * Return value:
   * - boolean true ถ้าแสดง empty state
   */
  function mnRepairDashShowEmpty(canvasId, message) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.parentNode) {
      return false;
    }
    canvas.style.display = 'none';
    var p = document.createElement('p');
    p.className = 'mn-repair-dashboard-empty';
    p.setAttribute('role', 'status');
    p.textContent = message;
    canvas.parentNode.insertBefore(p, canvas);
    return true;
  }

  /**
   * Purpose:
   * สร้าง Chart แบบแท่งแนวนอน (ป้ายกำกับยาว)
   *
   * Parameters สำคัญ:
   * - canvasId: string id ของ canvas
   * - items: array ของ { label, count }
   * - label: string ชื่อชุดข้อมูล
   * - emptyMessage: string ถ้าไม่มีแถวข้อมูล
   *
   * Return value:
   * - undefined
   */
  function mnRepairDashBarHorizontal(canvasId, items, label, emptyMessage) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
      return;
    }
    if (!items || items.length === 0) {
      mnRepairDashShowEmpty(canvasId, emptyMessage);
      return;
    }
    var labels = [];
    var data = [];
    var i;
    for (i = 0; i < items.length; i += 1) {
      labels.push(String(items[i].label));
      data.push(Number(items[i].count));
    }
    // eslint-disable-next-line no-new
    new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: label,
          data: data,
          backgroundColor: 'rgba(3, 105, 161, 0.65)',
          borderColor: 'rgba(3, 105, 161, 1)',
          borderWidth: 1
        }]
      },
      options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          x: { beginAtZero: true, ticks: { precision: 0 } }
        }
      }
    });
  }

  /**
   * Purpose:
   * กราฟแท่งรายเดือน (มีเดือนที่ count เป็น 0 ได้)
   *
   * Parameters สำคัญ:
   * - canvasId: string
   * - items: array ของ { month, count }
   *
   * Return value:
   * - undefined
   */
  function mnRepairDashMonthlyBar(canvasId, items) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
      return;
    }
    if (!items || items.length === 0) {
      mnRepairDashShowEmpty(canvasId, 'ไม่มีช่วงเดือนที่คำนวณได้จากวันที่ที่เลือก');
      return;
    }
    var labels = [];
    var data = [];
    var i;
    for (i = 0; i < items.length; i += 1) {
      labels.push(String(items[i].month));
      data.push(Number(items[i].count));
    }
    // eslint-disable-next-line no-new
    new Chart(canvas.getContext('2d'), {
      type: 'bar',
      data: {
        labels: labels,
        datasets: [{
          label: 'จำนวนงาน',
          data: data,
          backgroundColor: 'rgba(4, 170, 109, 0.55)',
          borderColor: 'rgba(4, 170, 109, 1)',
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false }
        },
        scales: {
          y: { beginAtZero: true, ticks: { precision: 0 } },
          x: { ticks: { maxRotation: 45, minRotation: 0 } }
        }
      }
    });
  }

  /**
   * Purpose:
   * กราฟโดนัทสัดส่วนสถานะ
   *
   * Parameters สำคัญ:
   * - canvasId: string
   * - items: array ของ { label, count }
   *
   * Return value:
   * - undefined
   */
  function mnRepairDashDoughnut(canvasId, items) {
    var canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
      return;
    }
    if (!items || items.length === 0) {
      mnRepairDashShowEmpty(canvasId, 'ไม่มีงานในช่วงวันที่ที่เลือก — สัดส่วนสถานะจึงว่าง');
      return;
    }
    var labels = [];
    var data = [];
    var bg = [
      'rgba(3, 105, 161, 0.75)',
      'rgba(4, 170, 109, 0.75)',
      'rgba(220, 38, 38, 0.7)',
      'rgba(124, 58, 237, 0.7)',
      'rgba(234, 88, 12, 0.75)',
      'rgba(8, 145, 178, 0.75)',
      'rgba(100, 116, 139, 0.75)',
      'rgba(22, 163, 74, 0.65)'
    ];
    var i;
    for (i = 0; i < items.length; i += 1) {
      labels.push(String(items[i].label));
      data.push(Number(items[i].count));
    }
    var colors = [];
    for (i = 0; i < labels.length; i += 1) {
      colors.push(bg[i % bg.length]);
    }
    // eslint-disable-next-line no-new
    new Chart(canvas.getContext('2d'), {
      type: 'doughnut',
      data: {
        labels: labels,
        datasets: [{
          data: data,
          backgroundColor: colors,
          borderWidth: 1
        }]
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } }
        }
      }
    });
  }

  /**
   * Purpose:
   * pad เลขเดือน/วันเป็น 2 หลัก
   *
   * Parameters สำคัญ:
   * - n: number
   *
   * Return value:
   * - string
   */
  function mnRepairDashPad2(n) {
    return n < 10 ? '0' + n : String(n);
  }

  /**
   * Purpose:
   * ตั้งช่วงวันที่ในฟอร์มแล้ว submit — ใช้กับปุ่มปีนี้/เดือนนี้
   *
   * Parameters สำคัญ:
   * - mode: 'year' | 'month'
   *
   * Return value:
   * - undefined
   */
  function mnRepairDashApplyPreset(mode) {
    var f = document.getElementById('dash_from');
    var t = document.getElementById('dash_to');
    if (!f || !t || !f.form) {
      return;
    }
    var now = new Date();
    var y = now.getFullYear();
    var m = now.getMonth() + 1;
    if (mode === 'year') {
      f.value = y + '-01-01';
      t.value = y + '-12-31';
    } else {
      var last = new Date(y, m, 0).getDate();
      f.value = y + '-' + mnRepairDashPad2(m) + '-01';
      t.value = y + '-' + mnRepairDashPad2(m) + '-' + mnRepairDashPad2(last);
    }
    f.form.submit();
  }

  /**
   * Purpose:
   * bind ปุ่มช่วงด่วน
   *
   * Parameters สำคัญ:
   * - ไม่มี
   *
   * Return value:
   * - undefined
   */
  function mnRepairDashBindPresets() {
    var yBtn = document.getElementById('mnDashThisYear');
    var mBtn = document.getElementById('mnDashThisMonth');
    if (yBtn) {
      yBtn.addEventListener('click', function () {
        mnRepairDashApplyPreset('year');
      });
    }
    if (mBtn) {
      mBtn.addEventListener('click', function () {
        mnRepairDashApplyPreset('month');
      });
    }
  }

  function mnRepairDashInit() {
    var d = mnRepairDashReadPayload();
    if (!d) {
      return;
    }
    mnRepairDashBarHorizontal(
      'mnChartSpare',
      d.spare_trends || [],
      'จำนวนครั้ง',
      'ไม่มีแถว Transac_spare ในใบงานที่เปิดในช่วงนี้ — จึงยังไม่มีแนวโน้มสินค้าสำรอง'
    );
    mnRepairDashMonthlyBar('mnChartMonthly', d.monthly_jobs || []);
    mnRepairDashDoughnut('mnChartStatus', d.status_breakdown || []);
    mnRepairDashBarHorizontal(
      'mnChartProducts',
      d.top_products || [],
      'จำนวนงาน',
      'ไม่มีข้อมูลรุ่นสินค้าในช่วงนี้'
    );
    mnRepairDashBindPresets();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mnRepairDashInit);
  } else {
    mnRepairDashInit();
  }
}());
