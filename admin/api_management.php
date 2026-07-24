<?php
require_once '../config.php';
// Auth Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบในฐานะผู้ดูแลระบบ';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'API Management';

// Handle Settings Update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_api_settings') {
    $api_enabled = isset($_POST['api_enabled']) ? '1' : '0';
    $api_token = trim($_POST['api_token']);

    if (!empty($api_token)) {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute(['api_enabled', $api_enabled, $api_enabled]);
        $stmt->execute(['api_token', $api_token, $api_token]);
        
        $_SESSION['success'] = 'บันทึกการตั้งค่า API เรียบร้อยแล้ว';
    } else {
        $_SESSION['error'] = 'กรุณาระบุ API Token';
    }
    
    header("Location: api_management.php");
    exit();
}

// Handle Log Clear
if (isset($_GET['action']) && $_GET['action'] == 'clear_logs') {
    $pdo->exec("TRUNCATE TABLE api_logs");
    $_SESSION['success'] = 'ล้างประวัติการใช้งาน API เรียบร้อยแล้ว';
    header("Location: api_management.php");
    exit();
}

// Fetch current settings
$settings = [];
$stmt = $pdo->query("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('api_enabled', 'api_token')");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}
$api_enabled = isset($settings['api_enabled']) ? $settings['api_enabled'] : '1';
$api_token = isset($settings['api_token']) ? $settings['api_token'] : 'npc_sf_2026_api_key_x9k2m';

// Fetch Logs
$logs = [];
try {
    $stmt = $pdo->query("SELECT * FROM api_logs ORDER BY created_at DESC LIMIT 500");
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // If table doesn't exist yet, ignore
}

?>
<?php include '../includes/header.php'; ?>
<?php include '../includes/sidebar.php'; ?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <!-- Content Header -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-plug text-primary mr-2"></i> API Management</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            
            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="fas fa-check-circle mr-2"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
                </div>
            <?php endif; ?>
            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <button type="button" class="close" data-dismiss="alert">&times;</button>
                    <i class="fas fa-exclamation-circle mr-2"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
                </div>
            <?php endif; ?>

            <div class="row">
                <!-- Settings Column -->
                <div class="col-md-4">
                    <div class="card card-outline card-primary shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title font-weight-bold">ตั้งค่า API</h3>
                        </div>
                        <form action="api_management.php" method="POST">
                            <input type="hidden" name="action" value="update_api_settings">
                            <div class="card-body">
                                
                                <div class="form-group">
                                    <div class="custom-control custom-switch custom-switch-lg">
                                        <input type="checkbox" class="custom-control-input" id="api_enabled" name="api_enabled" value="1" <?php echo $api_enabled == '1' ? 'checked' : ''; ?>>
                                        <label class="custom-control-label" for="api_enabled">เปิดใช้งาน API</label>
                                    </div>
                                    <small class="form-text text-muted">หากปิดการใช้งาน ระบบภายนอกจะไม่สามารถดึงข้อมูลผ่าน API ได้</small>
                                </div>

                                <div class="form-group mt-4">
                                    <label for="api_token">API Secret Token <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <div class="input-group-prepend">
                                            <span class="input-group-text"><i class="fas fa-key"></i></span>
                                        </div>
                                        <input type="text" class="form-control" id="api_token" name="api_token" value="<?php echo htmlspecialchars($api_token); ?>" required>
                                    </div>
                                    <small class="form-text text-muted">รหัสผ่านสำหรับให้ระบบอื่นใช้ยืนยันตัวตนตอนดึง API (ห้ามให้คนนอกรู้เด็ดขาด)</small>
                                </div>

                            </div>
                            <div class="card-footer">
                                <button type="submit" class="btn btn-primary btn-block"><i class="fas fa-save mr-1"></i> บันทึกการตั้งค่า</button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- Logs Column -->
                <div class="col-md-8">
                    <div class="card card-outline card-info shadow-sm">
                        <div class="card-header">
                            <h3 class="card-title font-weight-bold">ประวัติการเรียกใช้งาน (API Access Logs)</h3>
                            <div class="card-tools">
                                <button type="button" class="btn btn-tool text-danger" onclick="confirmClearLogs()">
                                    <i class="fas fa-trash"></i> ล้างประวัติทั้งหมด
                                </button>
                            </div>
                        </div>
                        <div class="card-body table-responsive">
                            <table id="logsTable" class="table table-bordered table-striped table-hover text-sm">
                                <thead class="bg-light">
                                    <tr>
                                        <th>วันที่ / เวลา</th>
                                        <th>Endpoint</th>
                                        <th>บัญชีที่ค้นหา</th>
                                        <th>IP Address</th>
                                        <th>สถานะ</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td data-sort="<?php echo strtotime($log['created_at']); ?>"><?php echo date('d/m/Y H:i:s', strtotime($log['created_at'])); ?></td>
                                            <td><code><?php echo htmlspecialchars($log['endpoint']); ?></code></td>
                                            <td><?php echo htmlspecialchars($log['requested_username']); ?></td>
                                            <td><span class="badge badge-secondary"><?php echo htmlspecialchars($log['ip_address']); ?></span></td>
                                            <td>
                                                <?php if ($log['status'] === 'success'): ?>
                                                    <span class="badge badge-success"><i class="fas fa-check"></i> สำเร็จ</span>
                                                <?php elseif ($log['status'] === 'unauthorized'): ?>
                                                    <span class="badge badge-danger" title="<?php echo htmlspecialchars($log['message']); ?>"><i class="fas fa-ban"></i> ผิดพลาด (Token)</span>
                                                <?php elseif ($log['status'] === 'disabled'): ?>
                                                    <span class="badge badge-warning" title="<?php echo htmlspecialchars($log['message']); ?>"><i class="fas fa-power-off"></i> ปิดใช้งาน</span>
                                                <?php else: ?>
                                                    <span class="badge badge-danger" title="<?php echo htmlspecialchars($log['message']); ?>"><i class="fas fa-times"></i> <?php echo htmlspecialchars($log['status']); ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>

<style>
.custom-switch-lg .custom-control-label::before {
    height: 1.5rem;
    width: 2.5rem;
    border-radius: 3rem;
}
.custom-switch-lg .custom-control-label::after {
    width: 1.5rem;
    height: 1.5rem;
    border-radius: 3rem;
}
.custom-switch-lg .custom-control-input:checked ~ .custom-control-label::after {
    transform: translateX(1rem);
}
</style>

<script>
$(document).ready(function() {
    $('#logsTable').DataTable({
        "order": [[ 0, "desc" ]],
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "pageLength": 25
    });
});

function confirmClearLogs() {
    Swal.fire({
        title: 'ยืนยันการล้างประวัติ?',
        text: "คุณต้องการลบข้อมูลประวัติการใช้งาน API ทั้งหมดใช่หรือไม่!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        cancelButtonColor: '#3085d6',
        confirmButtonText: 'ใช่, ลบทั้งหมด',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = 'api_management.php?action=clear_logs';
        }
    })
}
</script>
