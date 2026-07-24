<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['evaluator', 'staff'])) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$ees_id = isset($_GET['ees_id']) ? (int)$_GET['ees_id'] : 0;

if (!$ees_id) {
    header("Location: evaluate.php");
    exit();
}

// Verify ownership and status
$stmt = $pdo->prepare("
    SELECT ees.*, ei.id as instance_id, ei.status as global_status, 
           r.title as round_title, r.status as round_status, r.target_score,
           e1.prefix as ee_p, e1.firstname as ee_f, e1.lastname as ee_l, e1.staff_type,
           j.title as evaluated_job_title, j.description as job_description
    FROM evaluation_evaluator_status ees
    JOIN evaluation_instances ei ON ees.evaluation_instance_id = ei.id
    JOIN evaluation_rounds r ON ei.round_id = r.id
    JOIN users e1 ON ei.evaluatee_id = e1.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ees.id = ? AND ees.evaluator_id = ? AND ees.status = 'completed'
");
$stmt->execute([$ees_id, $user_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval) {
    $_SESSION['error'] = "ไม่พบข้อมูล หรือรายการประเมินยังไม่เสร็จสิ้น";
    header("Location: evaluate.php");
    exit();
}

// Fetch Staff's submitted answers + Criteria details + Evaluator's Scores
$staff_type = $eval['staff_type'] ?? 'staff';
$details_stmt = $pdo->prepare("
    SELECT c.id as criteria_id, c.parent_id, c.title as criteria_title, c.description as criteria_desc, c.max_score, c.sort_order,
           eci.staff_input_text, eci.staff_link, eci.staff_attachment, ees_scores.score as evaluator_score, ees_scores.comment as evaluator_comment
    FROM evaluation_criteria c
    LEFT JOIN evaluation_criteria_inputs eci ON c.id = eci.criteria_id AND eci.evaluation_instance_id = ?
    LEFT JOIN evaluation_evaluator_scores ees_scores ON c.id = ees_scores.criteria_id AND ees_scores.evaluation_evaluator_status_id = ?
    WHERE c.target_group = 'both' OR c.target_group = ?
    ORDER BY c.sort_order ASC, c.id ASC
");
$details_stmt->execute([$eval['instance_id'], $ees_id, $staff_type]);
$all_criteria = $details_stmt->fetchAll(PDO::FETCH_ASSOC);

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

$page_title = 'View Score Result';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-8">
                    <h1 class="m-0 text-dark"><i class="fas fa-eye text-success mr-2"></i> ดูผลการประเมิน</h1>
                    <p class="mt-2 mb-0">ผู้รับการประเมิน: <b><?php echo htmlspecialchars($eval['ee_p'].$eval['ee_f'].' '.$eval['ee_l']); ?></b></p>
                    <p class="mb-0 text-primary"><i class="fas fa-briefcase"></i> ตำแหน่ง: <b><?php 
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
                    <p class="text-muted">รอบการประเมิน: <?php echo htmlspecialchars($eval['round_title']); ?></p>
                </div>
                <div class="col-sm-4 text-right">
                    <a href="evaluate.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Total Score Card -->
            <?php 
                $active_target_score = (float)$eval['target_score'];
                $max_raw_score = getMaxRawScore($pdo);
                $scaled = $eval['total_score'];
                $raw = $active_target_score > 0 ? ($scaled / $active_target_score) * $max_raw_score : 0;
                
                // For gov_teacher: calculate 80/20 breakdown
                $is_gov_teacher = ($eval['staff_type'] === 'gov_teacher');
                $section_breakdown = [];
                if ($is_gov_teacher) {
                    foreach ($main_categories as $mc_id => $mc) {
                        $cat_raw_sum = 0;
                        $cat_max = $mc['calculated_max'];
                        // Sum scores for this category (from subs or the category itself)
                        if (isset($sub_criteria[$mc_id])) {
                            foreach ($sub_criteria[$mc_id] as $s) {
                                $cat_raw_sum += (float)$s['evaluator_score'];
                            }
                        } else {
                            $cat_raw_sum = (float)$mc['evaluator_score'];
                        }
                        
                        $weight = 0;
                        $label = '';
                        if (stripos($mc['criteria_title'], 'ผลสัมฤทธิ์') !== false) {
                            $weight = 0.80;
                            $label = 'ผลสัมฤทธิ์งาน (80%)';
                        } elseif (stripos($mc['criteria_title'], 'พฤติกรรม') !== false) {
                            $weight = 0.20;
                            $label = 'พฤติกรรมการปฏิบัติราชการ (20%)';
                        }
                        
                        if ($weight > 0) {
                            $normalized = $cat_max > 0 ? ($cat_raw_sum / $cat_max) * 100 : 0;
                            $weighted_score = $normalized * $weight;
                            $section_breakdown[] = [
                                'label' => $label,
                                'raw_score' => $cat_raw_sum,
                                'max_raw' => $cat_max,
                                'weight_pct' => (int)($weight * 100),
                                'normalized' => $normalized,
                                'weighted_score' => $weighted_score,
                            ];
                        }
                    }
                }
            ?>
            <?php if ($is_gov_teacher && !empty($section_breakdown)): ?>
            <!-- Gov Teacher 80/20 Breakdown Card -->
            <div class="card bg-success text-white shadow mb-4">
                <div class="card-body">
                    <h5 class="text-center text-white-50 mb-3">คะแนนเต็มดิบ <?php echo number_format($max_raw_score, 2); ?> ➔ คะแนนเต็มรอบ <?php echo number_format($active_target_score, 2); ?></h5>
                    <div class="row mb-3">
                        <?php foreach($section_breakdown as $sb): ?>
                        <div class="col-md-6 mb-3 mb-md-0">
                            <div class="card bg-white text-dark h-100" style="border-radius: 8px;">
                                <div class="card-body text-center py-3">
                                    <h6 class="font-weight-bold text-success mb-2"><?php echo htmlspecialchars($sb['label']); ?></h6>
                                    <div class="mb-1" style="font-size: 1.6rem; font-weight: bold;">
                                        <?php echo number_format($sb['raw_score'], 2); ?>
                                        <span class="text-muted" style="font-size: 1rem;">/ <?php echo number_format($sb['max_raw'], 2); ?> คะแนน</span>
                                    </div>
                                    <div class="text-muted small mb-2">
                                        = <?php echo number_format($sb['normalized'], 2); ?> / 100 คะแนน
                                    </div>
                                    <div class="badge badge-success px-3 py-2" style="font-size: 0.95rem;">
                                        × <?php echo $sb['weight_pct']; ?>% = <b><?php echo number_format($sb['weighted_score'], 2); ?> คะแนน</b>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="text-center border-top border-white pt-3">
                        <h3 class="font-weight-bold mb-0">คะแนนรวมสุทธิ: <?php echo number_format($raw, 2); ?> / <?php echo number_format($scaled, 2); ?> คะแนน</h3>
                        <p class="mb-0 mt-1" style="font-size: 0.9rem; opacity: 0.9;"><i class="fas fa-info-circle"></i> แสดงรูปแบบ คะแนนดิบ / คะแนนที่ปรับสัดส่วนแล้ว</p>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <div class="card bg-success text-white shadow mb-4">
                <div class="card-body text-center">
                    <h5 class="mb-2 text-white-50">คะแนนเต็มดิบ <?php echo number_format($max_raw_score, 2); ?> ➔ คะแนนเต็มรอบ <?php echo number_format($active_target_score, 2); ?></h5>
                    <h3 class="font-weight-bold mb-0">คะแนนรวมสุทธิ: <?php echo number_format($raw, 2); ?> / <?php echo number_format($scaled, 2); ?> คะแนน</h3>
                    <p class="mb-0 mt-2" style="font-size: 0.9rem; opacity: 0.9;"><i class="fas fa-info-circle"></i> แสดงรูปแบบ คะแนนดิบ / คะแนนที่ปรับสัดส่วนแล้ว</p>
                </div>
            </div>
            <?php endif; ?>

            <div class="card card-outline card-success shadow">
                <div class="card-header bg-white">
                    <h3 class="card-title text-success"><i class="fas fa-list-ul"></i> รายละเอียดคะแนนแต่ละหัวข้อ</h3>
                </div>
                <div class="card-body">
                    <?php 
                        $mc_index = 1;
                        foreach($main_categories as $m_cat): 
                            $subs = isset($sub_criteria[$m_cat['criteria_id']]) ? $sub_criteria[$m_cat['criteria_id']] : [];
                    ?>
                        <div class="card bg-light mb-5 shadow-sm border" style="border-radius: 10px;">
                            <div class="card-header border-0 bg-success text-white" style="border-radius: 10px 10px 0 0;">
                                <h4 class="mb-0"><b>ส่วนที่ <?php echo $mc_index++; ?>: <?php echo htmlspecialchars($m_cat['criteria_title']); ?></b></h4>
                                <?php if($m_cat['criteria_desc']): ?>
                                    <small class="d-block mt-1"><?php echo nl2br(htmlspecialchars($m_cat['criteria_desc'])); ?></small>
                                <?php endif; ?>
                                <span class="badge badge-light text-success float-right mt-1" style="font-size:16px;">
                                    คะแนนเต็มรวม <?php echo number_format($m_cat['calculated_max'], 2); ?> คะแนน
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
                                        <div class="p-4 <?php echo $i < count($subs)-1 ? 'border-bottom' : ''; ?>">
                                            <h5 class="text-dark font-weight-bold mb-3">
                                                <?php echo $d['sort_order'] !== '-' ? htmlspecialchars($m_cat['sort_order'] . '.' . $d['sort_order']) : ''; ?> 
                                                <?php echo htmlspecialchars($d['criteria_title']); ?>
                                            </h5>
                                            <?php if($d['criteria_desc']): ?>
                                                <p class="text-muted small mb-3"><i class="fas fa-info-circle"></i> <?php echo nl2br(htmlspecialchars($d['criteria_desc'])); ?></p>
                                            <?php endif; ?>
                                            
                                            <!-- Staff's Answer -->
                                            <div class="p-3 mb-4 bg-light" style="border-left: 4px solid var(--secondary-color); border-radius: 4px;">
                                                <strong class="text-secondary"><i class="fas fa-comment-dots"></i> ผลงานที่เจ้าหน้าที่รายงาน:</strong>
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
                                            
                                            <!-- Scoring Result -->
                                            <div class="row">
                                                <div class="col-md-4 mb-3 mb-md-0">
                                                    <div class="alert alert-secondary text-center mb-0 h-100 d-flex flex-column justify-content-center">
                                                        <h6 class="text-muted mb-1">คะแนนที่ท่านให้</h6>
                                                        <h2 class="text-success font-weight-bold mb-0"><?php echo number_format((float)$d['evaluator_score'], 2); ?> <small class="text-muted" style="font-size: 1rem;">/ <?php echo number_format($d['max_score'], 2); ?></small></h2>
                                                    </div>
                                                </div>
                                                <div class="col-md-8">
                                                    <div class="p-3 bg-light border h-100" style="border-radius: 4px;">
                                                        <strong class="text-info"><i class="fas fa-comment-medical"></i> ความคิดเห็น/ข้อเสนอแนะ:</strong>
                                                        <div class="mt-2 text-dark">
                                                            <?php 
                                                            if($d['evaluator_comment']) echo nl2br(htmlspecialchars($d['evaluator_comment']));
                                                            else echo '<span class="text-muted"><i>ไม่มีความคิดเห็น</i></span>';
                                                            ?>
                                                        </div>
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
