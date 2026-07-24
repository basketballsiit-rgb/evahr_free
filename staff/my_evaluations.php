<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'My Evaluations';
$user_id = $_SESSION['user_id'];

// Sync any missing evaluation instances for open rounds
syncOpenRounds($pdo);

// Get all assigned evaluations for this user
$evaluations = $pdo->prepare("
    SELECT ei.*, r.title as round_title, r.status as round_status, r.target_score, r.staff_deadline, j.title as evaluated_job_title,
        (SELECT GROUP_CONCAT(CONCAT(u.firstname, ' ', u.lastname) SEPARATOR ', ')
         FROM evaluation_evaluator_status ees
         JOIN users u ON ees.evaluator_id = u.id
         WHERE ees.evaluation_instance_id = ei.id) as evaluators_names
    FROM evaluation_instances ei
    JOIN evaluation_rounds r ON ei.round_id = r.id
    LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
    WHERE ei.evaluatee_id = ?
    ORDER BY ei.id DESC
");
$evaluations->execute([$user_id]);
$eval_list = $evaluations->fetchAll(PDO::FETCH_ASSOC);

$user_rounds = [];
foreach ($eval_list as $ev) {
    if (!isset($user_rounds[$ev['round_id']])) {
        $user_rounds[$ev['round_id']] = [
            'round_id' => $ev['round_id'],
            'round_title' => $ev['round_title'],
            'round_status' => $ev['round_status'],
            'staff_deadline' => $ev['staff_deadline'],
            'job_titles' => [],
            'has_pending_staff' => false,
            'has_pending_eval' => false,
            'all_published' => true,
            'all_completed' => true,
            'instances' => [],
            'first_instance_id' => $ev['id']
        ];
    }
    
    $j_name = $ev['evaluated_job_title'] ?: 'รวมทุกตำแหน่ง';
    if (!in_array($j_name, $user_rounds[$ev['round_id']]['job_titles'])) {
        $user_rounds[$ev['round_id']]['job_titles'][] = $j_name;
    }
    $user_rounds[$ev['round_id']]['instances'][] = $ev['id'];
    
    if ($ev['status'] == 'pending_staff') {
        $user_rounds[$ev['round_id']]['has_pending_staff'] = true;
        $user_rounds[$ev['round_id']]['all_completed'] = false;
    } elseif ($ev['status'] == 'pending_evaluator') {
        $user_rounds[$ev['round_id']]['has_pending_eval'] = true;
        $user_rounds[$ev['round_id']]['all_completed'] = false;
    }
    
    if ($ev['status'] != 'completed' || !isset($ev['is_published']) || $ev['is_published'] == 0) {
        $user_rounds[$ev['round_id']]['all_published'] = false;
    }
}
$stmt_st = $pdo->prepare("SELECT staff_type FROM users WHERE id = ?");
$stmt_st->execute([$user_id]);
$my_staff_type = $stmt_st->fetchColumn() ?: 'staff';
$max_raw_score = getMaxRawScore($pdo, $my_staff_type);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-file-alt text-primary mr-2"></i> รายการประเมินของฉัน (My Evaluations)</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">

            <div class="card card-outline card-primary shadow">
                <div class="card-body">
                    <table class="table table-bordered table-striped" id="myEvalTable">
                        <thead>
                            <tr class="bg-primary text-white">
                                <th width="5%">#</th>
                                <th>รอบการประเมิน</th>
                                <th class="text-center">คะแนนดิบ / สุทธิ</th>
                                <th width="15%">สถานะ</th>
                                <th width="15%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                                $row_idx = 1;
                                foreach($user_rounds as $rid => $ur): 
                                    if ($ur['has_pending_staff']) {
                                        $unified_status = 'pending_staff';
                                    } elseif ($ur['has_pending_eval'] || !$ur['all_completed']) {
                                        $unified_status = 'pending_evaluator';
                                    } else {
                                        $unified_status = 'completed';
                                    }
                            ?>
                            <tr>
                                <td class="text-center"><?php echo $row_idx++; ?></td>
                                <td>
                                    <?php echo htmlspecialchars($ur['round_title']); ?>
                                    <?php if($ur['round_status'] == 'closed') echo ' <span class="badge badge-secondary">รอบปิดแล้ว</span>'; ?>
                                    <br>
                                    <small class="text-primary"><i class="fas fa-briefcase"></i> ประเมินในตำแหน่ง: <b><?php 
                                        if ($my_staff_type == 'teacher') {
                                            echo 'ครูพิเศษสอน';
                                        } elseif ($my_staff_type == 'gov_teacher') {
                                            echo 'พนักงานราชการสายงานการสอน';
                                        } else {
                                            echo htmlspecialchars(implode('<br>', $ur['job_titles'])); 
                                        }
                                    ?></b></small>
                                </td>
                                <td class="text-center" style="font-size: 1.1rem;">
                                    <?php 
                                        if ($unified_status == 'completed') {
                                            if ($ur['all_published']) {
                                                echo '<span class="text-success font-weight-bold"><i class="fas fa-bullhorn"></i> ประกาศผลแล้ว</span><br>';
                                                echo '<small class="text-muted">กดปุ่มดูรายละเอียด</small>';
                                            } else {
                                                echo '<span class="text-warning font-weight-bold"><i class="fas fa-clock"></i> รอประกาศผล</span>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                    ?>
                                </td>
                                <td>
                                    <?php 
                                        if($unified_status == 'pending_staff') echo '<span class="badge badge-warning py-2 px-3">รอท่านกรอกข้อมูลผลงาน</span>';
                                        elseif($unified_status == 'pending_evaluator') echo '<span class="badge badge-info py-2 px-3">รอกรรมการประเมิน</span>';
                                        else echo '<span class="badge badge-success py-2 px-3">ประเมินเสร็จสิ้น</span>';
                                    ?>
                                </td>
                                <td class="text-center">
                                    <?php if($unified_status == 'pending_staff' && $ur['round_status'] == 'open'): ?>
                                        <?php if(!empty($ur['staff_deadline']) && strtotime('now') > strtotime($ur['staff_deadline'])): ?>
                                            <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-clock"></i> หมดเวลาลงส่งงาน</button>
                                        <?php else: ?>
                                            <a href="evaluation_form.php?id=<?php echo $ur['first_instance_id']; ?>" class="btn btn-primary btn-sm">
                                                <i class="fas fa-pen"></i> ประเมินตนเอง
                                            </a>
                                            <?php if(!empty($ur['staff_deadline'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-danger font-weight-bold countdown-timer" data-deadline="<?php echo date('Y-m-d\TH:i:s', strtotime($ur['staff_deadline'])); ?>">
                                                        <i class="fas fa-stopwatch"></i> <span class="time-left">กำลังคำนวณเวลา...</span>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    <?php elseif($unified_status == 'pending_evaluator' && $ur['round_status'] == 'open'): ?>
                                        <?php $deadline_passed = !empty($ur['staff_deadline']) && strtotime('now') > strtotime($ur['staff_deadline']); ?>
                                        <?php if(!$deadline_passed): ?>
                                            <a href="evaluation_form.php?id=<?php echo $ur['first_instance_id']; ?>" class="btn btn-warning btn-sm">
                                                <i class="fas fa-edit"></i> แก้ไขข้อมูล
                                            </a>
                                            <?php if(!empty($ur['staff_deadline'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-danger font-weight-bold countdown-timer" data-deadline="<?php echo date('Y-m-d\TH:i:s', strtotime($ur['staff_deadline'])); ?>">
                                                        <i class="fas fa-stopwatch"></i> <span class="time-left">กำลังคำนวณเวลา...</span>
                                                    </small>
                                                </div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <button class="btn btn-secondary btn-sm" disabled><i class="fas fa-hourglass-half"></i> รอกรรมการประเมิน</button>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($unified_status == 'completed' && !$ur['all_published']): ?>
                                            <button class="btn btn-secondary btn-sm" disabled title="แอดมินยังไม่เผยแพร่คะแนน"><i class="fas fa-eye-slash"></i> รอประกาศผล</button>
                                        <?php else: ?>
                                            <a href="evaluation_view.php?round_id=<?php echo $ur['round_id']; ?>" class="btn btn-info btn-sm">
                                                <i class="fas fa-eye"></i> ดูรายละเอียด
                                            </a>
                                        <?php endif; ?>
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
    $('#myEvalTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "order": [[ 0, "asc" ]]
    });

    // Countdown Timer Logic
    function updateCountdowns() {
        $('.countdown-timer').each(function() {
            var dl = $(this).data('deadline');
            if (!dl) return;
            
            // Replaces hyphens with slashes for broader older-browser legacy compatibility, though T format is usually okay.
            var deadlineObj = new Date(dl);
            var now = new Date();
            var diff = deadlineObj - now; // Time remaining in MS
            
            if (diff <= 0) {
                $(this).removeClass('text-danger').addClass('text-muted');
                $(this).find('.time-left').text('หมดเวลาแล้ว (รอรีเฟรชระบบ)');
                return;
            }
            
            var days = Math.floor(diff / (1000 * 60 * 60 * 24));
            var hours = Math.floor((diff % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
            var mins = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
            var secs = Math.floor((diff % (1000 * 60)) / 1000);
            
            var timeStr = "";
            if (days > 0) {
                timeStr += days + " วัน " + hours + " ชม.";
            } else if (hours > 0) {
                timeStr += hours + " ชม. " + mins + " นาที";
            } else {
                timeStr += mins + " นาที " + secs + " วินาที";
            }
            
            $(this).find('.time-left').text('เหลือ ' + timeStr);
        });
    }
    
    updateCountdowns();
    setInterval(updateCountdowns, 1000);
});
</script>
