<?php
/**
 * คัดลอกเป็น `env.local.php` (ไฟล์จริงไม่ควร commit — ใส่ใน .gitignore แล้ว)
 * ใช้ปรับสภาพแวดล้อมและ credential โดยไม่แก้โค้ดหลัก
 *
 * สภาพแวดล้อม (development / production):
 * - ถ้าไม่ตั้ง `MAINTENANCE_APP_ENV` และไม่ใส่คีย์ `app_env` ในไฟล์นี้ (หรือใส่เป็น `auto`)
 *   ระบบจะเดาเองจาก host ของ request: localhost / 127.0.0.1 / ::1 / *.local / *.test / IP private (10.x, 192.168.x, 172.16–31.x) = development
 *   นอกนั้น = production (ใช้ชุด DB default ใน `config/database.php` สำหรับ production)
 * - บังคับโหมดได้ด้วย `MAINTENANCE_APP_ENV` / `MAINTENANCE_NEW_APP_ENV` หรือ `'app_env' => 'production'|'development'|'auto'` ในไฟล์นี้
 *
 * คีย์ `database` (และ customer_mysql / address_mysql):
 * - ใช้ override credential ต่อโหมด — ถ้า MySQL บนเครื่องไม่ตรง default ให้ใส่ user/password/name ให้ตรง
 *
 * `auth_login_url` / getenv `MAINTENANCE_NEW_AUTH_LOGIN_URL` หรือ `MAINTENANCE_AUTH_LOGIN_URL`:
 * - ใช้เมื่อ production แล้วไม่มี `$_SESSION['profile']` — redirect ไปหน้า login (ค่า default ในโค้ดคือ BIT login บน bit-online.net)
 * Example (production — ปรับ maintenance_web_base ให้ตรง path จริง; มักไม่ต้องใส่ app_env เพราะโดเมนจริงจะถูกเดาเป็น production อัตโนมัติ):
 * return array(
 *     'auth_login_url' => 'https://bit-online.net/bitlogin/index.php',
 *     'maintenance_web_base' => '/maintenance',
 *     'database' => array(
 *         'host' => 'localhost',
 *         'user' => 'biton_maintenance',
 *         'password' => '***',
 *         'name' => 'biton_maintenance',
 *         'port' => 3306,
 *         'charset' => 'utf8mb4',
 *     ),
 *     'customer_mysql' => array(
 *         'user' => 'biton_leasing',
 *         'password' => '***',
 *         'name' => 'biton_leasing',
 *     ),
 *     'address_mysql' => array(
 *         'user' => 'biton_leasing',
 *         'password' => '***',
 *         'name' => 'maintenance',
 *     ),
 * );
 */

return array(
    // ไม่ใส่ app_env = ให้ระบบเดาจาก HTTP_HOST (localhost = development)
    // 'app_env' => 'auto',
    // 'app_env' => 'production',
    // ว่างไว้ = ใช้ default ในโค้ด (BIT login); บนเครื่อง dev ที่บังคับ production อาจ override เป็น path ทดสอบ
    'auth_login_url' => 'https://bit-online.net/bitlogin/index.php',
    'maintenance_web_base' => '/maintenance',
    'inventory_web_base' => '/inventory',
    // ระบบ production (ลิงก์ 'ดูที่มา' ของหลักฐานวันที่ซื้อ → หน้าเครื่อง asset.php?code=S/N)
    'production_web_base' => '/production/finishgoogs_ma_update',
    'database' => array(
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'CHANGE_ME',
        'name' => 'maintenance',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ),
    'customer_mysql' => array(
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'CHANGE_ME',
        'name' => 'biton_leasing',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ),
    'address_mysql' => array(
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'CHANGE_ME',
        'name' => 'maintenance',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ),
    'inventory_mysql' => array(
        'host' => 'localhost',
        'user' => 'root',
        'password' => 'CHANGE_ME',
        'name' => 'biton_inventory',
        'port' => 3306,
        'charset' => 'utf8mb4',
    ),

    // หลักฐานวันที่ซื้อ (หน้ารับเครื่อง → buy_date_suggest) อ่านอย่างเดียว — ใช้บัญชีสิทธิ์ "อ่านอย่างเดียว" ของแต่ละฐาน
    // ไม่ใส่ = ลองอ่านข้ามฐานด้วยบัญชีของระบบซ่อม (เข้าไม่ได้ก็ข้ามแหล่งนั้น)
    'stockparts_mysql' => array(   // ส่งเครื่องจากคลัง (stock_old)
        'host' => 'localhost',
        'user' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'name' => 'biton_stockparts',
    ),
    'production_mysql' => array(   // ประวัติติดตั้ง (installation_history)
        'host' => 'localhost',
        'user' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'name' => 'biton_production',
    ),
    'setup_mysql' => array(        // ส่งมอบ / ใบสั่งติดตั้ง (historical_serials, po_order_part_serials, setup_orders)
        'host' => 'localhost',
        'user' => 'CHANGE_ME',
        'password' => 'CHANGE_ME',
        'name' => 'biton_setup',
    ),

    // debug การบันทึกจาก add_job → insert_job (เขียนไฟล์ใน storage/logs/) — ปิดเมื่อแก้ปัญหาเสร็จ
    'add_job_insert_debug' => false,
    // 'add_job_insert_debug_log_dir' => 'D:\\logs\\maintenance',
);
