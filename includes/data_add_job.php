<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * ดึงข้อมูลที่หน้า add_job ต้องใช้ (รายชื่อพนักงาน, รายการ Product) ผ่าน mysqli โดยตรง
 * แยกออกจาก function.php เดิมเพื่อไม่ให้โค้ดในโฟลเดอร์ maintenance ดึง legacy connect
 *
 * องค์ประกอบหลัก:
 * - `maintenance_new_fetch_employee_names()` / `maintenance_new_fetch_employee_names_by_login_token()` — จับคู่ session กับ `employee` ผ่าน `emp_name` หรือ `emp_password`
 * - `maintenance_new_fetch_product_names_sorted()` สำหรับ dropdown รุ่นสินค้า (คอลัมน์ `pn_name`; ลองตาราง `product_name` แล้วค่อย `product_name`)
 *
 * Flow การใช้งานแบบสั้น:
 * require_once __DIR__ . '/../config/database.php';
 * require_once __DIR__ . '/data_add_job.php';
 * $mysqli = maintenance_new_db_connect();
 * $names = maintenance_new_fetch_employee_names($mysqli, '');
 * mysqli_close($mysqli);
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────
if (!function_exists('maintenance_new_fetch_employee_names_by_login_token')) {
    /**
     * Purpose:
     * ดึง `emp_name` ที่ตรงกับ token จาก session (login) — จับคู่ทั้ง `emp_name` และ `emp_password` เพราะ schema เดิมใช้คอลัมน์ password เก็บรหัสล็อกอิน
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection
     * - string $token: ค่าเช่น `login_name` จาก profile
     * - string $position: '' = ไม่กรองตำแหน่ง, 'TC' / 'SP-QC' = เพิ่มเงื่อนไข emp_position
     *
     * Return value:
     * - array ของ string ชื่อพนักงาน
     */
    function maintenance_new_fetch_employee_names_by_login_token($mysqli, $token, $position)
    {
        $names = array();
        $sql = 'SELECT emp_name FROM employee WHERE (TRIM(emp_name) = TRIM(?) OR (emp_password IS NOT NULL AND TRIM(emp_password) <> \'\' AND TRIM(emp_password) = TRIM(?)))';
        if ($position === 'TC') {
            $sql .= " AND emp_position = 'TC'";
        } elseif ($position === 'SP-QC') {
            $sql .= " AND emp_position = 'SP-QC'";
        }
        $sql .= ' ORDER BY emp_name ASC';
        $stmt = mysqli_prepare($mysqli, $sql);
        if ($stmt === false) {
            maintenance_new_db_fail('Prepare failed: ' . mysqli_error($mysqli));
        }
        mysqli_stmt_bind_param($stmt, 'ss', $token, $token);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $empName);
        while (mysqli_stmt_fetch($stmt)) {
            $names[] = $empName;
        }
        mysqli_stmt_close($stmt);
        sort($names);

        return $names;
    }
}

if (!function_exists('maintenance_new_fetch_employee_names')) {
    /**
     * Purpose:
     * ดึงรายชื่อพนักงานจากตาราง employee ตามเงื่อนไขตำแหน่ง และถ้ามี restrictEmpName จะกรองแบบ prepared โดยจับคู่กับ `emp_name` หรือ `emp_password` (token จาก session มักเป็น login ไม่ใช่ชื่อแสดง)
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection ที่เปิดแล้ว
     * - string $position: ค่าว่าง = ทุกคน (หรือกรองชื่อเมื่อมี restrict), 'TC' / 'SP-QC' = filter ตาม emp_position
     * - string|null $restrictEmpName: ถ้าไม่ null และไม่ว่าง จะ WHERE จับคู่ token นี้กับ emp_name / emp_password (ร่วมกับ position ถ้ามี)
     *
     * Return value:
     * - array ของ string ชื่อพนักงาน (emp_name)
     */
    function maintenance_new_fetch_employee_names($mysqli, $position, $restrictEmpName = null)
    {
        $names = array();
        $restrict = ($restrictEmpName !== null && $restrictEmpName !== '');

        if ($position === '') {
            if ($restrict) {
                return maintenance_new_fetch_employee_names_by_login_token($mysqli, $restrictEmpName, '');
            }
            $sql = 'SELECT * FROM employee';
        } elseif ($position === 'TC') {
            if ($restrict) {
                return maintenance_new_fetch_employee_names_by_login_token($mysqli, $restrictEmpName, 'TC');
            }
            $sql = "SELECT * FROM employee WHERE emp_position = 'TC'";
        } elseif ($position === 'SP-QC') {
            if ($restrict) {
                return maintenance_new_fetch_employee_names_by_login_token($mysqli, $restrictEmpName, 'SP-QC');
            }
            $sql = "SELECT * FROM employee WHERE emp_position = 'SP-QC'";
        } else {
            return $names;
        }

        $result = mysqli_query($mysqli, $sql);
        if ($result === false) {
            maintenance_new_db_fail('Error Query [' . $sql . ']: ' . mysqli_error($mysqli));
        }

        while ($row = mysqli_fetch_assoc($result)) {
            if (isset($row['emp_name'])) {
                $names[] = $row['emp_name'];
            }
        }
        mysqli_free_result($result);

        sort($names);
        return $names;
    }
}

if (!function_exists('maintenance_new_fetch_product_names_sorted')) {
    /**
     * Purpose:
     * ดึงชื่อสินค้าจากคอลัมน์ `pn_name` เรียงตามชื่อ — ลองตาราง `product_name` (schema เดิมใน maintenance dump) ก่อน ถ้าไม่มีค่อยลอง `product_name`
     *
     * Parameters สำคัญ:
     * - mysqli $mysqli: connection ที่เปิดแล้ว
     *
     * Return value:
     * - array ของ string ชื่อสินค้า
     */
    function maintenance_new_fetch_product_names_sorted($mysqli)
    {
        $names = array();
        $variants = array(
            'SELECT `pn_name` FROM `product_name` ORDER BY `pn_name` ASC',
            'SELECT `pn_name` FROM `product_name` ORDER BY `pn_name` ASC',
        );
        $lastError = '';
        foreach ($variants as $sql) {
            $result = mysqli_query($mysqli, $sql);
            if ($result !== false) {
                while ($row = mysqli_fetch_assoc($result)) {
                    if (isset($row['pn_name']) && $row['pn_name'] !== '') {
                        $names[] = $row['pn_name'];
                    }
                }
                mysqli_free_result($result);

                return $names;
            }
            $lastError = mysqli_error($mysqli);
        }
        maintenance_new_db_fail('ดึงรายการ Product ไม่ได้ (ลอง product_name และ product_name): ' . $lastError);
    }
}
