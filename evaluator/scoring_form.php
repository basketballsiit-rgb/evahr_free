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
           r.title as round_title, r.status as round_status, r.target_score, r.evaluator_deadline,
           e1.prefix as ee_p, e1.firstname as ee_f, e1.lastname as ee_l, e1.staff_type,
           j.title as evaluated_job_title, j.description as job_description
    FROM evaluation_evaluator_status ees
    JOIN evaluation_instances ei ON ees.evaluation_instance_id = ei.id
    JOIN evaluation_rounds r ON ei.round_id = r.id
    JOIN users e1 ON ei.evaluatee_id = e1.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ees.id = ? AND ees.evaluator_id = ?
");
$stmt->execute([$ees_id, $user_id]);
$eval = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$eval || !in_array($eval['global_status'], ['pending_evaluator', 'completed']) || $eval['status'] != 'pending' || $eval['round_status'] != 'open') {
    $_SESSION['error'] = "รายการนี้ไม่พร้อมให้ประเมินหรือรับการประเมินไปแล้ว";
    header("Location: evaluate.php");
    exit();
}

if (!empty($eval['evaluator_deadline'])) {
    if (strtotime('now') > strtotime($eval['evaluator_deadline'])) {
        $_SESSION['error'] = "หมดเวลาประเมินให้คะแนนแล้ว (ปิดรับอัตโนมัติเมื่อ " . date('d/m/Y H:i', strtotime($eval['evaluator_deadline'])) . " น.)";
        header("Location: evaluate.php");
        exit();
    }
}

// Fetch Staff's submitted answers + Criteria details
$staff_type = $eval['staff_type'] ?? 'staff';
if ($staff_type === 'general') $staff_type = 'staff';
$details_stmt = $pdo->prepare("
    SELECT c.id as criteria_id, c.parent_id, c.title as criteria_title, c.description as criteria_desc, c.max_score, c.sort_order,
           eci.staff_input_text, eci.staff_link, eci.staff_attachment
    FROM evaluation_criteria c
    LEFT JOIN evaluation_criteria_inputs eci ON c.id = eci.criteria_id AND eci.evaluation_instance_id = ?
    WHERE c.target_group = 'both' OR c.target_group = ?
    ORDER BY c.sort_order ASC, c.id ASC
");
$details_stmt->execute([$eval['instance_id'], $staff_type]);
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

// Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['submit_scores'])) {
    
    try {
        $pdo->beginTransaction();
        
        $total_score = 0;
        $insert_score = $pdo->prepare("INSERT INTO evaluation_evaluator_scores (evaluation_evaluator_status_id, criteria_id, score, comment) VALUES (?, ?, ?, ?)");
        
        foreach ($all_criteria as $d) {
            $cid = $d['criteria_id'];
            if (isset($_POST['score_'.$cid])) { // If a score input was rendered for this criteria
                $max_score = $d['max_score'];
                
                $score = (float)$_POST['score_'.$cid];
                if($score > $max_score) $score = $max_score; // Prevent overscoring cheating
                if($score < 0) $score = 0;
                
                $comment = isset($_POST['comment_'.$cid]) ? trim($_POST['comment_'.$cid]) : '';
                
                $insert_score->execute([$ees_id, $cid, $score, $comment]);
                $total_score += $score;
            }
        }

        // Calculate proportional score based on max_raw_score and target_score
        // Calculate proportional score based on max_raw_score and target_score
        $max_raw_score = array_sum(array_column($main_categories, 'calculated_max'));
        $proportional_score = 0;
        
        $applied_8020 = false;
        if ($staff_type == 'gov_teacher') {
            $behavior_score = 0;
            $performance_score = 0;
            $other_score = 0;
            
            foreach ($main_categories as $mc_id => $mc) {
                $cat_score_sum = 0;
                foreach ($all_criteria as $d) {
                    if (($d['parent_id'] == $mc_id || $d['criteria_id'] == $mc_id) && isset($_POST['score_'.$d['criteria_id']])) {
                        // Use raw posted values (already clamped bounds above)
                        $cat_score_sum += (float)$_POST['score_'.$d['criteria_id']];
                    }
                }
                
                if (stripos($mc['criteria_title'], 'พฤติกรรม') !== false) {
                    $weight = 0.20;
                    $normalized = $mc['calculated_max'] > 0 ? ($cat_score_sum / $mc['calculated_max']) * 100 : 0;
                    $behavior_score += $normalized * $weight;
                    $applied_8020 = true;
                } elseif (stripos($mc['criteria_title'], 'ผลสัมฤทธิ์') !== false) {
                    $weight = 0.80;
                    $normalized = $mc['calculated_max'] > 0 ? ($cat_score_sum / $mc['calculated_max']) * 100 : 0;
                    $performance_score += $normalized * $weight;
                    $applied_8020 = true;
                } else {
                    $other_score += $cat_score_sum;
                }
            }
            
            if ($applied_8020) {
                $percentage = ($behavior_score + $performance_score) + ($max_raw_score > 0 ? ($other_score / $max_raw_score)*100 : 0);
                $proportional_score = ($percentage / 100) * $eval['target_score'];
            }
        }
        
        if (!$applied_8020) {
            if ($max_raw_score > 0) {
                $proportional_score = ($total_score / $max_raw_score) * $eval['target_score'];
            }
        }

        // Update assigned evaluation status & total score for this specific evaluator
        $update_status = $pdo->prepare("UPDATE evaluation_evaluator_status SET status = 'completed', total_score = ? WHERE id = ?");
        $update_status->execute([$proportional_score, $ees_id]);

        // Check if ALL evaluators for this evaluation instance have completed their reviews
        $check_others = $pdo->prepare("SELECT COUNT(*) FROM evaluation_evaluator_status WHERE evaluation_instance_id = ? AND status = 'pending'");
        $check_others->execute([$eval['instance_id']]);
        
        if ($check_others->fetchColumn() == 0) {
            // All evaluators are done. Calculate average and mark instance as complete.
            $calc_avg = $pdo->prepare("SELECT AVG(total_score) FROM evaluation_evaluator_status WHERE evaluation_instance_id = ?");
            $calc_avg->execute([$eval['instance_id']]);
            $avg = $calc_avg->fetchColumn();
            
            $update_inst = $pdo->prepare("UPDATE evaluation_instances SET status = 'completed', total_score_average = ? WHERE id = ?");
            $update_inst->execute([$avg, $eval['instance_id']]);
        }

        // === REPLICATE SCORES TO SIBLING INSTANCES ===
        // Find all other evaluation instances for the same evaluatee in the same round
        // where this evaluator also has a pending ees row
        $find_evaluatee = $pdo->prepare("SELECT ei.evaluatee_id, ei.round_id FROM evaluation_instances ei WHERE ei.id = ?");
        $find_evaluatee->execute([$eval['instance_id']]);
        $eval_info = $find_evaluatee->fetch(PDO::FETCH_ASSOC);
        
        if ($eval_info) {
            $sibling_stmt = $pdo->prepare("
                SELECT ees.id as sibling_ees_id, ei.id as sibling_instance_id
                FROM evaluation_evaluator_status ees
                JOIN evaluation_instances ei ON ees.evaluation_instance_id = ei.id
                WHERE ei.round_id = ? AND ei.evaluatee_id = ? AND ees.evaluator_id = ?
                  AND ees.id != ? AND ees.status = 'pending'
                  AND ei.status = 'pending_evaluator'
            ");
            $sibling_stmt->execute([$eval_info['round_id'], $eval_info['evaluatee_id'], $user_id, $ees_id]);
            $siblings = $sibling_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $insert_sibling_score = $pdo->prepare("INSERT INTO evaluation_evaluator_scores (evaluation_evaluator_status_id, criteria_id, score, comment) VALUES (?, ?, ?, ?)");
            
            foreach ($siblings as $sib) {
                // Copy all scores from the original ees to this sibling ees
                $orig_scores = $pdo->prepare("SELECT criteria_id, score, comment FROM evaluation_evaluator_scores WHERE evaluation_evaluator_status_id = ?");
                $orig_scores->execute([$ees_id]);
                $scores_data = $orig_scores->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($scores_data as $sd) {
                    $insert_sibling_score->execute([$sib['sibling_ees_id'], $sd['criteria_id'], $sd['score'], $sd['comment']]);
                }
                
                // Update sibling ees status
                $update_status->execute([$proportional_score, $sib['sibling_ees_id']]);
                
                // Check if all evaluators are done for this sibling instance
                $check_others->execute([$sib['sibling_instance_id']]);
                if ($check_others->fetchColumn() == 0) {
                    $calc_avg->execute([$sib['sibling_instance_id']]);
                    $sib_avg = $calc_avg->fetchColumn();
                    $update_inst->execute([$sib_avg, $sib['sibling_instance_id']]);
                }
            }
        }

        $pdo->commit();
        $_SESSION['success'] = "บันทึกผลการประเมินเรียบร้อยแล้ว";
        header("Location: evaluate.php");
        exit();

    } catch(PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "เกิดข้อผิดพลาดในการบันทึก: " . $e->getMessage();
    }
}

$page_title = 'Scoring Form';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-8">
                    <h1 class="m-0 text-dark"><i class="fas fa-star-half-alt text-warning mr-2"></i> ให้คะแนนการปฏิบัติงาน</h1>
                    <p class="mt-2 mb-0" style="font-size: 1.1rem;">ผู้รับการประเมิน: <b><?php echo htmlspecialchars($eval['ee_p'].$eval['ee_f'].' '.$eval['ee_l']); ?></b></p>
                    <?php
                        // Fetch all job titles for this evaluatee in this round
                        $all_jobs_stmt = $pdo->prepare("
                            SELECT DISTINCT j.title 
                            FROM evaluation_instances ei 
                            LEFT JOIN jobs j ON ei.evaluated_job_id = j.id 
                            WHERE ei.round_id = (SELECT round_id FROM evaluation_instances WHERE id = ?) 
                              AND ei.evaluatee_id = (SELECT evaluatee_id FROM evaluation_instances WHERE id = ?)
                        ");
                        $all_jobs_stmt->execute([$eval['instance_id'], $eval['instance_id']]);
                        $all_job_titles = $all_jobs_stmt->fetchAll(PDO::FETCH_COLUMN);
                        
                        if ($eval['staff_type'] == 'teacher') {
                            $display_jobs = ['ครูพิเศษสอน'];
                        } elseif ($eval['staff_type'] == 'gov_teacher') {
                            $display_jobs = ['พนักงานราชการสายงานการสอน'];
                        } else {
                            $display_jobs = array_filter($all_job_titles);
                            if (empty($display_jobs)) $display_jobs = ['รวมทุกตำแหน่ง'];
                        }
                    ?>
                    <?php foreach($display_jobs as $dj): ?>
                        <p class="mb-0 text-primary"><i class="fas fa-briefcase"></i> ตำแหน่ง: <b><?php echo htmlspecialchars($dj); ?></b></p>
                    <?php endforeach; ?>
                    <?php if(count($display_jobs) > 1): ?>
                        <div class="alert alert-info mt-2 py-2 px-3 mb-0" style="font-size: 0.9rem;">
                            <i class="fas fa-info-circle"></i> <b>หมายเหตุ:</b> ท่านให้คะแนนเพียงครั้งเดียว ระบบจะนำคะแนนไปใช้กับทุกตำแหน่งของผู้รับการประเมินท่านนี้โดยอัตโนมัติ
                        </div>
                    <?php endif; ?>
                    <?php if(!empty($eval['job_description'])): ?>
                    <p class="mb-0 text-secondary" style="font-size: 0.95rem;"><i class="fas fa-tasks"></i> หน้าที่ของงาน: <?php echo nl2br(htmlspecialchars($eval['job_description'])); ?></p>
                    <?php endif; ?>
                    <p class="text-muted mt-1"><i class="fas fa-clock"></i> รอบการประเมิน: <?php echo htmlspecialchars($eval['round_title']); ?></p>
                </div>
                <div class="col-sm-4 text-right">
                    <a href="evaluate.php" class="btn btn-secondary mt-2"><i class="fas fa-arrow-left"></i> ย้อนกลับ</a>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-warning shadow">
                <div class="card-header bg-white">
                    <h3 class="card-title text-primary"><i class="fas fa-tasks"></i> แบบฟอร์มให้คะแนน</h3>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning text-dark border-warning">
                        <i class="fas fa-info-circle"></i> โปรดอ่านผลการปฏิบัติงานของเจ้าหน้าที่ในแต่ละหัวข้อ และให้คะแนนตามความเหมาะสม (ระบุทศนิยมได้)
                    </div>

                    <form action="scoring_form.php?ees_id=<?php echo $ees_id; ?>" method="POST">
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
                                    <span class="badge badge-light text-primary float-right mt-1" style="font-size:16px;">
                                        คะแนนเต็มรวม <?php echo number_format($m_cat['calculated_max'], 1); ?> คะแนน
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
                                                
                                                <!-- Scoring Section -->
                                                <div class="row">
                                                    <div class="col-md-5">
                                                        <div class="form-group">
                                                            <?php if ($staff_type === 'gov_teacher'): ?>
                                                                <label class="text-danger">ระดับเป้าหมาย (1=ต่ำมาก, 5=เกินคาด)</label>
                                                                <div class="d-flex justify-content-between align-items-center mb-1">
                                                                    <?php for($lvl=1; $lvl<=5; $lvl++): ?>
                                                                    <div class="custom-control custom-radio custom-control-inline mr-1">
                                                                        <input class="custom-control-input gov-radio" type="radio" 
                                                                            id="r_<?php echo $d['criteria_id'].'_'.$lvl; ?>" 
                                                                            name="level_<?php echo $d['criteria_id']; ?>" 
                                                                            value="<?php echo $lvl; ?>" 
                                                                            data-target="score_<?php echo $d['criteria_id']; ?>" 
                                                                            data-max="<?php echo $d['max_score']; ?>" required>
                                                                        <label for="r_<?php echo $d['criteria_id'].'_'.$lvl; ?>" class="custom-control-label" style="font-size: 1.1rem; cursor:pointer;" title="ระดับ <?php echo $lvl; ?>"><?php echo $lvl; ?></label>
                                                                    </div>
                                                                    <?php endfor; ?>
                                                                </div>
                                                                <small class="text-muted d-block mb-1">น้ำหนัก (ข): <?php echo number_format($d['max_score'], 0); ?>%</small>
                                                                <input type="hidden" id="score_<?php echo $d['criteria_id']; ?>" name="score_<?php echo $d['criteria_id']; ?>" class="gov-hidden-score" value="">
                                                                <div class="text-info font-weight-bold"><small>คะแนน (ก x ข / 5): <span id="display_<?php echo $d['criteria_id']; ?>">0.00</span></small></div>
                                                            <?php else: ?>
                                                                <label class="text-danger">ให้คะแนน (เต็ม <?php echo number_format($d['max_score'], 1); ?>)</label>
                                                                <input type="number" step="0.50" min="0" max="<?php echo $d['max_score']; ?>" name="score_<?php echo $d['criteria_id']; ?>" value="<?php echo $d['max_score']; ?>" class="form-control text-center text-lg font-weight-bold" style="font-size: 1.5rem; height: auto;" required placeholder="0.00">
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                    <div class="col-md-8">
                                                        <div class="form-group">
                                                            <label>ความคิดเห็น/ข้อเสนอแนะเพิ่มเติม (ออปชั่น)</label>
                                                            <textarea name="comment_<?php echo $d['criteria_id']; ?>" class="form-control" rows="2" placeholder="ระบุเหตุผลในการให้คะแนน หรือข้อเสนอแนะเพื่อการพัฒนา..."></textarea>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>

                        <div class="text-center mt-4 mb-2">
                            <button type="button" class="btn btn-warning btn-lg px-5 shadow rounded-pill text-dark font-weight-bold" id="btnSubmit">
                                <i class="fas fa-check-double mr-2"></i> อนุมัติผลคะแนน
                            </button>
                        </div>
                        <input type="hidden" name="submit_scores" value="1">
                    </form>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // For gov_teacher logic
    $('.gov-radio').on('change', function() {
        let level = parseInt($(this).val());
        let max = parseFloat($(this).data('max'));
        let targetId = $(this).data('target');
        
        // Score = (Level / 5) * Weight
        let calculatedScore = (level / 5) * max;
        
        $('#' + targetId).val(calculatedScore);
        $('#display_' + targetId.split('_')[1]).text(calculatedScore.toFixed(2));
    });

    $('#btnSubmit').click(function() {
        let isGov = <?php echo ($staff_type === 'gov_teacher') ? 'true' : 'false'; ?>;
        
        if (isGov) {
            // Check if all radio groups are checked
            let allChecked = true;
            $('.gov-hidden-score').each(function() {
                if ($(this).val() === "") {
                    allChecked = false;
                    return false; // break loop
                }
            });
            if (!allChecked) {
                Swal.fire({
                    icon: 'warning',
                    title: 'กรอกข้อมูลไม่ครบ',
                    text: 'กรุณาเลือกระดับเป้าหมายการประเมินให้ครบทุกหัวข้อ'
                });
                return;
            }
        }
        
        // Calculate total score dynamically from all inputs
        let totalScore = 0;
        let maxScore = <?php echo array_sum(array_column($main_categories, 'calculated_max')); ?>;
        
        if (isGov) {
            $('.gov-hidden-score').each(function() {
                totalScore += parseFloat($(this).val()) || 0;
            });
        } else {
            $('input[type="number"][name^="score_"]').each(function() {
                let val = parseFloat($(this).val()) || 0;
                totalScore += val;
            });
        }

        Swal.fire({
            title: 'ยืนยันการให้คะแนน?',
            html: "<b>คะแนนรวมที่ท่านให้ไว้คือ: " + totalScore.toFixed(2) + " / " + maxScore.toFixed(0) + " คะแนน</b><br><br>หากกดบันทึกแล้ว จะถือเป็นการสิ้นสุดกระบวนการประเมินในส่วนของท่าน สำหรับรอบนี้ คุณแน่ใจหรือไม่?",
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#d33',
            confirmButtonText: 'ยืนยันผลประเมิน',
            cancelButtonText: 'ยกเลิกทบทวนใหม่'
        }).then((result) => {
            if (result.isConfirmed) {
                $(this).closest('form').submit();
            }
        });
    });
});
</script>
