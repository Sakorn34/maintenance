<?php
/**
 * แม่แบบ config การดึงการตั้งค่า checklist จาก server มาใช้ใน localhost
 * คัดลอกเป็น config/checklist_sync.local.php (gitignored — ห้าม commit token)
 *
 * - server (test-tom): ใส่ token อย่างเดียว (เปิด checklist_export)
 * - localhost: ใส่ url + token (ค่าเดียวกับ server) — ปุ่ม「ดึงค่าจาก server」จะแสดงในหน้า checklist_settings
 * - cainfo: path ไฟล์ CA bundle เมื่อ PHP ในเครื่องตรวจใบรับรอง https ไม่ผ่าน (เช่น ca-bundle.crt ของ Git for Windows)
 */
return array(
    'url'    => '',
    'token'  => '',
    'cainfo' => '',
);
