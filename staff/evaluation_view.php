<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : 0;

if (!$round_id) {
    header("Location: my_evaluations.php");
    exit();
}

// Verify ownership and status
$stmt = $pdo->prepare("
    SELECT ei.*, r.title as round_title, r.status as round_status, r.target_score, u.staff_type, j.title as evaluated_job_title
    FROM evaluation_instances ei 
    JOIN evaluation_rounds r ON ei.round_id = r.id 
    JOIN users u ON ei.evaluatee_id = u.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ei.round_id = ? AND ei.evaluatee_id = ? AND ei.status = 'completed' AND ei.is_published = 1
");
$stmt->execute([$round_id, $user_id]);
$instances = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (!$instances) {
    $_SESSION['error'] = "ไม่พบข้อมูล หรือรายการประเมินคณะกรรมการยังตรวจไม่ครบถ้วน หรือยังไม่ประกาศผล";
    header("Location: my_evaluations.php");
    exit();
}

$eval = $instances[0]; // Base round info off the first instance
$instance_ids = array_column($instances, 'id');
$in_clause = implode(',', array_fill(0, count($instance_ids), '?'));

$job_titles = [];
foreach ($instances as $inst) {
    $j_name = $inst['evaluated_job_title'] ?: ($inst['staff_type'] == 'gov_teacher' ? 'พนักงานราชการ' : ($inst['staff_type'] == 'teacher' ? 'ครูพิเศษสอน' : 'รวมทุกตำแหน่ง'));
    if (!in_array($j_name, $job_titles)) {
        $job_titles[] = $j_name;
    }
}
$eval['evaluated_job_title'] = implode('<br>', $job_titles);

// Fetch Criteria + Staff's Input (From the FIRST instance since staff inputs are mirrored)
$staff_type = $eval['staff_type'] ?? 'staff';
$criteria_stmt = $pdo->prepare("
    SELECT c.id as criteria_id, c.parent_id, c.title as criteria_title, c.description as criteria_desc, c.max_score, c.sort_order,
           eci.staff_input_text, eci.staff_link, eci.staff_attachment
    FROM evaluation_criteria c
    LEFT JOIN evaluation_criteria_inputs eci ON c.id = eci.criteria_id AND eci.evaluation_instance_id = ?
    WHERE c.target_group = 'both' OR c.target_group = ?
    ORDER BY c.sort_order ASC, c.id ASC
");
$criteria_stmt->execute([$eval['id'], $staff_type]);
$all_criteria = $criteria_stmt->fetchAll(PDO::FETCH_ASSOC);

$main_categories = [];
$sub_criteria = [];

foreach ($all_criteria as $c) {
    if ($c['parent_id'] === null) {
        $c['calculated_max'] = $c['max_score'];
        $main_categories[$c['criteria_id']] = $c;
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

// Fetch Evaluator Scores for ALL instances in this round
$params = $instance_ids;
$scores_stmt = $pdo->prepare("
    SELECT ees.score, ees.comment,
           u.prefix, u.firstname, u.lastname,
           ees.criteria_id
    FROM evaluation_evaluator_scores ees
    JOIN evaluation_evaluator_status ees_status ON ees.evaluation_evaluator_status_id = ees_status.id
    JOIN users u ON ees_status.evaluator_id = u.id
    WHERE ees_status.evaluation_instance_id IN ($in_clause)
");
$scores_stmt->execute($params);
$scores_raw = $scores_stmt->fetchAll(PDO::FETCH_ASSOC);

// Organize scores by criteria_id
$evaluator_scores = [];
foreach ($scores_raw as $s) {
    $evaluator_scores[$s['criteria_id']][] = $s;
}

$page_title = 'View My Evaluation Result';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-8">
                    <h1 class="m-0 text-dark"><i class="fas fa-file-contract text-primary mr-2"></i> ผลการประเมินของฉัน (โดยคณะกรรมการ)</h1>
                    <p class="mt-2 mb-0">รอบการประเมิน: <b><?php echo htmlspecialchars($eval['round_title']); ?></b></p>
                    <p class="mb-0 text-primary" style="font-size: 1.05rem;"><i class="fas fa-briefcase"></i> ตำแหน่งที่รับการประเมิน: <b><?php 
                        if ($eval['staff_type'] == 'teacher') {
                            echo 'ครูพิเศษสอน';
                        } elseif ($eval['staff_type'] == 'gov_teacher') {
                            echo 'พนักงานราชการสายงานการสอน';
                        } else {
                            echo htmlspecialchars($eval['evaluated_job_title'] ?? 'รวมทุกตำแหน่ง'); 
                        }
                    ?></b></p>
                </div>
                <div class="col-sm-4 text-right">
                    <a href="my_evaluations.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Total Score Card -->
            <?php 
                $active_target_score = (float)$eval['target_score'];
                $max_raw_score = getMaxRawScore($pdo, $staff_type);
                
                // --- ADMIN ADJUSTMENT SCORE CALCULATION ---
                $adjustments = $eval['admin_category_adjustments'] ? json_decode($eval['admin_category_adjustments'], true) : [];
                if (!is_array($adjustments)) $adjustments = [];
                
                $category_scores = [];
                foreach ($main_categories as $mc_id => $mc) {
                    $cat_avg = 0;
                    $scoreable_ids = [];
                    if (isset($sub_criteria[$mc_id])) {
                        foreach ($sub_criteria[$mc_id] as $sub) { $scoreable_ids[] = $sub['criteria_id']; }
                    } else {
                        $scoreable_ids[] = $mc_id;
                    }
                    
                    foreach ($scoreable_ids as $sid) {
                        $sid_ev_list = $evaluator_scores[$sid] ?? [];
                        if (count($sid_ev_list) > 0) {
                            $sid_sum = 0;
                            foreach ($sid_ev_list as $evs) { $sid_sum += (float)$evs['score']; }
                            $cat_avg += ($sid_sum / count($sid_ev_list));
                        }
                    }
                    
                    if (isset($adjustments[$mc_id])) {
                        $cat_avg = (float)$adjustments[$mc_id];
                        $cat_avg = min($cat_avg, $mc['calculated_max']);
                        $cat_avg = max($cat_avg, 0);
                    }
                    $category_scores[$mc_id] = $cat_avg;
                }
                
                $total_raw = array_sum($category_scores);
                $percentage = $max_raw_score > 0 ? ($total_raw / $max_raw_score) * 100 : 0;
                $scaled = ($percentage / 100) * $active_target_score;
                
                // 80/20 Rule for gov_teacher
                $applied_8020 = false;
                if ($staff_type == 'gov_teacher') {
                    $behavior_score = 0;
                    $performance_score = 0;
                    $other_score = 0;
                    foreach ($main_categories as $mc_id => $mc) {
                        if (stripos($mc['criteria_title'], 'พฤติกรรม') !== false) {
                            $normalized = $mc['calculated_max'] > 0 ? ($category_scores[$mc_id] / $mc['calculated_max']) * 100 : 0;
                            $behavior_score += $normalized * 0.20;
                            $applied_8020 = true;
                        } elseif (stripos($mc['criteria_title'], 'ผลสัมฤทธิ์') !== false) {
                            $normalized = $mc['calculated_max'] > 0 ? ($category_scores[$mc_id] / $mc['calculated_max']) * 100 : 0;
                            $performance_score += $normalized * 0.80;
                            $applied_8020 = true;
                        } else {
                            $other_score += $category_scores[$mc_id];
                        }
                    }
                    if ($applied_8020) {
                        $percentage = ($behavior_score + $performance_score) + ($max_raw_score > 0 ? ($other_score / $max_raw_score) * 100 : 0);
                        $total_raw = $percentage;
                        $scaled = ($percentage / 100) * $active_target_score;
                    }
                }
                
                $raw = $total_raw; // The raw number is the sum
                if ($applied_8020) $max_raw_score = 100; // Cap visual display of max to 100 for 80/20 weights
                // ------------------------------------------
            ?>
            <div class="card bg-primary text-white shadow mb-4">
                <div class="card-body text-center">
                    <h5 class="mb-2 text-white-50">คะแนนเต็มดิบ <?php echo number_format($max_raw_score, 0); ?> ➔ คะแนนเต็มรอบ <?php echo number_format($active_target_score, 0); ?></h5>
                    <h3 class="font-weight-bold mb-0">คะแนนรวมเฉลี่ย: <?php echo number_format($raw, 2); ?> / <?php echo number_format($scaled, 2); ?> คะแนน</h3>
                    <p class="mb-0 mt-2" style="font-size: 0.9rem; opacity: 0.9;"><i class="fas fa-info-circle"></i> แสดงรูปแบบ คะแนนดิบ / คะแนนที่ปรับสัดส่วนแล้ว</p>
                </div>
            </div>

            <div class="card card-outline card-primary shadow">
                <div class="card-header bg-white">
                    <h3 class="card-title text-primary"><i class="fas fa-list-ul"></i> รายละเอียดคะแนนในแต่ละหัวข้อ</h3>
                </div>
                <div class="card-body">
                    <?php 
                        $mc_index = 1;
                        foreach($main_categories as $m_cat): 
                            $subs = isset($sub_criteria[$m_cat['criteria_id']]) ? $sub_criteria[$m_cat['criteria_id']] : [];
                    ?>
                        <div class="card bg-light mb-5 shadow-sm border" style="border-radius: 10px;">
                            <div class="card-header border-0 bg-primary text-white" style="border-radius: 10px 10px 0 0;">
                                <h4 class="mb-0"><b>ส่วนที่ <?php echo $mc_index++; ?>: <?php echo htmlspecialchars($m_cat['criteria_title']); ?></b></h4>
                                <?php if($m_cat['criteria_desc']): ?>
                                    <small class="d-block mt-1"><?php echo nl2br(htmlspecialchars($m_cat['criteria_desc'])); ?></small>
                                <?php endif; ?>
                                <?php if($applied_8020): ?>
                                    <?php 
                                        $weight_text = ''; $weighted_val = 0; $badge_col = '';
                                        if(stripos($m_cat['criteria_title'], 'ผลสัมฤทธิ์') !== false) {
                                            $weight_text = '80%';
                                            $normalized = $m_cat['calculated_max'] > 0 ? ($category_scores[$m_cat['criteria_id']] / $m_cat['calculated_max']) * 100 : 0;
                                            $weighted_val = $normalized * 0.80;
                                            $badge_col = 'badge-warning text-dark';
                                        } elseif(stripos($m_cat['criteria_title'], 'พฤติกรรม') !== false) {
                                            $weight_text = '20%';
                                            $normalized = $m_cat['calculated_max'] > 0 ? ($category_scores[$m_cat['criteria_id']] / $m_cat['calculated_max']) * 100 : 0;
                                            $weighted_val = $normalized * 0.20;
                                            $badge_col = 'badge-info';
                                        }
                                    ?>
                                    <?php if($weight_text): ?>
                                        <span class="badge <?php echo $badge_col; ?> float-right mt-1 ml-2" style="font-size:16px; box-shadow: 0 2px 4px rgba(0,0,0,0.2);">
                                            <i class="fas fa-balance-scale"></i> สัดส่วน <?php echo $weight_text; ?> (คิดเป็น <?php echo number_format($weighted_val, 2); ?> คะแนน)
                                        </span>
                                    <?php endif; ?>
                                <?php endif; ?>
                                <span class="badge badge-light text-primary float-right mt-1" style="font-size:16px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                    คะแนนดิบ <?php echo number_format($category_scores[$m_cat['criteria_id']], 2); ?> / เต็ม <?php echo number_format($m_cat['calculated_max'], 1); ?>
                                </span>
                            </div>
                            
                            <div class="card-body bg-white rounded-bottom p-0">
                                <?php 
                                if(empty($subs)): 
                                    // If no sub criteria exist, treat the main category itself as the single scorable item
                                    $mock_c = $m_cat;
                                    $mock_c['sort_order'] = '-'; 
                                    $subs = [$mock_c]; 
                                endif; 
                                ?>
                                    <?php foreach($subs as $i => $d): ?>
                                        <?php 
                                            // Sub-criteria logic
                                            $cid = $d['criteria_id'];
                                            $ev_list = $evaluator_scores[$cid] ?? [];
                                            $sum_score = 0;
                                            foreach($ev_list as $ev) { $sum_score += $ev['score']; }
                                            $avg_score = count($ev_list) > 0 ? $sum_score / count($ev_list) : 0;
                                        ?>
                                        <div class="p-4 <?php echo $i < count($subs)-1 ? 'border-bottom' : ''; ?>">
                                            <h5 class="text-dark font-weight-bold mb-3">
                                                <?php echo $d['sort_order'] !== '-' ? htmlspecialchars($m_cat['sort_order'] . '.' . $d['sort_order']) : ''; ?> 
                                                <?php echo htmlspecialchars($d['criteria_title']); ?>
                                            </h5>
                                            
                                            <!-- Staff's Answer -->
                                            <div class="p-3 mb-4 bg-light" style="border-left: 4px solid var(--secondary-color); border-radius: 4px;">
                                                <strong class="text-secondary"><i class="fas fa-comment-dots"></i> ผลงานที่ท่านระบุ:</strong>
                                                <div class="mt-2 pl-2 text-dark">
                                                    <?php 
                                                    if($d['staff_input_text']) echo nl2br(htmlspecialchars($d['staff_input_text']));
                                                    else echo '<span class="text-muted"><i>ไม่มีการระบุข้อมูล</i></span>';
                                                    ?>
                                                </div>
                                                
                                                <?php if(!empty($d['staff_link'])): ?>
                                                <div class="mt-3 pt-2 border-top">
                                                    <i class="fas fa-link text-info"></i> <strong>ลิงค์หลักฐาน:</strong>
                                                    <?php 
                                                        $link = trim($d['staff_link']);
                                                        if (!empty($link) && !preg_match("~^(?:f|ht)tps?://~i", $link)) { $link = "http://" . $link; }
                                                    ?>
                                                    <a href="<?php echo htmlspecialchars($link); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-info ml-2">
                                                        <i class="fas fa-external-link-alt"></i> เปิดลิงค์
                                                    </a>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($d['staff_link']); ?></small>
                                                </div>
                                                <?php endif; ?>
                                                
                                                <?php if(!empty($d['staff_attachment'])): ?>
                                                <div class="mt-3 pt-2 border-top">
                                                    <i class="fas fa-paperclip text-success"></i> <strong>ไฟล์แนบ:</strong>
                                                    <a href="<?php echo BASE_URL . '/assets/uploads/' . htmlspecialchars($d['staff_attachment']); ?>" target="_blank" class="btn btn-sm btn-outline-success ml-2">
                                                        <i class="fas fa-download"></i> ดาวน์โหลด/ดูไฟล์
                                                    </a>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($d['staff_attachment']); ?></small>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="row">
                                                <div class="col-md-12">
                                                    <div class="alert alert-secondary text-center mb-0 h-100 d-flex flex-column justify-content-center">
                                                        <h6 class="text-muted mb-1"><i class="fas fa-chart-pie mr-1"></i> คะแนนเฉลี่ยประเด็นนี้ (โดยคณะกรรมการ)</h6>
                                                        <h2 class="text-primary font-weight-bold mb-0 mt-2"><?php echo number_format($avg_score, 2); ?> <small class="text-muted" style="font-size: 1rem;">/ <?php echo number_format($d['max_score'], 1); ?></small></h2>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
