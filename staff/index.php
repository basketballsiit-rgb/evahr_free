<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
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
} else if (!in_array($fp_user['staff_type'], ['teacher', 'gov_teacher'])) {
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

$page_title = 'Staff Dashboard';
$user_id = $_SESSION['user_id'];

// Get pending evaluations
$pending_evaluations = $pdo->prepare("
    SELECT COUNT(*) 
    FROM evaluation_instances ea 
    JOIN evaluation_rounds r ON ea.round_id = r.id 
    WHERE ea.evaluatee_id = ? AND ea.status = 'pending_staff' AND r.status = 'open'
");
$pending_evaluations->execute([$user_id]);
$pending_count = $pending_evaluations->fetchColumn();

// Get completed evaluations
$completed_evaluations = $pdo->prepare("
    SELECT COUNT(*) 
    FROM evaluation_instances ea 
    JOIN evaluation_rounds r ON ea.round_id = r.id 
    WHERE ea.evaluatee_id = ? AND ea.status = 'completed'
");
$completed_evaluations->execute([$user_id]);
$completed_count = $completed_evaluations->fetchColumn();

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">ระบบหน้าจอเจ้าหน้าที่ (Staff Dashboard)</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Small boxes (Stat box) -->
            <div class="row">
                <div class="col-lg-6 col-6">
                    <div class="small-box bg-warning">
                        <div class="inner">
                            <h3><?php echo $pending_count; ?></h3>
                            <p>รายการประเมินที่ต้องกรอกข้อมูล (รอดำเนินการ)</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-file-signature"></i>
                        </div>
                        <a href="my_evaluations.php" class="small-box-footer">ไปหน้าประเมินส่วนตัว <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <!-- ./col -->
                <div class="col-lg-6 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo $completed_count; ?></h3>
                            <p>รายการประเมินที่เสร็จสิ้นแล้ว</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <a href="my_evaluations.php" class="small-box-footer">ดูประวัติ <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="card card-outline card-primary shadow glass-panel">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle text-info"></i> คำแนะนำการใช้งาน</h3>
                        </div>
                        <div class="card-body">
                            <ul>
                                <li>ไปที่ <b>"Update Profile"</b> เพื่ออัพเดทรูปโปรไฟล์และข้อมูลส่วนตัว</li>
                                <li>ไปที่ <b>"My Evaluations"</b> เพื่อกรอกข้อมูลผลการปฏิบัติงานของท่าน (Self-Assessment) ตามหัวข้อและเกณฑ์การประเมินที่กำหนดไว้</li>
                                <li>เมื่อท่านบันทึกข้อมูลและกดส่ง (Submit) สถานะจะเปลี่ยนเป็นรอการประเมินจากกรรมการ</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
