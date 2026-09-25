<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * คำนวณข้อความอายุเครื่องจากวันที่ซื้อ — ใช้ร่วมกับ `cal_lifetime.php` และ `detail_job_edit_helpers.php`
 */

// ─────────────────────────────────────────────────────────────────
// helpers
// ─────────────────────────────────────────────────────────────────

if (!function_exists('maintenance_cal_lifetime_plain_from_buy_date')) {
    /**
     * Purpose:
     * คำนวณข้อความอายุการใช้งานจากวันที่ซื้อถึงวันนี้ (ไม่มี HTML)
     *
     * Parameters สำคัญ:
     * - string $date_input: วันที่ที่ strtotime อ่านได้ (เช่น Y-m-d)
     *
     * Return value:
     * - string ข้อความระยะเวลา หรือค่าว่างถ้า parse ไม่ได้
     */
    function maintenance_cal_lifetime_plain_from_buy_date($date_input)
    {
        $trimmed = str_replace(' ', '', (string) $date_input);
        if ($trimmed === '' || $trimmed === '-') {
            return '';
        }
        $ts = strtotime($date_input);
        if ($ts === false) {
            return '';
        }
        $tz = new DateTimeZone('Asia/Bangkok');
        $start = DateTime::createFromFormat('Y-m-d', date('Y-m-d', $ts), $tz);
        if ($start === false) {
            return '';
        }
        $start->setTime(0, 0, 0);
        $end = new DateTime('now', $tz);
        $end->setTime(0, 0, 0);
        if ($start > $end) {
            return '0 วัน ';
        }
        $diff = $start->diff($end);
        $u_y = (int) $diff->y;
        $u_m = (int) $diff->m;
        $u_d = (int) $diff->d;
        $send = '';
        if ($u_y > 0) {
            if ($u_m > 0 && $u_d <= 0) {
                $send = $u_y . ' ปี ' . $u_m . ' เดือน ';
            } elseif ($u_m <= 0 && $u_d > 0) {
                $send = $u_y . ' ปี ' . $u_d . ' วัน ';
            } else {
                $send = $u_y . ' ปี ' . $u_m . ' เดือน ' . $u_d . ' วัน ';
            }
        } elseif ($u_m > 0) {
            if ($u_d <= 0) {
                $send = $u_m . ' เดือน ';
            } else {
                $send = $u_m . ' เดือน ' . $u_d . ' วัน ';
            }
        } elseif ($u_d > 0) {
            $send = $u_d . ' วัน ';
        } else {
            $send = '0 วัน ';
        }

        return $send;
    }
}
