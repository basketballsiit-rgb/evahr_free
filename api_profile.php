<?php
/**
 * NPC Job — User Profile API (New Endpoint)
 * ดึงข้อมูล ตำแหน่ง/ฝ่าย/งาน ของบุคลากร เพื่อใช้กับระบบภายใน
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://service.npc.ac.th');
header('Access-Control-Allow-Methods: GET');

// ─── Get API Settings from Database ───────────────────────────────────────
$api_enabled = '1';
$api_secret = 'npc_sf_2026_api_key_x9k2m';
try {
    $stmt_set = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('api_enabled', 'api_token')");
    while ($row = $stmt_set->fetch(PDO::FETCH_ASSOC)) {
        if ($row['setting_key'] === 'api_enabled') $api_enabled = $row['setting_value'];
        if ($row['setting_key'] === 'api_token') $api_secret = $row['setting_value'];
    }
} catch (Exception $e) {}

// Helper function to log API requests
function log_api_request($pdo, $endpoint, $username, $status, $message = '') {
    try {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $stmt = $pdo->prepare("INSERT INTO api_logs (endpoint, requested_username, ip_address, status, message) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$endpoint, $username, $ip, $status, $message]);
    } catch (Exception $e) {}
}

// ─── Auth ──────────────────────────────────────────────────────────────────
$token    = $_GET['token']    ?? '';
$username = trim($_GET['username'] ?? '');

if ($api_enabled !== '1') {
    log_api_request($pdo, '/api_profile.php', $username, 'disabled', 'API is currently disabled by admin');
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'API is currently disabled']);
    exit;
}

if ($token !== $api_secret) {
    log_api_request($pdo, '/api_profile.php', $username, 'unauthorized', 'Invalid token provided');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (empty($username)) {
    log_api_request($pdo, '/api_profile.php', $username, 'bad_request', 'username is required');
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'username is required']);
    exit;
}

// ─── Query npcjob DB ────────────────────────────────────────────────────────
try {
    // 1. ดึงข้อมูลพื้นฐานของผู้ใช้
    $stmt = $pdo->prepare("
        SELECT
            u.id,
            u.prefix,
            u.firstname,
            u.lastname,
            u.username,
            u.role,
            u.staff_type,
            d.name  AS department_name
        FROM users u
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE u.username = ?
        LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        log_api_request($pdo, '/api_profile.php', $username, 'not_found', 'User not found in DB');
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'User not found', 'username' => $username]);
        exit;
    }

    // 2. ดึงข้อมูลตำแหน่งที่เกี่ยวข้องทั้งหมดจากตาราง user_jobs
    $stmt_jobs = $pdo->prepare("
        SELECT j.title, j.job_level, d.name as job_department
        FROM user_jobs uj
        JOIN jobs j ON uj.job_id = j.id
        LEFT JOIN departments d ON j.department_id = d.id
        WHERE uj.user_id = ?
        ORDER BY j.job_level ASC
    ");
    $stmt_jobs->execute([$user['id']]);
    $jobs = $stmt_jobs->fetchAll(PDO::FETCH_ASSOC);

    // สร้าง array ของชื่อตำแหน่งอย่างเดียวเพื่อความเข้ากันได้
    $job_titles = array_column($jobs, 'title');
    $primary_position = !empty($job_titles) ? implode(', ', $job_titles) : null;
    $primary_job_level = !empty($jobs) ? $jobs[0]['job_level'] : null;

    // Log success
    log_api_request($pdo, '/api_profile.php', $username, 'success', 'Successfully fetched profile');

    // ─── Return clean response ─────────────────────────────────────────────
    echo json_encode([
        'success' => true,
        'user'    => [
            'username'        => $user['username'],
            'prefix'          => $user['prefix']          ?? '',
            'firstname'       => $user['firstname']       ?? '',
            'lastname'        => $user['lastname']         ?? '',
            'display_name'    => trim(($user['prefix'] ?? '') . $user['firstname'] . ' ' . $user['lastname']),
            'department_name' => $user['department_name'] ?? null,
            'position'        => $primary_position, // ส่งเป็น string คั่นด้วยลูกน้ำ (สำหรับระบบเก่าที่ยังใช้)
            'job_level'       => $primary_job_level,
            'staff_type'      => $user['staff_type']      ?? null,
            'role'            => $user['role']             ?? 'staff',
            'all_positions'   => $jobs // ส่งแบบ Array เต็มรูปแบบพร้อมแผนกของตำแหน่งนั้นๆ
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (PDOException $e) {
    log_api_request($pdo, '/api_profile.php', $username, 'error', 'Database error');
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
