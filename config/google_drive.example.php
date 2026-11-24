<?php
/**
 * แม่แบบการเชื่อม Google Drive สำหรับเก็บรูปประกอบการประเมิน
 * คัดลอกเป็น config/google_drive.local.php (gitignored — ห้าม commit)
 *
 * ขั้นตอนตั้งค่า (ทำครั้งเดียว ด้วยบัญชี Google Workspace ของออฟฟิศ):
 *  1) console.cloud.google.com → สร้างโปรเจกต์ → APIs & Services → Library → เปิด "Google Drive API"
 *  2) IAM & Admin → Service Accounts → Create (ไม่ต้องให้ role) → เปิดบัญชีนั้น → Keys → Add key → JSON → ดาวน์โหลด
 *     อัปโหลดไฟล์ .json ไว้ที่ config/google_drive_sa.credentials.json (ชื่อนี้ถูก gitignore แล้ว)
 *  3) Google Drive → Shared drives → สร้าง เช่น "รูปงานซ่อม" → Manage members → เพิ่มอีเมล service account
 *     (xxx@<project>.iam.gserviceaccount.com) สิทธิ์ "Content manager"
 *  4) เปิด Shared drive (หรือโฟลเดอร์ในนั้น) → คัดลอก id จาก URL หลัง /folders/ มาใส่ folder_id
 *  5) เปิด checklist_photo?action=drive_check ในเบราว์เซอร์ (ล็อกอินอยู่) → ต้องได้ "ok": true
 *
 * folder_id ต้องเป็น Shared Drive (หรือโฟลเดอร์ใน Shared Drive) — โฟลเดอร์ใน My Drive ใช้ไม่ได้ เพราะ service account ไม่มีพื้นที่เก็บไฟล์ของตัวเอง
 * impersonate (ทางเลือก แทน Shared Drive): อีเมลผู้ใช้ Workspace ที่ให้ระบบทำงานในนาม — ต้องให้ผู้ดูแล Workspace เปิด domain-wide delegation
 *   (admin.google.com → Security → API controls → Domain-wide delegation → Add: Client ID ของ service account, scope https://www.googleapis.com/auth/drive)
 * keep_local: true = เก็บรูปขนาดดูบน server ไว้ด้วย (ปกติ false — เหลือแค่รูปเล็กบน server ประหยัดพื้นที่)
 * cainfo: path ไฟล์ CA bundle ถ้า PHP ตรวจใบรับรอง https ไม่ผ่าน (เช่นเครื่อง Windows ที่ใช้ AppServ)
 */
return array(
    'enabled'     => true,
    'credentials' => __DIR__ . '/google_drive_sa.credentials.json',
    'folder_id'   => '',
    'keep_local'  => false,
    'impersonate' => '',
    'cainfo'      => '',
);
