<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * หน้ารายละเอียดใบงานตาม `ts_id` — อ้างอิง layout / ฟิลด์จาก `old_system/detail_job.php`
 * (transac + transac_repair, ลูปรายการซ่อม/สำรอง, comment, copy tracking) ใช้ theme mn-* และ helper เดียวกับหน้า maintenance ใหม่
 *
 * Flow:
 * GET `ts_id` → bootstrap → SELECT join → ตรวจล็อกหัวใบงาน (มีแถวซ่อม `trp_status_job = เสร็จสิ้น` ห้ามแก้หัว) / ล็อกรายการซ่อมรายแถวเมื่อสถานะรายการนั้นเป็นเสร็จสิ้น → แสดงหัวข้อ transac → ลูป repair → ปิด mysqli หลัง render
 */

date_default_timezone_set('Asia/Bangkok');
require_once __DIR__ . '/includes/require_auth.php';
header('Content-type: text/html; charset=utf-8');
require_once __DIR__ . '/includes/bootstrap_detail_job_page.php';
require_once __DIR__ . '/includes/status_logistic_helpers.php';
require_once __DIR__ . '/includes/maintenance_flow_sql_helpers.php';
require_once __DIR__ . '/includes/status_wait_hardware_helpers.php';
require_once __DIR__ . '/includes/detail_job_edit_helpers.php';
require_once __DIR__ . '/includes/bootstrap_logistic_recorder.php';

$ts_id = isset($_GET['ts_id']) ? (int) $_GET['ts_id'] : 0;
if ($ts_id <= 0) {
    maintenance_db_odbc_close($connect);
    header('Location: index');
    exit;
}

$sql_main = 'SELECT * FROM transac INNER JOIN transac_repair ON transac.ts_id = transac_repair.trp_ts_id '
    . 'WHERE transac_repair.trp_ts_id = ' . $ts_id;
$exe_main = maintenance_db_odbc_exec($connect, $sql_main);
if (!$exe_main) {
    maintenance_db_odbc_close($connect);
    exit('Error Query [' . $sql_main . ']: ' . maintenance_db_odbc_errormsg($connect));
}

$array1 = array();
$sql_pn = 'SELECT pn_name FROM product_name ORDER BY pn_name ASC';
$exe_pn = maintenance_db_odbc_exec($connect, $sql_pn);
if ($exe_pn) {
    while ($rpn = maintenance_db_odbc_fetch_array($exe_pn)) {
        if (is_array($rpn) && isset($rpn['pn_name']) && (string) $rpn['pn_name'] !== '') {
            $array1[] = (string) $rpn['pn_name'];
        }
    }
}
if (count($array1) === 0) {
    $sql_pn2 = 'SELECT pn_name FROM product_name ORDER BY pn_name ASC';
    $exe_pn2 = maintenance_db_odbc_exec($connect, $sql_pn2);
    if ($exe_pn2) {
        while ($rpn2 = maintenance_db_odbc_fetch_array($exe_pn2)) {
            if (is_array($rpn2) && isset($rpn2['pn_name']) && (string) $rpn2['pn_name'] !== '') {
                $array1[] = (string) $rpn2['pn_name'];
            }
        }
    }
}
$arr_length1 = count($array1);
$dj_product_names_utf8 = array();
foreach ($array1 as $pn_raw) {
    $dj_product_names_utf8[] = maintenance_flow_db_text_to_utf8($pn_raw);
}
require_once __DIR__ . '/includes/logistics_helpers.php';
$dj_logistics_utf8 = maintenance_detail_job_list_logistics_utf8($connect);
$dj_logistics_tracking_map = maintenance_logistics_fetch_tracking_url_map($connect);

$dj_header_edit_locked = maintenance_detail_job_header_locked($connect, $ts_id);

$show = false;
$i = 0;
$m = 0;
$has_any = false;
$dj_page_title_mtn = '';

ob_start();
while ($outer = maintenance_db_odbc_fetch_array($exe_main)) {
    $has_any = true;
    if ($show === false) {
        $show = true;
        $dj_page_title_mtn = (string) maintenance_status_logistic_row($outer, 'ts_mtn_number');
        $addr_raw = (string) maintenance_status_logistic_row($outer, 'ts_address');
        ?>
      <tr>
        <td colspan="4" align="center" style="font-size:x-large;"><b>งาน : &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_mtn_number')); ?></b><br /></td>
      </tr>
      <tr><td colspan="4"><hr /></td></tr>
      <tr class="mn-dj-header-row">
        <td colspan="2" width="50%"><b class="ex2">วันที่รับเรื่อง : </b> &nbsp;
          <input type="date" name="ts_date" id="ts_date" class="mn-dj-header-field" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_date')); ?>" style="width:auto;" readonly="readonly" />
        </td>
        <td colspan="2" width="50%">
          <b class="ex2">ผู้รับเรื่อง : </b> &nbsp;
          <span class="mn-dj-readonly"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_user_recive')); ?></span>
        </td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="2">
          <div class="search-box-user"><b>บริษัท End user/สถานที่</b>
            <input type="text" autocomplete="off" name="ts_enduser_com" id="ts_enduser_com" class="mn-dj-header-field" style="width:310px;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_enduser_com')); ?>" readonly="readonly" />
            <div class="result"></div>
          </div>
        </td>
        <td colspan="2">
          <div class="search-box-sec"><b>บริษัท รปภ.</b>
            <input type="text" autocomplete="off" name="ts_security_com" id="ts_security_com" class="mn-dj-header-field" style="width:400px;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_security_com')); ?>" readonly="readonly" />
            <div class="result_sec"></div>
          </div>
        </td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="4"><b>ผู้แจ้ง : </b></td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="4">
          <p style="display:inline"><b>Name </b><input type="text" name="ts_report_name" id="ts_report_name" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_report_name')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b> Tel </b><input type="text" name="ts_report_tel" id="ts_report_tel" class="mn-dj-header-field" style="width:190px;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_report_tel')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b>Line </b><input type="text" name="ts_report_line" id="ts_report_line" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_report_line')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b>Email </b><input type="text" name="ts_report_email" id="ts_report_email" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_report_email')); ?>" readonly="readonly" /></p>
        </td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="4"><b>ผู้อนุมัติ : </b></td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="4">
          <p style="display:inline"><b>Name </b><input type="text" name="ts_approve_name" id="ts_approve_name" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_approve_name')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b> Tel </b><input type="text" name="ts_approve_tel" id="ts_approve_tel" class="mn-dj-header-field" style="width:190px;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_approve_tel')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b>Line </b><input type="text" name="ts_approve_line" id="ts_approve_line" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_approve_line')); ?>" readonly="readonly" />
            &nbsp; &nbsp; <b>Email </b><input type="text" name="ts_approve_email" id="ts_approve_email" class="mn-dj-header-field" style="width:auto;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_approve_email')); ?>" readonly="readonly" /></p>
        </td>
      </tr>
      <tr class="mn-dj-header-row">
        <td colspan="4">
          <div class="search-box-tambon"><b>ที่อยู่</b>
            <input type="text" name="ts_address2" id="ts_address2" class="mn-dj-header-field" placeholder="ค้นหา ตำบล" style="width:100%;max-width:925px;box-sizing:border-box;" value="<?php echo maintenance_status_logistic_h($addr_raw); ?>" readonly="readonly" />
            <div class="result_tambon"></div>
          </div>
        </td>
      </tr>
      <tr class="mn-dj-header-row"><td colspan="4"><b>หมายเหตุ </b><input type="text" name="ts_remarks" id="ts_remarks" class="mn-dj-header-field" style="width:100%;max-width:925px;box-sizing:border-box;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'ts_remarks')); ?>" readonly="readonly" /></td></tr>
      <tr id="mn-dj-header-actions-row" class="mn-dj-header-row">
        <td colspan="4">
          <?php if ($dj_header_edit_locked) { ?>
            <span class="mn-dj-header-locked-msg">งานซ่อมสถานะ "เสร็จสิ้น" แล้ว — ไม่สามารถแก้ไขข้อมูลได้</span>
          <?php } else { ?>
            <div id="mn-dj-header-actions-wrap" class="mn-dj-header-actions-wrap">
              <button type="button" id="mn-dj-header-edit-btn" class="mn-dj-header-edit-btn"><i class="fa fa-edit" aria-hidden="true"></i> แก้ไขข้อมูล</button>
              <span id="mn-dj-header-edit-actions" class="mn-dj-header-edit-actions" aria-hidden="true">
                <button type="button" id="mn-dj-header-save-btn" class="mn-dj-header-save-btn"><i class="fa fa-save" aria-hidden="true"></i> บันทึก</button>
                <button type="button" id="mn-dj-header-cancel-btn" class="mn-dj-header-cancel-btn"><i class="fa fa-times" aria-hidden="true"></i> ยกเลิก</button>
              </span>
            </div>
          <?php } ?>
        </td>
      </tr>

      <tr><td colspan="4"><hr /></td></tr>
      <tr>
        <td><b>เครื่อง </b></td>
        <td align="left"></td>
        <td colspan="2"></td>
      </tr>
        <?php
    }

    $temp_spare = '';
    $tsp_datesend = '';
    $tsp_logistics = '';
    $tsp_tracking = '';
    $tsp_sendby = '';
    $tsp_datereturn = '';
    $tsp_receiveby = '';
    $dj_show_early_spare_return = false;
    $dj_tsp_datereturn_display = '';
    $dj_tsp_datereturn_input = '';
    $dj_tsp_receiveby_current = '';

    $trp_id_outer = (int) maintenance_status_logistic_row($outer, 'trp_id', 0);
    $sqlch_sp = 'SELECT tsp_trp_id FROM transac_spare WHERE tsp_trp_id = ' . $trp_id_outer;
    $exech_sp = maintenance_db_odbc_exec($connect, $sqlch_sp);
    $resultch_sp = $exech_sp ? maintenance_db_odbc_fetch_array($exech_sp) : false;
    $has_spare = is_array($resultch_sp) && isset($resultch_sp['tsp_trp_id']) && (string) $resultch_sp['tsp_trp_id'] !== '';

    $sql2 = 'SELECT * FROM transac_repair WHERE trp_id = ' . $trp_id_outer;
    if ($has_spare) {
        $sql2 = 'SELECT * FROM transac_repair INNER JOIN transac_spare ON transac_repair.trp_id = transac_spare.tsp_trp_id '
            . 'WHERE transac_spare.tsp_trp_id = ' . $trp_id_outer;
    }
    $exe2 = maintenance_db_odbc_exec($connect, $sql2);
    if (!$exe2) {
        echo '</tbody></table><p>Error Query [' . htmlspecialchars($sql2, ENT_QUOTES, 'UTF-8') . ']</p>';
        maintenance_db_odbc_close($connect);
        $connect = null;
        break;
    }

    $trp_product_outer = (string) maintenance_status_logistic_row($outer, 'trp_product');

    while ($inner = maintenance_db_odbc_fetch_array($exe2)) {
        $i += 1;
        $m += 1;
        $n = (string) $i . (string) $m;
        $trp_product_inner = (string) maintenance_status_logistic_row($inner, 'trp_product');
        if ($temp_spare !== $trp_product_inner) {
            $temp_spare = $trp_product_inner;
            $trp_id_inner = (int) maintenance_status_logistic_row($inner, 'trp_id', 0);
            $dj_trp_status_job = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_status_job')));
            $dj_repair_row_edit_locked = maintenance_detail_job_repair_row_locked_from_status($dj_trp_status_job);
            $dj_trp_qv_number = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qv_number')));
            $dj_trp_qv_date = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qv_date')));
            $dj_trp_qv_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_qv_date'));
            $dj_trp_qv_price = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qv_price')));
            $dj_trp_qv_send = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qv_send')));
            $dj_trp_firm_qv_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_firm_qv_date')));
            $dj_trp_firm_qv_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_firm_qv_date'));
            $dj_trp_status_firmma = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_status_firmma')));
            $dj_repair_ma_approved_locked = maintenance_detail_job_repair_ma_approved_locked(
                maintenance_status_logistic_row($inner, 'trp_firm_qv_date'),
                $dj_trp_status_job,
                $dj_trp_status_firmma
            );
            $dj_repair_rate_edit_locked = maintenance_detail_job_repair_quote_offered($dj_trp_qv_number, $dj_trp_qv_date);
            $dj_trp_product_current = trim(maintenance_flow_db_text_to_utf8($trp_product_inner));
            $dj_trp_sn_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_sn')));
            $dj_trp_repair_inform_current = maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_repair_inform'));
            $dj_trp_buy_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_buy_date')));
            $dj_trp_buy_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_buy_date'));
            $dj_tsp_id_inner = 0;
            $dj_tsp_datesend_display = '';
            $dj_tsp_datesend_input = '';
            $dj_tsp_logistics_current = '';
            $dj_tsp_sendby_current = '';
            $dj_spare_send_has_data = false;
            $dj_tsp_sn_current = '';
            $dj_trp_receive_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_receive_date')));
            $dj_trp_receive_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_receive_date'));
            $dj_trp_user_recive_ma_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_user_recive_ma')));
            $dj_trp_success_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_success_date')));
            $dj_trp_success_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_success_date'));
            $dj_trp_user_ma_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_user_ma')));
            $dj_repair_qc_done_locked = maintenance_detail_job_repair_qc_done_locked(
                maintenance_status_logistic_row($inner, 'trp_qc_date')
            );
            $dj_trp_qc_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qc_date')));
            $dj_trp_qc_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_qc_date'));
            $dj_trp_qc_user_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qc_user')));
            $dj_trp_qc_comment_current = maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_qc_comment'));
            $dj_repair_returned_locked = maintenance_detail_job_repair_returned_locked(
                maintenance_status_logistic_row($inner, 'trp_send_date')
            );
            $dj_trp_send_date_display = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_send_date')));
            $dj_trp_send_date_input = maintenance_detail_job_buy_date_for_html_input(maintenance_status_logistic_row($inner, 'trp_send_date'));
            $dj_trp_logistics_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_logistics')));
            $dj_trp_tracking_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_tracking')));
            $dj_trp_sendby_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($inner, 'trp_sendby')));
            $dj_spare_returned_locked = maintenance_detail_job_spare_returned_locked_db($connect, $trp_id_inner);
            $dj_machine_has_data = maintenance_detail_job_group_has_machine_data(
                $dj_trp_product_current,
                $dj_trp_sn_current,
                $dj_trp_repair_inform_current
            );
            $dj_rate_has_data = maintenance_detail_job_group_has_rate_data($inner);
            $dj_receive_has_data = maintenance_detail_job_date_has_data(
                maintenance_status_logistic_row($inner, 'trp_receive_date')
            );
            $dj_qv_has_data = maintenance_detail_job_group_has_qv_data($dj_trp_qv_number, $dj_trp_qv_date);
            $dj_ma_has_data = maintenance_detail_job_date_has_data(
                maintenance_status_logistic_row($inner, 'trp_success_date')
            );
            $dj_qc_has_data = maintenance_detail_job_group_has_qc_data(
                maintenance_status_logistic_row($inner, 'trp_qc_date'),
                maintenance_status_logistic_row($inner, 'trp_qc_user')
            );
            $dj_return_has_data = maintenance_detail_job_date_has_data(
                maintenance_status_logistic_row($inner, 'trp_send_date')
            );
            ?>
      <tr valign="top">
        <td>
          <b>No. <?php echo (int) $i; ?> </b>
          <a class="mn-dj-process-link" href="job_process?trp_id=<?php echo (int) $trp_id_inner; ?>" title="ดูความคืบหน้าแนวตั้ง">ความคืบหน้า</a>
          <span class="mn-dj-inline-field-wrap mn-dj-product-field-wrap">
            <select class="custom-select mn-dj-product-select" id="pd_mtn<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" name="pd_mtn1<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" style="width:150px;" disabled="disabled">
              <option value="<?php echo maintenance_status_logistic_h($dj_trp_product_current); ?>" selected="selected"><?php echo maintenance_status_logistic_h($dj_trp_product_current); ?></option>
              <?php
              for ($a = 0; $a < $arr_length1; $a++) {
                  $pnv_utf8 = maintenance_flow_db_text_to_utf8($array1[$a]);
                  if ($pnv_utf8 === $dj_trp_product_current) {
                      continue;
                  }
                  ?>
              <option value="<?php echo maintenance_status_logistic_h($pnv_utf8); ?>"><?php echo maintenance_status_logistic_h($pnv_utf8); ?></option>
              <?php } ?>
            </select>
          </span>
        </td>
        <td>
          <b>S/N ซ่อม </b>
          <span class="mn-dj-inline-field-wrap">
            <input type="text" name="sn_mtn<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" id="sn_mtn<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" class="mn-dj-sn-mtn-input" style="width:150px;" value="<?php echo maintenance_status_logistic_h($dj_trp_sn_current); ?>" readonly="readonly" />
          </span>
        </td>
            <?php
            $dj_edit_spare_base_q = null;
            $dj_spare_returned_locked_trp = maintenance_detail_job_spare_returned_locked_db($connect, $trp_id_inner);
            if (!$dj_repair_row_edit_locked && !$dj_spare_returned_locked_trp) {
                $dj_spare_detail_plain = maintenance_wait_hardware_contact_plain_for_spare($inner);
                $dj_spare_product = trim(maintenance_flow_db_text_to_utf8($trp_product_inner));
                $dj_edit_spare_base_q = 'trp_id=' . (int) $trp_id_inner
                    . '&trp_ts_id=' . (int) $ts_id
                    . '&trp_product=' . rawurlencode($dj_spare_product)
                    . '&trp_detail=' . rawurlencode(str_replace(array("\r", "\n"), ' ', $dj_spare_detail_plain));
            }
            $sql3 = 'SELECT * FROM transac_spare WHERE tsp_trp_id = ' . $trp_id_inner;
            $exe3 = maintenance_db_odbc_exec($connect, $sql3);
            if (!$exe3) {
                echo '<td></td>';
            } else {
                $l = 0;
                $sp_any = false;
                while ($sp = maintenance_db_odbc_fetch_array($exe3)) {
                    $sp_any = true;
                    if ($l === 0) {
                        $l = 1;
                        $dj_tsp_id_inner = (int) maintenance_status_logistic_row($sp, 'tsp_id', 0);
                        $tsp_datesend = (string) maintenance_status_logistic_row($sp, 'tsp_datesend');
                        $tsp_logistics = (string) maintenance_status_logistic_row($sp, 'tsp_logistics');
                        $tsp_tracking = (string) maintenance_status_logistic_row($sp, 'tsp_tracking');
                        $tsp_sendby = (string) maintenance_status_logistic_row($sp, 'tsp_sendby');
                        $dj_tsp_datesend_display = trim(maintenance_flow_db_text_to_utf8($tsp_datesend));
                        $dj_tsp_datesend_input = maintenance_detail_job_buy_date_for_html_input($tsp_datesend);
                        $dj_tsp_logistics_current = trim(maintenance_flow_db_text_to_utf8($tsp_logistics));
                        $dj_tsp_sendby_current = trim(maintenance_flow_db_text_to_utf8($tsp_sendby));
                        $dj_spare_send_has_data = maintenance_detail_job_date_has_data($tsp_datesend);
                        $dj_tsp_sn_current = trim(maintenance_flow_db_text_to_utf8(maintenance_status_logistic_row($sp, 'tsp_sn')));
                        $tsp_datereturn = (string) maintenance_status_logistic_row($sp, 'tsp_datereturn');
                        $tsp_receiveby = (string) maintenance_status_logistic_row($sp, 'tsp_receiveby');
                        $dj_tsp_datereturn_display = trim(maintenance_flow_db_text_to_utf8($tsp_datereturn));
                        $dj_tsp_datereturn_input = maintenance_detail_job_buy_date_for_html_input($tsp_datereturn);
                        $dj_tsp_receiveby_current = trim(maintenance_flow_db_text_to_utf8($tsp_receiveby));
                    }
                    $sp_row_tsp_id = (int) maintenance_status_logistic_row($sp, 'tsp_id', 0);
                    $sp_row_datesend = (string) maintenance_status_logistic_row($sp, 'tsp_datesend');
                    $sp_row_datereturn = (string) maintenance_status_logistic_row($sp, 'tsp_datereturn');
                    $sp_row_receiveby = (string) maintenance_status_logistic_row($sp, 'tsp_receiveby');
                    $sp_row_send_has_data = maintenance_detail_job_date_has_data($sp_row_datesend);
                    $sp_row_returned_locked = maintenance_detail_job_spare_returned_locked($sp_row_datereturn);
                    $dj_show_early_spare_return = maintenance_detail_job_should_show_early_spare_return_link(
                        $dj_repair_row_edit_locked,
                        $dj_repair_returned_locked,
                        $sp_row_tsp_id,
                        $sp_row_send_has_data,
                        $sp_row_returned_locked
                    );
                    $dj_show_early_spare_return_done = maintenance_detail_job_should_show_early_spare_return_done_notice(
                        $dj_repair_row_edit_locked,
                        $dj_repair_returned_locked,
                        $sp_row_returned_locked
                    );
                    $sp_row_datereturn_display = trim(maintenance_flow_db_text_to_utf8($sp_row_datereturn));
                    $sp_row_datereturn_input = maintenance_detail_job_buy_date_for_html_input($sp_row_datereturn);
                    $sp_row_receiveby_current = trim(maintenance_flow_db_text_to_utf8($sp_row_receiveby));
                    ?>
        <td><b>S/N สำรอง </b> <input type="text" name="sn_sp<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" id="sn_sp<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" style="width:150px;" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($sp, 'tsp_sn')); ?>" readonly="readonly" /><br>
                    <?php
                    if (!$dj_repair_row_edit_locked && $dj_edit_spare_base_q !== null && !$sp_row_returned_locked) {
                        $tsp_sn_for_edit = trim((string) maintenance_status_logistic_row($sp, 'tsp_sn'));
                        if ($tsp_sn_for_edit !== '') {
                            $dj_edit_spare_href = 'edit_spare?' . $dj_edit_spare_base_q
                                . '&tsp_sn=' . rawurlencode(maintenance_flow_db_text_to_utf8($tsp_sn_for_edit));
                            ?>
          <a class="mn-dj-edit-spare-link" href="<?php echo htmlspecialchars($dj_edit_spare_href, ENT_QUOTES, 'UTF-8'); ?>" title="เปลี่ยนเครื่องสำรอง">[เปลี่ยนเครื่องสำรอง]</a>
                            <?php
                        }
                    }
                    ?>
          <?php if ($dj_show_early_spare_return) { ?>
          <br />
          <button type="button" class="mn-dj-spare-early-return-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-tsp-id="<?php echo (int) $sp_row_tsp_id; ?>"
            data-datereturn="<?php echo maintenance_status_logistic_h($sp_row_datereturn_input); ?>"
            data-datereturn-display="<?php echo maintenance_status_logistic_h($sp_row_datereturn_display); ?>"
            data-receiveby="<?php echo maintenance_status_logistic_h($sp_row_receiveby_current !== '' ? $sp_row_receiveby_current : $mn_logistic_recorder_value); ?>">ลูกค้าคืนสำรองก่อนปิดงาน</button>
          <?php } elseif ($dj_show_early_spare_return_done) { ?>
          <br />
          <span class="mn-dj-spare-early-return-done">ส่งสำรองคืนแล้วก่อนปิดงาน</span>
          <?php } ?>
        </td>
                    <?php
                }
                if (!$sp_any) {
                    ?>
        <td class="mn-dj-spare-actions">
                    <?php if ($dj_edit_spare_base_q !== null) { ?>
          <a class="mn-dj-edit-spare-btn" href="<?php echo htmlspecialchars('edit_spare?' . $dj_edit_spare_base_q, ENT_QUOTES, 'UTF-8'); ?>" title="เพิ่มเครื่องสำรองให้รายการซ่อมนี้">เพิ่มเครื่องสำรอง</a>
                    <?php } else { ?>
          <span>—</span>
                    <?php } ?>
        </td>
                    <?php
                }
            }
            ?>
      </tr>
      <tr>
        <td align="left" colspan="4" style="color:blue;">
          <b style="color:#666666;">อาการ : </b>
          <span class="mn-dj-symptom-text"><?php echo maintenance_status_logistic_h($dj_trp_repair_inform_current); ?></span>
          <br />
          <b style="color:#666666;">วันที่ซื้อ </b> <?php echo maintenance_status_logistic_h($dj_trp_buy_date_display); ?> <b style="color:#666666;"> อายุเครื่อง </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($inner, 'trp_repair_age')); ?> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_insurance')); ?>
        </td>
      </tr>
      <?php if (!$dj_repair_row_edit_locked && $dj_machine_has_data) { ?>
      <tr class="mn-dj-repair-machine-edit-row">
        <td colspan="4" align="left">
          <button type="button" class="mn-dj-repair-machine-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-product="<?php echo maintenance_status_logistic_h($dj_trp_product_current); ?>"
            data-sn="<?php echo maintenance_status_logistic_h($dj_trp_sn_current); ?>"
            data-inform="<?php echo maintenance_status_logistic_h($dj_trp_repair_inform_current); ?>"
            data-buy-date="<?php echo maintenance_status_logistic_h($dj_trp_buy_date_input); ?>"
            data-buy-date-display="<?php echo maintenance_status_logistic_h($dj_trp_buy_date_display); ?>">แก้ไขข้อมูลเครื่องซ่อม</button>
        </td>
      </tr>
      <?php } ?>
      <tr>
        <td align="left" colspan="4" style="color:blue;">
          <b style="color:#666666;">วันที่ประเมิน : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_rate_date')); ?> <b style="color:#666666;">ผู้ประเมิน : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_user_rate')); ?><br />
          <b style="color:#666666;">ข้อมูลช่างประเมิน : </b>
          <textarea name="trp_repair_technician<?php echo (int) $i; ?>" id="trp_repair_technician<?php echo (int) $i; ?>" cols="100%" rows="3" readonly="readonly"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($inner, 'trp_repair_technician')); ?></textarea><br />
          <b style="color:#666666;">ทำความสะอาด : </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_clean')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repeat_solder')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_spray')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_fw')); ?>
          &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no1')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no2')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no3')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no4')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no5')); ?>
          &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no6')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no7')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no8')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no9')); ?> &nbsp; <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_no10')); ?> <br />
          <b style="color:#666666;">หมายเหตุซ่อม : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_repair_remarks')); ?><br />
            <?php if (!$dj_repair_row_edit_locked && !$dj_repair_rate_edit_locked && $trp_id_inner > 0 && $dj_rate_has_data) { ?>
          <a class="mn-dj-edit-rate-btn" href="update_rate?trp_id=<?php echo (int) $trp_id_inner; ?>">แก้ไขงานประเมินซ่อม</a><br />
            <?php } ?>
          <br />

          <b style="color:#666666;">วันที่ส่งสำรอง </b> &nbsp; <?php echo maintenance_status_logistic_h($tsp_datesend); ?>&nbsp; &nbsp; &nbsp;
          <b style="color:#666666;">ขนส่ง </b><?php echo maintenance_status_logistic_h($tsp_logistics); ?><b style="color:#666666;"> Tracking </b>
          <input id="tracking_<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h($tsp_tracking); ?>" style="border:none; width:auto; color:#0000FF; text-decoration:underline; cursor:pointer;" onclick="copy_text(this.id, <?php echo htmlspecialchars(json_encode($tsp_logistics, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);" title="คลิกเพื่อ copy และเปิดหน้า track ขนส่ง" readonly="readonly" />
          <b style="color:#666666;">ผู้ส่ง </b><?php echo maintenance_status_logistic_h($tsp_sendby); ?>
            <?php
            $dj_spare_sn_has_data = maintenance_detail_job_text_has_data($dj_tsp_sn_current);
            if (!$dj_repair_row_edit_locked && $dj_tsp_id_inner > 0 && ($dj_spare_send_has_data || $dj_spare_sn_has_data)) {
                $dj_spare_send_btn_label = $dj_spare_send_has_data
                    ? 'แก้ไขวันที่/ขนส่ง/Tracking สำรอง'
                    : 'บันทึกวันที่ส่งสำรอง';
                ?>
          <br />
          <button type="button" class="mn-dj-spare-send-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-tsp-id="<?php echo (int) $dj_tsp_id_inner; ?>"
            data-datesend="<?php echo maintenance_status_logistic_h($dj_tsp_datesend_input); ?>"
            data-datesend-display="<?php echo maintenance_status_logistic_h($dj_tsp_datesend_display); ?>"
            data-logistics="<?php echo maintenance_status_logistic_h($dj_tsp_logistics_current); ?>"
            data-sendby="<?php echo maintenance_status_logistic_h($dj_tsp_sendby_current !== '' ? $dj_tsp_sendby_current : $mn_logistic_recorder_value); ?>"><?php echo maintenance_status_logistic_h($dj_spare_send_btn_label); ?></button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">วันที่รับเครื่อง </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_receive_date')); ?> <b style="color:#666666;"> ผู้รับ </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_user_recive_ma')); ?>
            <?php if (!$dj_repair_row_edit_locked && !$dj_repair_rate_edit_locked && $trp_id_inner > 0 && $dj_receive_has_data) { ?>
          <br />
          <button type="button" class="mn-dj-repair-receive-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-receive-date="<?php echo maintenance_status_logistic_h($dj_trp_receive_date_input); ?>"
            data-receive-date-display="<?php echo maintenance_status_logistic_h($dj_trp_receive_date_display); ?>"
            data-user-recive-ma="<?php echo maintenance_status_logistic_h($dj_trp_user_recive_ma_current); ?>">แก้ไขวันที่รับเครื่องซ่อม</button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">ใบเสนอราคา : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qv_number')); ?>
          <b style="color:#666666;">วันที่เสนอราคา : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qv_date')); ?>
          <b style="color:#666666;">จำนวนเงิน : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qv_price')); ?>
          <b style="color:#666666;">วันที่ส่งเสนอราคา : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qv_send')); ?>
          <b style="color:#666666;">วันที่เสนอราคาแนน : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_firm_qv_date')); ?>
            <?php if (!$dj_repair_row_edit_locked && !$dj_repair_ma_approved_locked && $trp_id_inner > 0 && $dj_qv_has_data) { ?>
          <br />
          <button type="button" class="mn-dj-repair-qv-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-qv-number="<?php echo maintenance_status_logistic_h($dj_trp_qv_number); ?>"
            data-qv-date="<?php echo maintenance_status_logistic_h($dj_trp_qv_date_input); ?>"
            data-qv-date-display="<?php echo maintenance_status_logistic_h($dj_trp_qv_date); ?>"
            data-qv-price="<?php echo maintenance_status_logistic_h($dj_trp_qv_price); ?>"
            data-qv-send="<?php echo maintenance_status_logistic_h($dj_trp_qv_send); ?>"
            data-firm-qv-date="<?php echo maintenance_status_logistic_h($dj_trp_firm_qv_date_input); ?>"
            data-firm-qv-date-display="<?php echo maintenance_status_logistic_h($dj_trp_firm_qv_date_display); ?>">แก้ไขใบเสนอราคา</button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">วันที่ซ่อม : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_success_date')); ?>
          <b style="color:#666666;"> ช่างซ่อม : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_user_ma')); ?>
            <?php if (!$dj_repair_row_edit_locked && !$dj_repair_qc_done_locked && $trp_id_inner > 0 && $dj_ma_has_data) { ?>
          <br />
          <button type="button" class="mn-dj-repair-ma-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-success-date="<?php echo maintenance_status_logistic_h($dj_trp_success_date_input); ?>"
            data-success-date-display="<?php echo maintenance_status_logistic_h($dj_trp_success_date_display); ?>"
            data-user-ma="<?php echo maintenance_status_logistic_h($dj_trp_user_ma_current); ?>">แก้ไขวันที่ซ่อม/ช่างซ่อม</button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">วันที่ QC : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qc_date')); ?>
          <b style="color:#666666;"> QC : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qc_user')); ?><br />
          <b style="color:#666666;">Comment QC : </b> <?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_qc_comment')); ?>
            <?php if (!$dj_repair_row_edit_locked && !$dj_repair_returned_locked && $trp_id_inner > 0 && $dj_qc_has_data) { ?>
          <br />
          <button type="button" class="mn-dj-repair-qc-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-qc-date="<?php echo maintenance_status_logistic_h($dj_trp_qc_date_input); ?>"
            data-qc-date-display="<?php echo maintenance_status_logistic_h($dj_trp_qc_date_display); ?>"
            data-qc-user="<?php echo maintenance_status_logistic_h($dj_trp_qc_user_current); ?>"
            data-qc-comment="<?php echo maintenance_status_logistic_h($dj_trp_qc_comment_current); ?>">แก้ไขข้อมูล QC</button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">วันที่ส่งคืน : </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_send_date')); ?>
          <b style="color:#666666;">ขนส่ง : </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_logistics')); ?>
          <b style="color:#666666;">tracking : </b><input id="trackingMA_<?php echo (int) $i; ?>" value="<?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_tracking')); ?>" style="border:none; width:auto; color:#0000FF; text-decoration:underline; cursor:pointer;" onclick="copy_text(this.id, <?php echo htmlspecialchars(json_encode((string) maintenance_status_logistic_row($outer, 'trp_logistics'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8'); ?>);" title="คลิกเพื่อ copy และเปิดหน้า track ขนส่ง" readonly="readonly" />
          <b style="color:#666666;">ผู้ส่ง : </b><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($outer, 'trp_sendby')); ?>
            <?php if (!$dj_repair_row_edit_locked && !$dj_spare_returned_locked && $trp_id_inner > 0 && $dj_return_has_data) { ?>
          <br />
          <button type="button" class="mn-dj-repair-return-edit-btn"
            data-ts-id="<?php echo (int) $ts_id; ?>"
            data-trp-id="<?php echo (int) $trp_id_inner; ?>"
            data-send-date="<?php echo maintenance_status_logistic_h($dj_trp_send_date_input); ?>"
            data-send-date-display="<?php echo maintenance_status_logistic_h($dj_trp_send_date_display); ?>"
            data-logistics="<?php echo maintenance_status_logistic_h($dj_trp_logistics_current); ?>"
            data-tracking="<?php echo maintenance_status_logistic_h($dj_trp_tracking_current); ?>"
            data-sendby="<?php echo maintenance_status_logistic_h($dj_trp_sendby_current); ?>">แก้ไขข้อมูลส่งคืน</button>
            <?php } ?>
          <br /><br />

          <b style="color:#666666;">วันที่รับสำรองคืน : </b><?php echo maintenance_status_logistic_h($tsp_datereturn); ?>
          <b style="color:#666666;">ผู้รับ : </b><?php echo maintenance_status_logistic_h($tsp_receiveby); ?>

          <hr />
        </td>
      </tr>
      <tr class="mn-dj-comment-row">
        <td colspan="4">
          <div class="mn-dj-comment-block">
          <b class="mn-dj-comment-label">Comment :</b>
          <textarea name="trp_other_comment<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" id="trp_other_comment<?php echo htmlspecialchars($n, ENT_QUOTES, 'UTF-8'); ?>" class="mn-dj-comment-textarea" rows="4"><?php echo maintenance_status_logistic_h(maintenance_status_logistic_row($inner, 'trp_other_comment')); ?></textarea>
          <a href="javascript:void(0);" class="mn-dj-comment-save-btn"
            onclick="saveComment(<?php echo (int) maintenance_status_logistic_row($inner, 'trp_id', 0); ?>, <?php echo (int) $ts_id; ?>, <?php echo (int) $n; ?>);"
            title="บันทึก Comment"><i class="fa fa-save" aria-hidden="true"></i> บันทึก Comment</a>
          </div>
          <hr class="mn-dj-section-hr" />
        </td>
      </tr>
            <?php
        }
    }
}
$table_body = ob_get_clean();

if (!$has_any) {
    maintenance_db_odbc_close($connect);
    ?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<title>ไม่พบใบงาน</title>
</head>
<body class="mn-addjob-page mn-detail-job-page">
  <div class="header">
    <?php
    $page = '';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container"><div class="progress-bar" id="myBar"></div></div>
  </div>
  <div class="content mn-workspace" style="padding-top:7rem;">
    <p>ไม่พบใบงานสำหรับรหัสนี้</p>
    <p><a href="index">กลับหน้าแรก</a></p>
  </div>
</body>
</html>
    <?php
    exit;
}

if ($connect) {
    maintenance_db_odbc_close($connect);
    $connect = null;
}
?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<link rel="stylesheet" href="css/mn-theme.css" />
<link rel="stylesheet" href="css/mn-layout.css" />
<link rel="stylesheet" href="css/mn-nav.css?v=<?php echo filemtime(__DIR__ . '/css/mn-nav.css'); ?>" />
<link type="text/css" rel="stylesheet" href="css/site-legacy.css" />
<link rel="stylesheet" type="text/css" href="css/smoothness/jquery-ui-1.7.2.custom.css" />
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.6.3/css/font-awesome.min.css" />
<link rel="stylesheet" href="css/mn-page-addjob.css" />
<link rel="stylesheet" href="css/mn-page-detail-job.css" />
<?php
$mnModalAssetPrefix = '';
require_once __DIR__ . '/includes/mn_modal_assets.php';
?>
<title><?php echo maintenance_status_logistic_h($dj_page_title_mtn !== '' ? 'งาน ' . $dj_page_title_mtn . ' — รายละเอียด' : 'รายละเอียดใบงาน'); ?></title>
<script type="text/javascript">
window.MN_DETAIL_JOB = <?php echo json_encode(
    array(
        'tsId' => $ts_id,
        'headerEditLocked' => $dj_header_edit_locked,
        'productNames' => $dj_product_names_utf8,
        'logisticsNames' => $dj_logistics_utf8,
        'qvSendOptions' => array('Mail', 'Line', 'Fax'),
        'logisticRecorder' => isset($mn_logistic_recorder_value) ? (string) $mn_logistic_recorder_value : '',
        'flashMessage' => (isset($_GET['mn_flash']) && $_GET['mn_flash'] === 'spare_sn_saved')
            ? 'บันทึก S/N เครื่องสำรองแล้ว — บันทึกวันที่ส่ง/ขนส่งได้ภายหลังจากปุ่มด้านล่าง'
            : '',
    ),
    JSON_UNESCAPED_UNICODE
); ?>;
</script>
<script type="text/javascript" src="js/jquery-1.3.2.min.js"></script>
<script type="text/javascript" src="js/jquery-ui-1.7.2.custom.min.js"></script>
<script type="text/javascript" src="js/jquery-3.5.1.min.js"></script>
<script type="text/javascript" src="js/jquery-add_object.min.js"></script>
<?php
$mnLogisticsTrackingMap = $dj_logistics_tracking_map;
$mnLogisticsTrackingAssetPrefix = '';
require_once __DIR__ . '/includes/mn_logistics_tracking_assets.php';
?>
<script type="text/javascript" src="js/detail_job-page.js"></script>
<script type="text/javascript" src="js/detail_job-field-edit-modal.js"></script>
<script type="text/javascript">
jQuery(document).ready(function ($) {
	$('.search-box-user input[type="text"]').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result");
		if (inputVal.length) {
			$.get("search_cusname", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result p", function () {
		$(this).parents(".search-box-user").find('input[type="text"]').val($(this).text());
		$(this).parent(".result").empty();
	});
	$('.search-box-sec input[type="text"]').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result_sec");
		if (inputVal.length) {
			$.get("search_cusname", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result_sec p", function () {
		$(this).parents(".search-box-sec").find('input[type="text"]').val($(this).text());
		$(this).parent(".result_sec").empty();
	});
	$('.search-box-tambon input').on("keyup input", function () {
		var inputVal = $(this).val();
		var resultDropdown = $(this).siblings(".result_tambon");
		if (inputVal.length) {
			$.get("search_address", { term: inputVal }).done(function (data) {
				resultDropdown.html(data);
			});
		} else {
			resultDropdown.empty();
		}
	});
	$(document).on("click", ".result_tambon p", function () {
		$(this).parents(".search-box-tambon").find('input').val($(this).text());
		$(this).parent(".result_tambon").empty();
	});
});
</script>
</head>
<body class="mn-addjob-page mn-detail-job-page">
  <div class="header">
    <?php
    $page = '';
    require_once __DIR__ . '/includes/title-h.php';
    ?>
    <div class="progress-container">
      <div class="progress-bar" id="myBar"></div>
    </div>
  </div>
  <div class="content mn-workspace">
    <div class="mn-form-centered">
      <table id="table1" width="100%">
        <tbody>
<?php echo $table_body; ?>
        <tr>
          <td colspan="4"><hr /><a href="index">« กลับหน้าแรก</a></td>
        </tr>
        </tbody>
      </table>
    </div>
  </div>
</body>
</html>
