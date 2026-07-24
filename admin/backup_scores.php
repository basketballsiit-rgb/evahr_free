<?php
// admin/backup_scores.php
require_once '../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'ระบบสำรองข้อมูล (Backup)';

// Handle Export Action
if (isset($_GET['action'])) {
    if ($_GET['action'] === 'export_csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=scores_backup_' . date('Ymd_His') . '.csv');
        
        // Add BOM to fix UTF-8 in Excel
        echo "\xEF\xBB\xBF";
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['รอบการประเมิน', 'ID ผู้รับการประเมิน', 'ชื่อผู้รับการประเมิน', 'นามสกุลผู้รับการประเมิน', 'คะแนนเฉลี่ยรวม', 'สถานะ']);
        
        $sql = "
            SELECT r.name as round_name, u.id as user_id, u.firstname, u.lastname, ei.total_score_average, ei.status 
            FROM evaluation_instances ei
            JOIN evaluation_rounds r ON ei.round_id = r.id
            JOIN users u ON ei.evaluatee_id = u.id
            ORDER BY r.id DESC, u.firstname ASC
        ";
        $stmt = $pdo->query($sql);
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            fputcsv($output, $row);
        }
        fclose($output);
        exit();
    }
    
    if ($_GET['action'] === 'export_sql' || $_GET['action'] === 'close_and_backup') {
        
        $round_title_clean = 'All';
        
        if ($_GET['action'] === 'close_and_backup') {
            // ดึงชื่อรอบประเมินปัจจุบันมาทำชื่อไฟล์
            $r_stmt = $pdo->query("SELECT title FROM evaluation_rounds WHERE status = 'open' LIMIT 1");
            $r_row = $r_stmt->fetch(PDO::FETCH_ASSOC);
            if ($r_row && !empty($r_row['title'])) {
                // แทนที่ช่องว่าง สแลช ด้วย underscore
                $round_title_clean = str_replace([' ', '/', '\\', 'ครั้งที่'], ['_', '_', '_', ''], $r_row['title']);
                $round_title_clean = trim($round_title_clean, '_');
            } else {
                $round_title_clean = 'closed_round';
            }
            
            // ปิดระบบการประเมินรอบปัจจุบันทั้งหมด
            $pdo->query("UPDATE evaluation_rounds SET status = 'closed' WHERE status = 'open'");
        }
        
        $filename = 'backup_round_' . $round_title_clean . '_' . date('Ymd_His') . '.sql';
        
        $sql_content = "-- Database Backup Generated: " . date('Y-m-d H:i:s') . "\n";
        if ($_GET['action'] === 'close_and_backup') {
            $sql_content .= "-- System Status: CLOSED\n\n";
        } else {
            $sql_content .= "-- System Status: MANUAL BACKUP\n\n";
        }
        
        $tables_to_backup = [];
        $stmt = $pdo->query("SHOW TABLES");
        while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
            $tables_to_backup[] = $row[0];
        }
        
        foreach ($tables_to_backup as $table) {
            $sql_content .= "-- \n-- Table structure for table `$table`\n-- \n\n";
            // Get create table
            $stmt = $pdo->query("SHOW CREATE TABLE `$table`");
            $row = $stmt->fetch(PDO::FETCH_NUM);
            $sql_content .= "DROP TABLE IF EXISTS `$table`;\n";
            $sql_content .= $row[1] . ";\n\n";
            
            // Get data
            $sql_content .= "-- \n-- Dumping data for table `$table`\n-- \n\n";
            $stmt = $pdo->query("SELECT * FROM `$table`");
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (count($rows) > 0) {
                $sql_content .= "INSERT INTO `$table` VALUES \n";
                $inserts = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $vals[] = "NULL";
                        } else {
                            $vals[] = $pdo->quote($val);
                        }
                    }
                    $inserts[] = "(" . implode(", ", $vals) . ")";
                }
                $sql_content .= implode(",\n", $inserts) . ";\n\n";
            }
        }
        
        // Save to Server Directory if it's a "close and backup" (or always, up to you)
        if ($_GET['action'] === 'close_and_backup') {
            $backup_dir = '../backups';
            if (!is_dir($backup_dir)) {
                mkdir($backup_dir, 0777, true);
            }
            file_put_contents($backup_dir . '/' . $filename, $sql_content);
        }
        
        // Send to Browser Download
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        
        echo $sql_content;
        exit();
    }
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<!-- Content Wrapper -->
<div class="content-wrapper">
    <!-- Content Header -->
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0">ระบบสำรองข้อมูล (Backup System)</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            
            <?php if(isset($_GET['closed']) && $_GET['closed'] == 'success'): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> ปิดระบบการประเมินและสำรองข้อมูลเรียบร้อยแล้ว!
            </div>
            <?php endif; ?>

            <div class="row">
                <div class="col-md-6">
                    <div class="card card-primary">
                        <div class="card-header">
                            <h3 class="card-title">ดาวน์โหลดไฟล์สำรองข้อมูลคะแนน</h3>
                        </div>
                        <div class="card-body">
                            <p>คุณสามารถสำรองข้อมูลคะแนนทั้งหมดในระบบเก็บไว้ในเครื่องคอมพิวเตอร์ของคุณ เพื่อป้องกันข้อมูลสูญหาย หรือใช้สำหรับดูข้อมูลย้อนหลังได้ครับ</p>
                            
                            <a href="backup_scores.php?action=export_csv" class="btn btn-success btn-lg btn-block mb-3">
                                <i class="fas fa-file-excel"></i> ดาวน์โหลดคะแนนเป็นไฟล์ Excel (CSV)
                            </a>
                            <p class="text-muted text-sm">เหมาะสำหรับเปิดดูคะแนนและนำไปคำนวณต่อในโปรแกรม Excel ได้ง่าย</p>
                            
                            <hr>
                            
                            <a href="backup_scores.php?action=export_sql" class="btn btn-warning btn-lg btn-block mb-3">
                                <i class="fas fa-database"></i> ดาวน์โหลดไฟล์ฐานข้อมูลโครงสร้าง (.SQL)
                            </a>
                            <p class="text-muted text-sm mb-4">เหมาะสำหรับสำรองฐานข้อมูล เพื่อนำมา Import กลับเข้าระบบเมื่อเกิดเหตุฉุกเฉิน</p>
                            
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card card-danger">
                        <div class="card-header">
                            <h3 class="card-title">ปิดระบบการประเมิน (Lock System)</h3>
                        </div>
                        <div class="card-body text-center">
                            <i class="fas fa-lock fa-4x text-danger mb-3"></i>
                            <p class="text-danger font-weight-bold">ปุ่มนี้จะทำการ "ปิดการประเมินในรอบปัจจุบันทั้งหมด"</p>
                            <p class="text-left">
                                - ผู้รับการประเมิน และกรรมการ <b>จะถูกล็อกไม่ให้แก้ไขหรือกรอกคะแนนเพิ่มได้อีก</b><br>
                                - ผู้ใช้งานจะทำได้แค่ "ดูผลงานเดิม" และ "ดาวน์โหลดไฟล์แนบ" เท่านั้น<br>
                                - แอดมินสามารถดูสรุปผลและรายงานได้ตามปกติ<br>
                                - <b>เมื่อกดปุ่มนี้ ระบบจะล็อกการประเมิน และดาวน์โหลดไฟล์แบ็คอัพ (.SQL) ให้ทันที</b>
                            </p>
                            
                            <a href="backup_scores.php?action=close_and_backup" onclick="return confirm('ยืนยันการ ปิดระบบการประเมิน และโหลดไฟล์ Backup ใช่หรือไม่?\n\n*ผู้ใช้งานจะไม่สามารถแก้ไขข้อมูลได้อีกจนกว่าจะเปิดรอบใหม่');" class="btn btn-danger btn-lg btn-block mt-3">
                                <i class="fas fa-power-off"></i> ปิดระบบประเมิน + ดาวน์โหลด Backup
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php require_once '../includes/footer.php'; ?>
