<?php
// includes/sidebar.php
$current_page = basename($_SERVER['PHP_SELF']);
$role = $_SESSION['role'] ?? '';
$is_director = isDirector($pdo, $_SESSION['user_id'] ?? 0);
$is_evaluator = ($role === 'evaluator') || isJobEvaluator($pdo, $_SESSION['user_id'] ?? 0);

if ($is_evaluator && $role !== 'evaluator') {
    // Only show menu if they actually have at least one assigned evaluation
    $chk_evals = $pdo->prepare("SELECT 1 FROM evaluation_evaluator_status WHERE evaluator_id = ? LIMIT 1");
    $chk_evals->execute([$_SESSION['user_id'] ?? 0]);
    if (!$chk_evals->fetchColumn()) {
        $is_evaluator = false;
    }
}

error_log("Sidebar Load: user_id=" . ($_SESSION['user_id'] ?? 'none') . ", role=" . $role . ", is_eval=" . ($is_evaluator ? '1' : '0'));
?>
<!-- Main Sidebar Container -->
<aside class="main-sidebar sidebar-dark-primary elevation-4">
    <!-- Brand Logo -->
    <a href="<?php echo BASE_URL; ?>/index.php" class="brand-link" style="padding-top: 15px; padding-bottom: 15px; height: auto;">
        <div class="text-center mb-2">
            <img src="<?php echo getSystemLogo(); ?>" alt="Logo" class="img-circle elevation-2" style="width: 60px; height: 60px; object-fit: contain; background: white; padding: 2px;">
        </div>
        <span class="brand-text font-weight-bold text-white text-center d-block" style="font-size: 0.9rem; line-height: 1.4; white-space: normal;">
            ระบบประเมิน<br>บุคลากรทางการศึกษา<br><?php echo htmlspecialchars(getSystemName()); ?>
        </span>
    </a>

    <!-- Sidebar -->
    <div class="sidebar">
        <!-- Sidebar Menu -->
        <nav class="mt-4">
            <ul class="nav nav-pills nav-sidebar flex-column text-sm" data-widget="treeview" role="menu" data-accordion="false">
                
                <?php if ($role === 'admin'): ?>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/index.php" class="nav-link <?php echo $current_page == 'index.php' && strpos($_SERVER['REQUEST_URI'], '/admin/') !== false ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/departments.php" class="nav-link <?php echo $current_page == 'departments.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-building"></i>
                        <p>Departments</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/jobs.php" class="nav-link <?php echo $current_page == 'jobs.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-briefcase"></i>
                        <p>Jobs & Positions</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/users.php" class="nav-link <?php echo $current_page == 'users.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-users"></i>
                        <p>Users Management</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/criteria.php" class="nav-link <?php echo $current_page == 'criteria.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-list-alt"></i>
                        <p>Evaluation Criteria</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/rounds.php" class="nav-link <?php echo $current_page == 'rounds.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-calendar-alt"></i>
                        <p>Evaluation Rounds</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <p>Reports</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/report_summary.php" class="nav-link <?php echo $current_page == 'report_summary.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-file-alt"></i>
                        <p>สรุปผลรายบุคคล</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/settings.php" class="nav-link <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-cogs"></i>
                        <p>System Settings</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/eval_settings.php" class="nav-link <?php echo $current_page == 'eval_settings.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tasks"></i>
                        <p>Evaluation Settings</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/api_management.php" class="nav-link <?php echo $current_page == 'api_management.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-plug"></i>
                        <p>API Management</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/backup_scores.php" class="nav-link <?php echo $current_page == 'backup_scores.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-save"></i>
                        <p>Backup System</p>
                    </a>
                </li>

                <?php elseif (in_array($role, ['evaluator', 'staff'])): ?>
                <li class="nav-header">เมนูสำหรับบุคลากร</li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/staff/index.php" class="nav-link <?php echo $current_page == 'index.php' && strpos($_SERVER['REQUEST_URI'], '/staff/') !== false ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-home"></i>
                        <p>Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/staff/profile.php" class="nav-link <?php echo $current_page == 'profile.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-user-edit"></i>
                        <p>Update Profile</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/staff/my_evaluations.php" class="nav-link <?php echo $current_page == 'my_evaluations.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-file-alt"></i>
                        <p>My Evaluations</p>
                    </a>
                </li>
                
                <?php if ($is_evaluator): ?>
                <li class="nav-header">เมนูสำหรับกรรมการประเมิน</li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/evaluator/index.php" class="nav-link <?php echo $current_page == 'index.php' && strpos($_SERVER['REQUEST_URI'], '/evaluator/') !== false ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-tachometer-alt"></i>
                        <p>Evaluator Dashboard</p>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/evaluator/evaluate.php" class="nav-link <?php echo $current_page == 'evaluate.php' && strpos($_SERVER['REQUEST_URI'], '/evaluator/') !== false ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-clipboard-check"></i>
                        <p>Pending Evaluations</p>
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>

                <?php if ($is_director && $role !== 'admin'): ?>
                <li class="nav-header">รายงานผู้บริหาร</li>
                <li class="nav-item">
                    <a href="<?php echo BASE_URL; ?>/admin/reports.php" class="nav-link <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>">
                        <i class="nav-icon fas fa-chart-line"></i>
                        <p>Executive Reports</p>
                    </a>
                </li>
                <?php endif; ?>

                <li class="nav-item mt-4">
                    <a href="<?php echo BASE_URL; ?>/logout.php" class="nav-link text-danger">
                        <i class="nav-icon fas fa-sign-out-alt"></i>
                        <p>Logout</p>
                    </a>
                </li>
            </ul>
        </nav>
        <!-- /.sidebar-menu -->
    </div>
    <!-- /.sidebar -->
</aside>
