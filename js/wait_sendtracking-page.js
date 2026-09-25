/**
 * วัตถุประสงค์และขอบเขต:
 * หน้า `wait_sendtracking` — คัดลอกข้อความแชร์ลูกค้า (แบบ `job_process-page.js`) แล้วลบแถวออกจากคิว DB + DOM
 *
 * Flow:
 * click `.mn-wst-copy-btn` → clipboard → POST `remove_sendtracking_queue` → ลบแถวจากตาราง
 */
(function ($) {
  'use strict';

  /**
   * Purpose:
   * เขียนข้อความลง clipboard — รูปแบบเดียวกับ `mnJpClipboardWrite` ใน `job_process-page.js`
   *
   * Parameters สำคัญ:
   * - string text: ข้อความดิบ
   *
   * Return value:
   * - Promise ที่ resolve เป็น boolean
   */
  function mnWstClipboardWrite(text) {
    if (
      typeof navigator !== 'undefined' &&
      navigator.clipboard &&
      typeof navigator.clipboard.writeText === 'function'
    ) {
      return navigator.clipboard.writeText(text).then(function () {
        return true;
      });
    }
    try {
      var ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.focus();
      ta.select();
      var ok = document.execCommand('copy');
      document.body.removeChild(ta);
      return Promise.resolve(ok);
    } catch (e) {
      return Promise.resolve(false);
    }
  }

  /**
   * Purpose:
   * เลือกข้อความใน textarea แล้ว `execCommand('copy')` — รูปแบบเดียวกับ `mnJpFallbackCopy` ใน `job_process-page.js`
   *
   * Parameters สำคัญ:
   * - HTMLTextAreaElement el: element ที่มีข้อความ
   *
   * Return value:
   * - boolean
   */
  function mnWstFallbackCopy(el) {
    if (!el) {
      return false;
    }
    el.focus();
    el.select();
    try {
      return document.execCommand('copy');
    } catch (e2) {
      return false;
    }
  }

  /**
   * Purpose:
   * แจ้งผู้ใช้หลังคัดลอก — ใช้ `mnMaintenanceFlowAlert` หรือ `alert` สำรอง
   *
   * Parameters สำคัญ:
   * - string message: ข้อความ
   * - string variant: variant สำหรับ modal
   */
  function mnWstNotify(message, variant) {
    if (typeof window.mnMaintenanceFlowAlert === 'function') {
      window.mnMaintenanceFlowAlert(String(message), variant || 'warning');
    } else if (typeof window.alert === 'function') {
      window.alert(String(message));
    }
  }

  /**
   * Purpose:
   * แสดงแถวว่างเมื่อไม่มีรายการคิวเหลือในตาราง
   *
   * Return value:
   * - undefined
   */
  function mnWstEnsureEmptyRow() {
    var $tb = $('#table1 tbody');
    if ($tb.find('tr.mn-wst-data-row').length === 0 && $tb.find('tr.mn-wst-empty-row').length === 0) {
      $tb.append(
        '<tr class="mn-wait-sendtracking-row mn-wst-empty-row">' +
          '<td colspan="9" align="center">ไม่มีรายการในคิว</td>' +
          '</tr>'
      );
    }
  }

  /**
   * Purpose:
   * ลบแถวออกจาก DOM หลังคัดลอกสำเร็จ
   *
   * Parameters สำคัญ:
   * - jQuery $tr: แถวข้อมูล
   *
   * Return value:
   * - undefined
   */
  function mnWstHideDataRow($tr) {
    $tr.fadeOut(200, function () {
      $(this).remove();
      mnWstEnsureEmptyRow();
    });
  }

  /**
   * Purpose:
   * เรียก API ลบแถว `sendtracking_history` หลังคัดลอกสำเร็จ
   *
   * Parameters สำคัญ:
   * - number sthId: `sth_id`
   * - function onDone: เรียกด้วย `(ok: boolean)`
   *
   * Return value:
   * - undefined
   */
  function mnWstRemoveQueueOnServer(sthId, onDone) {
    if (sthId < 1) {
      onDone(false);
      return;
    }
    var fd = new FormData();
    fd.append('sth_id', String(sthId));
    fetch('remove_sendtracking_queue', {
      method: 'POST',
      credentials: 'same-origin',
      body: fd,
    })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('HTTP ' + res.status);
        }
        return res.json();
      })
      .then(function (data) {
        onDone(data && data.ok === true);
      })
      .catch(function () {
        onDone(false);
      });
  }

  /**
   * Purpose:
   * หลังคัดลอกสำเร็จ — ลบคิวบนเซิร์ฟเวอร์แล้วเอาแถวออกจากหน้า
   *
   * Parameters สำคัญ:
   * - jQuery $tr: แถวข้อมูล
   *
   * Return value:
   * - undefined
   */
  function mnWstAfterCopySuccess($tr) {
    var sthId = parseInt($tr.attr('data-sth-id'), 10);
    if (isNaN(sthId)) {
      sthId = 0;
    }

    function doneNotify() {
      mnWstNotify(
        'คัดลอกข้อความแล้ว — รายการนี้ถูกนำออกจากคิวแล้ว',
        'success'
      );
    }

    if (sthId > 0) {
      mnWstRemoveQueueOnServer(sthId, function (ok) {
        if (ok) {
          mnWstHideDataRow($tr);
          doneNotify();
        } else {
          mnWstNotify(
            'คัดลอกข้อความแล้ว แต่ลบออกจากคิวไม่สำเร็จ — รีเฟรชหน้าแล้วลองอีกครั้ง',
            'warning'
          );
        }
      });
    } else {
      mnWstHideDataRow($tr);
      doneNotify();
    }
  }

  $(function () {
    $(document).on('click', '.mn-wst-copy-btn', function () {
      var $btn = $(this);
      if ($btn.prop('disabled')) {
        return;
      }
      var $tr = $btn.closest('tr');
      var $ta = $tr.find('textarea.mn-wst-share-plain').first();
      var raw = '';
      if ($ta.length && typeof $ta.val === 'function') {
        raw = String($ta.val() || '');
      }
      if (raw === '') {
        mnWstNotify('ไม่มีข้อความให้คัดลอก — ตรวจสอบว่ามีงานซ่อม (trp_id) และลิงก์ติดตามลูกค้า', 'warning');
        return;
      }

      function after(ok) {
        if (ok) {
          mnWstAfterCopySuccess($tr);
          return;
        }
        if (mnWstFallbackCopy($ta.get(0))) {
          mnWstAfterCopySuccess($tr);
          return;
        }
        mnWstNotify(
          'เบราว์เซอร์ไม่รองรับการคัดลอกอัตโนมัติ — เลือกที่ช่องข้อความแล้วกด Ctrl+C',
          'warning'
        );
      }

      var p = mnWstClipboardWrite(raw);
      if (p && typeof p.then === 'function') {
        p.then(after).catch(function () {
          after(mnWstFallbackCopy($ta.get(0)));
        });
      } else {
        after(false);
      }
    });
  });
})(jQuery);
