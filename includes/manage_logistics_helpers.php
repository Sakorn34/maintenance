<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * จัดการตาราง `logistics_carrier` — เพิ่ม/แก้ไขชื่อ, URL tracking, เปิด-ปิดใช้งาน (ไม่มีการลบแถว)
 *
 * Flow:
 * - หน้า `manage_logistics.php` เรียก `maintenance_manage_logistics_fetch_all()`
 * - `save_manage_logistics.php` เรียก `maintenance_manage_logistics_save_post()`
 *
 * Example:
 * $rows = maintenance_manage_logistics_fetch_all($connect);
 * $result = maintenance_manage_logistics_save_post($connect, $_POST);
 */

require_once __DIR__ . '/logistics_helpers.php';
require_once __DIR__ . '/maintenance_flow_sql_helpers.php';

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_manage_logistics_fetch_all')) {
    /**
     * Purpose:
     * ดึงรายการขนส่งทั้งหมด (รวมปิดใช้งาน) สำหรับหน้าจัดการ
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - array<int,array{lc_id:int,lc_name:string,lc_tracking_url:string,lc_sort_order:int,lc_active:int}>
     */
    function maintenance_manage_logistics_fetch_all($connect)
    {
        $rows = maintenance_logistics_fetch_rows($connect, false);
        $out = array();
        foreach ($rows as $row) {
            $out[] = array(
                'lc_id' => (int) (isset($row['lc_id']) ? $row['lc_id'] : 0),
                'lc_name' => trim(maintenance_flow_db_text_to_utf8(isset($row['lc_name']) ? $row['lc_name'] : '')),
                'lc_tracking_url' => trim(maintenance_flow_db_text_to_utf8(isset($row['lc_tracking_url']) ? $row['lc_tracking_url'] : '')),
                'lc_sort_order' => (int) (isset($row['lc_sort_order']) ? $row['lc_sort_order'] : 0),
                'lc_active' => (int) (isset($row['lc_active']) ? $row['lc_active'] : 0),
            );
        }

        return $out;
    }
}

if (!function_exists('maintenance_manage_logistics_fetch_by_id')) {
    /**
     * Purpose:
     * ดึงแถวขนส่งตาม `lc_id` สำหรับโหมดแก้ไข
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $lc_id: รหัสขนส่ง
     *
     * Return value:
     * - array|null แถวเดียวหรือ null ถ้าไม่พบ
     */
    function maintenance_manage_logistics_fetch_by_id($connect, $lc_id)
    {
        $lc_id = (int) $lc_id;
        if ($lc_id < 1 || !maintenance_logistics_table_available($connect)) {
            return null;
        }
        $sql = 'SELECT lc_id, lc_name, lc_tracking_url, lc_sort_order, lc_active FROM logistics_carrier WHERE lc_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return null;
        }
        mysqli_stmt_bind_param($stmt, 'i', $lc_id);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $row = $res ? mysqli_fetch_assoc($res) : null;
        mysqli_stmt_close($stmt);
        if (!is_array($row)) {
            return null;
        }

        return array(
            'lc_id' => (int) $row['lc_id'],
            'lc_name' => trim(maintenance_flow_db_text_to_utf8($row['lc_name'])),
            'lc_tracking_url' => trim(maintenance_flow_db_text_to_utf8(isset($row['lc_tracking_url']) ? $row['lc_tracking_url'] : '')),
            'lc_sort_order' => (int) $row['lc_sort_order'],
            'lc_active' => (int) $row['lc_active'],
        );
    }
}

if (!function_exists('maintenance_manage_logistics_name_exists')) {
    /**
     * Purpose:
     * ตรวจว่าชื่อขนส่งซ้ำกับแถวอื่น
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $name: ชื่อขนส่ง
     * - int $exclude_lc_id: ยกเว้น lc_id นี้ (โหมดแก้ไข)
     *
     * Return value:
     * - bool true ถ้าชื่อซ้ำ
     */
    function maintenance_manage_logistics_name_exists($connect, $name, $exclude_lc_id = 0)
    {
        $exclude_lc_id = (int) $exclude_lc_id;
        if ($exclude_lc_id > 0) {
            $sql = 'SELECT lc_id FROM logistics_carrier WHERE lc_name = ? AND lc_id <> ? LIMIT 1';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return false;
            }
            mysqli_stmt_bind_param($stmt, 'si', $name, $exclude_lc_id);
        } else {
            $sql = 'SELECT lc_id FROM logistics_carrier WHERE lc_name = ? LIMIT 1';
            $stmt = mysqli_prepare($connect, $sql);
            if ($stmt === false) {
                return false;
            }
            mysqli_stmt_bind_param($stmt, 's', $name);
        }
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $found = ($res && mysqli_fetch_assoc($res));
        mysqli_stmt_close($stmt);

        return (bool) $found;
    }
}

if (!function_exists('maintenance_manage_logistics_validate_fields')) {
    /**
     * Purpose:
     * validate ฟิลด์ฟอร์มเพิ่ม/แก้ไขขนส่ง
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $name: ชื่อขนส่ง
     * - string $tracking_url: URL (ว่างได้)
     * - int $lc_id: รหัสเมื่อแก้ไข (0 = เพิ่มใหม่)
     *
     * Return value:
     * - array{ok:bool,error?:string,name?:string,tracking_url?:string,lc_active?:int}
     */
    function maintenance_manage_logistics_validate_fields($connect, $name, $tracking_url, $lc_id = 0)
    {
        $name = trim((string) $name);
        $tracking_url = trim((string) $tracking_url);
        $lc_id = (int) $lc_id;

        if ($name === '') {
            return array('ok' => false, 'error' => 'กรุณากรอกชื่อขนส่ง');
        }
        if (mb_strlen($name, 'UTF-8') > 100) {
            return array('ok' => false, 'error' => 'ชื่อขนส่งยาวเกิน 100 ตัวอักษร');
        }
        if ($tracking_url !== '') {
            if (mb_strlen($tracking_url, 'UTF-8') > 500) {
                return array('ok' => false, 'error' => 'ลิงก์ตรวจ tracking ยาวเกิน 500 ตัวอักษร');
            }
            if (!preg_match('#^https?://#i', $tracking_url)) {
                return array('ok' => false, 'error' => 'ลิงก์ตรวจ tracking ต้องขึ้นต้นด้วย http:// หรือ https://');
            }
        }
        if (!maintenance_logistics_table_available($connect)) {
            return array('ok' => false, 'error' => 'ยังไม่มีตาราง logistics_carrier — รัน database/logistics_carrier.sql ก่อน');
        }
        if (maintenance_manage_logistics_name_exists($connect, $name, $lc_id)) {
            return array('ok' => false, 'error' => 'ชื่อขนส่งนี้มีในระบบแล้ว');
        }

        return array(
            'ok' => true,
            'name' => $name,
            'tracking_url' => $tracking_url,
        );
    }
}

if (!function_exists('maintenance_manage_logistics_next_sort_order')) {
    /**
     * Purpose:
     * ค่า `lc_sort_order` ถัดไปสำหรับแถวใหม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     *
     * Return value:
     * - int
     */
    function maintenance_manage_logistics_next_sort_order($connect)
    {
        $res = mysqli_query($connect, 'SELECT COALESCE(MAX(lc_sort_order), 0) + 10 AS nxt FROM logistics_carrier');
        if ($res === false) {
            return 10;
        }
        $row = mysqli_fetch_assoc($res);
        mysqli_free_result($res);

        return (int) (isset($row['nxt']) ? $row['nxt'] : 10);
    }
}

if (!function_exists('maintenance_manage_logistics_insert')) {
    /**
     * Purpose:
     * เพิ่มขนส่งใหม่
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - string $name: ชื่อ
     * - string $tracking_url: URL (ว่าง = NULL)
     * - int $lc_active: 1 เปิด / 0 ปิด
     *
     * Return value:
     * - array{ok:bool,error?:string,lc_id?:int}
     */
    function maintenance_manage_logistics_insert($connect, $name, $tracking_url, $lc_active)
    {
        $valid = maintenance_manage_logistics_validate_fields($connect, $name, $tracking_url, 0);
        if (!$valid['ok']) {
            return $valid;
        }
        $urlDb = $valid['tracking_url'];
        $sort = maintenance_manage_logistics_next_sort_order($connect);
        $active = ((int) $lc_active === 1) ? 1 : 0;

        $sql = 'INSERT INTO logistics_carrier (lc_name, lc_tracking_url, lc_sort_order, lc_active) VALUES (?,?,?,?)';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'error' => mysqli_error($connect));
        }
        mysqli_stmt_bind_param($stmt, 'ssii', $valid['name'], $urlDb, $sort, $active);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_error($connect);
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'error' => $err !== '' ? $err : 'บันทึกไม่สำเร็จ');
        }
        $newId = (int) mysqli_insert_id($connect);
        mysqli_stmt_close($stmt);

        return array('ok' => true, 'lc_id' => $newId);
    }
}

if (!function_exists('maintenance_manage_logistics_update')) {
    /**
     * Purpose:
     * แก้ไขขนส่งที่มีอยู่ (ไม่ลบแถว)
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - int $lc_id: รหัส
     * - string $name: ชื่อ
     * - string $tracking_url: URL
     * - int $lc_active: 1/0
     *
     * Return value:
     * - array{ok:bool,error?:string}
     */
    function maintenance_manage_logistics_update($connect, $lc_id, $name, $tracking_url, $lc_active)
    {
        $lc_id = (int) $lc_id;
        if ($lc_id < 1) {
            return array('ok' => false, 'error' => 'รหัสขนส่งไม่ถูกต้อง');
        }
        if (maintenance_manage_logistics_fetch_by_id($connect, $lc_id) === null) {
            return array('ok' => false, 'error' => 'ไม่พบรายการขนส่งที่ต้องการแก้ไข');
        }
        $valid = maintenance_manage_logistics_validate_fields($connect, $name, $tracking_url, $lc_id);
        if (!$valid['ok']) {
            return $valid;
        }
        $urlDb = $valid['tracking_url'];
        $active = ((int) $lc_active === 1) ? 1 : 0;

        $sql = 'UPDATE logistics_carrier SET lc_name = ?, lc_tracking_url = ?, lc_active = ? WHERE lc_id = ? LIMIT 1';
        $stmt = mysqli_prepare($connect, $sql);
        if ($stmt === false) {
            return array('ok' => false, 'error' => mysqli_error($connect));
        }
        mysqli_stmt_bind_param($stmt, 'ssii', $valid['name'], $urlDb, $active, $lc_id);
        if (!mysqli_stmt_execute($stmt)) {
            $err = mysqli_error($connect);
            mysqli_stmt_close($stmt);

            return array('ok' => false, 'error' => $err !== '' ? $err : 'บันทึกไม่สำเร็จ');
        }
        mysqli_stmt_close($stmt);

        return array('ok' => true);
    }
}

if (!function_exists('maintenance_manage_logistics_save_post')) {
    /**
     * Purpose:
     * ประมวลผล POST จาก `save_manage_logistics.php`
     *
     * Parameters สำคัญ:
     * - mysqli $connect: connection
     * - array $post: ข้อมูล POST
     *
     * Return value:
     * - array{ok:bool,error?:string,action?:string,lc_id?:int}
     */
    function maintenance_manage_logistics_save_post($connect, array $post)
    {
        $action = isset($post['mn_action']) ? trim((string) $post['mn_action']) : '';
        $name = isset($post['lc_name']) ? (string) $post['lc_name'] : '';
        $url = isset($post['lc_tracking_url']) ? (string) $post['lc_tracking_url'] : '';
        $active = isset($post['lc_active']) && (string) $post['lc_active'] === '1' ? 1 : 0;

        if ($action === 'insert') {
            $r = maintenance_manage_logistics_insert($connect, $name, $url, $active);
            if (!$r['ok']) {
                return $r;
            }

            return array('ok' => true, 'action' => 'insert', 'lc_id' => (int) $r['lc_id']);
        }
        if ($action === 'update') {
            $lc_id = isset($post['lc_id']) ? (int) $post['lc_id'] : 0;
            $r = maintenance_manage_logistics_update($connect, $lc_id, $name, $url, $active);
            if (!$r['ok']) {
                return $r;
            }

            return array('ok' => true, 'action' => 'update', 'lc_id' => $lc_id);
        }

        return array('ok' => false, 'error' => 'คำสั่งบันทึกไม่ถูกต้อง');
    }
}

if (!function_exists('maintenance_manage_logistics_redirect_url')) {
    /**
     * Purpose:
     * สร้าง URL redirect กลับหน้าจัดการพร้อม flash
     *
     * Parameters สำคัญ:
     * - bool $ok: สำเร็จหรือไม่
     * - string $message: ข้อความ
     * - int $edit_lc_id: เปิดโหมดแก้ไขหลัง redirect (0 = ไม่ระบุ)
     *
     * Return value:
     * - string path สำหรับ Location header
     */
    function maintenance_manage_logistics_redirect_url($ok, $message, $edit_lc_id = 0)
    {
        $q = array();
        $q[] = 'mn_flash=' . ($ok ? 'ok' : 'error');
        if ($message !== '') {
            $q[] = 'mn_msg=' . rawurlencode($message);
        }
        $edit_lc_id = (int) $edit_lc_id;
        if ($edit_lc_id > 0) {
            $q[] = 'edit=' . $edit_lc_id;
        }

        return 'manage_logistics?' . implode('&', $q);
    }
}
