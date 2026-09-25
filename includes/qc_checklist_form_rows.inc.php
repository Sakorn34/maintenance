<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Markup หน้า QC checklist (include จาก `qc_checklist.php` เท่านั้น) — เลย์เอาต์การ์ด + กริดตามธีม mn-*
 *
 * ตัวแปรที่ต้องมีใน scope ก่อน include:
 * - array $result_trp, array $result_ts
 * - string $detail, string $list_repair (HTML บางส่วนมี tag อยู่แล้ว)
 * - string $history_html จาก `maintenance_qc_checklist_history_html()` — ครั้งที่ 1, 2, … ตาม S/N; ห้ามห่อ htmlspecialchars ซ้ำทั้งก้อน
 * - string $mn_logistic_recorder_value จาก `bootstrap_logistic_recorder.php` (ชื่อเจ้าหน้าที่ QC ตามผู้ login)
 */
?>
<div class="mn-qc-stack">
  <div class="mn-qc-toolbar">
    <a class="mn-qc-toolbar__link" href="status_wait_qc"><i class="fa fa-arrow-left" aria-hidden="true"></i> กลับรายการรอ QC</a>
  </div>

  <header class="mn-qc-hero">
    <h1 class="mn-qc-hero__title">QC หลังซ่อม</h1>
    <p class="mn-qc-hero__meta">
      <span class="mn-qc-hero__job">เลขที่ <?= htmlspecialchars((string) $result_ts['ts_mtn_number'], ENT_QUOTES, 'UTF-8'); ?></span>
      <span class="mn-qc-hero__product"><?= htmlspecialchars((string) $result_trp['trp_product'], ENT_QUOTES, 'UTF-8'); ?></span>
    </p>
  </header>

  <section class="mn-qc-card">
    <div class="mn-qc-card__head">
      <h2 class="mn-qc-card__title">บริษัท / ผู้ติดต่อ และเครื่อง</h2>
    </div>
    <div class="mn-qc-card__body mn-qc-card__body--prose">
      <div class="mn-qc-prose"><?= $detail; ?></div>
      <p class="mn-qc-inline-meta">
        <strong><?= htmlspecialchars((string) $result_trp['trp_product'], ENT_QUOTES, 'UTF-8'); ?></strong>
        <span class="mn-qc-sep">·</span>
        <span>S/N <?= htmlspecialchars((string) $result_trp['trp_sn'], ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="mn-qc-sep">·</span>
        <span class="mn-qc-tag mn-qc-tag--age">อายุเครื่อง <?= htmlspecialchars(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_repair_age']), ENT_QUOTES, 'UTF-8'); ?></span>
        <span class="mn-qc-tag mn-qc-tag--insurance"><?= htmlspecialchars(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_insurance']), ENT_QUOTES, 'UTF-8'); ?></span>
      </p>
    </div>
  </section>

  <section class="mn-qc-card mn-qc-card--split">
    <div class="mn-qc-split">
      <div class="mn-qc-split__col mn-qc-split__col--main">
        <h2 class="mn-qc-card__title mn-qc-card__title--inline">การแจ้งซ่อม</h2>
        <dl class="mn-qc-dl">
          <div class="mn-qc-dl__row"><dt>ผู้รับเรื่อง</dt><dd><?= htmlspecialchars((string) $result_trp['trp_user_recive_ma'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
          <div class="mn-qc-dl__row"><dt>อาการที่ลูกค้าแจ้ง</dt><dd><?= htmlspecialchars(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_repair_inform']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
          <div class="mn-qc-dl__row"><dt>ประเมินโดย / ซ่อมโดย</dt><dd><?= htmlspecialchars((string) $result_trp['trp_user_rate'], ENT_QUOTES, 'UTF-8'); ?> · <?= htmlspecialchars(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_user_ma']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
          <div class="mn-qc-dl__row mn-qc-dl__row--emph"><dt>อาการที่ช่างประเมิน</dt><dd><?= htmlspecialchars(maintenance_qc_checklist_db_text_to_utf8($result_trp['trp_repair_technician']), ENT_QUOTES, 'UTF-8'); ?></dd></div>
        </dl>
        <div class="mn-qc-list-repair"><?php echo $list_repair; ?></div>
      </div>
      <div class="mn-qc-split__col mn-qc-split__col--side">
        <h2 class="mn-qc-card__title mn-qc-card__title--inline">ประวัติซ่อม</h2>
        <div class="mn-qc-history"><?php echo $history_html; ?></div>
      </div>
    </div>
  </section>

  <section class="mn-qc-card">
    <div class="mn-qc-card__head">
      <h2 class="mn-qc-card__title">ผู้ตรวจ QC</h2>
    </div>
    <div class="mn-qc-card__body">
      <div class="mn-qc-meta-grid">
        <div class="mn-qc-field mn-qc-field--grow">
          <label class="mn-qc-label" for="trp_qc_date">วันที่ QC</label>
          <input class="mn-qc-input mn-qc-input--date" type="date" name="trp_qc_date" id="trp_qc_date" value="<?php echo htmlspecialchars(date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" />
        </div>
        <div class="mn-qc-field mn-qc-field--grow">
          <label class="mn-qc-label" for="trp_qc_user">เจ้าหน้าที่ QC</label>
          <input type="hidden" name="trp_qc_user" id="trp_qc_user" value="<?php echo htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8'); ?>" />
          <span class="mn-user-receive-display ex2"><?php echo $mn_logistic_recorder_value !== '' ? htmlspecialchars($mn_logistic_recorder_value, ENT_QUOTES, 'UTF-8') : '—'; ?></span>
        </div>
      </div>
    </div>
  </section>

  <section class="mn-qc-card mn-qc-card--checklist">
    <div class="mn-qc-card__head">
      <h2 class="mn-qc-card__title">รายการตรวจตามประเภทสินค้า</h2>
      <p class="mn-qc-card__lead">กรอกตามจริงแล้วกดบันทึก — ระบบจะส่งไปบันทึกที่ <code>update_wait_qc</code></p>
    </div>
    <div class="mn-qc-card__body">

<?php if ($result_trp['trp_product'] == 'bitVisitor Plus' || $result_trp['trp_product'] == 'bitVisitor M' || $result_trp['trp_product'] == 'bitVisitor Lite') { ?>
      <div class="mn-qc-band">
        <span class="mn-qc-badge mn-qc-badge--danger">HARDWARE</span>
        <span class="mn-qc-badge mn-qc-badge--accent">CAMERA</span>
      </div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vusbvolt"><i class="fa fa-angle-right" aria-hidden="true"></i> การจ่ายไฟช่อง USB</label><input class="mn-qc-input" type="text" name="qc_vusbvolt" id="qc_vusbvolt" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สถานะไฟ Lan</span><input type="checkbox" name="qc_vledlan" id="qc_vledlan" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> โฟกัสกล้อง A</span><input type="checkbox" name="qc_vcamerafocus" id="qc_vcamerafocus" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟ LED ถ่ายบัตร</span><input type="checkbox" name="qc_vledcard" id="qc_vledcard" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ระดับเสียงลำโพง</span><input type="checkbox" name="qc_vsoundlevel" id="qc_vsoundlevel" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Print test</span><input type="checkbox" name="qc_vtestprint" id="qc_vtestprint" value="" /></div>
      </div>
      <div class="mn-qc-subhead mn-qc-subhead--ok">DATA CONNECTION</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vfirmware"><i class="fa fa-angle-right" aria-hidden="true"></i> Firmware</label><input class="mn-qc-input" type="text" name="qc_vfirmware" id="qc_vfirmware" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vtimestart"><i class="fa fa-angle-right" aria-hidden="true"></i> เวลาเปิดเครื่อง</label>
          <select class="mn-qc-select custom-select" name="qc_vtimestart" id="qc_vtimestart">
							<option value="">Select</option>
							<option value="30 วินาที">30 วินาที</option>
							<option value="40 วินาที">40 วินาที</option>
							<option value="50 วินาที">50 วินาที</option>
							<option value="มากกว่า 1 นาที">มากกว่า 1 นาที</option>
				  </select>
        </div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vcpuuse"><i class="fa fa-angle-right" aria-hidden="true"></i> CPU temp. <em class="mn-qc-hint">*Plus/M</em></label><input class="mn-qc-input" type="text" name="qc_vcpuuse" id="qc_vcpuuse" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vramuse"><i class="fa fa-angle-right" aria-hidden="true"></i> RAM 743 mb <em class="mn-qc-hint">*Plus/M</em></label><input class="mn-qc-input" type="text" name="qc_vramuse" id="qc_vramuse" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ดึงข้อมูลผ่าน LAN</span><input type="checkbox" name="qc_vexportlan" id="qc_vexportlan" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ดึงข้อมูลผ่าน Flash drive</span><input type="checkbox" name="qc_vexportusb" id="qc_vexportusb" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Touch screen</span><input type="checkbox" name="qc_vtouch" id="qc_vtouch" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Sleep mode</span><input type="checkbox" name="qc_vsleepmode" id="qc_vsleepmode" value="" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vnoopenclose"><i class="fa fa-angle-right" aria-hidden="true"></i> เปิด-ปิด เครื่อง</label>
          <select class="mn-qc-select custom-select" name="qc_vnoopenclose" id="qc_vnoopenclose">
							<option value="">Select</option>
            <option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option>
          </select><span class="mn-qc-suffix">ครั้ง</span>
        </div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_vinout"><i class="fa fa-angle-right" aria-hidden="true"></i> IN-OUT</label>
          <select class="mn-qc-select custom-select" name="qc_vinout" id="qc_vinout">
							<option value="">Select</option>
            <option value="10">10</option><option value="20">20</option><option value="30">30</option><option value="40">40</option><option value="50">50</option>
          </select><span class="mn-qc-suffix">ครั้ง</span>
        </div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'bitScan Pro' || $result_trp['trp_product'] == 'bitScan Kit' || $result_trp['trp_product'] == 'bitScan Lite' || $result_trp['trp_product'] == 'bitScan AL' || $result_trp['trp_product'] == 'bitVehicle') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">BITSCAN / BITVEHICLE</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_sscan"><i class="fa fa-angle-right" aria-hidden="true"></i> ทดสอบการยิง</label>
          <select class="mn-qc-select custom-select" name="qc_sscan" id="qc_sscan"><option value="">select</option><option value="30">30</option><option value="40">40</option></select>
        </div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_sphasescan"><i class="fa fa-angle-right" aria-hidden="true"></i> ระยะการยิง</label>
          <select class="mn-qc-select custom-select" name="qc_sphasescan" id="qc_sphasescan"><option value="">select</option><option value="3-5 ซม.">3-5 ซม.</option><option value="มากกว่า 5 ซม.">มากกว่า 5 ซม.</option></select>
        </div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เสียง สั่น แสง</span><input type="checkbox" name="qc_ssound_shake_light" id="qc_ssound_shake_light" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> โหลดข้อมูล</span><input type="checkbox" name="qc_sloaddata" id="qc_sloaddata" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Smart Card') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Smart Card</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สถานะไฟ LED</span><input type="checkbox" name="qc_scled" id="qc_scled" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ความแน่นช่องเสียบบัตร</span><input type="checkbox" name="qc_scslotcard" id="qc_scslotcard" value="" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_screadcard"><i class="fa fa-angle-right" aria-hidden="true"></i> ทดสอบอ่านบัตร</label>
          <select class="mn-qc-select custom-select" name="qc_screadcard" id="qc_screadcard"><option value="">select</option><option value="10">10</option><option value="20">20</option></select>
        </div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Magnetic') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Magnetic</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สถานะไฟ LED</span><input type="checkbox" name="qc_mtled" id="qc_mtled" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เช็คปิด Track error</span><input type="checkbox" name="qc_mttrackerror" id="qc_mttrackerror" value="" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_mtreadcard"><i class="fa fa-angle-right" aria-hidden="true"></i> ทดสอบอ่านบัตร</label>
          <select class="mn-qc-select custom-select" name="qc_mtreadcard" id="qc_mtreadcard"><option value="">select</option><option value="10">10</option><option value="20">20</option></select>
        </div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Printer 57 mm.' || $result_trp['trp_product'] == 'Printer 80 mm.') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Printer</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Logo ตรงกับหน่วยงาน</span><input type="checkbox" name="qc_plogo" id="qc_plogo" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ความคมชัดสลิป</span><input type="checkbox" name="qc_psharpness" id="qc_psharpness" value="" /><span class="mn-qc-suffix">ชัดเจน</span></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เสียงขณะพิมพ์</span><input type="checkbox" name="qc_psoundprint" id="qc_psoundprint" value="" /><span class="mn-qc-suffix">ปกติ</span></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> มีสาย data มาด้วย</span><input type="checkbox" name="qc_pdatacable" id="qc_pdatacable" value="No" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สติ๊กเกอร์ล็อคสาย</span><input type="checkbox" name="qc_psticker_lock" id="qc_psticker_lock" value="" /><span class="mn-qc-suffix">ครบ</span></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Scanner') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Scanner</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟเข้า (แดง)</span><input type="checkbox" name="qc_snledred" id="qc_snledred" value="" /><span class="mn-qc-suffix">ติด</span></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟการอ่าน (ฟ้า)</span><input type="checkbox" name="qc_snledblue" id="qc_snledblue" value="" /><span class="mn-qc-suffix">ติด</span></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Jack RJ45</span><input type="checkbox" name="qc_snrj45" id="qc_snrj45" value="" /><span class="mn-qc-suffix">แน่น</span></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_snreadqr"><i class="fa fa-angle-right" aria-hidden="true"></i> อ่าน QR (PR)</label>
          <select class="mn-qc-select custom-select" name="qc_snreadqr" id="qc_snreadqr"><option value="">select</option><option value="10">10</option><option value="20">20</option></select><span class="mn-qc-suffix">ครั้ง</span>
        </div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Scanner Wireless') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Scanner Wireless</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สาย USB แน่น</span><input type="checkbox" name="qc_swcable" id="qc_swcable" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> หยอดกาว Port USB ฐาน</span><input type="checkbox" name="qc_swportusb" id="qc_swportusb" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ปุ่ม Reset (BD-6530BT)</span><input type="checkbox" name="qc_swresetbutton" id="qc_swresetbutton" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Auto pair หลัง Reset</span><input type="checkbox" name="qc_swresetpair" id="qc_swresetpair" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟ Scanner ครบ RGB</span><input type="checkbox" name="qc_swhandheldledrgb" id="qc_swhandheldledrgb" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟฐานรับครบ RGB</span><input type="checkbox" name="qc_swreseiveledrgb" id="qc_swreseiveledrgb" value="" /></div>
        <div class="mn-qc-field"><label class="mn-qc-label" for="qc_swreadqr"><i class="fa fa-angle-right" aria-hidden="true"></i> อ่าน QR (PR)</label>
          <select class="mn-qc-select custom-select" name="qc_swreadqr" id="qc_swreadqr"><option value="">select</option><option value="10">10</option><option value="20">20</option></select><span class="mn-qc-suffix">ครั้ง</span>
        </div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'bitSupply 125' || $result_trp['trp_product'] == 'bitSupply 1224') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">BIT SUPPLY</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เสียงเตือนปลั๊กหลุด/ไฟดับ</span><input type="checkbox" name="qc_bssoundalert" id="qc_bssoundalert" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ไฟ AC DC</span><input type="checkbox" name="qc_bsledacdc" id="qc_bsledacdc" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> การจ่ายไฟปกติ</span><input type="checkbox" name="qc_bspower" id="qc_bspower" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> จ่ายไฟตอน Test print</span><input type="checkbox" name="qc_bspowerprint" id="qc_bspowerprint" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Card Cam') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Card Cam</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> การ Focus</span><input type="checkbox" name="qc_ccled" id="qc_ccled" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> สถานะไฟ LED</span><input type="checkbox" name="qc_ccfocus" id="qc_ccfocus" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> มีแผ่นใสมาด้วย</span><input type="checkbox" name="qc_ccacrylic" id="qc_ccacrylic" value="No" /><span class="mn-qc-suffix">มี</span></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'bitStamp') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">BIT STAMP</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ความสว่าง LED</span><input type="checkbox" name="qc_bstled" id="qc_bstled" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> อ่านบัตร RFiD</span><input type="checkbox" name="qc_bstreadrfid" id="qc_bstreadrfid" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Port USB</span><input type="checkbox" name="qc_bstusb" id="qc_bstusb" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เทสเปิด-ปิด</span><input type="checkbox" name="qc_bstopenclose" id="qc_bstopenclose" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> เข้าโหมด Setting</span><input type="checkbox" name="qc_bstsettingmode" id="qc_bstsettingmode" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'A4 Camera') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">A4 Camera</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Fix Focus</span><input type="checkbox" name="qc_a4focus" id="qc_a4focus" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> มุมกล้อง</span><input type="checkbox" name="qc_a4position" id="qc_a4position" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Keyboard 84Key' || $result_trp['trp_product'] == 'Keyboard 128Key') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Keyboard</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ค่าปุ่มกดถูกต้อง</span><input type="checkbox" name="qc_kbbuttonvalue" id="qc_kbbuttonvalue" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ปุ่มกดไม่ยุบ</span><input type="checkbox" name="qc_kbbuttonok" id="qc_kbbuttonok" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Mobile') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Mobile</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> การโอนรูปภาพ</span><input type="checkbox" name="qc_mbtranferimg" id="qc_mbtranferimg" value="" /></div>
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Clear cache มือถือ</span><input type="checkbox" name="qc_mbclearcache" id="qc_mbclearcache" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'Router') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">Router</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Set OK</span><input type="checkbox" name="qc_rtsetok" id="qc_rtsetok" value="" /></div>
      </div>

<?php } elseif ($result_trp['trp_product'] == 'FaceCam Indoor' || $result_trp['trp_product'] == 'FaceCam Outdoor') { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">FaceCam</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> Auto Focus</span><input type="checkbox" name="qc_fcfocus" id="qc_fcfocus" value="" /></div>
      </div>
<?php } else { ?>
      <div class="mn-qc-subhead mn-qc-subhead--danger">สินค้าอื่น ๆ</div>
      <div class="mn-qc-field-grid">
        <div class="mn-qc-field"><span class="mn-qc-label"><i class="fa fa-angle-right" aria-hidden="true"></i> ผลการตรวจ QC</span><input type="checkbox" name="qc_checkok" id="qc_checkok" value="" /><span class="mn-qc-suffix">OK</span></div>
      </div>
<?php } ?>

    </div>
  </section>

  <section class="mn-qc-card mn-qc-card--actions">
    <div class="mn-qc-card__body">
      <div class="mn-qc-field mn-qc-field--block">
        <label class="mn-qc-label" for="qc_comment">Comment</label>
        <textarea class="mn-qc-textarea" name="qc_comment" id="qc_comment" rows="4"></textarea>
      </div>
      <div class="mn-qc-actions">
        <input type="hidden" id="trp_id" value="<?php echo (int) $result_trp['trp_id']; ?>" />
        <input type="hidden" id="qc_product_name" name="qc_product_name" value="<?php echo htmlspecialchars((string) $result_trp['trp_product'], ENT_QUOTES, 'UTF-8'); ?>" />
        <button type="button" class="mn-qc-btn mn-qc-btn--primary" id="mn-qc-checklist-save" title="บันทึก">
          <i class="fa fa-save" aria-hidden="true"></i> บันทึกผล QC
        </button>
      </div>
    </div>
  </section>
</div>
