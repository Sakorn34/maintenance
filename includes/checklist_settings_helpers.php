<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * Helper หน้า checklist_settings — อ่าน/บันทึกข้อตรวจ อาการ กลุ่มชิ้นส่วน และการใช้ร่วมกับรุ่นอื่น
 * ใช้ร่วมกับ checklist_helpers.php (ensure_tables ต้องเรียกก่อน)
 */

if (!function_exists('mn_cls_types')) {
    function mn_cls_types()
    {
        return array('options', 'passfail', 'number', 'auto');
    }
}

if (!function_exists('mn_cls_colors')) {
    function mn_cls_colors()
    {
        return array('green', 'teal', 'blue', 'violet', 'amber', 'orange', 'red', 'gray');
    }
}

if (!function_exists('mn_cls_query_all')) {
    function mn_cls_query_all($connect, $sql, $types = '', array $params = array())
    {
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return array(); }
        if ($types !== '') {
            $bind = array($stmt, $types);
            foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
            call_user_func_array('mysqli_stmt_bind_param', $bind);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $out = array();
        while ($res && ($row = mysqli_fetch_assoc($res))) { $out[] = $row; }
        mysqli_stmt_close($stmt);
        return $out;
    }
}

if (!function_exists('mn_cls_exec')) {
    function mn_cls_exec($connect, $sql, $types, array $params)
    {
        $stmt = mysqli_prepare($connect, $sql);
        if (!$stmt) { return false; }
        $bind = array($stmt, $types);
        foreach ($params as $k => $v) { $bind[] = &$params[$k]; }
        call_user_func_array('mysqli_stmt_bind_param', $bind);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        return $ok;
    }
}

if (!function_exists('mn_cls_product_exists')) {
    function mn_cls_product_exists($connect, $product)
    {
        $rows = mn_cls_query_all($connect, 'SELECT pn_id FROM product_name WHERE pn_name = ? LIMIT 1', 's', array($product));
        return count($rows) > 0;
    }
}

/**
 * รายชื่อรุ่นทั้งหมด เรียงตามจำนวนงานซ่อม พร้อมจำนวนข้อตรวจและรุ่นที่ใช้ร่วม
 */
if (!function_exists('mn_cls_products')) {
    function mn_cls_products($connect)
    {
        $rows = mn_cls_query_all($connect,
            'SELECT p.pn_name AS name,'
            . ' (SELECT COUNT(*) FROM transac_repair t WHERE t.trp_product = p.pn_name) AS repairs,'
            . ' (SELECT COUNT(*) FROM cl_item i WHERE i.ci_product = p.pn_name AND i.ci_enabled = 1) AS items,'
            . ' COALESCE(c.cp_share_from, \'\') AS share_from'
            . ' FROM product_name p'
            . ' LEFT JOIN cl_product c ON c.cp_product = p.pn_name'
            . ' ORDER BY repairs DESC, p.pn_name ASC'
        );
        foreach ($rows as &$r) {
            $r['repairs'] = (int) $r['repairs'];
            $r['items'] = (int) $r['items'];
        }
        unset($r);
        return $rows;
    }
}

if (!function_exists('mn_cls_item_out')) {
    function mn_cls_item_out(array $r)
    {
        $type = 'passfail';
        if ((string) $r['ci_auto_source'] !== '') {
            $type = 'auto';
        } elseif ((string) $r['ci_unit'] !== '') {
            $type = 'number';
        } elseif ((string) $r['ci_opts'] !== '') {
            $type = 'options';
        }
        $labels = (string) $r['ci_opts'] !== '' ? explode('|', (string) $r['ci_opts']) : array();
        $rates = json_decode((string) $r['ci_opt_rates'], true);
        $colors = json_decode((string) $r['ci_opt_colors'], true);
        $notes = json_decode((string) $r['ci_opt_notes'], true);
        $groups = json_decode((string) $r['ci_opt_groups'], true);
        $options = array();
        foreach ($labels as $i => $raw) {
            $raw = trim($raw);
            $note = substr($raw, -5) === ':note';
            $rate = is_array($rates) ? (isset($rates[$i]) ? (string) $rates[$i] : '') : ($i > 0 ? (string) $r['ci_rate_on_fail'] : '');
            $color = is_array($colors) && isset($colors[$i]) ? (string) $colors[$i] : '';
            $note_default = $note && is_array($notes) && isset($notes[$i]) ? (string) $notes[$i] : '';
            $group = is_array($groups) && isset($groups[$i]) ? (int) $groups[$i] : 0;
            $options[] = array('label' => $note ? substr($raw, 0, -5) : $raw, 'note' => $note, 'note_default' => $note_default, 'rate' => $rate, 'color' => $color, 'group' => $group);
        }
        return array(
            'id'         => (int) $r['ci_id'],
            'symptom_id' => (int) $r['ci_symptom_id'],
            'group_id'   => (int) $r['ci_group_id'],
            'step'       => (int) $r['ci_step'],
            'question'   => (string) $r['ci_question'],
            'hint'       => (string) $r['ci_hint'],
            'type'       => $type,
            'options'    => $type === 'options' ? $options : array(),
            'multi'      => $type === 'options' && (int) $r['ci_multi'] === 1,
            'extra_opt'  => $type === 'number' && $labels ? str_replace(':note', '', trim($labels[0])) : '',
            'unit'       => (string) $r['ci_unit'],
            'min'        => $r['ci_val_min'] === null ? '' : (float) $r['ci_val_min'],
            'max'        => $r['ci_val_max'] === null ? '' : (float) $r['ci_val_max'],
            'auto'       => (string) $r['ci_auto_source'],
            'rate_fail'  => (string) $r['ci_rate_on_fail'],
            'enabled'    => (int) $r['ci_enabled'] === 1,
        );
    }
}

/**
 * ข้อมูลทั้งหมดของรุ่นสำหรับหน้าตั้งค่า
 */
if (!function_exists('mn_cls_load')) {
    function mn_cls_load($connect, $product)
    {
        $share = mn_cls_query_all($connect, 'SELECT cp_share_from FROM cl_product WHERE cp_product = ?', 's', array($product));
        $symptoms = mn_cls_query_all($connect,
            'SELECT cs_id AS id, cs_name AS name, cs_order AS ord FROM cl_symptom WHERE cs_product = ? ORDER BY cs_order, cs_id',
            's', array($product)
        );
        $groups = mn_cls_query_all($connect,
            'SELECT cg_id AS id, cg_name AS name, cg_order AS ord, cg_note AS note FROM cl_group WHERE cg_product = ? ORDER BY cg_order, cg_id',
            's', array($product)
        );
        $items = array();
        $rows = mn_cls_query_all($connect,
            'SELECT ci_id, ci_symptom_id, ci_group_id, ci_step, ci_question, ci_hint, ci_rate_on_fail, ci_unit,'
            . ' ci_val_min, ci_val_max, ci_opts, ci_opt_rates, ci_opt_colors, ci_multi, ci_opt_notes, ci_opt_groups, ci_auto_source, ci_enabled'
            . ' FROM cl_item WHERE ci_product = ? ORDER BY ci_symptom_id, ci_step, ci_id',
            's', array($product)
        );
        foreach ($rows as $r) { $items[] = mn_cls_item_out($r); }
        foreach ($symptoms as &$s) { $s['id'] = (int) $s['id']; }
        unset($s);
        foreach ($groups as &$g) { $g['id'] = (int) $g['id']; }
        unset($g);

        return array(
            'product'    => $product,
            'share_from' => $share ? (string) $share[0]['cp_share_from'] : '',
            'symptoms'   => $symptoms,
            'groups'     => $groups,
            'items'      => $items,
            'rates'      => maintenance_update_rate_fetch_rate_options($connect, maintenance_update_rate_resolve_rate_product($product)),
            'bundles'    => maintenance_checklist_fetch_bundles($connect, $product),
            'reasons'    => (object) maintenance_checklist_fetch_rate_reasons($connect, $product),
        );
    }
}

/**
 * บันทึกชุดรายการซ่อม — $old = ชื่อรายการชุดเดิม (กรณีเปลี่ยนรายการชุด) ว่าง = สร้างใหม่
 */
if (!function_exists('mn_cls_save_bundle')) {
    function mn_cls_save_bundle($connect, $product, $bundle, $old, array $members)
    {
        $rates = maintenance_update_rate_fetch_rate_options($connect, maintenance_update_rate_resolve_rate_product($product));
        $bundle = trim((string) $bundle);
        $old = trim((string) $old);
        if ($bundle === '' || !in_array($bundle, $rates, true)) { return array('ok' => false, 'error' => 'เลือกรายการชุดจากรายการซ่อมของรุ่นนี้'); }
        $clean = array();
        foreach ($members as $m) {
            $m = trim((string) $m);
            if ($m !== '' && $m !== $bundle && in_array($m, $rates, true) && !in_array($m, $clean, true)) { $clean[] = $m; }
        }
        if (!$clean) { return array('ok' => false, 'error' => 'เลือกรายการที่รวมอยู่ในชุดอย่างน้อย 1 รายการ'); }
        if ($bundle !== $old) {
            $dup = mn_cls_query_all($connect, 'SELECT crb_id FROM cl_rate_bundle WHERE crb_product = ? AND crb_bundle = ? LIMIT 1', 'ss', array($product, $bundle));
            if ($dup) { return array('ok' => false, 'error' => '“' . $bundle . '” ตั้งเป็นชุดไว้แล้ว'); }
        }
        mysqli_begin_transaction($connect);
        $ok = mn_cls_exec($connect, 'DELETE FROM cl_rate_bundle WHERE crb_product = ? AND crb_bundle IN (?, ?)', 'sss', array($product, $bundle, $old !== '' ? $old : $bundle));
        foreach ($clean as $m) {
            $ok = $ok && mn_cls_exec($connect, 'INSERT INTO cl_rate_bundle (crb_product, crb_bundle, crb_member) VALUES (?, ?, ?)', 'sss', array($product, $bundle, $m));
        }
        if ($ok) { mysqli_commit($connect); } else { mysqli_rollback($connect); }
        return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'บันทึกไม่สำเร็จ');
    }
}

/**
 * บันทึกเหตุผลทางเทคนิคของรายการซ่อม (ว่าง = ลบ)
 */
if (!function_exists('mn_cls_save_reason')) {
    function mn_cls_save_reason($connect, $product, $rate, $reason)
    {
        $rates = maintenance_update_rate_fetch_rate_options($connect, maintenance_update_rate_resolve_rate_product($product));
        $rate = trim((string) $rate);
        if ($rate === '' || !in_array($rate, $rates, true)) { return array('ok' => false, 'error' => 'ไม่พบรายการซ่อมนี้ในรุ่น'); }
        $reason = mb_substr(trim((string) $reason), 0, 1000, 'UTF-8');
        $ok = mn_cls_exec($connect, 'DELETE FROM cl_rate_reason WHERE crr_product = ? AND crr_rate = ?', 'ss', array($product, $rate));
        if ($ok && $reason !== '') {
            $ok = mn_cls_exec($connect, 'INSERT INTO cl_rate_reason (crr_product, crr_rate, crr_reason) VALUES (?, ?, ?)', 'sss', array($product, $rate, $reason));
        }
        return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'บันทึกไม่สำเร็จ');
    }
}

if (!function_exists('mn_cls_delete_bundle')) {
    function mn_cls_delete_bundle($connect, $product, $bundle)
    {
        $ok = mn_cls_exec($connect, 'DELETE FROM cl_rate_bundle WHERE crb_product = ? AND crb_bundle = ?', 'ss', array($product, trim((string) $bundle)));
        return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'ลบไม่สำเร็จ');
    }
}

if (!function_exists('mn_cls_belongs')) {
    function mn_cls_belongs($connect, $table, $id, $product)
    {
        $map = array(
            'symptom' => 'SELECT cs_id FROM cl_symptom WHERE cs_id = ? AND cs_product = ?',
            'group'   => 'SELECT cg_id FROM cl_group WHERE cg_id = ? AND cg_product = ?',
            'item'    => 'SELECT ci_id FROM cl_item WHERE ci_id = ? AND ci_product = ?',
        );
        return count(mn_cls_query_all($connect, $map[$table], 'is', array((int) $id, $product))) > 0;
    }
}

if (!function_exists('mn_cls_clip')) {
    function mn_cls_clip($s, $max)
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        return mb_substr($s, 0, $max, 'UTF-8');
    }
}

/**
 * บันทึกข้อตรวจ 1 ข้อ (เพิ่มใหม่เมื่อ id = 0) — คืน array('ok'=>bool,'error'=>string,'id'=>int)
 */
if (!function_exists('mn_cls_save_item')) {
    function mn_cls_save_item($connect, $product, array $in)
    {
        $id = isset($in['id']) ? (int) $in['id'] : 0;
        $symptom_id = isset($in['symptom_id']) ? (int) $in['symptom_id'] : 0;
        $group_id = isset($in['group_id']) ? (int) $in['group_id'] : 0;
        $question = mn_cls_clip(isset($in['question']) ? $in['question'] : '', 300);
        $hint = mn_cls_clip(isset($in['hint']) ? $in['hint'] : '', 300);
        $type = isset($in['type']) ? (string) $in['type'] : '';
        $enabled = !empty($in['enabled']) ? 1 : 0;

        if ($question === '') { return array('ok' => false, 'error' => 'กรอกสิ่งที่ตรวจ (คำถาม)'); }
        if (!in_array($type, mn_cls_types(), true)) { return array('ok' => false, 'error' => 'ประเภทคำตอบไม่ถูกต้อง'); }
        if ($id > 0 && !mn_cls_belongs($connect, 'item', $id, $product)) { return array('ok' => false, 'error' => 'ไม่พบข้อตรวจนี้'); }
        if ($symptom_id > 0 && !mn_cls_belongs($connect, 'symptom', $symptom_id, $product)) { return array('ok' => false, 'error' => 'ไม่พบอาการนี้ในรุ่น'); }
        if ($group_id > 0 && !mn_cls_belongs($connect, 'group', $group_id, $product)) { return array('ok' => false, 'error' => 'ไม่พบกลุ่มชิ้นส่วนนี้ในรุ่น'); }

        $opts = null;
        $opt_rates = null;
        $opt_colors = null;
        $opt_notes = null;
        $opt_groups = null;
        $multi = 0;
        $unit = '';
        $min = null;
        $max = null;
        $auto = null;
        $rate_fail = mn_cls_clip(isset($in['rate_fail']) ? $in['rate_fail'] : '', 200);

        if ($type === 'options') {
            $labels = array();
            $rates = array();
            $colors = array();
            $notes = array();
            $groups = array();
            foreach ((isset($in['options']) && is_array($in['options']) ? $in['options'] : array()) as $o) {
                $label = str_replace(array('|', ':note'), array('/', ''), mn_cls_clip(isset($o['label']) ? $o['label'] : '', 100));
                if ($label === '') { continue; }
                $labels[] = $label . (!empty($o['note']) ? ':note' : '');
                $rates[] = count($rates) === 0 ? '' : mn_cls_clip(isset($o['rate']) ? $o['rate'] : '', 200);
                $c = isset($o['color']) ? (string) $o['color'] : '';
                $colors[] = in_array($c, mn_cls_colors(), true) ? $c : '';
                // ค่าเริ่มต้นช่องรายละเอียด — ยาวไม่เกินช่อง cji_note (200)
                $notes[] = !empty($o['note']) && count($labels) > 1 ? mn_cls_clip(isset($o['note_default']) ? $o['note_default'] : '', 200) : '';
                $g = isset($o['group']) ? (int) $o['group'] : 0;
                $groups[] = count($labels) > 1 && $g >= 1 && $g <= 3 ? $g : 0;
            }
            if (count($labels) < 2) { return array('ok' => false, 'error' => 'ปุ่มตัวเลือกต้องมีอย่างน้อย 2 ตัวเลือก'); }
            if (count($labels) > 6) { return array('ok' => false, 'error' => 'ตัวเลือกได้สูงสุด 6 ตัว'); }
            $opts = implode('|', $labels);
            $opt_rates = json_encode($rates, JSON_UNESCAPED_UNICODE);
            $opt_colors = implode('', $colors) !== '' ? json_encode($colors) : null;
            $opt_notes = implode('', $notes) !== '' ? json_encode($notes, JSON_UNESCAPED_UNICODE) : null;
            $multi = !empty($in['multi']) ? 1 : 0;
            $opt_groups = $multi && array_sum($groups) > 0 ? json_encode($groups) : null;
            $rate_fail = '';
            foreach ($rates as $r) { if ($r !== '') { $rate_fail = $r; break; } }
        } elseif ($type === 'number') {
            $unit = mn_cls_clip(isset($in['unit']) ? $in['unit'] : '', 20);
            if ($unit === '') { return array('ok' => false, 'error' => 'กรอกหน่วยของค่าที่วัด'); }
            if (!isset($in['min']) || !is_numeric($in['min']) || !isset($in['max']) || !is_numeric($in['max'])) {
                return array('ok' => false, 'error' => 'กรอกค่าต่ำสุดและค่าสูงสุดเป็นตัวเลข');
            }
            $min = (float) $in['min'];
            $max = (float) $in['max'];
            if ($min > $max) { return array('ok' => false, 'error' => 'ค่าต่ำสุดต้องไม่มากกว่าค่าสูงสุด'); }
            $extra = str_replace(array('|', ':note'), array('/', ''), mn_cls_clip(isset($in['extra_opt']) ? $in['extra_opt'] : '', 100));
            $opts = $extra !== '' ? $extra : null;
        } elseif ($type === 'auto') {
            $auto = isset($in['auto']) ? (string) $in['auto'] : '';
            if (!in_array($auto, array('buy_date', 'last_ma'), true)) { return array('ok' => false, 'error' => 'เลือกแหล่งข้อมูลอัตโนมัติ'); }
        }

        if ($id > 0) {
            $ok = mn_cls_exec($connect,
                'UPDATE cl_item SET ci_symptom_id=?, ci_group_id=?, ci_question=?, ci_hint=?, ci_rate_on_fail=?, ci_unit=?,'
                . ' ci_val_min=?, ci_val_max=?, ci_opts=?, ci_opt_rates=?, ci_opt_colors=?, ci_multi=?, ci_opt_notes=?, ci_opt_groups=?, ci_auto_source=?, ci_enabled=?'
                . ' WHERE ci_id=? AND ci_product=?',
                'iissssddsssisssiis',
                array($symptom_id, $group_id, $question, $hint, $rate_fail, $unit, $min, $max, $opts, $opt_rates, $opt_colors, $multi, $opt_notes, $opt_groups, $auto, $enabled, $id, $product)
            );
            return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'บันทึกไม่สำเร็จ', 'id' => $id);
        }

        $next = mn_cls_query_all($connect,
            'SELECT COALESCE(MAX(ci_step), 0) + 1 AS n FROM cl_item WHERE ci_product = ? AND ci_symptom_id = ?',
            'si', array($product, $symptom_id)
        );
        $step = $next ? (int) $next[0]['n'] : 1;
        $ok = mn_cls_exec($connect,
            'INSERT INTO cl_item (ci_product, ci_symptom_id, ci_group_id, ci_step, ci_question, ci_hint, ci_rate_on_fail, ci_unit,'
            . ' ci_val_min, ci_val_max, ci_opts, ci_opt_rates, ci_opt_colors, ci_multi, ci_opt_notes, ci_opt_groups, ci_auto_source, ci_enabled)'
            . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            'siiissssddsssisssi',
            array($product, $symptom_id, $group_id, $step, $question, $hint, $rate_fail, $unit, $min, $max, $opts, $opt_rates, $opt_colors, $multi, $opt_notes, $opt_groups, $auto, $enabled)
        );
        return array('ok' => (bool) $ok, 'error' => $ok ? '' : 'บันทึกไม่สำเร็จ', 'id' => $ok ? (int) mysqli_insert_id($connect) : 0);
    }
}

/**
 * ลบข้อตรวจ — ถ้าเคยถูกใช้ในใบงานแล้วจะปิดการใช้งานแทน เพื่อไม่ให้ผลตรวจเดิมหาย
 * $force = ลบถาวรพร้อมผลตรวจของข้อนี้ในทุกใบงาน (ผู้ใช้ยืนยันแล้ว)
 */
if (!function_exists('mn_cls_delete_item')) {
    function mn_cls_delete_item($connect, $product, $id, $force = false)
    {
        if (!mn_cls_belongs($connect, 'item', $id, $product)) { return array('ok' => false, 'error' => 'ไม่พบข้อตรวจนี้'); }
        $used = mn_cls_query_all($connect, 'SELECT COUNT(*) AS n FROM cl_job_item WHERE cji_item_id = ?', 'i', array((int) $id));
        if ($force) {
            mn_cls_exec($connect, 'DELETE FROM cl_job_item WHERE cji_item_id = ?', 'i', array((int) $id));
        } elseif ($used && (int) $used[0]['n'] > 0) {
            mn_cls_exec($connect, 'UPDATE cl_item SET ci_enabled = 0 WHERE ci_id = ? AND ci_product = ?', 'is', array((int) $id, $product));
            return array('ok' => true, 'disabled' => true, 'used' => (int) $used[0]['n']);
        }
        $ok = mn_cls_exec($connect, 'DELETE FROM cl_item WHERE ci_id = ? AND ci_product = ?', 'is', array((int) $id, $product));
        return array('ok' => (bool) $ok, 'disabled' => false);
    }
}

if (!function_exists('mn_cls_reorder')) {
    function mn_cls_reorder($connect, $product, $kind, array $ids)
    {
        $sql = array(
            'item'    => 'UPDATE cl_item SET ci_step = ? WHERE ci_id = ? AND ci_product = ?',
            'symptom' => 'UPDATE cl_symptom SET cs_order = ? WHERE cs_id = ? AND cs_product = ?',
            'group'   => 'UPDATE cl_group SET cg_order = ? WHERE cg_id = ? AND cg_product = ?',
        );
        if (!isset($sql[$kind])) { return array('ok' => false, 'error' => 'ประเภทไม่ถูกต้อง'); }
        $n = 0;
        foreach ($ids as $id) {
            $n++;
            mn_cls_exec($connect, $sql[$kind], 'iis', array($n, (int) $id, $product));
        }
        return array('ok' => true);
    }
}

if (!function_exists('mn_cls_save_named')) {
    function mn_cls_save_named($connect, $product, $kind, array $in)
    {
        $id = isset($in['id']) ? (int) $in['id'] : 0;
        $name = mn_cls_clip(isset($in['name']) ? $in['name'] : '', 100);
        $note = mn_cls_clip(isset($in['note']) ? $in['note'] : '', 300);
        if ($name === '') { return array('ok' => false, 'error' => 'กรอกชื่อ'); }
        $t = $kind === 'symptom'
            ? array('tbl' => 'cl_symptom', 'id' => 'cs_id', 'name' => 'cs_name', 'prod' => 'cs_product', 'ord' => 'cs_order')
            : array('tbl' => 'cl_group', 'id' => 'cg_id', 'name' => 'cg_name', 'prod' => 'cg_product', 'ord' => 'cg_order');

        $dup = mn_cls_query_all($connect,
            "SELECT {$t['id']} AS id FROM {$t['tbl']} WHERE {$t['prod']} = ? AND {$t['name']} = ? AND {$t['id']} <> ?",
            'ssi', array($product, $name, $id)
        );
        if ($dup) { return array('ok' => false, 'error' => 'มีชื่อนี้อยู่แล้ว'); }

        if ($id > 0) {
            if (!mn_cls_belongs($connect, $kind, $id, $product)) { return array('ok' => false, 'error' => 'ไม่พบรายการนี้'); }
            $ok = $kind === 'symptom'
                ? mn_cls_exec($connect, 'UPDATE cl_symptom SET cs_name = ? WHERE cs_id = ? AND cs_product = ?', 'sis', array($name, $id, $product))
                : mn_cls_exec($connect, 'UPDATE cl_group SET cg_name = ?, cg_note = ? WHERE cg_id = ? AND cg_product = ?', 'ssis', array($name, $note, $id, $product));
            return array('ok' => (bool) $ok, 'id' => $id);
        }
        $next = mn_cls_query_all($connect, "SELECT COALESCE(MAX({$t['ord']}), 0) + 1 AS n FROM {$t['tbl']} WHERE {$t['prod']} = ?", 's', array($product));
        $ord = $next ? (int) $next[0]['n'] : 1;
        $ok = $kind === 'symptom'
            ? mn_cls_exec($connect, 'INSERT INTO cl_symptom (cs_product, cs_name, cs_order) VALUES (?, ?, ?)', 'ssi', array($product, $name, $ord))
            : mn_cls_exec($connect, 'INSERT INTO cl_group (cg_product, cg_name, cg_order, cg_note) VALUES (?, ?, ?, ?)', 'ssis', array($product, $name, $ord, $note));
        return array('ok' => (bool) $ok, 'id' => $ok ? (int) mysqli_insert_id($connect) : 0);
    }
}

if (!function_exists('mn_cls_delete_named')) {
    function mn_cls_delete_named($connect, $product, $kind, $id)
    {
        if (!mn_cls_belongs($connect, $kind, $id, $product)) { return array('ok' => false, 'error' => 'ไม่พบรายการนี้'); }
        if ($kind === 'symptom') {
            $n = mn_cls_query_all($connect, 'SELECT COUNT(*) AS n FROM cl_item WHERE ci_symptom_id = ? AND ci_product = ?', 'is', array((int) $id, $product));
            if ($n && (int) $n[0]['n'] > 0) {
                return array('ok' => false, 'error' => 'อาการนี้ยังมีข้อตรวจ ' . (int) $n[0]['n'] . ' ข้อ — ย้ายหรือลบข้อตรวจก่อน');
            }
            $ok = mn_cls_exec($connect, 'DELETE FROM cl_symptom WHERE cs_id = ? AND cs_product = ?', 'is', array((int) $id, $product));
            return array('ok' => (bool) $ok);
        }
        mn_cls_exec($connect, 'UPDATE cl_item SET ci_group_id = 0 WHERE ci_group_id = ? AND ci_product = ?', 'is', array((int) $id, $product));
        $ok = mn_cls_exec($connect, 'DELETE FROM cl_group WHERE cg_id = ? AND cg_product = ?', 'is', array((int) $id, $product));
        return array('ok' => (bool) $ok);
    }
}

if (!function_exists('mn_cls_set_share')) {
    function mn_cls_set_share($connect, $product, $from, $by)
    {
        $from = trim((string) $from);
        if ($from !== '') {
            if ($from === $product) { return array('ok' => false, 'error' => 'เลือกรุ่นอื่นที่ไม่ใช่รุ่นนี้'); }
            if (!mn_cls_product_exists($connect, $from)) { return array('ok' => false, 'error' => 'ไม่พบรุ่นที่เลือก'); }
            $chain = mn_cls_query_all($connect, 'SELECT cp_share_from FROM cl_product WHERE cp_product = ?', 's', array($from));
            if ($chain && trim((string) $chain[0]['cp_share_from']) !== '') {
                return array('ok' => false, 'error' => 'รุ่น ' . $from . ' ใช้ร่วมกับรุ่นอื่นอยู่แล้ว — เลือกรุ่นต้นทางแทน');
            }
            $dep = mn_cls_query_all($connect, 'SELECT cp_product FROM cl_product WHERE cp_share_from = ?', 's', array($product));
            if ($dep) { return array('ok' => false, 'error' => 'มีรุ่นอื่นใช้รายการตรวจของรุ่นนี้อยู่ จึงตั้งให้รุ่นนี้ใช้ร่วมกับรุ่นอื่นไม่ได้'); }
        }
        $now = date('Y-m-d H:i');
        $by = mb_substr((string) $by, 0, 100, 'UTF-8');
        $ok = mn_cls_exec($connect,
            'INSERT INTO cl_product (cp_product, cp_share_from, cp_updated_at, cp_updated_by) VALUES (?, ?, ?, ?)'
            . ' ON DUPLICATE KEY UPDATE cp_share_from = VALUES(cp_share_from), cp_updated_at = VALUES(cp_updated_at), cp_updated_by = VALUES(cp_updated_by)',
            'ssss', array($product, $from, $now, $by)
        );
        return array('ok' => (bool) $ok);
    }
}

/**
 * สรุปประวัติการซ่อมของรุ่น: รายการซ่อมที่ใช้บ่อย + ชุดรายการซ่อมที่พบบ่อย
 */
if (!function_exists('mn_cls_history')) {
    function mn_cls_history($connect, $product)
    {
        $cols = array();
        for ($i = 1; $i <= 10; $i++) { $cols[] = 'trp_repair_no' . $i; }
        $rows = mn_cls_query_all($connect,
            'SELECT trp_receive_date, trp_repair_inform, trp_repair_technician, ' . implode(', ', $cols)
            . ' FROM transac_repair WHERE trp_product = ? ORDER BY trp_id DESC LIMIT 3000',
            's', array($product)
        );
        $jobs = array();
        foreach ($rows as $r) {
            $parts = array();
            foreach ($cols as $c) {
                $v = trim((string) $r[$c]);
                if ($v !== '' && !in_array($v, $parts, true)) { $parts[] = $v; }
            }
            if (!$parts) { continue; }
            $jobs[] = array(
                'd' => (string) $r['trp_receive_date'],
                'inf' => mn_cls_clip($r['trp_repair_inform'], 500),
                'tech' => mn_cls_clip($r['trp_repair_technician'], 500),
                'parts' => $parts,
            );
        }
        $good = function ($t) { return mb_strlen(preg_replace('/[\s\-.]/u', '', $t), 'UTF-8') >= 6; };
        $freq = array();
        foreach ($jobs as $j) { foreach ($j['parts'] as $p) { $freq[$p] = isset($freq[$p]) ? $freq[$p] + 1 : 1; } }
        arsort($freq);

        $parts = array();
        $used = array();
        foreach (array_slice($freq, 0, 15, true) as $name => $n) {
            $ex = '';
            foreach ($jobs as $j) {
                if (in_array((string) $name, $j['parts'], true) && $good($j['tech']) && !isset($used[$j['tech']])) {
                    $ex = $j['tech'];
                    $used[$j['tech']] = true;
                    break;
                }
            }
            $parts[] = array('name' => (string) $name, 'n' => (int) $n, 'ex' => mb_substr($ex, 0, 180, 'UTF-8'));
        }

        $combos = array();
        foreach ($jobs as $j) {
            $k = $j['parts'];
            sort($k);
            $key = implode('|', $k);
            if (!isset($combos[$key])) { $combos[$key] = array(); }
            $combos[$key][] = $j;
        }
        uasort($combos, function ($a, $b) { return count($b) - count($a); });
        $combo_out = array();
        foreach (array_slice($combos, 0, 12, true) as $key => $list) {
            $label = explode('|', (string) $key);
            usort($label, function ($a, $b) use ($freq) { return $freq[$b] - $freq[$a]; });
            $inf = array();
            foreach ($list as $j) {
                if (!$good($j['inf'])) { continue; }
                $k2 = mb_strtolower($j['inf'], 'UTF-8');
                if (!isset($inf[$k2])) { $inf[$k2] = array('text' => mb_substr($j['inf'], 0, 70, 'UTF-8'), 'n' => 0); }
                $inf[$k2]['n']++;
            }
            uasort($inf, function ($a, $b) { return $b['n'] - $a['n']; });
            $ex = array();
            $seen = array();
            foreach ($list as $j) {
                if (count($ex) >= 2) { break; }
                if (!$good($j['tech']) || isset($seen[$j['tech']])) { continue; }
                $seen[$j['tech']] = true;
                $ex[] = array('d' => $j['d'], 'text' => mb_substr($j['tech'], 0, 160, 'UTF-8'));
            }
            $combo_out[] = array('parts' => $label, 'n' => count($list), 'inform' => array_values(array_slice($inf, 0, 3)), 'examples' => $ex);
        }
        return array('total' => count($jobs), 'parts' => $parts, 'combos' => $combo_out);
    }
}
