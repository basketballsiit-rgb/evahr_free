<?php
// Script สำหรับติดตั้งไฟล์ api/user_profile.php โดยตรงผ่าน Web Server
$target = __DIR__ . '/api/user_profile.php'; // แก้ไข Path ให้ถูกต้อง

// โค้ดของ api/user_profile.php ที่อัปเดตล่าสุด
$api_code = <<<'EOD'
<?php
/**
 * NPC Job — User Profile API
 * ดึงข้อมูล ตำแหน่ง/ฝ่าย/งาน ของบุคลากร เพื่อใช้กับระบบภายใน
 *
 * GET /npcjob/api/user_profile.php?username={username}&token={API_TOKEN}
 */

require_once dirname(__DIR__) . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: https://service.npc.ac.th');
header('Access-Control-Allow-Methods: GET');

// ─── Secret Token (ต้องตรงกับ NPCJOB_API_TOKEN ใน npc_smartflow .env) ───
define('API_SECRET_TOKEN', 'npc_sf_2026_api_key_x9k2m');

// ─── Auth ──────────────────────────────────────────────────────────────────
$token    = $_GET['token']    ?? '';
$username = trim($_GET['username'] ?? '');

if ($token !== API_SECRET_TOKEN) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (empty($username)) {
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
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}
EOD;

// ลบไฟล์เดิมถ้ามี (เพื่อเคลียร์สิทธิ์)
if (file_exists($target)) {
    @unlink($target);
}

// สร้างและเขียนไฟล์
$result = file_put_contents($target, $api_code);

if ($result !== false) {
    // กำหนดสิทธิ์ให้ไฟล์ใหม่เผื่อไว้
    chmod($target, 0777);
    echo "<h1><span style='color: green;'>ติดตั้งสำเร็จ! 🎉</span></h1>";
    echo "<p>ระบบได้ทำการเขียนโค้ดใหม่ทับไฟล์ <b>api/user_profile.php</b> สำเร็จแล้ว!</p>";
    echo "<p>ตอนนี้สามารถกลับไปใช้งานระบบประเมินได้ตามปกติเลยครับ</p>";
} else {
    echo "<h1><span style='color: red;'>พบปัญหา Permission จริงๆ</span></h1>";
    echo "<p>ตอนนี้มั่นใจแล้วครับว่าตัวโฟลเดอร์ <b>api</b> ถูกล็อคจากระดับ OS ทำให้เขียนไฟล์ไม่ได้เลยครับ</p>";
    echo "<p>รบกวนคุณนิพนธ์แก้ไขด้วยวิธีนี้ครับ:</p>";
    echo "<ol>";
    echo "<li>ไปที่โปรแกรม WinSCP</li>";
    echo "<li>คลิกขวาที่ไฟล์ <b>user_profile.php</b> เก่าในโฟลเดอร์ api แล้วกด <b>Rename (เปลี่ยนชื่อ)</b> เป็นชื่ออะไรก็ได้ (เช่น user_profile_old.php)</li>";
    echo "<li>เมื่อเปลี่ยนชื่อเสร็จแล้ว ให้ลากไฟล์ user_profile.php จากเครื่องตัวเองไปวางใหม่ครับ (มันจะไม่มองว่าเป็นการเขียนทับแล้ว)</li>";
    echo "</ol>";
}
?>
