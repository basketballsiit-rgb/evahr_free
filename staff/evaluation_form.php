<?php
// VERSION: v6 - 2026-03-19 22:18
require_once '../config.php';

$form_error = null;

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$assigned_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$assigned_id) {
    header("Location: my_evaluations.php");
    exit();
}

// Verify ownership and status
$stmt = $pdo->prepare("
    SELECT ei.*, r.title as round_title, r.status as round_status, r.staff_deadline, j.title as evaluated_job_title, j.description as job_description, u.staff_type
    FROM evaluation_instances ei 
    JOIN evaluation_rounds r ON ei.round_id = r.id 
    JOIN users u ON ei.evaluatee_id = u.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ei.id = ? AND ei.evaluatee_id = ?
");
$stmt->execute([$assigned_id, $user_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval || !in_array($eval['status'], ['pending_staff', 'pending_evaluator']) || $eval['round_status'] != 'open') {
    $_SESSION['error'] = "รายการนี้ไม่สามารถแก้ไขได้ หรือการประเมินเสร็จสิ้นแล้ว";
    header("Location: my_evaluations.php");
    exit();
}

if (!empty($eval['staff_deadline'])) {
    if (strtotime('now') > strtotime($eval['staff_deadline'])) {
        $_SESSION['error'] = "หมดเวลาการประเมินตนเองแล้ว (ปิดรับอัตโนมัติเมื่อ " . date('d/m/Y H:i', strtotime($eval['staff_deadline'])) . " น.)";
        header("Location: my_evaluations.php");
        exit();
    }
}

$page_title = 'Self Assessment Form';

// Get all criteria structured
$staff_type = $eval['staff_type'] ?? 'staff';
if ($staff_type === 'general') $staff_type = 'staff';
$stmt_crit = $pdo->prepare("SELECT * FROM evaluation_criteria WHERE target_group = 'both' OR target_group = ? ORDER BY sort_order ASC, id ASC");
$stmt_crit->execute([$staff_type]);
$all_criteria = $stmt_crit->fetchAll(PDO::FETCH_ASSOC);

$main_categories = [];
$sub_criteria = [];

foreach ($all_criteria as $c) {
    if ($c['parent_id'] === null) {
        $c['calculated_max'] = $c['max_score'];
        $main_categories[$c['id']] = $c;
    } else {
        $sub_criteria[$c['parent_id']][] = $c;
    }
}

foreach ($sub_criteria as $p_id => $subs) {
    if (isset($main_categories[$p_id])) {
        $main_categories[$p_id]['calculated_max'] = 0;
        foreach ($subs as $s) {
            $main_categories[$p_id]['calculated_max'] += $s['max_score'];
        }
    }
}

// Pre-load existing saved answers (for re-editing after submission)
$existing_inputs = [];
$stmt_ex = $pdo->prepare("SELECT criteria_id, staff_input_text, staff_link, staff_attachment FROM evaluation_criteria_inputs WHERE evaluation_instance_id = ?");
$stmt_ex->execute([$assigned_id]);
foreach ($stmt_ex->fetchAll(PDO::FETCH_ASSOC) as $ex) {
    $existing_inputs[$ex['criteria_id']] = $ex;
}

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_assessment'])) {
    
    $debug_steps = [];
    $debug_steps[] = "instance_id=$assigned_id, user_id=$user_id";
    $debug_steps[] = "POST keys: " . implode(', ', array_keys($_POST));
    
    // Pre-check: detect available columns
    $has_link_col = false;
    $has_attachment_col = false;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM evaluation_criteria_inputs")->fetchAll(PDO::FETCH_COLUMN);
        $has_link_col = in_array('staff_link', $cols);
        $has_attachment_col = in_array('staff_attachment', $cols);
        $debug_steps[] = "Columns: link=" . ($has_link_col ? 'Y' : 'N') . ", attach=" . ($has_attachment_col ? 'Y' : 'N');
    } catch(Exception $e) {
        $debug_steps[] = "Col detect error: " . $e->getMessage();
    }
    
    $upload_dir = __DIR__ . '/../assets/uploads/';
    if ($has_attachment_col && !is_dir($upload_dir)) {
        @mkdir($upload_dir, 0777, true);
    }
    
    try {
        $pdo->beginTransaction();
        
        // 1. Find all active instances for this user in this round
        $stmt_ri = $pdo->prepare("SELECT round_id FROM evaluation_instances WHERE id = ?");
        $stmt_ri->execute([$assigned_id]);
        $curr_round = $stmt_ri->fetchColumn();

        $all_my_instances = [$assigned_id];
        if ($curr_round) {
            $stmt_all = $pdo->prepare("SELECT id FROM evaluation_instances WHERE round_id = ? AND evaluatee_id = ?");
            $stmt_all->execute([$curr_round, $user_id]);
            $all_my_instances = $stmt_all->fetchAll(PDO::FETCH_COLUMN);
        }
        
        // Delete old inputs for all instances
        $delete_old = $pdo->prepare("DELETE FROM evaluation_criteria_inputs WHERE evaluation_instance_id = ?");
        foreach ($all_my_instances as $inst_id) {
            $delete_old->execute([$inst_id]);
        }
        $debug_steps[] = "Deleted old inputs for " . count($all_my_instances) . " instances";
        
        // Build SQL
        if ($has_link_col && $has_attachment_col) {
            $sql = "INSERT INTO evaluation_criteria_inputs (evaluation_instance_id, criteria_id, staff_input_text, staff_link, staff_attachment) VALUES (?, ?, ?, ?, ?)";
        } elseif ($has_attachment_col) {
            $sql = "INSERT INTO evaluation_criteria_inputs (evaluation_instance_id, criteria_id, staff_input_text, staff_attachment) VALUES (?, ?, ?, ?)";
        } else {
            $sql = "INSERT INTO evaluation_criteria_inputs (evaluation_instance_id, criteria_id, staff_input_text) VALUES (?, ?, ?)";
        }
        $insert_detail = $pdo->prepare($sql);
        
        $insert_count = 0;
        foreach ($all_criteria as $c) {
            $cid = $c['id'];
            if (isset($_POST['criteria_'.$cid])) {
                $input_text = trim($_POST['criteria_'.$cid]);
                $link = isset($_POST['link_'.$cid]) ? trim($_POST['link_'.$cid]) : '';
                
                $attachment_filename = null;
                if ($has_attachment_col && isset($_FILES['file_'.$cid]) && $_FILES['file_'.$cid]['error'] === UPLOAD_ERR_OK) {
                    $original_name = $_FILES['file_'.$cid]['name'];
                    $ext = pathinfo($original_name, PATHINFO_EXTENSION);
                    $safe_name = 'eval_' . $assigned_id . '_c' . $cid . '_' . time() . '.' . $ext;
                    $dest = $upload_dir . $safe_name;
                    if (move_uploaded_file($_FILES['file_'.$cid]['tmp_name'], $dest)) {
                        $attachment_filename = $safe_name;
                    }
                }
                
                foreach ($all_my_instances as $inst_id) {
                    if ($has_link_col && $has_attachment_col) {
                        $insert_detail->execute([$inst_id, $cid, $input_text, $link ?: null, $attachment_filename]);
                    } elseif ($has_attachment_col) {
                        $insert_detail->execute([$inst_id, $cid, $input_text, $attachment_filename]);
                    } else {
                        $insert_detail->execute([$inst_id, $cid, $input_text]);
                    }
                }
                $insert_count++;
            }
        }
        $debug_steps[] = "Inserted $insert_count rows per instance";

        $update_status = $pdo->prepare("UPDATE evaluation_instances SET status = 'pending_evaluator' WHERE id = ? AND status != 'completed'");
        foreach ($all_my_instances as $inst_id) {
            $update_status->execute([$inst_id]);
        }

        $pdo->commit();
        $_SESSION['success'] = "ส่งข้อมูลการประเมินของท่านเรียบร้อยแล้ว (ระบบทำการคัดลอกข้อมูลไปยังทุกตำแหน่งของท่านในรอบคราวนี้แล้ว)";
        header("Location: my_evaluations.php");
        exit();

    } catch(Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $debug_steps[] = "ERROR: " . $e->getMessage();
        $form_error = "เกิดข้อผิดพลาด: " . $e->getMessage() . "\n\nDebug:\n" . implode("\n", $debug_steps);
    }
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-8">
                    <h1 class="m-0 text-dark"><i class="fas fa-edit text-primary mr-2"></i> กรอกประวัติ/ผลการปฏิบัติงาน</h1>
                    <!-- VERSION: v6 -->
                    <p class="mt-2 mb-0" style="font-size: 1.1rem;">ผู้รับการประเมิน: <b><?php echo htmlspecialchars(($_SESSION['prefix'] ?? '') . $_SESSION['firstname'] . ' ' . $_SESSION['lastname']); ?></b></p>
                    <p class="mb-0 text-primary" style="font-size: 1.05rem;"><i class="fas fa-briefcase"></i> ตำแหน่งที่รับการประเมิน: <b><?php 
                        if ($eval['staff_type'] == 'teacher') {
                            echo 'ครูพิเศษสอน';
                        } elseif ($eval['staff_type'] == 'gov_teacher') {
                            echo 'พนักงานราชการสายงานการสอน';
                        } else {
                            echo htmlspecialchars($eval['evaluated_job_title'] ?? 'รวมทุกตำแหน่ง'); 
                        }
                    ?></b></p>
                    <?php if(!empty($eval['job_description'])): ?>
                    <p class="mb-0 text-secondary" style="font-size: 0.95rem;"><i class="fas fa-tasks"></i> หน้าที่ของงาน: <?php echo nl2br(htmlspecialchars($eval['job_description'])); ?></p>
                    <?php endif; ?>
                    <p class="text-muted mt-1"><i class="fas fa-clock"></i> รอบการประเมิน: <b><?php echo htmlspecialchars($eval['round_title']); ?></b></p>
                </div>
                <div class="col-sm-4 text-right">
                    <a href="my_evaluations.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-primary shadow">
                <div class="card-header bg-white">
                    <h3 class="card-title">แบบฟอร์มประเมินตนเองและรายงานผลงาน (Self Assessment)</h3>
                </div>
                <div class="card-body">
                    <?php if($form_error): ?>
                    <div class="alert alert-danger" style="white-space: pre-wrap; font-family: monospace; font-size: 13px;">
                        <h5><i class="fas fa-exclamation-triangle"></i> พบข้อผิดพลาดในการบันทึก</h5>
                        <?php echo htmlspecialchars($form_error); ?>
                    </div>
                    <?php endif; ?>
                    <?php if($eval['status'] == 'pending_evaluator'): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-edit"></i> <b>โหมดแก้ไขข้อมูล:</b> ท่านได้ส่งอ้างอิงเอกสารประเมินตนเองไปแล้ว สามารถแก้ไขและส่งใหม่ได้จนกว่าจะถึงวันเวลาสิ้นสุดการส่ง
                        <?php if(!empty($eval['staff_deadline'])): ?>
                        (<b>สิ้นสุด: <?php echo date('d/m/Y H:i', strtotime($eval['staff_deadline'])); ?> น.</b>)
                        <?php endif; ?>
                    </div>
                    <?php else: ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> โปรดกรอกข้อมูลผลการปฏิบัติงานของท่านในแต่ละหัวข้อ เพื่อให้คณะกรรมการพิจารณาประกอบการให้คะแนน
                    </div>
                    <?php endif; ?>

                    <form action="evaluation_form.php?id=<?php echo $assigned_id; ?>" method="POST" enctype="multipart/form-data">
                        <?php 
                            $mc_index = 1;
                            foreach($main_categories as $m_cat): 
                                $subs = isset($sub_criteria[$m_cat['id']]) ? $sub_criteria[$m_cat['id']] : [];
                        ?>
                            <div class="card bg-light mb-4 shadow-sm border" style="border-radius: 10px;">
                                <div class="card-header border-0 bg-primary text-white" style="border-radius: 10px 10px 0 0;">
                                    <h4 class="mb-0"><b>ส่วนที่ <?php echo $mc_index++; ?>: <?php echo htmlspecialchars($m_cat['title']); ?></b></h4>
                                    <?php if($m_cat['description']): ?>
                                        <small class="d-block mt-1"><?php echo nl2br(htmlspecialchars($m_cat['description'])); ?></small>
                                    <?php endif; ?>
                                    <span class="badge badge-light text-primary float-right mt-1" style="font-size:16px;">
                                        คะแนนเต็มรวม <?php echo number_format($m_cat['calculated_max'], 1); ?> คะแนน
                                    </span>
                                </div>
                                <div class="card-body bg-white rounded-bottom">
                                    <?php 
                                    if(empty($subs)): 
                                        // If no sub criteria exist, treat the main category itself as the single scorable item
                                        $mock_c = $m_cat;
                                        $mock_c['sort_order'] = '-'; 
                                        $subs = [$mock_c]; 
                                    endif; 
                                    ?>
                                        <?php foreach($subs as $i => $c): ?>
                                            <div class="border-bottom pb-4 <?php echo $i > 0 ? 'pt-4' : ''; ?>">
                                                <h5 class="text-dark font-weight-bold">
                                                    <?php echo $c['sort_order'] !== '-' ? htmlspecialchars($m_cat['sort_order'] . '.' . $c['sort_order']) : ''; ?> 
                                                    <?php echo htmlspecialchars($c['title']); ?>
                                                </h5>
                                                <?php if($c['description']): ?>
                                                    <p class="text-muted small mb-2"><i class="fas fa-info-circle"></i> <?php echo nl2br(htmlspecialchars($c['description'])); ?></p>
                                                <?php endif; ?>
                                                <div class="mb-2 text-right">
                                                    <span class="badge badge-danger" style="font-size:13px;">คะแนนเต็ม <?php echo number_format($c['max_score'], 1); ?></span>
                                                </div>
                                                
                                                <?php
                                                    $saved = isset($existing_inputs[$c['id']]) ? $existing_inputs[$c['id']] : [];
                                                    $saved_text = $saved['staff_input_text'] ?? '';
                                                    $saved_link = $saved['staff_link'] ?? '';
                                                    $saved_file = $saved['staff_attachment'] ?? '';
                                                ?>
                                                <!-- ผลงาน/รายละเอียด -->
                                                <div class="form-group mb-3">
                                                    <label><i class="fas fa-pen text-primary"></i> ผลการปฏิบัติงาน/รายละเอียด</label>
                                                    <textarea name="criteria_<?php echo $c['id']; ?>" class="form-control" rows="3" placeholder="อธิบายผลงานที่เกี่ยวข้อง..."><?php echo htmlspecialchars($saved_text); ?></textarea>
                                                </div>
                                                
                                                <div class="row">
                                                    <!-- ลิงค์หลักฐาน -->
                                                    <div class="col-md-6">
                                                        <div class="form-group mb-2">
                                                            <label><i class="fas fa-link text-info"></i> แนบลิงค์หลักฐาน (URL)</label>
                                                            <input type="url" name="link_<?php echo $c['id']; ?>" class="form-control" placeholder="https://example.com/..." value="<?php echo htmlspecialchars($saved_link); ?>">
                                                            <small class="text-muted">วาง URL ลิงค์เอกสารออนไลน์ เช่น Google Drive, เว็บไซต์</small>
                                                        </div>
                                                    </div>
                                                    <!-- แนบไฟล์ -->
                                                    <div class="col-md-6">
                                                        <div class="form-group mb-2">
                                                            <label><i class="fas fa-paperclip text-success"></i> แนบไฟล์หลักฐาน</label>
                                                            <?php if($saved_file): ?>
                                                            <div class="mb-1">
                                                                <small class="text-success"><i class="fas fa-check-circle"></i> ไฟล์เดิม: <b><?php echo htmlspecialchars($saved_file); ?></b> (อัพโหลดไฟล์ใหม่เพื่อเปลี่ยน)</small>
                                                            </div>
                                                            <?php endif; ?>
                                                            <input type="file" name="file_<?php echo $c['id']; ?>" class="form-control-file border rounded p-2">
                                                            <small class="text-muted">รองรับไฟล์ PDF, รูปภาพ, เอกสาร (สูงสุด 10MB)</small>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="text-center mt-4 mb-2">
                            <button type="button" class="btn btn-primary btn-lg px-5 shadow rounded-pill" id="btnSubmit">
                                <i class="fas fa-paper-plane mr-2"></i> ส่งแบบประเมิน
                            </button>
                        </div>
                        <input type="hidden" name="submit_assessment" value="1">
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#btnSubmit').click(function() {
        var $form = $(this).closest('form');
        
        // Check file sizes before submitting
        var totalSize = 0;
        var maxFileSize = 10 * 1024 * 1024; // 10MB per file
        var maxTotalSize = 50 * 1024 * 1024; // 50MB total
        var tooBig = false;
        
        $form.find('input[type=file]').each(function() {
            if (this.files && this.files[0]) {
                totalSize += this.files[0].size;
                if (this.files[0].size > maxFileSize) {
                    tooBig = true;
                    Swal.fire('ไฟล์ใหญ่เกินไป', 'ไฟล์ "' + this.files[0].name + '" มีขนาด ' + (this.files[0].size / 1024 / 1024).toFixed(1) + 'MB (สูงสุด 10MB)', 'error');
                }
            }
        });
        
        if (tooBig) return;
        
        if (totalSize > maxTotalSize) {
            Swal.fire('ไฟล์รวมใหญ่เกินไป', 'ขนาดไฟล์รวม ' + (totalSize / 1024 / 1024).toFixed(1) + 'MB เกินขีดจำกัด (สูงสุด 50MB)', 'error');
            return;
        }
        
        Swal.fire({
            title: 'ยืนยันการส่งแบบประเมิน?',
            text: "หากกดส่งแล้วจะไม่สามารถกลับมาแก้ไขข้อมูลได้อีก คุณแน่ใจหรือไม่?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'ยืนยันการส่ง',
            cancelButtonText: 'ยกเลิกทบทวนใหม่'
        }).then((result) => {
            if (result.isConfirmed) {
                // Remove empty file inputs to reduce POST size
                $form.find('input[type=file]').each(function() {
                    if (!this.files || !this.files.length) {
                        $(this).prop('disabled', true);
                    }
                });
                $form.submit();
            }
        });
    });
});
</script>
