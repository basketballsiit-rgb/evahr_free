<?php
require_once '../config.php';
// Auth Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบในฐานะผู้ดูแลระบบ';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Admin Dashboard';

// Fetch Statistics
try {
    $stat_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $stat_depts = $pdo->query("SELECT COUNT(*) FROM departments")->fetchColumn();
    $stat_jobs = $pdo->query("SELECT COUNT(*) FROM jobs")->fetchColumn();
    $stat_rounds = $pdo->query("SELECT COUNT(*) FROM evaluation_rounds WHERE status='open'")->fetchColumn();
} catch(PDOException $e) {
    $_SESSION['error'] = 'Error loading stats: ' . $e->getMessage();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <!-- Content Header (Page header) -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">ภาพรวมระบบ (Admin Dashboard)</h1>
                </div>
            </div>
        </div>
    </div>
    <!-- /.content-header -->

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <!-- Small boxes (Stat box) -->
            <div class="row">
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-purple">
                        <div class="inner">
                            <h3><?php echo $stat_users; ?></h3>
                            <p>ผู้ใช้งานทั้งหมด</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-users"></i>
                        </div>
                        <a href="users.php" class="small-box-footer">ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <!-- ./col -->
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-info">
                        <div class="inner">
                            <h3><?php echo $stat_depts; ?></h3>
                            <p>ฝ่าย/แผนก</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-building"></i>
                        </div>
                        <a href="departments.php" class="small-box-footer">ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <!-- ./col -->
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-primary">
                        <div class="inner">
                            <h3><?php echo $stat_jobs; ?></h3>
                            <p>งาน/ตำแหน่ง</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-briefcase"></i>
                        </div>
                        <a href="jobs.php" class="small-box-footer">ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <!-- ./col -->
                <div class="col-lg-3 col-6">
                    <div class="small-box bg-success">
                        <div class="inner">
                            <h3><?php echo $stat_rounds; ?></h3>
                            <p>รอบที่เปิดประเมิน</p>
                        </div>
                        <div class="icon">
                            <i class="fas fa-calendar-check"></i>
                        </div>
                        <a href="rounds.php" class="small-box-footer">ดูรายละเอียด <i class="fas fa-arrow-circle-right"></i></a>
                    </div>
                </div>
                <!-- ./col -->
            </div>
            <!-- /.row -->
            
            <!-- Quick Actions -->
            <div class="row mt-4">
                <div class="col-md-6">
                    <div class="card card-outline card-primary shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-bolt text-warning mr-1"></i> เมนูด่วน (Quick Actions)</h3>
                        </div>
                        <div class="card-body">
                            <a href="rounds.php?action=add" class="btn btn-app bg-success">
                                <i class="fas fa-plus"></i> เปิดรอบใหม่
                            </a>
                            <a href="users.php?action=add" class="btn btn-app bg-info">
                                <i class="fas fa-user-plus"></i> เพิ่มบุคลากร
                            </a>
                            <a href="hierarchies.php" class="btn btn-app bg-purple">
                                <i class="fas fa-sitemap"></i> จัดสายประเมิน
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card card-outline card-primary shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-info-circle text-info mr-1"></i> ข้อมูลระบบ</h3>
                        </div>
                        <div class="card-body">
                            <p>ยินดีต้อนรับสู่ระบบประเมินการปฏิบัติงาน <?php echo htmlspecialchars(getSystemName()); ?></p>
                            <p class="text-muted"><small>เวอร์ชัน 1.0.0 | <i class="fas fa-circle text-success mr-1"></i> เชื่อมต่อฐานข้อมูลสำเร็จ</small></p>
                        </div>
                    </div>
                </div>
            </div>
        </div><!-- /.container-fluid -->
    </section>
    <!-- /.content -->
</div>
<!-- /.content-wrapper -->

<?php include '../includes/footer.php'; ?>
