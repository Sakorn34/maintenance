/**
 * หน้า detail_job (แบบ B2) — แท็บแยกเครื่อง (สถานะ/ความคืบหน้าในการ์ดใบงานเปลี่ยนตามแท็บ), หน้าต่างประวัติซ่อม, สลับช่องหัวใบงานตอนแก้ไข
 * ปุ่มแก้ไขข้อมูลแต่ละส่วนยังใช้ js/detail_job-field-edit-modal.js และ js/detail_job-page.js เดิม
 */
(function (global) {
	'use strict';
	var doc = global.document;

	function all(sel, root) {
		return Array.prototype.slice.call((root || doc).querySelectorAll(sel));
	}

	// ---------- แท็บเครื่อง ----------
	function showMachine(k) {
		var n = all('[data-dj-tab]').length;
		if (k < 0 || k >= n) { k = 0; }
		all('[data-dj-tab]').forEach(function (b) {
			var on = Number(b.getAttribute('data-dj-tab')) === k;
			b.classList.toggle('is-on', on);
			b.setAttribute('aria-selected', on ? 'true' : 'false');
		});
		all('[data-dj-m]').forEach(function (el) {
			el.hidden = Number(el.getAttribute('data-dj-m')) !== k;
		});
		if (global.history && global.history.replaceState) {
			global.history.replaceState(null, '', k > 0 ? '#m' + (k + 1) : global.location.pathname + global.location.search);
		}
		// จำแท็บไว้ กลับมาหน้าเดิมหลังบันทึก (เช่น Comment ที่ redirect กลับโดยไม่มี #m)
		try { global.sessionStorage.setItem(tabKey(), String(k)); } catch (e) { /* ignore */ }
	}

	function tabKey() {
		var cfg = global.MN_DETAIL_JOB || {};
		return 'mn_dj_tab_' + (cfg.tsId || '');
	}

	// ---------- หน้าต่างประวัติซ่อม ----------
	var openHp = null;

	function closeHistory() {
		if (!openHp) { return; }
		openHp.hidden = true;
		openHp = null;
		doc.body.classList.remove('mn-dj2-hp-open');
	}

	function openHistory(k) {
		var hp = doc.querySelector('[data-dj-hp="' + k + '"]');
		if (!hp) { return; }
		closeHistory();
		hp.hidden = false;
		openHp = hp;
		doc.body.classList.add('mn-dj2-hp-open');
		hp.scrollTop = 0;
		// ซ่อนปุ่ม "ดูทั้งหมด" ถ้าข้อความสั้นอยู่แล้ว
		all('.mn-dj2-clamp', hp).forEach(function (c) {
			var more = c.nextElementSibling;
			if (more && more.hasAttribute('data-dj-more') && c.scrollHeight <= c.clientHeight + 2) { more.hidden = true; }
		});
		var x = hp.querySelector('[data-dj-hp-close]');
		if (x) { x.focus(); }
	}

	doc.addEventListener('click', function (e) {
		var t = e.target;
		var tab = t.closest('[data-dj-tab]');
		if (tab) { showMachine(Number(tab.getAttribute('data-dj-tab'))); return; }
		var hb = t.closest('[data-dj-hist]');
		if (hb && !hb.disabled) { openHistory(hb.getAttribute('data-dj-hist')); return; }
		if (t.closest('[data-dj-hp-close]') || (openHp && t === openHp)) { closeHistory(); return; }
		var more = t.closest('[data-dj-more]');
		if (more) {
			var c = more.previousElementSibling;
			if (c) { c.classList.remove('mn-dj2-clamp'); }
			more.hidden = true;
			return;
		}
		// แก้ไขหัวใบงาน: แสดงช่องกรอกแทนข้อความ (บันทึก = ส่งฟอร์มแล้วโหลดหน้าใหม่)
		if (t.closest('#mn-dj-header-edit-btn')) { doc.body.classList.add('mn-dj2-editing'); return; }
		if (t.closest('#mn-dj-header-cancel-btn')) { doc.body.classList.remove('mn-dj2-editing'); }
	});

	doc.addEventListener('keydown', function (e) {
		if (e.key === 'Escape' && openHp) { closeHistory(); }
	});

	function init() {
		var m = /^#m(\d+)$/.exec(global.location.hash || '');
		var k = m ? Number(m[1]) - 1 : 0;
		if (!m) {
			try { k = Number(global.sessionStorage.getItem(tabKey()) || 0) || 0; } catch (e) { k = 0; }
		}
		showMachine(k);
	}

	if (doc.readyState === 'loading') {
		doc.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})(typeof window !== 'undefined' ? window : this);
