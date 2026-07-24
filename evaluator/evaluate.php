<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['evaluator', 'staff'])) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Pending Evaluations';
$user_id = $_SESSION['user_id'];

// Run automated sync to clear obsolete jobs from evaluatees before displaying
syncOpenRounds($pdo);

// Get all evaluations assigned to this evaluator
$evaluations = $pdo->prepare("
    SELECT ees.id as ees_id, ees.status as my_status, ees.total_score,
           ei.id as original_instance_id, ei.status as global_status, 
           r.title as round_title, r.status as round_status, r.target_score, r.evaluator_deadline,
           e1.prefix as ee_p, e1.firstname as ee_f, e1.lastname as ee_l, e1.profile_image, e1.staff_type,
           j.title as evaluated_job_title
    FROM evaluation_evaluator_status ees
    JOIN evaluation_instances ei ON ees.evaluation_instance_id = ei.id
    JOIN evaluation_rounds r ON ei.round_id = r.id
    JOIN users e1 ON ei.evaluatee_id = e1.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ees.evaluator_id = ?
    ORDER BY ees.id DESC
");
$evaluations->execute([$user_id]);
$eval_list = $evaluations->fetchAll(PDO::FETCH_ASSOC);

$max_raw_staff = getMaxRawScore($pdo, 'staff');
$max_raw_teacher = getMaxRawScore($pdo, 'teacher');

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-clipboard-check text-primary mr-2"></i> รายการบุคลากรในความรับผิดชอบ</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-primary shadow">
                <div class="card-body">
                    <table class="table table-bordered table-striped" id="evalTable">
                        <thead>
                            <tr class="bg-primary text-white">
                                <th width="5%">#</th>
                                <th>รอบประเมิน</th>
                                <th>ผู้รับการประเมิน</th>
                                <th>สถานะ</th>
                                <th>คะแนน</th>
                                <th width="15%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            // Group by evaluatee + round
                            $grouped_evals = [];
                            foreach($eval_list as $ev) {
                                $key = $ev['round_title'] . '||' . $ev['ee_p'] . $ev['ee_f'] . $ev['ee_l'];
                                if (!isset($grouped_evals[$key])) {
                                    $grouped_evals[$key] = [
                                        'round_title' => $ev['round_title'],
                                        'round_status' => $ev['round_status'],
                                        'ee_p' => $ev['ee_p'],
                                        'ee_f' => $ev['ee_f'],
                                        'ee_l' => $ev['ee_l'],
                                        'profile_image' => $ev['profile_image'],
                                        'staff_type' => $ev['staff_type'],
                                        'target_score' => $ev['target_score'],
                                        'evaluator_deadline' => $ev['evaluator_deadline'],
                                        'job_titles' => [],
                                        'ees_ids' => [],
                                        'max_global_status' => 0, // 0=staff, 1=eval, 2=completed
                                        'has_pending_staff' => false,
                                        'has_pending_eval_ready' => false,
                                        'has_pending_eval_not_ready' => false,
                                        'all_completed' => true,
                                        'sum_score' => 0,
                                        'count_score' => 0,
                                        'first_ees_id' => null,
                                        'first_view_ees_id' => null
                                    ];
                                }
                                $g = &$grouped_evals[$key];
                                
                                // Collect job titles
                                if ($ev['staff_type'] == 'teacher') $jt = 'ครูพิเศษสอน';
                                elseif ($ev['staff_type'] == 'gov_teacher') $jt = 'พนักงานราชการสายงานการสอน';
                                else $jt = $ev['evaluated_job_title'] ?? 'รวมทุกตำแหน่ง';
                                if (!in_array($jt, $g['job_titles'])) $g['job_titles'][] = $jt;
                                
                                $g['ees_ids'][] = $ev['ees_id'];
                                $g_stat = 0;
                                if ($ev['global_status'] == 'completed') $g_stat = 2;
                                elseif ($ev['global_status'] == 'pending_evaluator') $g_stat = 1;
                                
                                $g['max_global_status'] = max($g['max_global_status'], $g_stat);
                                
                                // Track if ANY role is still waiting for staff
                                if ($ev['global_status'] == 'pending_staff') {
                                    $g['has_pending_staff'] = true;
                                }
                                
                                // Fallback ID: Only grab an ID if it's actually scoreable based on global status
                                if (!$g['first_ees_id'] && $g_stat > 0) {
                                    $g['first_ees_id'] = $ev['ees_id']; 
                                }
                                
                                if ($ev['my_status'] == 'pending') {
                                    $g['all_completed'] = false;
                                    
                                    if ($g_stat > 0) {
                                        // Staff has submitted, and Evaluator hasn't scored
                                        $g['has_pending_eval_ready'] = true;
                                        $g['first_ees_id'] = $ev['ees_id'];
                                    } else {
                                        // Staff hasn't submitted, so Evaluator CANNOT score
                                        $g['has_pending_eval_not_ready'] = true;
                                    }
                                }
                                
                                if ($ev['my_status'] == 'completed') {
                                    $g['sum_score'] += (float)$ev['total_score'];
                                    $g['count_score']++;
                                    if (!$g['first_view_ees_id']) $g['first_view_ees_id'] = $ev['ees_id'];
                                }
                                unset($g);
                            }
                            
                            $row_idx = 1;
                            foreach($grouped_evals as $gev): 
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $row_idx++; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($gev['round_title']); ?>
                                </td>
                                <td>
                                    <img src="<?php echo $gev['profile_image'] ? BASE_URL . '/assets/img/' . $gev['profile_image'] : 'https://ui-avatars.com/api/?name='.urlencode($gev['ee_f']).'&background=random&color=fff&rounded=true'; ?>" class="img-circle mr-2 bg-white" alt="User Image" style="width:30px; height:30px; object-fit:cover;">
                                    <?php echo htmlspecialchars($gev['ee_p'].$gev['ee_f'].' '.$gev['ee_l']); ?>
                                    <div class="mt-1">
                                        <?php foreach($gev['job_titles'] as $jt): ?>
                                            <small class="text-primary d-block"><i class="fas fa-briefcase"></i> ตำแหน่ง: <b><?php echo htmlspecialchars($jt); ?></b></small>
                                        <?php endforeach; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php 
                                        if($gev['has_pending_eval_ready']) echo '<span class="badge badge-warning py-2 px-3">รอท่านให้คะแนน</span>';
                                        elseif($gev['has_pending_staff']) echo '<span class="badge badge-secondary py-2 px-3">รอเจ้าหน้าที่กรอกข้อมูล</span>';
                                        elseif($gev['all_completed']) echo '<span class="badge badge-success py-2 px-3">ท่านประเมินเสร็จแล้ว</span>';
                                        else echo '<span class="badge badge-secondary py-2 px-3">รอดำเนินการ</span>';
                                    ?>
                                </td>
                                <td class="text-center font-weight-bold text-success">
                                    <?php 
                                        if ($gev['all_completed'] && $gev['count_score'] > 0) {
                                            $scaled = $gev['sum_score'] / $gev['count_score'];
                                            $target = (float)$gev['target_score'];
                                            $max_raw_score_g = ($gev['staff_type'] == 'teacher') ? $max_raw_teacher : $max_raw_staff;
                                            $raw = $target > 0 ? ($scaled / $target) * $max_raw_score_g : 0;
                                            echo number_format($raw, 2) . ' / ' . number_format($scaled, 2); 
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if($gev['has_pending_eval_ready'] && $gev['round_status'] == 'open'): ?>
                                        <?php if (!empty($gev['evaluator_deadline']) && strtotime('now') > strtotime($gev['evaluator_deadline'])): ?>
                                            <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-clock"></i> หมดเวลาประเมิน</button>
                                        <?php else: ?>
                                            <a href="scoring_form.php?ees_id=<?php echo $gev['first_ees_id']; ?>" class="btn btn-warning btn-sm">
                                                <i class="fas fa-star-half-alt"></i> ให้คะแนน
                                            </a>
                                        <?php endif; ?>
                                    <?php elseif($gev['all_completed'] && $gev['count_score'] > 0): ?>
                                        <a href="view_score.php?ees_id=<?php echo $gev['first_view_ees_id']; ?>" class="btn btn-success btn-sm">
                                            <i class="fas fa-eye"></i> ดูผลลัพธ์ที่ให้
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-hourglass-half"></i> รอดำเนินการ</button>
                                    <?php endif; ?>
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

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#evalTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "order": [[ 0, "asc" ]]
    });
});
</script>
