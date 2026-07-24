<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Evaluation Rounds';

// Ensure instances table can take NULL for job_id (for teachers)
try { $pdo->exec("ALTER TABLE evaluation_instances MODIFY evaluated_job_id INT DEFAULT NULL;"); } catch (Exception $e) {}

// Handle Add/Edit Round
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $title = trim($_POST['title']);
    $status = $_POST['status'];
    $start_date = !empty($_POST['start_date']) ? $_POST['start_date'] : null;
    $end_date = !empty($_POST['end_date']) ? $_POST['end_date'] : null;
    $staff_deadline = !empty($_POST['staff_deadline']) ? $_POST['staff_deadline'] : null;
    $evaluator_deadline = !empty($_POST['evaluator_deadline']) ? $_POST['evaluator_deadline'] : null;
    $target_score = !empty($_POST['target_score']) ? $_POST['target_score'] : 100.00;
    
    if ($_POST['action'] == 'add') {
        try {
            $pdo->beginTransaction();
            
            // Insert new round
            $stmt = $pdo->prepare("INSERT INTO evaluation_rounds (title, target_score, status, start_date, end_date, staff_deadline, evaluator_deadline) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $target_score, $status, $start_date, $end_date, $staff_deadline, $evaluator_deadline]);
            $round_id = $pdo->lastInsertId();

            // If it's opened right away, we should instantiate the evaluation assignments based on automatic job level hierarchies
            if ($status === 'open') {
                $enable_head_eval = '1';
                try {
                    $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enable_head_evaluation'");
                    $cs->execute();
                    $val = $cs->fetchColumn();
                    if ($val !== false) $enable_head_eval = $val;
                } catch(Exception $e) {}

                // Get all users who have at least one job assigned (they can be evaluatees if their job is in the hierarchy)
                $staffs = $pdo->query("SELECT DISTINCT u.id 
                                       FROM users u 
                                       JOIN user_jobs uj ON u.id = uj.user_id 
                                       WHERE u.role IN ('staff', 'evaluator')")->fetchAll(PDO::FETCH_ASSOC);
                
                $insert_inst = $pdo->prepare("INSERT INTO evaluation_instances (round_id, evaluatee_id, evaluated_job_id, status) VALUES (?, ?, ?, 'pending_staff')");
                $insert_ev = $pdo->prepare("INSERT INTO evaluation_evaluator_status (evaluation_instance_id, evaluator_id, status) VALUES (?, ?, 'pending')");
                
                // Fetch all jobs to map their department and level
                $all_jobs_query = $pdo->query("SELECT id, title, department_id, job_level FROM jobs")->fetchAll(PDO::FETCH_ASSOC);
                $jobs_map = [];
                foreach ($all_jobs_query as $j) {
                    $jobs_map[$j['id']] = $j;
                }
                
                // Find all users and their jobs to quickly find evaluators
                $all_users_jobs = $pdo->query("SELECT user_id, job_id FROM user_jobs")->fetchAll(PDO::FETCH_ASSOC);
                
                // Group user_jobs by user_id to quickly find an evaluatee's jobs
                $jobs_by_user = [];
                foreach ($all_users_jobs as $uj) {
                    $u_id = $uj['user_id'];
                    if (!isset($jobs_by_user[$u_id])) {
                        $jobs_by_user[$u_id] = [];
                    }
                    $jobs_by_user[$u_id][] = $uj['job_id'];
                }

                foreach ($staffs as $staff) {
                    $staff_id = $staff['id'];
                    $my_jobs = isset($jobs_by_user[$staff_id]) ? $jobs_by_user[$staff_id] : [];
                    
                    // Assign evaluatee PER JOB
                    foreach ($my_jobs as $my_job_id) {
                        if (!isset($jobs_map[$my_job_id])) continue;
                        
                        $my_dept = $jobs_map[$my_job_id]['department_id'];
                        $my_level = $jobs_map[$my_job_id]['job_level'];
                        
                        // Build lookup: which L3 job titles does each user hold in this staff's department?
                        // This prevents heads of unrelated work areas from evaluating through their L2 job
                        $user_l3_titles_in_dept = [];
                        if ($my_level == 4) {
                            foreach ($all_users_jobs as $uj_check) {
                                $check_uid = $uj_check['user_id'];
                                $check_jid = $uj_check['job_id'];
                                if (isset($jobs_map[$check_jid]) && $jobs_map[$check_jid]['department_id'] == $my_dept && $jobs_map[$check_jid]['job_level'] == 3) {
                                    if (!isset($user_l3_titles_in_dept[$check_uid])) {
                                        $user_l3_titles_in_dept[$check_uid] = [];
                                    }
                                    $user_l3_titles_in_dept[$check_uid][] = $jobs_map[$check_jid]['title'];
                                }
                            }
                        }
                        
                        // Find potential evaluators: users holding a job in the same dept with a lower level index (higher rank)
                        $assigned_evaluators_for_job = [];
                        
                        $my_work_name = trim(str_replace(['เจ้าหน้าที่งาน', 'เจ้าหน้าที่'], '', $jobs_map[$my_job_id]['title']));
                        
                        foreach ($all_users_jobs as $potential_eval_uj) {
                            $evaluator_user_id = $potential_eval_uj['user_id'];
                            $evaluator_job_id = $potential_eval_uj['job_id'];
                            
                            // Don't evaluate yourself
                            if ($evaluator_user_id === $staff_id) continue;
                            
                            if (isset($jobs_map[$evaluator_job_id])) {
                                $eval_dept = $jobs_map[$evaluator_job_id]['department_id'];
                                $eval_level = $jobs_map[$evaluator_job_id]['job_level'];
                                
                                // Condition 1: Evaluator is Level 1 (Director) -> Can ONLY evaluate Level 2 (Deputy Director), regardless of department.
                                // Condition 2: Evaluator is Level > 1 -> Must be in the Same Department AND have a HIGHER rank (LOWER job_level index).
                                if ( ($eval_level == 1 && $my_level == 2) || ($eval_dept == $my_dept && $eval_level > 1 && $eval_level < $my_level) ) {
                                    
                                    $is_valid_hierarchy = true;
                                    
                                    if ($my_level == 4) {
                                        // Constraint for evaluating L4 staff:
                                        // If evaluator holds ANY L3 job in the same department,
                                        // at least one of those L3 titles must match the evaluated work area.
                                        // This prevents heads of unrelated work areas from evaluating through their L2 job.
                                        if (isset($user_l3_titles_in_dept[$evaluator_user_id])) {
                                            $has_matching_l3 = false;
                                            $my_n_work = str_replace(['หัวหน้างาน', 'เจ้าหน้าที่งาน', 'หัวหน้า', 'เจ้าหน้าที่', 'การ', 'งาน', ' '], '', $jobs_map[$my_job_id]['title']);
                                            foreach ($user_l3_titles_in_dept[$evaluator_user_id] as $l3_title) {
                                                $l3_work = str_replace(['หัวหน้างาน', 'เจ้าหน้าที่งาน', 'หัวหน้า', 'เจ้าหน้าที่', 'การ', 'งาน', ' '], '', $l3_title);
                                                if ($l3_work === $my_n_work || strpos($l3_work, $my_n_work) !== false || strpos($my_n_work, $l3_work) !== false) {
                                                    $has_matching_l3 = true;
                                                    break;
                                                }
                                            }
                                            if (!$has_matching_l3) {
                                                $is_valid_hierarchy = false;
                                            }
                                        }
                                        // If evaluator does NOT hold any L3 job in same dept (pure L2 deputy), allow
                                    }

                                    if ($is_valid_hierarchy && !in_array($evaluator_user_id, $assigned_evaluators_for_job)) {
                                        $assigned_evaluators_for_job[] = $evaluator_user_id;
                                    }
                                }
                            }
                        }
                        
                        // We always create an instance for every job. If they have no evaluators (e.g. they are Level 1), 
                        // the instance will just stay pending forever or auto-complete depending on future logic, 
                        // Let's create it anyway so they can self-assess, even if no one scores them immediately.
                        
                        // HOWEVER, Director (Level 1) and Deputy Director (Level 2) are exempt from self-assessment/evaluations.
                        if ($my_level == 1 || $my_level == 2) {
                            continue;
                        }

                        // BUT if they are a Head of Work (Level 3) and the setting is disabled, SKIP!
                        if ($enable_head_eval == '0' && $my_level == 3) {
                            continue;
                        }

                        $insert_inst->execute([$round_id, $staff_id, $my_job_id]);
                        $instance_id = $pdo->lastInsertId();
                        
                        foreach ($assigned_evaluators_for_job as $e_id) {
                            $insert_ev->execute([$instance_id, $e_id]);
                        }
                    }
                }

                $all_users = $pdo->query("SELECT id, staff_type FROM users")->fetchAll(PDO::FETCH_ASSOC);
                $valid_user_ids = array_column($all_users, 'id');
                
                // --- Process Teachers ---
                $user_staff_type_map = [];
                foreach ($all_users as $au) {
                    $user_staff_type_map[$au['id']] = $au['staff_type'];
                }
                
                $teachers = $pdo->query("SELECT id, department_id, staff_type FROM users WHERE staff_type IN ('teacher', 'gov_teacher') AND department_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
                
                // Fetch Central Evaluators
                $central_evals_json = '';
                $central_gov_evals_json = '';
                try {
                    $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_teacher_evaluators'");
                    $cs->execute();
                    $central_evals_json = $cs->fetchColumn();

                    $cs_gov = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_gov_teacher_evaluators'");
                    $cs_gov->execute();
                    $central_gov_evals_json = $cs_gov->fetchColumn();
                } catch(Exception $e) {}
                
                $central_evals_list = $central_evals_json ? json_decode($central_evals_json, true) : [];
                if (!is_array($central_evals_list)) $central_evals_list = [];

                $central_gov_evals_list = $central_gov_evals_json ? json_decode($central_gov_evals_json, true) : [];
                if (!is_array($central_gov_evals_list)) $central_gov_evals_list = [];

                foreach ($teachers as $teacher) {
                    $t_id = $teacher['id'];
                    $t_dept = $teacher['department_id'];
                    $t_staff_type = $teacher['staff_type'];
                    
                    // 1. Find Heads of Department (General staff with "หัวหน้าแผนกวิชา" in title in the same department)
                    $head_evaluators = [];
                    
                    if ($t_staff_type !== 'gov_teacher') {
                        foreach ($all_users_jobs as $uj) {
                            $uid = $uj['user_id'];
                            $jid = $uj['job_id'];
                            
                            if ($uid === $t_id) continue;
                            
                            // Security check: Only actual staff/general should be 'Head of Dept' evaluators, not teachers who self-assign the job
                            if (isset($user_staff_type_map[$uid]) && in_array($user_staff_type_map[$uid], ['teacher', 'gov_teacher'])) continue;
                            
                            if (isset($jobs_map[$jid])) {
                                $j_dept = $jobs_map[$jid]['department_id'];
                                $j_title = $jobs_map[$jid]['title'];
                                if ($j_dept == $t_dept && strpos($j_title, 'หัวหน้าแผนกวิชา') !== false) {
                                    if (!in_array($uid, $head_evaluators)) {
                                        $head_evaluators[] = $uid;
                                    }
                                }
                            }
                        }
                    }
                    
                    // Combine Head of Dept + Central Evaluators
                    $target_central = ($t_staff_type === 'gov_teacher') ? $central_gov_evals_list : $central_evals_list;
                    $assigned_evaluators = array_unique(array_merge($head_evaluators, $target_central));
                    
                    // Remove self-evaluators completely (in case the teacher is listed as a central evaluator)
                    $assigned_evaluators = array_filter($assigned_evaluators, function($e_id) use ($t_id) {
                        return $e_id != $t_id;
                    });
                    
                    // Insert evaluation instance (evaluated_job_id = NULL)
                    $insert_inst->execute([$round_id, $t_id, null]); 
                    $instance_id = $pdo->lastInsertId();
                    
                    foreach ($assigned_evaluators as $e_id) {
                        if (in_array($e_id, $valid_user_ids)) {
                            $insert_ev->execute([$instance_id, $e_id]);
                        }
                    }
                }
            }
            
            $pdo->commit();
            $_SESSION['success'] = "สร้างรอบประเมินสำเร็จและสร้างรายการประเมินให้บุคลากรแล้ว";
        } catch(PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        try {
            // Updating round name, status, and dates
            $stmt = $pdo->prepare("UPDATE evaluation_rounds SET title = ?, target_score = ?, status = ?, start_date = ?, end_date = ?, staff_deadline = ?, evaluator_deadline = ? WHERE id = ?");
            $stmt->execute([$title, $target_score, $status, $start_date, $end_date, $staff_deadline, $evaluator_deadline, $id]);
            $_SESSION['success'] = "อัพเดทรอบการประเมินสำเร็จ";
            
            // Note: If admins change from 'closed' to 'open', we don't auto-recreate assigned evaluations here to avoid duplicates.
            // If they need to recreate, they must make a new round or handle corner cases manually.
            
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    header("Location: rounds.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM evaluation_rounds WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = "ลบรอบประเมินสำเร็จ";
    } catch(PDOException $e) {
        $_SESSION['error'] = "ไม่สามารถลบได้ เนื่องจากถูกใช้งานอยู่";
    }
    header("Location: rounds.php");
    exit();
}

// Handle Manual Sync
if (isset($_GET['sync'])) {
    syncOpenRounds($pdo);
    $_SESSION['success'] = "ซิงค์สิทธิผู้ประเมินสำหรับรอบที่เปิดอยู่ทั้งหมดสำเร็จแล้ว";
    header("Location: rounds.php");
    exit();
}

$rounds = $pdo->query("SELECT * FROM evaluation_rounds ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-calendar-alt text-primary mr-2"></i> จัดการรอบการประเมิน</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#addModal">
                        <i class="fas fa-plus"></i> เพิ่มรอบประเมินใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-primary shadow">
                <div class="card-body">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i> <b>ข้อควรระวัง:</b> เมื่อสร้างรอบการประเมินและตั้งสถานะเป็น "เปิด (Open)" ระบบจะดึงสายการประเมินทั้งหมด (Hierarchies) ไปสร้างเป็นรายการประเมินทันที <br>
                        <i class="fas fa-info-circle text-info mt-2"></i> <b>ทิป:</b> หากมีการเปลี่ยนแปลงชื่อตำแหน่งหรือโยกย้ายบุคลากร คุณสามารถกดปุ่ม <span class="badge badge-info"><i class="fas fa-sync-alt"></i></span> ท้ายรอบที่เปิดอยู่ เพื่อสั่งให้ระบบทบทวนและอัพเดทสิทธิผู้ประเมินใหม่ให้เป็นปัจจุบันได้ทันที
                    </div>
                    <table class="table table-bordered table-striped" id="rTable">
                        <thead>
                            <tr class="bg-primary text-white">
                                <th width="10%">รหัสรอบ</th>
                                <th>ชื่อรอบการประเมิน</th>
                                <th>คะแนนเต็ม</th>
                                <th>ระยะเวลา</th>
                                <th>ปิดระบบอัตโนมัติ</th>
                                <th>สถานะ</th>
                                <th>สร้างเมื่อ</th>
                                <th width="15%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($rounds as $r): ?>
                            <tr>
                                <td class="text-center"><?php echo $r['id']; ?></td>
                                <td><?php echo htmlspecialchars($r['title']); ?></td>
                                <td><?php echo number_format($r['target_score'], 2); ?></td>
                                <td>
                                    <?php 
                                        $sd = $r['start_date'] ? date('d/m/Y', strtotime($r['start_date'])) : '-';
                                        $ed = $r['end_date'] ? date('d/m/Y', strtotime($r['end_date'])) : '-';
                                        echo "<span class='text-muted'><i class='far fa-calendar-alt'></i> {$sd} ถึง {$ed}</span>";
                                    ?>
                                </td>
                                <td style="font-size:0.85rem">
                                    <div class="mb-1"><span class="badge badge-info text-dark" style="width:70px">ผู้รับการประเมิน</span>: <?php echo ($r['staff_deadline'] ?? null) ? date('d/m/Y H:i', strtotime($r['staff_deadline'])) : '<span class="text-muted">ไม่กำหนด</span>'; ?></div>
                                    <div><span class="badge badge-warning text-dark" style="width:70px">กรรมการ</span>: <?php echo ($r['evaluator_deadline'] ?? null) ? date('d/m/Y H:i', strtotime($r['evaluator_deadline'])) : '<span class="text-muted">ไม่กำหนด</span>'; ?></div>
                                </td>
                                <td>
                                    <?php if($r['status'] == 'open'): ?>
                                        <span class="badge badge-success px-3 py-2">กำลังเปิดประเมิน</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary px-3 py-2">ปิดรอบแล้ว</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('d/m/Y H:i', strtotime($r['created_at'])); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm btn-edit" 
                                            data-id="<?php echo $r['id']; ?>" 
                                            data-title="<?php echo htmlspecialchars($r['title']); ?>"
                                            data-tscore="<?php echo $r['target_score']; ?>"
                                            data-status="<?php echo $r['status']; ?>"
                                            data-start="<?php echo $r['start_date']; ?>"
                                            data-end="<?php echo $r['end_date']; ?>"
                                            data-sdeadline="<?php echo ($r['staff_deadline'] ?? null) ? date('Y-m-d\TH:i', strtotime($r['staff_deadline'])) : ''; ?>"
                                            data-edeadline="<?php echo ($r['evaluator_deadline'] ?? null) ? date('Y-m-d\TH:i', strtotime($r['evaluator_deadline'])) : ''; ?>"
                                            data-toggle="modal" data-target="#editModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if($r['status'] == 'open'): ?>
                                    <a href="rounds.php?sync=1" class="btn btn-info btn-sm" onclick="return confirm('ยืนยันหน้าการซิงค์สิทธิผู้ประเมินใหม่สำหรับรอบที่เปิดอยู่? (ระบบจะดึงข้อมูลโครงสร้างปัจจุบันมาประมวลผล)');" title="ซิงค์สิทธิผู้ประเมิน">
                                        <i class="fas fa-sync-alt"></i>
                                    </a>
                                    <?php endif; ?>
                                    <button class="btn btn-danger btn-sm btn-delete" data-id="<?php echo $r['id']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="rounds.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> เพิ่มรอบประเมินใหม่</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-9 form-group">
                            <label>ชื่อรอบการประเมิน (เช่น ปีงบประมาณ 2569 ครั้งที่ 1)</label>
                            <input type="text" name="title" class="form-control" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>คะแนนเต็ม</label>
                            <input type="number" step="0.01" name="target_score" class="form-control" value="100" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>วันที่เริ่มต้น</label>
                            <input type="date" name="start_date" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>วันที่สิ้นสุด</label>
                            <input type="date" name="end_date" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>กำหนดปิดรับการประเมินตนเอง (ผู้ถูกประเมิน)</label>
                            <input type="datetime-local" name="staff_deadline" class="form-control">
                            <small class="text-muted d-block mt-1">ปล่อยเว้นว่างไว้ หากไม่ต้องการกำหนดเวลาปิดอัตโนมัติ</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>กำหนดปิดการให้คะแนน (คณะกรรมการ)</label>
                            <input type="datetime-local" name="evaluator_deadline" class="form-control">
                            <small class="text-muted d-block mt-1">ตั้งค่าเพื่อปิดรับคะแนนจากกรรมการโดยอัตโนมัติ</small>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>สถานะของรอบประเมินนี้</label>
                        <select name="status" class="form-control" required>
                            <option value="open">เปิด (Open) - ประเมินได้ทันที</option>
                            <option value="closed">ปิด (Closed) - สร้างไว้ก่อน ยังไม่ประเมิน</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">ยืนยันการสร้างรอบ</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="rounds.php" method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> แก้ไขรอบประเมิน</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-9 form-group">
                            <label>ชื่อรอบการประเมิน</label>
                            <input type="text" name="title" id="edit_title" class="form-control" required>
                        </div>
                        <div class="col-md-3 form-group">
                            <label>คะแนนเต็ม</label>
                            <input type="number" step="0.01" name="target_score" id="edit_target" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>วันที่เริ่มต้น</label>
                            <input type="date" name="start_date" id="edit_start" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>วันที่สิ้นสุด</label>
                            <input type="date" name="end_date" id="edit_end" class="form-control" required>
                        </div>
                    </div>
                    <div class="row bg-light pt-2 pb-1 mb-3 rounded border">
                        <div class="col-md-6 form-group">
                            <label>กำหนดปิดรับการประเมินตนเอง</label>
                            <input type="datetime-local" name="staff_deadline" id="edit_staff_deadline" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>กำหนดปิดการให้คะแนนจากกรรมการ</label>
                            <input type="datetime-local" name="evaluator_deadline" id="edit_evaluator_deadline" class="form-control">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>สถานะ</label>
                        <select name="status" id="edit_status" class="form-control" required>
                            <option value="open">เปิด (Open)</option>
                            <option value="closed">ปิด (Closed)</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#rTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "order": [[ 0, "desc" ]]
    });

    $('.btn-edit').click(function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_title').val($(this).data('title'));
        $('#edit_target').val($(this).data('tscore'));
        $('#edit_status').val($(this).data('status'));
        $('#edit_start').val($(this).data('start'));
        $('#edit_end').val($(this).data('end'));
        $('#edit_staff_deadline').val($(this).data('sdeadline'));
        $('#edit_evaluator_deadline').val($(this).data('edeadline'));
    });

    $('.btn-delete').click(function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "หากลบรอบนี้ ข้อมูลประเมินในรอบนี้จะหายไปด้วย คุณแน่ใจหรือไม่?",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ใช่, ฉันต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'rounds.php?delete=' + id;
            }
        })
    });
});
</script>
