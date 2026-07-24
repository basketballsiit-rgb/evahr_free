<?php
require_once '../config.php';
$is_director = isDirector($pdo, $_SESSION['user_id'] ?? 0);
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && !$is_director)) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Evaluation Reports';

// Fetch all rounds for filter
$rounds = $pdo->query("SELECT * FROM evaluation_rounds ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Determine active round for report (default to latest)
$active_round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : ($rounds[0]['id'] ?? 0);
$selected_staff_type = isset($_GET['staff_type']) && $_GET['staff_type'] !== '' ? $_GET['staff_type'] : 'staff';

$stats = [
    'total' => 0,
    'completed' => 0,
    'pending_evaluator' => 0,
    'pending_staff' => 0
];
$leaderboard = [];
$details_list = [];

$active_target_score = 100;
foreach ($rounds as $r) {
    if ($r['id'] == $active_round_id) {
        $active_target_score = (float)$r['target_score'];
        break;
    }
}

$max_raw_staff = getMaxRawScore($pdo, 'staff');
$max_raw_teacher = getMaxRawScore($pdo, 'teacher');
$max_raw_gov_teacher = getMaxRawScore($pdo, 'gov_teacher');

// Check if gov_teacher uses the 80/20 logic
$chk = $pdo->query("SELECT title FROM evaluation_criteria WHERE target_group = 'gov_teacher' AND parent_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
$has_bx = false; $has_px = false;
foreach ($chk as $t) {
    if (stripos($t, 'พฤติกรรม') !== false) $has_bx = true;
    if (stripos($t, 'ผลสัมฤทธิ์') !== false) $has_px = true;
}
if ($has_bx && $has_px) {
    $max_raw_gov_teacher = 100; // The scoring engine internally maps this to exactly 100
}

if ($active_round_id > 0) {
    // Get Stats
    $stats_query = $pdo->prepare("
        SELECT ei.status, COUNT(*) as count 
        FROM evaluation_instances ei
        JOIN users u ON ei.evaluatee_id = u.id
        WHERE ei.round_id = ? AND u.staff_type = ?
        GROUP BY ei.status
    ");
    $stats_query->execute([$active_round_id, $selected_staff_type]);
    $res = $stats_query->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($res as $row) {
        $stats['total'] += $row['count'];
        $stats[$row['status']] = $row['count'];
    }

    // Get Leaderboard (Completed only)
    $lb_query = $pdo->prepare("
        SELECT ei.total_score_average, 
               e1.prefix, e1.firstname, e1.lastname, e1.profile_image, e1.staff_type,
               d.name as department_name, 
               j.title as job_title
        FROM evaluation_instances ei
        JOIN users e1 ON ei.evaluatee_id = e1.id
        LEFT JOIN departments d ON e1.department_id = d.id
        LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
        WHERE ei.round_id = ? AND ei.status = 'completed' AND e1.staff_type = ?
        ORDER BY ei.total_score_average DESC
    ");
    $lb_query->execute([$active_round_id, $selected_staff_type]);
    $leaderboard = $lb_query->fetchAll(PDO::FETCH_ASSOC);

    // Get Status Details
    $details_query = $pdo->prepare("
        SELECT ei.status, ei.total_score_average,
               e1.prefix as ee_p, e1.firstname as ee_f, e1.lastname as ee_l, e1.staff_type,
               (SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname, '|', ees.status) SEPARATOR '||')
                FROM evaluation_evaluator_status ees
                JOIN users u ON ees.evaluator_id = u.id
                WHERE ees.evaluation_instance_id = ei.id) as evaluators_data,
               d.name as dept_name,
               j.title as evaluated_job_title
        FROM evaluation_instances ei
        JOIN users e1 ON ei.evaluatee_id = e1.id
        LEFT JOIN departments d ON e1.department_id = d.id
        LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
        WHERE ei.round_id = ? AND e1.staff_type = ?
        ORDER BY ei.status ASC, d.name ASC, e1.firstname ASC
    ");
    $details_query->execute([$active_round_id, $selected_staff_type]);
    $details_list = $details_query->fetchAll(PDO::FETCH_ASSOC);
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-chart-line text-primary mr-2"></i> รายงานสถิติและผลการประเมิน</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Filter -->
            <div class="card shadow-sm mb-4 border-top-primary">
                <div class="card-body py-3">
                    <form action="reports.php" method="GET" class="form-inline flex-wrap">
                        <label class="mr-3 text-dark"><i class="fas fa-filter mr-1"></i> เลือกรอบการประเมิน:</label>
                        <select name="round_id" class="form-control mr-3 mb-2" style="min-width: 250px;" onchange="this.form.submit()">
                            <?php foreach($rounds as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo $r['id'] == $active_round_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['title']); ?> 
                                    (<?php echo $r['status'] == 'open' ? 'กำลังเปิด' : 'ปิดแล้ว'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <label class="mr-3 text-dark ml-3"><i class="fas fa-users mr-1"></i> ประเภทบุคลากร:</label>
                        <select name="staff_type" class="form-control mr-3 mb-2" style="min-width: 180px;" onchange="this.form.submit()">
                            <option value="staff"<?php echo $selected_staff_type == 'staff' ? ' selected' : ''; ?>>เจ้าหน้าที่งาน</option>
                            <option value="teacher"<?php echo $selected_staff_type == 'teacher' ? ' selected' : ''; ?>>ครูพิเศษสอน</option>
                            <option value="gov_teacher"<?php echo $selected_staff_type == 'gov_teacher' ? ' selected' : ''; ?>>พนักงานราชการสายการสอน</option>
                            <option value="general"<?php echo $selected_staff_type == 'general' ? ' selected' : ''; ?>>บุคลากรทั่วไป</option>
                        </select>
                        <button type="submit" class="btn btn-primary mb-2 d-none"><i class="fas fa-search"></i> แสดงรายงาน</button>
                        <a href="report_summary.php?round_id=<?php echo $active_round_id; ?>&staff_type=<?php echo $selected_staff_type; ?>" class="btn btn-success mb-2 ml-2">
                            <i class="fas fa-file-alt"></i> สรุปผลการประเมินรายบุคคล
                        </a>
                    </form>
                </div>
            </div>

            <?php if($active_round_id > 0): ?>
                
                <!-- Stat Cards -->
                <div class="row">
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box bg-info">
                            <span class="info-box-icon"><i class="fas fa-tasks"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">รายการทั้งหมด</span>
                                <span class="info-box-number h3 mb-0"><?php echo $stats['total']; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box bg-success">
                            <span class="info-box-icon"><i class="fas fa-check-circle"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">ประเมินเสร็จสิ้น</span>
                                <span class="info-box-number h3 mb-0"><?php echo $stats['completed']; ?></span>
                                <div class="progress">
                                    <div class="progress-bar" style="width: <?php echo $stats['total'] > 0 ? ($stats['completed']/$stats['total']*100) : 0; ?>%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box bg-warning">
                            <span class="info-box-icon text-white"><i class="fas fa-user-clock text-white"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text text-white">รอกรรมการประเมิน</span>
                                <span class="info-box-number h3 mb-0 text-white"><?php echo $stats['pending_evaluator']; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 col-sm-6 col-12">
                        <div class="info-box bg-secondary">
                            <span class="info-box-icon"><i class="fas fa-hourglass-start"></i></span>
                            <div class="info-box-content">
                                <span class="info-box-text">รอเจ้าหน้าที่รายงานผล</span>
                                <span class="info-box-number h3 mb-0"><?php echo $stats['pending_staff']; ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Progress Details -->
                    <div class="col-md-12">
                        <div class="card shadow card-outline card-primary" style="height: 100%;">
                            <div class="card-header bg-white">
                                <h3 class="card-title text-primary font-weight-bold"><i class="fas fa-list-ol"></i> รายละเอียดสถานะรายบุคคล</h3>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive" style="max-height: 500px;">
                                    <table class="table table-striped table-valign-middle m-0 text-sm">
                                        <thead class="bg-light sticky-top">
                                            <tr>
                                                <th>ผู้รับการประเมิน</th>
                                                <th>คณะกรรมการ</th>
                                                <th>สถานะ</th>
                                                <th class="text-center">คะแนนเฉลี่ย</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            // Grouping details by candidate
                                            $grouped_details = [];
                                            foreach($details_list as $dt) {
                                                $key = $dt['ee_p'] . $dt['ee_f'] . $dt['ee_l'];
                                                if (!isset($grouped_details[$key])) {
                                                    $grouped_details[$key] = [
                                                        'name' => ($dt['ee_p'] ?? '').$dt['ee_f'].' '.$dt['ee_l'],
                                                        'staff_type' => $dt['staff_type'],
                                                        'dept_name' => $dt['dept_name'],
                                                        'job_titles' => [],
                                                        'evaluators' => [],
                                                        'max_status' => 0, // 2=completed, 1=pending_eval, 0=pending_staff
                                                        'sum_scaled' => 0,
                                                        'count_score' => 0
                                                    ];
                                                }
                                                
                                                $job_title = '';
                                                if ($dt['staff_type'] == 'teacher') $job_title = 'ครูพิเศษสอน';
                                                else if ($dt['staff_type'] == 'gov_teacher') $job_title = 'พนักงานราชการสายการสอน';
                                                else if ($dt['staff_type'] == 'general') $job_title = 'บุคลากรทั่วไป';
                                                else $job_title = $dt['evaluated_job_title'] ?: 'รวมทุกตำแหน่ง';
                                                
                                                if (!in_array($job_title, $grouped_details[$key]['job_titles'])) {
                                                    $grouped_details[$key]['job_titles'][] = $job_title;
                                                }
                                                
                                                if (!empty($dt['evaluators_data'])) {
                                                    $ev_list = explode('||', $dt['evaluators_data']);
                                                    foreach ($ev_list as $ev_item) {
                                                        $parts = explode('|', $ev_item);
                                                        $e_name = trim($parts[0] ?? '');
                                                        $e_status = $parts[1] ?? 'pending';
                                                        if (!isset($grouped_details[$key]['evaluators'][$e_name])) {
                                                            $grouped_details[$key]['evaluators'][$e_name] = $e_status;
                                                        } else {
                                                            if ($e_status !== 'completed') {
                                                                $grouped_details[$key]['evaluators'][$e_name] = $e_status;
                                                            }
                                                        }
                                                    }
                                                }
                                                
                                                $sp = 0;
                                                if ($dt['status'] == 'completed') $sp = 2;
                                                elseif ($dt['status'] == 'pending_evaluator') $sp = 1;
                                                
                                                $grouped_details[$key]['max_status'] = max($grouped_details[$key]['max_status'], $sp);
                                                
                                                if ($dt['status'] == 'completed') {
                                                    $grouped_details[$key]['sum_scaled'] += (float)$dt['total_score_average'];
                                                    $grouped_details[$key]['count_score']++;
                                                }
                                            }
                                            
                                            foreach ($grouped_details as $k => $v) {
                                                $all_evals_completed = true;
                                                $has_any_evaluators = false;
                                                if (!empty($v['evaluators'])) {
                                                    $has_any_evaluators = true;
                                                    foreach ($v['evaluators'] as $ename => $estat) {
                                                        if ($estat !== 'completed') {
                                                            $all_evals_completed = false;
                                                            break;
                                                        }
                                                    }
                                                } else {
                                                    $all_evals_completed = false; // no evaluators yet
                                                }
                                                
                                                if ($all_evals_completed && $has_any_evaluators) {
                                                    $grouped_details[$k]['status'] = 'completed';
                                                } elseif ($v['max_status'] == 1 || $v['max_status'] == 2) {
                                                    $grouped_details[$k]['status'] = 'pending_evaluator';
                                                } else {
                                                    $grouped_details[$k]['status'] = 'pending_staff';
                                                }
                                            }
                                            
                                            foreach($grouped_details as $dt): 
                                            ?>
                                            <tr>
                                                <td>
                                                    <b><?php echo htmlspecialchars($dt['name']); ?></b><br>
                                                    <small class="text-muted"><i class="fas fa-briefcase"></i> <?php 
                                                        echo implode('<br><i class="fas fa-briefcase" style="visibility:hidden;"></i> ', array_map('htmlspecialchars', $dt['job_titles']));
                                                    ?></small><br>
                                                    <small class="text-muted"><i class="fas fa-building"></i> <?php echo htmlspecialchars($dt['dept_name'] ?? '-'); ?></small>
                                                </td>
                                                <td>
                                                    <?php 
                                                        if (!empty($dt['evaluators'])) {
                                                            foreach ($dt['evaluators'] as $e_name => $e_status) {
                                                                if ($e_status === 'completed') {
                                                                    echo "<div class='mb-1'><span class='badge badge-success px-2 py-1' style='font-size:0.85rem;' title='ประเมินเสร็จแล้ว'><i class='fas fa-check-circle mr-1'></i> " . htmlspecialchars($e_name) . "</span></div>";
                                                                } else {
                                                                    echo "<div class='mb-1'><span class='badge badge-warning text-dark px-2 py-1' style='font-size:0.85rem;' title='รอดำเนินการประเมิน'><i class='fas fa-hourglass-half mr-1'></i> " . htmlspecialchars($e_name) . "</span></div>";
                                                                }
                                                            }
                                                        } else {
                                                            echo '<span class="text-muted">-</span>';
                                                        }
                                                    ?>
                                                </td>
                                                <td>
                                                    <?php 
                                                        if($dt['status'] == 'completed') echo '<span class="badge badge-success">เสร็จสิ้น</span>';
                                                        elseif($dt['status'] == 'pending_evaluator') echo '<span class="badge badge-warning">รอกรรมการตรวจ</span>';
                                                        else echo '<span class="badge badge-secondary">รอเจ้าหน้าที่ส่งงาน</span>';
                                                    ?>
                                                </td>
                                                <td class="text-center font-weight-bold <?php echo ($dt['status'] == 'completed' ? 'text-success' : 'text-muted'); ?>">
                                                    <?php 
                                                        if ($dt['status'] == 'completed') {
                                                            $scaled = $dt['sum_scaled'] / $dt['count_score'];
                                                            if ($dt['staff_type'] == 'teacher') {
                                                                $max_raw_score = $max_raw_teacher;
                                                            } elseif ($dt['staff_type'] == 'gov_teacher') {
                                                                $max_raw_score = $max_raw_gov_teacher;
                                                            } else {
                                                                $max_raw_score = $max_raw_staff;
                                                            }
                                                            $raw = $active_target_score > 0 ? ($scaled / $active_target_score) * $max_raw_score : 0;
                                                            echo number_format($raw, 2) . ' / ' . number_format($max_raw_score, 0) . '<br><small class="text-muted">➔ ' . number_format($scaled, 2) . ' / ' . number_format($active_target_score, 0) . '</small>';
                                                        } else {
                                                            echo '-';
                                                        }
                                                    ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                            <?php if(empty($grouped_details)): ?>
                                                <tr><td colspan="4" class="text-center text-muted">ไม่พบข้อมูลในรอบการประเมินนี้</td></tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            <?php else: ?>
                <div class="alert alert-warning text-center">
                    <h5><i class="fas fa-exclamation-triangle"></i> ยังไม่มีการสร้างรอบการประเมิน</h5>
                    <p>กรุณาไปที่เมนู <a href="rounds.php">จัดการรอบการประเมิน</a> เพื่อสร้างรอบประเมินใหม่</p>
                </div>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
