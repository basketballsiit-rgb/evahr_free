<?php
// includes/header.php
if(session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
// Ensure user is logged in
$is_login_page = !isset($_SESSION['user_id']);
if ($is_login_page && basename($_SERVER['PHP_SELF']) != 'index.php') {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$user_header_job = "";
if (!$is_login_page) {
    if(!isset($pdo)) {
        require_once dirname(__FILE__) . '/../config.php';
    }
    try {
        // Enforce basic profile requirement (skip for admin)
        if (($_SESSION['role'] ?? '') !== 'admin') {
            $chk_stmt = $pdo->prepare("SELECT id, department_id, staff_type FROM users WHERE id = ?");
            $chk_stmt->execute([$_SESSION['user_id']]);
            if ($chk_u = $chk_stmt->fetch(PDO::FETCH_ASSOC)) {
                $needs_profile = false;
                if (empty($chk_u['department_id']) || empty($chk_u['staff_type'])) {
                    $needs_profile = true;
                } else if (!in_array($chk_u['staff_type'], ['teacher', 'gov_teacher'])) {
                    $job_chk = $pdo->prepare("SELECT COUNT(*) FROM user_jobs WHERE user_id = ?");
                    $job_chk->execute([$chk_u['id']]);
                    if ($job_chk->fetchColumn() == 0) {
                        $needs_profile = true;
                    }
                }
                
                if ($needs_profile && basename($_SERVER['PHP_SELF']) != 'forced_profile.php') {
                    header("Location: " . BASE_URL . "/staff/forced_profile.php");
                    exit();
                }
            }
        }

        $hj_stmt = $pdo->prepare("
            SELECT 
                (SELECT GROUP_CONCAT(j.title SEPARATOR ', ') FROM user_jobs uj JOIN jobs j ON uj.job_id = j.id WHERE uj.user_id = u.id) as job_titles,
                d.name as dept_name
            FROM users u
            LEFT JOIN departments d ON u.department_id = d.id
            WHERE u.id = ?
        ");
        $hj_stmt->execute([$_SESSION['user_id']]);
        if ($res = $hj_stmt->fetch(PDO::FETCH_ASSOC)) {
            if (!empty($res['job_titles'])) {
                $user_header_job = $res['job_titles'];
            } else if (!empty($res['dept_name'])) {
                $user_header_job = $res['dept_name'];
            }
        }
    } catch(Exception $e) {}
}
?>
<?php $system_theme = getSystemTheme(); ?>
<!DOCTYPE html>
<html lang="th" <?php echo $system_theme !== 'default' ? 'data-theme="'.$system_theme.'"' : ''; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">


    <!-- Google Font: Sarabun & Kanit -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Theme style (AdminLTE v3.2) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- DataTables -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap4.min.css">
    
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/index.css">
    
    <!-- Theme Initializer -->
    <script>
        // Global theme handled by PHP data-theme attribute on HTML tag.
    </script>
</head>
<body class="hold-transition sidebar-mini <?php echo $is_login_page ? 'login-page' : ''; ?>">
<?php if(!$is_login_page): ?>
<div class="wrapper">
    <!-- Navbar -->
    <nav class="main-header navbar navbar-expand navbar-white navbar-light">
        <ul class="navbar-nav">
            <li class="nav-item">
                <a class="nav-link" data-widget="pushmenu" href="#" role="button"><i class="fas fa-bars"></i></a>
            </li>
        </ul>
        <ul class="navbar-nav ml-auto">
            <li class="nav-item dropdown user-menu">
                <a href="#" class="nav-link dropdown-toggle" data-toggle="dropdown">
                    <?php 
                        $avatar_url = !empty($_SESSION['profile_image']) 
                            ? BASE_URL . '/assets/img/' . $_SESSION['profile_image'] 
                            : 'https://ui-avatars.com/api/?name=' . urlencode($_SESSION['firstname']) . '&background=random&color=fff&rounded=true';
                    ?>
                    <img src="<?php echo $avatar_url; ?>" class="user-image img-circle elevation-2" alt="User Image">
                    <span class="d-none d-md-inline-block text-left" style="vertical-align: middle; line-height: 1.2;">
                        <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?><br>
                        <?php if($user_header_job): ?>
                            <small class="text-muted" style="font-size: 0.75rem; font-weight: 500;"><?php echo htmlspecialchars($user_header_job); ?></small>
                        <?php endif; ?>
                    </span>
                </a>
                <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-right">
                    <li class="user-header bg-primary">
                        <img src="<?php echo $avatar_url; ?>" class="img-circle elevation-2" alt="User Image">
                        <p>
                            <?php echo htmlspecialchars($_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?>
                            <small><?php echo htmlspecialchars($user_header_job ? $user_header_job : ucfirst($_SESSION['role'])); ?></small>
                        </p>
                    </li>
                    <li class="user-footer">
                        <?php if($_SESSION['role'] == 'staff'): ?>
                            <a href="<?php echo BASE_URL; ?>/staff/profile.php" class="btn btn-default btn-flat">Profile</a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>/logout.php" class="btn btn-default btn-flat float-right">Sign out</a>
                    </li>
                </ul>
            </li>
        </ul>
    </nav>
    <!-- /.navbar -->
<?php endif; ?>
