<?php
/**
 * วัตถุประสงค์และขอบเขต:
 * เชื่อม Google Drive (Shared Drive ของ Google Workspace) ด้วย service account — PHP ล้วน (curl + openssl) ไม่ต้องใช้ไลบรารี
 * ใช้เก็บรูปประกอบการประเมิน: อัปโหลด / ดาวน์โหลด / ย้ายลงถังขยะ / หา-สร้างโฟลเดอร์
 *
 * config: config/google_drive.local.php (gitignored — แม่แบบ config/google_drive.example.php)
 * กุญแจ service account (.json) เก็บนอก git เช่น config/google_drive_sa.credentials.json
 */

if (!function_exists('mn_gd_config')) {
    function mn_gd_config()
    {
        static $cfg = null;
        if ($cfg !== null) { return $cfg; }
        $cfg = array('enabled' => false, 'credentials' => '', 'folder_id' => '', 'cainfo' => '', 'keep_local' => false, 'impersonate' => '');
        $file = dirname(__DIR__) . '/config/google_drive.local.php';
        if (is_file($file)) {
            $loaded = require $file;
            if (is_array($loaded)) {
                foreach ($cfg as $k => $v) {
                    if (array_key_exists($k, $loaded)) { $cfg[$k] = is_bool($v) ? (bool) $loaded[$k] : trim((string) $loaded[$k]); }
                }
            }
        }
        return $cfg;
    }
}

if (!function_exists('mn_gd_enabled')) {
    function mn_gd_enabled()
    {
        $cfg = mn_gd_config();
        return $cfg['enabled'] && $cfg['credentials'] !== '' && $cfg['folder_id'] !== '' && is_file($cfg['credentials'])
            && function_exists('curl_init') && function_exists('openssl_sign');
    }
}

if (!function_exists('mn_gd_log')) {
    function mn_gd_log($msg)
    {
        $dir = dirname(__DIR__) . '/storage/logs';
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($dir . '/gdrive.log', date('Y-m-d H:i:s') . ' ' . $msg . "\n", FILE_APPEND);
    }
}

if (!function_exists('mn_gd_b64url')) {
    function mn_gd_b64url($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

/** เรียก HTTP → array(http_code, body, error) */
if (!function_exists('mn_gd_http')) {
    function mn_gd_http($method, $url, array $headers = array(), $body = null, $timeout = 30)
    {
        $cfg = mn_gd_config();
        $ch = curl_init($url);
        $opts = array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
        );
        if ($body !== null) { $opts[CURLOPT_POSTFIELDS] = $body; }
        if ($cfg['cainfo'] !== '') { $opts[CURLOPT_CAINFO] = $cfg['cainfo']; }
        curl_setopt_array($ch, $opts);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return array($code, $res === false ? '' : $res, $err);
    }
}

/** สร้าง JWT ที่ลงลายเซ็นด้วยกุญแจ service account (RS256) */
if (!function_exists('mn_gd_jwt')) {
    function mn_gd_jwt(array $sa, $scope, $now, $sub = '')
    {
        $head = mn_gd_b64url(json_encode(array('alg' => 'RS256', 'typ' => 'JWT')));
        $claims = array(
            'iss'   => $sa['client_email'],
            'scope' => $scope,
            'aud'   => isset($sa['token_uri']) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        );
        // ทำงานในนามผู้ใช้ Workspace (domain-wide delegation) — ไฟล์เป็นของผู้ใช้นั้น ใช้โฟลเดอร์ใน My Drive ได้
        if ($sub !== '') { $claims['sub'] = $sub; }
        $claim = mn_gd_b64url(json_encode($claims));
        $key = openssl_pkey_get_private($sa['private_key']);
        if (!$key) { return ''; }
        $sig = '';
        if (!openssl_sign($head . '.' . $claim, $sig, $key, OPENSSL_ALGO_SHA256)) { return ''; }
        return $head . '.' . $claim . '.' . mn_gd_b64url($sig);
    }
}

/** access token (เก็บไว้ใช้ซ้ำจนใกล้หมดอายุ) → array(token, error) */
if (!function_exists('mn_gd_token')) {
    function mn_gd_token($force = false)
    {
        $cfg = mn_gd_config();
        $cache = dirname(__DIR__) . '/storage/gdrive_token.json';
        if (!$force && is_file($cache)) {
            $c = json_decode((string) @file_get_contents($cache), true);
            if (is_array($c) && !empty($c['token']) && (int) $c['exp'] > time() + 120) { return array($c['token'], ''); }
        }
        $sa = json_decode((string) @file_get_contents($cfg['credentials']), true);
        if (!is_array($sa) || empty($sa['client_email']) || empty($sa['private_key'])) { return array('', 'ไฟล์กุญแจ service account ไม่ถูกต้อง'); }
        $jwt = mn_gd_jwt($sa, 'https://www.googleapis.com/auth/drive', time(), $cfg['impersonate']);
        if ($jwt === '') { return array('', 'ลงลายเซ็นด้วยกุญแจ service account ไม่สำเร็จ'); }
        $uri = isset($sa['token_uri']) ? $sa['token_uri'] : 'https://oauth2.googleapis.com/token';
        list($code, $body, $err) = mn_gd_http('POST', $uri, array('Content-Type: application/x-www-form-urlencoded'),
            http_build_query(array('grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => $jwt)), 20);
        $res = json_decode($body, true);
        if ($code !== 200 || empty($res['access_token'])) {
            $why = $err !== '' ? $err : (isset($res['error_description']) ? $res['error_description'] : ('HTTP ' . $code));
            return array('', 'ขอสิทธิ์จาก Google ไม่สำเร็จ: ' . $why);
        }
        $dir = dirname($cache);
        if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
        @file_put_contents($cache, json_encode(array('token' => $res['access_token'], 'exp' => time() + (int) $res['expires_in'])));
        return array($res['access_token'], '');
    }
}

/** เรียก Drive API พร้อม token (ลองขอ token ใหม่ 1 ครั้งถ้าหมดอายุ) → array(code, decoded|raw, error) */
if (!function_exists('mn_gd_api')) {
    function mn_gd_api($method, $url, array $headers = array(), $body = null, $raw = false)
    {
        for ($try = 0; $try < 2; $try++) {
            list($token, $err) = mn_gd_token($try > 0);
            if ($token === '') { return array(0, null, $err); }
            list($code, $res, $cerr) = mn_gd_http($method, $url, array_merge(array('Authorization: Bearer ' . $token), $headers), $body, 60);
            if ($code === 401 && $try === 0) { continue; }
            if ($cerr !== '') { return array(0, null, $cerr); }
            if ($code < 200 || $code >= 300) {
                $j = json_decode($res, true);
                return array($code, null, isset($j['error']['message']) ? $j['error']['message'] : ('HTTP ' . $code));
            }
            return array($code, $raw ? $res : json_decode($res, true), '');
        }
        return array(401, null, 'สิทธิ์ไม่ถูกต้อง');
    }
}

if (!function_exists('mn_gd_q')) {
    function mn_gd_q($s)
    {
        return str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $s);
    }
}

/** หา/สร้างโฟลเดอร์ชื่อ $name ใต้ $parent (จำ id ไว้ในตาราง cl_drive_folder) → array(id, error) */
if (!function_exists('mn_gd_folder')) {
    function mn_gd_folder($connect, $parent, $name)
    {
        $name = trim(preg_replace('/[\\\\\/:*?"<>|]+/u', ' ', (string) $name));
        if ($name === '') { $name = 'อื่น ๆ'; }
        $key = md5($parent . '|' . $name);
        $stmt = mysqli_prepare($connect, 'SELECT cdf_id FROM cl_drive_folder WHERE cdf_key = ?');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $key);
            mysqli_stmt_execute($stmt);
            $r = mysqli_stmt_get_result($stmt);
            $row = $r ? mysqli_fetch_row($r) : null;
            mysqli_stmt_close($stmt);
            if ($row) { return array((string) $row[0], ''); }
        }
        $q = "name = '" . mn_gd_q($name) . "' and '" . mn_gd_q($parent) . "' in parents and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        list($code, $res, $err) = mn_gd_api('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query(array(
            'q' => $q, 'fields' => 'files(id)', 'pageSize' => 1,
            'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true', 'corpora' => 'allDrives',
        )));
        if ($err !== '') { return array('', $err); }
        $id = !empty($res['files'][0]['id']) ? (string) $res['files'][0]['id'] : '';
        if ($id === '') {
            list($code, $res, $err) = mn_gd_api('POST', 'https://www.googleapis.com/drive/v3/files?supportsAllDrives=true&fields=id',
                array('Content-Type: application/json; charset=UTF-8'),
                json_encode(array('name' => $name, 'mimeType' => 'application/vnd.google-apps.folder', 'parents' => array($parent)), JSON_UNESCAPED_UNICODE));
            if ($err !== '' || empty($res['id'])) { return array('', $err !== '' ? $err : 'สร้างโฟลเดอร์ไม่สำเร็จ'); }
            $id = (string) $res['id'];
        }
        $ins = mysqli_prepare($connect, 'REPLACE INTO cl_drive_folder (cdf_key, cdf_id) VALUES (?, ?)');
        if ($ins) {
            mysqli_stmt_bind_param($ins, 'ss', $key, $id);
            mysqli_stmt_execute($ins);
            mysqli_stmt_close($ins);
        }
        return array($id, '');
    }
}

/** อัปโหลดไฟล์ → array(file_id, error) */
if (!function_exists('mn_gd_upload')) {
    function mn_gd_upload($folder, $name, $path, $mime)
    {
        $data = @file_get_contents($path);
        if ($data === false) { return array('', 'อ่านไฟล์ไม่ได้'); }
        $b = 'mnb' . bin2hex(random_bytes(8));
        $body = '--' . $b . "\r\nContent-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode(array('name' => $name, 'parents' => array($folder)), JSON_UNESCAPED_UNICODE) . "\r\n"
            . '--' . $b . "\r\nContent-Type: " . $mime . "\r\n\r\n" . $data . "\r\n--" . $b . "--";
        list($code, $res, $err) = mn_gd_api('POST', 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&supportsAllDrives=true&fields=id',
            array('Content-Type: multipart/related; boundary=' . $b), $body);
        if ($err !== '' || empty($res['id'])) { return array('', $err !== '' ? $err : 'อัปโหลดไม่สำเร็จ'); }
        return array((string) $res['id'], '');
    }
}

/** ดาวน์โหลดไฟล์ → array(bytes, error) */
if (!function_exists('mn_gd_download')) {
    function mn_gd_download($file_id)
    {
        list($code, $res, $err) = mn_gd_api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?alt=media&supportsAllDrives=true', array(), null, true);
        return array($err === '' ? (string) $res : '', $err);
    }
}

/** ย้ายไฟล์ลงถังขยะของ Drive (กู้คืนได้ภายใน 30 วัน) */
if (!function_exists('mn_gd_trash')) {
    function mn_gd_trash($file_id)
    {
        list($code, $res, $err) = mn_gd_api('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?supportsAllDrives=true&fields=id',
            array('Content-Type: application/json; charset=UTF-8'), json_encode(array('trashed' => true)));
        return $err;
    }
}

/** เปลี่ยนชื่อไฟล์บน Drive → error */
if (!function_exists('mn_gd_rename')) {
    function mn_gd_rename($file_id, $name)
    {
        list($code, $res, $err) = mn_gd_api('PATCH', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($file_id) . '?supportsAllDrives=true&fields=id',
            array('Content-Type: application/json; charset=UTF-8'), json_encode(array('name' => (string) $name)));
        return $err;
    }
}

/** ตรวจการตั้งค่า (ใช้หลังตั้งค่าครั้งแรก) → array ผลแต่ละขั้น */
if (!function_exists('mn_gd_check')) {
    function mn_gd_check()
    {
        $cfg = mn_gd_config();
        $out = array('enabled' => $cfg['enabled'], 'config' => is_file(dirname(__DIR__) . '/config/google_drive.local.php'),
            'credentials' => $cfg['credentials'] !== '' && is_file($cfg['credentials']), 'folder_id' => $cfg['folder_id'] !== '',
            'curl' => function_exists('curl_init'), 'openssl' => function_exists('openssl_sign'), 'token' => false, 'folder' => '', 'error' => '');
        if (!$out['config'] || !$out['credentials'] || !$out['folder_id']) { $out['error'] = 'ยังตั้งค่าไม่ครบ (ดู config/google_drive.example.php)'; return $out; }
        list($token, $err) = mn_gd_token(true);
        $out['token'] = $token !== '';
        if (!$out['token']) { $out['error'] = $err; return $out; }
        list($code, $res, $err) = mn_gd_api('GET', 'https://www.googleapis.com/drive/v3/files/' . rawurlencode($cfg['folder_id']) . '?supportsAllDrives=true&fields=id,name,driveId,capabilities(canAddChildren)');
        $out['shared_drive'] = !empty($res['driveId']);
        if ($err !== '') { $out['error'] = 'เปิดโฟลเดอร์ไม่ได้: ' . $err . ' (แชร์โฟลเดอร์/Shared Drive ให้ service account แล้วหรือยัง)'; return $out; }
        $out['folder'] = isset($res['name']) ? (string) $res['name'] : '';
        if (empty($res['capabilities']['canAddChildren'])) { $out['error'] = 'service account ไม่มีสิทธิ์เพิ่มไฟล์ในโฟลเดอร์นี้ (ตั้งเป็น Content manager)'; }
        // service account ไม่มีพื้นที่ของตัวเอง — อัปโหลดลงโฟลเดอร์ใน My Drive ไม่ได้ ต้องเป็น Shared Drive หรือตั้ง impersonate
        elseif (!$out['shared_drive'] && $cfg['impersonate'] === '') {
            $out['error'] = 'โฟลเดอร์ "' . $out['folder'] . '" อยู่ใน My Drive ไม่ใช่ Shared Drive — service account อัปโหลดไฟล์ลงที่นี่ไม่ได้'
                . ' (Google ไม่ให้ service account เป็นเจ้าของไฟล์) ให้สร้าง Shared Drive แล้วใช้ id ของ Shared Drive แทน หรือตั้ง impersonate';
        }
        return $out;
    }
}
