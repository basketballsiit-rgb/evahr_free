<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['evaluator', 'staff'])) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

// Force profile completion check
$fp_stmt = $pdo->prepare("SELECT id, staff_type, department_id FROM users WHERE id = ?");
$fp_stmt->execute([$_SESSION['user_id']]);
$fp_user = $fp_stmt->fetch(PDO::FETCH_ASSOC);

$needs_profile = false;
if (!$fp_user) {
    $needs_profile = true;
} else if (empty($fp_user['staff_type']) || empty($fp_user['department_id'])) {
    $needs_profile = true;
} else if ($fp_user['staff_type'] !== 'teacher') {
    // Staff and general must have at least one job
    $job_chk = $pdo->prepare("SELECT COUNT(*) FROM user_jobs WHERE user_id = ?");
    $job_chk->execute([$fp_user['id']]);
    if ($job_chk->fetchColumn() == 0) {
        $needs_profile = true;
    }
}

if ($needs_profile) {
    header("Location: " . BASE_URL . "/staff/forced_profile.php");
    exit();
}

// Run automated sync to clear obsolete jobs from evaluatees before displaying
syncOpenRounds($pdo);

$page_title = 'Evaluator Dashboard';
$user_id = $_SESSION['user_id'];

// Fetch all evaluations for this evaluator in open rounds to group them
$stmt = $pdo->prepare("
    SELECT ei.evaluatee_id, ei.round_id, ei.status as global_status, ees.status as my_status
    FROM evaluation_evaluator_status ees
    JOIN evaluation_instances ei ON ees.evaluation_instance_id = ei.id
    JOIN evaluation_rounds r ON ei.round_id = r.id
    WHERE ees.evaluator_id = ? AND r.status = 'open'
");
$stmt->execute([$user_id]);
$all_evals = $stmt->fetchAll(PDO::FETCH_ASSOC);

$grouped_stats = [];
foreach ($all_evals as $ev) {
    $key = $ev['round_id'] . '_' . $ev['evaluatee_id'];
    if (!isset($grouped_stats[$key])) {
        $grouped_stats[$key] = [
            'max_global_status' => 0,
            'has_pending_staff' => false,
            'has_pending_eval_ready' => false,
            'has_pending_eval_not_ready' => false,
            'all_completed' => true
        ];
    }
    
    $g_stat = 0;
    if ($ev['global_status'] == 'completed') $g_stat = 2;
    elseif ($ev['global_status'] == 'pending_evaluator') $g_stat = 1;
    
    $grouped_stats[$key]['max_global_status'] = max($grouped_stats[$key]['max_global_status'], $g_stat);
    
    if ($ev['global_status'] == 'pending_staff') {
        $grouped_stats[$key]['has_pending_staff'] = true;
    }
    
    if ($ev['my_status'] == 'pending') {
        $grouped_stats[$key]['all_completed'] = false;
        
        if ($g_stat > 0) {
            $grouped_stats[$key]['has_pending_eval_ready'] = true;
        } else {
            $grouped_stats[$key]['has_pending_eval_not_ready'] = true;
        }
    }
}

$pending_count = 0;
$completed_count = 0;
$waiting_count = 0;

foreach ($grouped_stats as $g) {
    if ($g['has_pending_eval_ready']) {
        $pending_count++;
    } elseif ($g['has_pending_staff']) {
        $waiting_count++;
    } elseif ($g['all_completed']) {
        $completed_count++;
    } else {
        $pending_count++; // Fallback
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">ระบบหน้าจอกรรมการ (Evaluator Dashboard)</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-lg-4 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?php echo $pending_count; ?></h3>
                            <p>รอท่านให้คะแนน (Staff ส่งแล้ว)</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-edit"></i>
                        </div>
                        <a href="evaluate.php" class="small-box-footer">ไปหน้าให้คะแนน <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <div class="col-lg-4 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo $completed_count; ?></h3>
                            <p>ประเมินเสร็จสิ้นแล้ว</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-check-double"></i>
                        </div>
                        <a href="evaluate.php" class="small-box-footer">ดูประวัติ <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <div class="col-lg-4 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo $waiting_count; ?></h3>
                            <p>รอเจ้าหน้าที่กรอกข้อมูล</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <a href="evaluate.php" class="small-box-footer">ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card card-outline card-primary shadow glass-panel">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle text-info"></i> คำแนะนำสำหรับกรรมการ</h3>
                        </div>
                        <div class="card-body">
                            <ul>
                                <li>ไปที่ <b>"Pending Evaluations"</b> เพื่อดูรายชื่อเจ้าหน้าที่ที่ท่านต้องประเมิน</li>
                                <li>ระบบจะแสดงเฉพาะรายชื่อในรอบที่มีสถานะ <b>เปิด (Open)</b> เท่านั้น</li>
                                <li>ท่านสามารถประเมินได้ก็ต่อเมื่อเจ้าหน้าที่ได้กรอกข้อมูลผลการปฏิบัติงานส่งมาแล้ว (สถานะเป็น "รอกรรมการประเมิน")</li>
                                <li>เมื่อให้คะแนนและบันทึกผล สถานะจะเปลี่ยนเป็น "ประเมินเสร็จสิ้น" และไปโชว์ที่ Dashboard ของ Admin</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
