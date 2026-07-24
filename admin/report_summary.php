<?php
require_once '../config.php';
$is_director = isDirector($pdo, $_SESSION['user_id'] ?? 0);
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] !== 'admin' && !$is_director)) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

// POST Handler for Admin Adjustment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_adjustments' && $_SESSION['role'] === 'admin') {
    $instance_ids = array_filter(array_map('intval', explode(',', $_POST['instance_id'] ?? '')));
    $is_published = isset($_POST['is_published']) ? 1 : 0;
    $reason = $_POST['admin_adjustment_reason'] ?? '';
    
    // Build JSON for category adjustments
    $adj_data = [];
    if (isset($_POST['adj']) && is_array($_POST['adj'])) {
        foreach ($_POST['adj'] as $mc_id => $val) {
            if (trim($val) !== '') {
                $adj_data[$mc_id] = (float)$val;
            }
        }
    }
    $adj_json = empty($adj_data) ? null : json_encode($adj_data);
    
    try {
        if (!empty($instance_ids)) {
            $in_clause = implode(',', array_fill(0, count($instance_ids), '?'));
            $params = array_merge([$is_published, $adj_json, $reason], $instance_ids);
            $upd = $pdo->prepare("UPDATE evaluation_instances SET is_published = ?, admin_category_adjustments = ?, admin_adjustment_reason = ? WHERE id IN ($in_clause)");
            $upd->execute($params);
        }
        $_SESSION['success'] = "บันทึกการปรับคะแนนและสถานะการเผยแพร่เรียบร้อยแล้ว";
    } catch (Exception $e) {
        $_SESSION['error'] = "Error updating: " . $e->getMessage();
    }
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    $qs = preg_replace('/&?action=[^&]*/', '', $qs);
    header("Location: report_summary.php?" . $qs);
    exit();
}

$page_title = 'Summary Report';

// Fetch all rounds for filter
$rounds = $pdo->query("SELECT * FROM evaluation_rounds ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Determine active round
$active_round_id = isset($_GET['round_id']) ? (int)$_GET['round_id'] : ($rounds[0]['id'] ?? 0);
$active_round = null;
foreach ($rounds as $r) {
    if ($r['id'] == $active_round_id) {
        $active_round = $r;
        break;
    }
}

// Fetch distinct job titles from evaluation instances for this round
$job_filter_list = [];
if ($active_round_id > 0) {
    $jf_stmt = $pdo->prepare("
        SELECT DISTINCT j.id, j.title 
        FROM evaluation_instances ei
        JOIN jobs j ON ei.evaluated_job_id = j.id
        WHERE ei.round_id = ? AND ei.status = 'completed'
        ORDER BY j.title ASC
    ");
    $jf_stmt->execute([$active_round_id]);
    $job_filter_list = $jf_stmt->fetchAll(PDO::FETCH_ASSOC);
}

$selected_job_id = isset($_GET['job_id']) ? (int)$_GET['job_id'] : 0;
// Default to 'staff' to avoid combining mismatched criteria columns and breaking max score percentages
$selected_staff_type = isset($_GET['staff_type']) && $_GET['staff_type'] !== '' ? $_GET['staff_type'] : 'staff';

// Fetch main evaluation criteria (columns for the table)
if ($selected_staff_type && in_array($selected_staff_type, ['staff', 'teacher', 'gov_teacher', 'general'])) {
    $crit_stmt = $pdo->prepare("SELECT * FROM evaluation_criteria WHERE target_group = 'both' OR target_group = ? ORDER BY sort_order ASC, id ASC");
    $crit_stmt->execute([$selected_staff_type]);
    $all_criteria = $crit_stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $all_criteria = $pdo->query("SELECT * FROM evaluation_criteria ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$main_categories = [];
$sub_criteria_map = [];

foreach ($all_criteria as $c) {
    if ($c['parent_id'] === null) {
        $c['calculated_max'] = (float)$c['max_score'];
        $main_categories[$c['id']] = $c;
    } else {
        $sub_criteria_map[$c['parent_id']][] = $c;
    }
}

// Recalculate max for categories with children
foreach ($sub_criteria_map as $p_id => $subs) {
    if (isset($main_categories[$p_id])) {
        $main_categories[$p_id]['calculated_max'] = 0;
        foreach ($subs as $s) {
            $main_categories[$p_id]['calculated_max'] += (float)$s['max_score'];
        }
    }
}

$max_raw_score = array_sum(array_column($main_categories, 'calculated_max'));
$num_evaluators_per_instance = []; // cache

// Build report data
$report_rows = [];

if ($active_round_id > 0 && $active_round) {
    $target_score = (float)$active_round['target_score'];
    
    // Get completed evaluation instances for this round
    $inst_sql = "
        SELECT ei.id as instance_id, ei.evaluatee_id, ei.total_score_average, ei.evaluated_job_id,
               ei.is_published, ei.admin_category_adjustments, ei.admin_adjustment_reason,
               u.prefix, u.firstname, u.lastname, u.staff_type,
               j.title as job_title, d.name as department_name
        FROM evaluation_instances ei
        JOIN users u ON ei.evaluatee_id = u.id
        LEFT JOIN jobs j ON ei.evaluated_job_id = j.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE ei.round_id = ? AND ei.status = 'completed'
    ";
    $params = [$active_round_id];
    
    if ($selected_job_id > 0) {
        $inst_sql .= " AND ei.evaluated_job_id = ?";
        $params[] = $selected_job_id;
    }
    
    if ($selected_staff_type && in_array($selected_staff_type, ['staff', 'teacher', 'gov_teacher', 'general'])) {
        $inst_sql .= " AND u.staff_type = ?";
        $params[] = $selected_staff_type;
    }
    
    $inst_sql .= " ORDER BY ei.total_score_average DESC";
    
    $inst_stmt = $pdo->prepare($inst_sql);
    $inst_stmt->execute($params);
    $instances = $inst_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $all_unique_evaluators = [];
    $user_summaries = [];
    foreach ($instances as $inst) {
        $instance_id = $inst['instance_id'];
        
        // Get all completed evaluator statuses for this instance
        $ev_stmt = $pdo->prepare("
            SELECT ees.id as ees_id, ees.total_score, ees.evaluator_id, u.firstname, u.lastname
            FROM evaluation_evaluator_status ees
            JOIN users u ON ees.evaluator_id = u.id
            WHERE ees.evaluation_instance_id = ? AND ees.status = 'completed'
            ORDER BY ees.id ASC
        ");
        $ev_stmt->execute([$instance_id]);
        $evaluators = $ev_stmt->fetchAll(PDO::FETCH_ASSOC);
        $num_evaluators = count($evaluators);
        
        $adjustments = $inst['admin_category_adjustments'] ? json_decode($inst['admin_category_adjustments'], true) : [];
        if (!is_array($adjustments)) $adjustments = [];
        
        if ($num_evaluators == 0) continue;
        $ees_to_evaluator = [];
        $evaluator_scores = [];
        foreach ($evaluators as $ev) {
            $e_id = $ev['evaluator_id'];
            $ees_to_evaluator[$ev['ees_id']] = $e_id;
            if (!isset($all_unique_evaluators[$e_id])) {
                $all_unique_evaluators[$e_id] = $ev['firstname'] . ' ' . $ev['lastname'];
            }
            $evaluator_scores[$e_id] = ($target_score > 0) ? ($ev['total_score'] / $target_score) * 100 : 0;
        }
        
        // For each main category, calculate the average score across all evaluators
        $category_scores = [];
        $raw_averages = [];
        $evaluator_cat_scores = [];
        
        foreach ($main_categories as $mc_id => $mc) {
            // Get all scoreable criteria IDs for this main category
            $scoreable_ids = [];
            if (isset($sub_criteria_map[$mc_id])) {
                foreach ($sub_criteria_map[$mc_id] as $sub) {
                    $scoreable_ids[] = $sub['id'];
                }
            } else {
                // Main cat is its own scoreable item
                $scoreable_ids[] = $mc_id;
            }
            
            if (empty($scoreable_ids)) {
                $category_scores[$mc_id] = 0;
                continue;
            }
            
            // Sum scores for these criteria across all evaluators, then average
            $placeholders = implode(',', array_fill(0, count($scoreable_ids), '?'));
            $ees_ids = array_column($evaluators, 'ees_id');
            $ees_placeholders = implode(',', array_fill(0, count($ees_ids), '?'));
            
            $score_stmt = $pdo->prepare("
                SELECT evaluation_evaluator_status_id, SUM(score) as cat_total
                FROM evaluation_evaluator_scores
                WHERE evaluation_evaluator_status_id IN ($ees_placeholders)
                  AND criteria_id IN ($placeholders)
                GROUP BY evaluation_evaluator_status_id
            ");
            $score_stmt->execute(array_merge($ees_ids, $scoreable_ids));
            $score_results = $score_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $sum_across_evaluators = 0;
            if (!isset($evaluator_cat_scores[$mc_id])) $evaluator_cat_scores[$mc_id] = [];
            foreach ($score_results as $sr) {
                $sum_across_evaluators += (float)$sr['cat_total'];
                $e_id = $ees_to_evaluator[$sr['evaluation_evaluator_status_id']] ?? 0;
                $normalized = $mc['calculated_max'] > 0 ? ((float)$sr['cat_total'] / $mc['calculated_max']) * 100 : 0;
                if ($e_id) $evaluator_cat_scores[$mc_id][$e_id] = $normalized;
            }
            
            $cat_avg = $num_evaluators > 0 ? $sum_across_evaluators / $num_evaluators : 0;
            $orig_avg = $cat_avg;
            
            // APPLY ADMIN OVERRIDE HERE
            if (isset($adjustments[$mc_id])) {
                $cat_avg = (float)$adjustments[$mc_id];
                $cat_avg = min($cat_avg, $mc['calculated_max']); // Cap at max
                $cat_avg = max($cat_avg, 0); // Don't allow negative
            }
            
            $raw_averages[$mc_id] = $mc['calculated_max'] > 0 ? ($cat_avg / $mc['calculated_max']) * 100 : 0;
            $category_scores[$mc_id] = $cat_avg;
            if (!isset($category_scores_original)) $category_scores_original = [];
            $category_scores_original[$mc_id] = $orig_avg;
        }

        $uid = $inst['evaluatee_id'];
        if (!isset($user_summaries[$uid])) {
            $user_summaries[$uid] = [
                'evaluatee_id' => $uid,
                'is_published' => $inst['is_published'],
                'admin_adjustment_reason' => $inst['admin_adjustment_reason'],
                'admin_category_adjustments' => $inst['admin_category_adjustments'],
                'prefix' => $inst['prefix'],
                'firstname' => $inst['firstname'],
                'lastname' => $inst['lastname'],
                'staff_type' => $inst['staff_type'],
                'department_name' => $inst['department_name'] ?? '-',
                'job_titles' => [],
                'instances' => [],
                'category_scores_list' => [],
                'evaluator_cat_scores_raw' => [],
                'num_evaluators_total' => 0
            ];
        }
        
        $j_name = $inst['job_title'] ?: ($inst['staff_type'] == 'gov_teacher' ? 'พนักงานราชการ' : ($inst['staff_type'] == 'teacher' ? 'ครูพิเศษสอน' : '-'));
        if (!in_array($j_name, $user_summaries[$uid]['job_titles'])) {
            $user_summaries[$uid]['job_titles'][] = $j_name;
        }
        $user_summaries[$uid]['instances'][] = $inst['instance_id'];
        $user_summaries[$uid]['num_evaluators_total'] += $num_evaluators;
        
        foreach ($main_categories as $mc_id => $mc) {
            $user_summaries[$uid]['category_scores_list'][$mc_id][] = $category_scores[$mc_id] ?? 0;
            if (!isset($user_summaries[$uid]['category_scores_original_list'])) $user_summaries[$uid]['category_scores_original_list'] = [];
            $user_summaries[$uid]['category_scores_original_list'][$mc_id][] = $category_scores_original[$mc_id] ?? 0;
            
            if (!isset($user_summaries[$uid]['evaluator_cat_scores_raw'][$mc_id])) {
                $user_summaries[$uid]['evaluator_cat_scores_raw'][$mc_id] = [];
            }
            foreach ($all_unique_evaluators as $e_id => $e_name) {
                if (isset($evaluator_cat_scores[$mc_id][$e_id])) {
                    $user_summaries[$uid]['evaluator_cat_scores_raw'][$mc_id][$e_id][] = $evaluator_cat_scores[$mc_id][$e_id];
                }
            }
        }
    } // End of instances loop
    
    // NOW POST-PROCESS $user_summaries INTO $report_rows
    foreach ($user_summaries as $uid => $us) {
        $category_scores = [];
        $raw_averages = [];
        $merged_evaluator_cat_scores = [];
        
        foreach ($main_categories as $mc_id => $mc) {
            $scores_for_mc = $us['category_scores_list'][$mc_id] ?? [];
            if (count($scores_for_mc) > 0) {
                $merged_avg = array_sum($scores_for_mc) / count($scores_for_mc);
            } else {
                $merged_avg = 0;
            }
            $orig_scores_for_mc = $us['category_scores_original_list'][$mc_id] ?? [];
            if (count($orig_scores_for_mc) > 0) {
                $merged_orig_avg = array_sum($orig_scores_for_mc) / count($orig_scores_for_mc);
            } else {
                $merged_orig_avg = 0;
            }
            
            $category_scores[$mc_id] = $merged_avg;
            if (!isset($category_scores_original)) $category_scores_original = [];
            $category_scores_original[$mc_id] = $merged_orig_avg;
            
            $raw_averages[$mc_id] = $mc['calculated_max'] > 0 ? ($merged_avg / $mc['calculated_max']) * 100 : 0;
            
            foreach ($all_unique_evaluators as $e_id => $e_name) {
                $e_scores = $us['evaluator_cat_scores_raw'][$mc_id][$e_id] ?? [];
                if (count($e_scores) > 0) {
                    $merged_evaluator_cat_scores[$mc_id][$e_id] = array_sum($e_scores) / count($e_scores);
                }
            }
        }
        
        // Calculate total raw score (average across evaluators)
        $total_raw = array_sum($category_scores);
        
        // Calculate percentage
        $percentage = $max_raw_score > 0 ? ($total_raw / $max_raw_score) * 100 : 0;
        
        // Custom 80/20 Weighting Logic for gov_teacher
        $applied_8020 = false;
        if ($selected_staff_type == 'gov_teacher') {
            $behavior_score = 0;
            $performance_score = 0;
            $other_score = 0;
            
            foreach ($main_categories as $mc_id => $mc) {
                if (stripos($mc['title'], 'พฤติกรรม') !== false) {
                    $weight = 0.20;
                    $normalized = $mc['calculated_max'] > 0 ? ($category_scores[$mc_id] / $mc['calculated_max']) * 100 : 0;
                    $weighted = $normalized * $weight;
                    $behavior_score += $weighted;
                    $category_scores[$mc_id] = $weighted;
                    $applied_8020 = true;
                } elseif (stripos($mc['title'], 'ผลสัมฤทธิ์') !== false) {
                    $weight = 0.80;
                    $normalized = $mc['calculated_max'] > 0 ? ($category_scores[$mc_id] / $mc['calculated_max']) * 100 : 0;
                    $weighted = $normalized * $weight;
                    $performance_score += $weighted;
                    $category_scores[$mc_id] = $weighted;
                    $applied_8020 = true;
                } else {
                    $other_score += $category_scores[$mc_id];
                }
            }
            
            if ($applied_8020) {
                // Determine new percentage & raw score (both base 100)
                $percentage = ($behavior_score + $performance_score) + ($max_raw_score > 0 ? ($other_score / $max_raw_score) * 100 : 0);
                $total_raw = $percentage;
            }
        }
        
        // Determine grade level
        $grade_class = 'grade-improve';
        if ($percentage >= 90) {
            $grade = 'ดีเยี่ยม'; $grade_class = 'grade-excellent';
        } elseif ($percentage >= 80) {
            $grade = 'ดีมาก'; $grade_class = 'grade-very-good';
        } elseif ($percentage >= 70) {
            $grade = 'ดี'; $grade_class = 'grade-good';
        } elseif ($percentage >= 60) {
            $grade = 'พอใช้'; $grade_class = 'grade-fair';
        } else {
            $grade = 'ต้องปรับปรุง';
        }
        
        $report_rows[] = [
            'instance_id' => implode(',', $us['instances']),
            'is_published' => $us['is_published'],
            'admin_adjustment_reason' => $us['admin_adjustment_reason'],
            'admin_category_adjustments' => $us['admin_category_adjustments'],
            'prefix' => $us['prefix'],
            'firstname' => $us['firstname'],
            'lastname' => $us['lastname'],
            'job_title' => implode('<br>', $us['job_titles']),
            'department_name' => $us['department_name'],
            'category_scores' => $category_scores,
            'category_scores_original' => $category_scores_original,
            'total_raw' => $total_raw,
            'max_raw' => $applied_8020 ? 100 : $max_raw_score,
            'percentage' => $percentage,
            'grade' => $grade,
            'grade_class' => $grade_class,
            'num_evaluators' => count($us['instances']) > 0 ? ($us['num_evaluators_total'] / count($us['instances'])) : 0,
            'evaluator_scores' => [], // Merged mode doesn't track this easily, hide in summary if needed
            'evaluator_cat_scores' => $merged_evaluator_cat_scores,
            'raw_averages' => $raw_averages,
        ];
    }
    
    // Globally fix max_raw_score display to 100 if the list was transformed to 80/20 weights
    if (!empty($report_rows) && $selected_staff_type == 'gov_teacher') {
        $has_8020_rows = false;
        foreach ($report_rows as $row) {
            if ($row['max_raw'] == 100 && $max_raw_score != 100) { $has_8020_rows = true; break; }
        }
        if ($has_8020_rows) {
            $max_raw_score = 100;
        }
    }
    
    // Sort the final array by percentage descending to ensure highest scores are on top
    usort($report_rows, function($a, $b) {
        return $b['percentage'] <=> $a['percentage'];
    });
}

// Get selected job title for header display
$selected_job_title = 'ทุกตำแหน่ง';
if ($selected_job_id > 0) {
    foreach ($job_filter_list as $jf) {
        if ($jf['id'] == $selected_job_id) {
            $selected_job_title = $jf['title'];
            break;
        }
    }
}

// Load committee members from settings according to selected staff type
$committee_members = [];
try {
    // If selecting 'general', fallback to staff configuration
    $fetch_type = ($selected_staff_type == 'general') ? 'staff' : $selected_staff_type;
    $ckey = 'report_committee_' . $fetch_type;
    $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $cs->execute([$ckey]);
    $cj = $cs->fetchColumn();
    
    // Legacy fallback for staff
    if (!$cj && $fetch_type === 'staff') {
        $cs->execute(['report_committee']);
        $cj = $cs->fetchColumn();
    }
    
    if ($cj) {
        $committee_members = json_decode($cj, true);
        if (!is_array($committee_members)) $committee_members = [];
    }
} catch(Exception $e) {}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<style>
@media print {
    .screen-check { display: none !important; }
    @page {
        margin: 0;
        size: auto;
    }
    body {
        font-size: 11pt;
        background: #fff !important;
        margin: 10mm 15mm;
    }
    .main-sidebar, .main-header, .content-header, .no-print, .main-footer {
        display: none !important;
    }
    .content-wrapper {
        margin-left: 0 !important;
        padding: 0 !important;
    }
    .content-wrapper .content {
        padding: 0 !important;
    }
    .card {
        border: none !important;
        box-shadow: none !important;
    }
    .report-table {
        font-size: 10pt;
    }
    .print-header {
        display: block !important;
    }
    .print-committee {
        display: block !important;
    }
    .print-committee h5 {
        display: none !important;
    }
}

.print-header {
    display: none;
    text-align: center;
    margin-bottom: 20px;
}

.report-table {
    border-collapse: collapse;
    width: 100%;
    font-size: 13px;
}
.report-table th, .report-table td {
    border: 1px solid #333;
    padding: 5px 8px;
    text-align: center;
    vertical-align: middle;
}
.report-table thead th {
    background: #e8f4fd;
    color: #1a5276;
    font-weight: bold;
    font-size: 12px;
}
.report-table thead th.criteria-header {
    white-space: normal;
    padding: 6px 4px;
    font-size: 10px;
    line-height: 1.3;
    max-width: 80px;
    word-break: break-word;
}
.report-table thead .max-score-row th {
    background: #fff;
    color: #333;
    font-weight: bold;
    font-size: 12px;
    height: auto;
    writing-mode: horizontal-tb;
}
.vertical-header {
    writing-mode: vertical-rl;
    text-orientation: mixed;
    transform: rotate(180deg);
    white-space: nowrap;
    width: 45px;
    height: 140px;
    vertical-align: middle;
    padding: 10px 5px !important;
}
.vertical-header span {
    display: inline-block;
}
.report-table tbody td {
    font-size: 13px;
}
.report-table tbody td.name-cell {
    text-align: left;
    white-space: nowrap;
    padding-left: 10px;
}
.report-table tbody td.position-cell {
    text-align: left;
    font-size: 12px;
}
.report-table tbody tr:nth-child(even) {
    background: #f8fbff;
}
.report-table tbody tr:hover {
    background: #e3f2fd;
}
.grade-excellent { color: #1e8449; font-weight: bold; }
.grade-very-good { color: #2471a3; font-weight: bold; }
.grade-good { color: #1a5276; font-weight: bold; }
.grade-fair { color: #b7950b; font-weight: bold; }
.grade-improve { color: #c0392b; font-weight: bold; }

.screen-header {
    text-align: center;
    margin-bottom: 15px;
}
.screen-header h4, .screen-header h5 {
    margin: 5px 0;
    color: #1a5276;
}

.print-committee {
    display: none;
    margin-top: 40px;
    page-break-inside: avoid;
}
.print-committee .committee-grid {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-around;
    gap: 30px;
}
.print-committee .committee-item {
    text-align: center;
    min-width: 200px;
    flex: 0 0 auto;
}
.print-committee .committee-item .sign-line {
    margin-top: 50px;
    border-top: 1px dotted #333;
    padding-top: 5px;
    font-weight: bold;
}
.print-committee .committee-item .sign-position {
    font-size: 11px;
    color: #555;
    margin-top: 3px;
}
.screen-committee {
    margin-top: 20px;
}
.screen-committee .committee-card {
    text-align: center;
    padding: 15px;
    border: 1px dashed #ccc;
    border-radius: 8px;
    background: #fafafa;
}
.screen-committee .sign-line {
    margin-top: 30px;
    border-top: 1px dotted #333;
    padding-top: 5px;
    font-weight: bold;
}
.screen-committee .sign-position {
    font-size: 12px;
    color: #777;
    margin-top: 3px;
}
</style>

<div class="content-wrapper">
    <div class="content-header no-print">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-file-alt text-primary mr-2"></i> สรุปผลการประเมินรายบุคคล</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <a href="reports.php<?php echo $active_round_id ? '?round_id='.$active_round_id : ''; ?>" class="btn btn-secondary mt-2">
                        <i class="fas fa-arrow-left"></i> กลับหน้ารายงาน
                    </a>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <!-- Filters -->
            <div class="card shadow-sm mb-4 border-top-primary no-print">
                <div class="card-body py-3">
                    <form action="report_summary.php" method="GET" class="form-inline flex-wrap">
                        <label class="mr-3 text-dark"><i class="fas fa-filter mr-1"></i> รอบประเมิน:</label>
                        <select name="round_id" class="form-control mr-3 mb-2" style="min-width: 280px;" onchange="this.form.submit()">
                            <?php foreach($rounds as $r): ?>
                                <option value="<?php echo $r['id']; ?>" <?php echo $r['id'] == $active_round_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($r['title']); ?> 
                                    (<?php echo $r['status'] == 'open' ? 'กำลังเปิด' : 'ปิดแล้ว'; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <label class="mr-3 text-dark ml-3"><i class="fas fa-briefcase mr-1"></i> ตำแหน่ง:</label>
                        <select name="job_id" class="form-control mr-3 mb-2" style="min-width: 250px;" onchange="this.form.submit()">
                            <option value="0">-- ทุกตำแหน่ง --</option>
                            <?php foreach($job_filter_list as $jf): ?>
                                <option value="<?php echo $jf['id']; ?>" <?php echo $jf['id'] == $selected_job_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($jf['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <select name="staff_type" class="form-control mr-3 mb-2" style="min-width: 180px;" onchange="this.form.submit()">
                            <option value="staff"<?php echo $selected_staff_type == 'staff' ? ' selected' : ''; ?>>เจ้าหน้าที่งาน</option>
                            <option value="teacher"<?php echo $selected_staff_type == 'teacher' ? ' selected' : ''; ?>>ครูพิเศษสอน</option>
                            <option value="gov_teacher"<?php echo $selected_staff_type == 'gov_teacher' ? ' selected' : ''; ?>>พนักงานราชการสายการสอน</option>
                            <option value="general"<?php echo $selected_staff_type == 'general' ? ' selected' : ''; ?>>บุคลากรทั่วไป</option>
                        </select>
                        
                        <button type="button" class="btn btn-info mb-2 ml-2" onclick="window.print()">
                            <i class="fas fa-print"></i> พิมพ์รายงาน
                        </button>
                        <button type="button" class="btn btn-warning mb-2 ml-2 text-dark font-weight-bold" onclick="toggleFullScreen()">
                            <i class="fas fa-expand"></i> นำเสนอ (เต็มจอ)
                        </button>
                    </form>
                </div>
            </div>

            <?php if($active_round_id > 0 && $active_round): ?>
            <div class="card shadow-sm" id="presentationCard">
                <div class="card-body p-3" id="presentationBody">
                    
                    <!-- Print Header (visible only in print) -->
                    <div class="print-header">
                        <?php 
                            $staff_type_title = '';
                            if ($selected_staff_type == 'staff') $staff_type_title = 'เจ้าหน้าที่งาน';
                            elseif ($selected_staff_type == 'teacher') $staff_type_title = 'ครูพิเศษสอน';
                            elseif ($selected_staff_type == 'gov_teacher') $staff_type_title = 'พนักงานราชการสายการสอน';
                            elseif ($selected_staff_type == 'general') $staff_type_title = 'บุคลากรทั่วไป';
                        ?>
                        <div class="mb-3 text-center">
                            <img src="<?php echo getSystemLogo(); ?>" alt="Logo" style="height: 120px; object-fit: contain;">
                        </div>
                        <h4 style="font-weight:bold; margin-bottom: 5px;">สรุปผลการประเมินผลการปฏิบัติงาน<?php echo $staff_type_title ? ' ตำแหน่ง ' . $staff_type_title : ''; ?></h4>
                        <?php if ($selected_job_id > 0): ?>
                            <h5>(ตำแหน่ง<?php echo htmlspecialchars($selected_job_title); ?>)</h5>
                        <?php endif; ?>
                        <h5><?php echo htmlspecialchars($active_round['title']); ?></h5>
                        <?php if ($active_round['start_date'] && $active_round['end_date']): 
                            // Convert to Thai date
                            $months_th = ['', 'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน', 'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'];
                            $sd = strtotime($active_round['start_date']);
                            $ed = strtotime($active_round['end_date']);
                            $start_str = (int)date('j', $sd) . ' ' . $months_th[(int)date('n', $sd)] . ' ' . ((int)date('Y', $sd) + 543);
                            $end_str = (int)date('j', $ed) . ' ' . $months_th[(int)date('n', $ed)] . ' ' . ((int)date('Y', $ed) + 543);
                        ?>
                            <h6 style="color:#666;">(<?php echo $start_str; ?> - <?php echo $end_str; ?>)</h6>
                        <?php endif; ?>
                    </div>

                    <!-- Screen Header -->
                    <div class="screen-header no-print text-center">
                        <div class="mb-3">
                            <img src="<?php echo getSystemLogo(); ?>" alt="Logo" style="height: 120px; object-fit: contain;">
                        </div>
                        <h4 style="font-weight:bold; color: #1a5276;">สรุปผลการประเมินผลการปฏิบัติงาน<?php echo $staff_type_title ? ' ตำแหน่ง ' . $staff_type_title : ''; ?></h4>
                        <?php if ($selected_job_id > 0): ?>
                            <h5 class="text-info">(ตำแหน่ง<?php echo htmlspecialchars($selected_job_title); ?>)</h5>
                        <?php endif; ?>
                        <h5 class="text-muted"><?php echo htmlspecialchars($active_round['title']); ?></h5>
                        <?php if (isset($start_str) && isset($end_str)): ?>
                            <h6 class="text-muted">(<?php echo $start_str; ?> - <?php echo $end_str; ?>)</h6>
                        <?php endif; ?>
                    </div>

                    <?php if (count($report_rows) > 0): ?>
                    <div class="table-responsive">
                        <table class="report-table">
                            <thead>
                                <?php if ($selected_staff_type == 'gov_teacher'): ?>
                                    <tr>
                                        <th rowspan="3" style="width:40px;">ลำดับ<br>ที่</th>
                                        <th rowspan="3" style="min-width:140px;">รายชื่อผู้รับการประเมิน</th>
                                        <?php foreach ($main_categories as $mc): ?>
                                            <th colspan="<?php echo count($all_unique_evaluators) + 2; ?>" class="criteria-header" style="font-size:12px;"><?php echo htmlspecialchars($mc['title']); ?></th>
                                        <?php endforeach; ?>
                                        <th rowspan="3" style="width:70px;">รวม<br>(100)</th>
                                        <th rowspan="3" style="width:80px;">ระดับ<br>ผลการ<br>ประเมิน</th>
                                        <?php if($_SESSION['role'] === 'admin'): ?><th rowspan="3" class="no-print" style="width:80px;">จัดการ</th><?php endif; ?>
                                    </tr>
                                    <tr>
                                        <?php foreach ($main_categories as $mc): ?>
                                            <?php foreach ($all_unique_evaluators as $e_id => $e_name): ?>
                                                <th rowspan="2" class="vertical-header"><span><?php echo htmlspecialchars($e_name); ?></span></th>
                                            <?php endforeach; ?>
                                            <th style="width:60px;">เฉลี่ยเต็ม<br>100</th>
                                            <th style="width:60px;">ร้อยละ<br><?php echo stripos($mc['title'], 'ผลสัมฤทธิ์') !== false ? '80' : '20'; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                    <tr>
                                        <?php foreach ($main_categories as $mc): ?>
                                            <th>100</th>
                                            <th><?php echo stripos($mc['title'], 'ผลสัมฤทธิ์') !== false ? '80' : '20'; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php else: ?>
                                    <!-- Row 1: Column headers with rotated criteria names -->
                                    <tr>
                                        <th rowspan="2" style="width:40px;">ลำดับ<br>ที่</th>
                                        <th rowspan="2" style="min-width:140px;">รายชื่อผู้รับการประเมิน</th>
                                        <th rowspan="2" style="min-width:100px;">ตำแหน่ง</th>
                                        <?php foreach ($main_categories as $mc): ?>
                                            <th class="criteria-header"><?php echo htmlspecialchars($mc['title']); ?></th>
                                        <?php endforeach; ?>
                                        <th rowspan="2" style="width:70px;">รวม<br>(<?php echo number_format($max_raw_score, 0); ?>)</th>
                                        <th rowspan="2" style="width:65px;">ร้อยละ</th>
                                        <th rowspan="2" style="width:80px;">ระดับ<br>ผลการ<br>ประเมิน</th>
                                        <?php if($_SESSION['role'] === 'admin'): ?><th rowspan="2" class="no-print" style="width:80px;">จัดการ</th><?php endif; ?>
                                    </tr>
                                    <!-- Row 2: Max scores for each criteria column -->
                                    <tr class="max-score-row">
                                        <?php foreach ($main_categories as $mc): ?>
                                            <th><?php echo number_format($mc['calculated_max'], 0); ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endif; ?>
                            </thead>
                            <tbody>
                                <?php foreach ($report_rows as $i => $row): ?>
                                <tr>
                                    <td><?php echo $i + 1; ?></td>
                                    <td class="name-cell">
                                        <?php echo htmlspecialchars($row['prefix'].$row['firstname'].' '.$row['lastname']); ?>
                                        <?php if ($row['is_published']): ?>
                                            <span class="badge badge-success ml-1 screen-check" title="เผยแพร่แล้ว"><i class="fas fa-check"></i></span>
                                        <?php endif; ?>
                                    </td>
                                    
                                    <?php if ($selected_staff_type == 'gov_teacher'): ?>
                                        <?php foreach ($main_categories as $mc_id => $mc): ?>
                                            <?php foreach ($all_unique_evaluators as $e_id => $e_name): ?>
                                                <td class="text-secondary"><?php echo isset($row['evaluator_cat_scores'][$mc_id][$e_id]) ? number_format($row['evaluator_cat_scores'][$mc_id][$e_id], 2) : '-'; ?></td>
                                            <?php endforeach; ?>
                                            <td style="background:#f0f8ff;"><?php echo number_format($row['raw_averages'][$mc_id] ?? 0, 2); ?></td>
                                            <td style="background:#e8f4fd; font-weight:bold;"><?php echo number_format($row['category_scores'][$mc_id] ?? 0, 2); ?></td>
                                        <?php endforeach; ?>
                                        <td style="font-weight:bold; background:#fff3e0;"><?php echo number_format($row['total_raw'], 2); ?></td>
                                        <td class="<?php echo $row['grade_class']; ?>"><?php echo $row['grade']; ?></td>
                                        <?php if($_SESSION['role'] === 'admin'): ?>
                                        <td class="no-print">
                                            <button class="btn btn-sm btn-outline-primary" onclick="openAdjustModal('<?php echo $row['instance_id']; ?>', '<?php echo htmlspecialchars(addslashes($row['firstname'].' '.$row['lastname'])); ?>', <?php echo empty($row['admin_category_adjustments']) ? 'null' : htmlspecialchars($row['admin_category_adjustments']); ?>, '<?php echo htmlspecialchars(addslashes((string)$row['admin_adjustment_reason'])); ?>', <?php echo $row['is_published'] ? 1 : 0; ?>, <?php echo htmlspecialchars(json_encode($row['category_scores_original'])); ?>)"><i class="fas fa-edit"></i> จัดการ</button>
                                        </td>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <td class="position-cell"><?php echo $row['job_title']; ?></td>
                                        <?php foreach ($main_categories as $mc_id => $mc): ?>
                                            <td><?php echo number_format($row['category_scores'][$mc_id] ?? 0, 2); ?></td>
                                        <?php endforeach; ?>
                                        <td style="font-weight:bold;"><?php echo number_format($row['total_raw'], 2); ?></td>
                                        <td style="font-weight:bold;"><?php echo number_format($row['percentage'], 2); ?></td>
                                        <td class="<?php echo $row['grade_class']; ?>"><?php echo $row['grade']; ?></td>
                                        <?php if($_SESSION['role'] === 'admin'): ?>
                                        <td class="no-print">
                                            <button class="btn btn-sm btn-outline-primary" onclick="openAdjustModal('<?php echo $row['instance_id']; ?>', '<?php echo htmlspecialchars(addslashes($row['firstname'].' '.$row['lastname'])); ?>', <?php echo empty($row['admin_category_adjustments']) ? 'null' : htmlspecialchars($row['admin_category_adjustments']); ?>, '<?php echo htmlspecialchars(addslashes((string)$row['admin_adjustment_reason'])); ?>', <?php echo $row['is_published'] ? 1 : 0; ?>, <?php echo htmlspecialchars(json_encode($row['category_scores_original'])); ?>)"><i class="fas fa-edit"></i> จัดการ</button>
                                        </td>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-3 no-print">
                        <small class="text-muted">
                            <i class="fas fa-info-circle"></i> แสดงเฉพาะผู้ที่ได้รับการประเมินเสร็จสิ้นแล้วเท่านั้น
                            | จำนวน <?php echo count($report_rows); ?> คน
                            | คะแนนเป็นค่าเฉลี่ยจากผู้ประเมินทั้งหมด
                        </small>
                    </div>

                    <!-- Grade Legend -->
                    <div class="mt-3 p-3 bg-light rounded border no-print">
                        <strong><i class="fas fa-award text-warning"></i> เกณฑ์ระดับผลการประเมิน:</strong>
                        <div class="row mt-2">
                            <div class="col-auto"><span class="badge badge-success px-3 py-2">≥ 90% = ดีเยี่ยม</span></div>
                            <div class="col-auto"><span class="badge badge-primary px-3 py-2">≥ 80% = ดีมาก</span></div>
                            <div class="col-auto"><span class="badge badge-info px-3 py-2">≥ 70% = ดี</span></div>
                            <div class="col-auto"><span class="badge badge-warning px-3 py-2">≥ 60% = พอใช้</span></div>
                            <div class="col-auto"><span class="badge badge-danger px-3 py-2">< 60% = ต้องปรับปรุง</span></div>
                        </div>
                    </div>

                    <?php if (!empty($committee_members)): ?>
                    <!-- Committee Section (Print) -->
                    <div class="print-committee">
                        <h5 style="text-align:center; font-weight:bold; margin-bottom:10px;">กรรมการกลั่นกรอง</h5>
                        <div class="committee-grid">
                            <?php foreach ($committee_members as $cm): ?>
                            <div class="committee-item">
                                <div class="sign-line">(<?php echo htmlspecialchars($cm['name']); ?>)</div>
                                <?php if (!empty($cm['position'])): ?>
                                    <div class="sign-position"><?php echo htmlspecialchars($cm['position']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Committee Section (Screen) -->
                    <div class="screen-committee no-print" id="screenCommitteeSection">
                        <h5 class="text-center font-weight-bold text-dark"><i class="fas fa-users text-warning mr-1"></i> กรรมการกลั่นกรอง</h5>
                        <div class="row mt-3 justify-content-center">
                            <?php foreach ($committee_members as $cm): ?>
                            <div class="col-md-3 col-sm-6 mb-3">
                                <div class="committee-card">
                                    <div class="sign-line">(<?php echo htmlspecialchars($cm['name']); ?>)</div>
                                    <?php if (!empty($cm['position'])): ?>
                                        <div class="sign-position"><?php echo htmlspecialchars($cm['position']); ?></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php else: ?>
                    <div class="alert alert-warning text-center mt-3">
                        <i class="fas fa-exclamation-triangle fa-2x mb-2 d-block"></i>
                        <h5>ยังไม่มีข้อมูลการประเมินที่เสร็จสิ้น</h5>
                        <p>ไม่พบรายการประเมินที่มีสถานะ "เสร็จสิ้น" ในรอบประเมินนี้<?php echo $selected_job_id > 0 ? ' สำหรับตำแหน่งที่เลือก' : ''; ?></p>
                    </div>
                    <?php endif; ?>
                </div>
                <!-- controls -->
                <div id="fullscreenControls" style="display:none; position:fixed; bottom:30px; right:30px; z-index:9999;">
                    <button class="btn btn-primary btn-lg rounded-circle shadow mr-2" style="width:60px; height:60px; font-size:24px;" onclick="zoomIn()" title="ขยาย"><i class="fas fa-search-plus"></i></button>
                    <button class="btn btn-secondary btn-lg rounded-circle shadow" style="width:60px; height:60px; font-size:24px;" onclick="zoomOut()" title="ย่อ"><i class="fas fa-search-minus"></i></button>
                    <button class="btn btn-danger btn-lg rounded-circle shadow ml-2" style="width:60px; height:60px; font-size:24px;" onclick="document.exitFullscreen()" title="ปิดเต็มจอ"><i class="fas fa-times"></i></button>
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

<!-- Adjust Modal -->
<div class="modal fade" id="adjustModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form method="POST" action="report_summary.php?<?php echo $_SERVER['QUERY_STRING'] ?? ''; ?>">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-sliders-h"></i> จัดการผลการประเมิน: <span id="adjName"></span></h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="save_adjustments">
                    <input type="hidden" name="instance_id" id="adjInstanceId">
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> คุณสามารถกรอกคะแนนสุทธิใหม่ลงในช่องว่าง เพื่อแทนที่คะแนนเดิมจากระบบ (หากหมวดใดไม่ต้องการแก้ไข ให้เว้นช่องนั้นว่างไว้)
                    </div>
                    
                    <div class="table-responsive mb-3">
                        <table class="table table-bordered table-sm">
                            <thead class="bg-light">
                                <tr>
                                    <th>หมวดหมู่การประเมิน</th>
                                    <th style="width:100px;">คะแนนเต็ม</th>
                                    <th style="width:150px;">แก้ไขเป็นคะแนน (กรอกใหม่)</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($main_categories as $mc_id => $mc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($mc['title']); ?></td>
                                    <td class="text-center"><?php echo number_format($mc['calculated_max'], 2); ?></td>
                                    <td>
                                        <input type="number" step="0.01" name="adj[<?php echo $mc_id; ?>]" id="adj_mc_<?php echo $mc_id; ?>" class="form-control form-control-sm text-center" placeholder="0.00">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="form-group">
                        <label>ข้อคิดเห็น / เหตุผลในการปรับคะแนน จากคณะกรรมการ (ถ้ามี)</label>
                        <textarea name="admin_adjustment_reason" id="adjReason" rows="2" class="form-control" placeholder="คณะกรรมการพิจารณาแล้วเห็นสมควร..."></textarea>
                    </div>
                    
                    <div class="form-check mt-3 mb-2 p-3 border rounded bg-light">
                        <input type="checkbox" class="form-check-input ml-1" id="adjIsPublished" name="is_published" value="1">
                        <label class="form-check-label ml-4 font-weight-bold text-success" for="adjIsPublished">
                            ล็อกผลและเผยแพร่ (Publish) ให้ผู้รับการประเมินสามารถตรวจสอบคะแนนตัวเองได้
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
var currentZoom = 1;

function applyZoom() {
    var body = document.getElementById('presentationBody');
    body.style.zoom = currentZoom;
    body.style.MozTransform = 'scale(' + currentZoom + ')';
    body.style.MozTransformOrigin = 'top center';
}

function openAdjustModal(instanceId, name, adjJson, reason, isPublished, origScores) {
    document.getElementById('adjInstanceId').value = instanceId;
    document.getElementById('adjName').textContent = name;
    document.getElementById('adjReason').value = reason;
    document.getElementById('adjIsPublished').checked = (isPublished == 1);
    
    // reset all inputs
    const inputs = document.querySelectorAll('input[id^="adj_mc_"]');
    inputs.forEach(i => {
        i.value = '';
        i.placeholder = '0.00';
    });
    
    // set placeholders from original scores
    if (origScores) {
        if (typeof origScores === 'string') {
            try { origScores = JSON.parse(origScores); } catch(e) { origScores = {}; }
        }
        for (const [mcId, val] of Object.entries(origScores)) {
            const el = document.getElementById('adj_mc_' + mcId);
            if (el) el.placeholder = parseFloat(val).toFixed(2);
        }
    }
    
    // fill json
    if (adjJson) {
        if (typeof adjJson === 'string') {
            try { adjJson = JSON.parse(adjJson); } catch(e) { adjJson = {}; }
        }
        for (const [mcId, val] of Object.entries(adjJson)) {
            const el = document.getElementById('adj_mc_' + mcId);
            if (el) el.value = val;
        }
    }
    
    $('#adjustModal').modal('show');
}

function zoomIn() {
    currentZoom += 0.1;
    applyZoom();
}

function zoomOut() {
    currentZoom -= 0.1;
    if (currentZoom < 0.5) currentZoom = 0.5;
    applyZoom();
}

// Presentation Full Screen Mode
function toggleFullScreen() {
    var elem = document.getElementById("presentationCard");
    var bodyElem = document.getElementById("presentationBody");
    var committeeElem = document.getElementById("screenCommitteeSection");
    var controlsElem = document.getElementById("fullscreenControls");
    
    if (!elem) return;

    if (!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        // Enter fullscreen
        if (elem.requestFullscreen) {
            elem.requestFullscreen();
        } else if (elem.msRequestFullscreen) {
            elem.msRequestFullscreen();
        } else if (elem.mozRequestFullScreen) {
            elem.mozRequestFullScreen();
        } else if (elem.webkitRequestFullscreen) {
            elem.webkitRequestFullscreen(Element.ALLOW_KEYBOARD_INPUT);
        }
        
        // Add styling suitable for presentation
        elem.style.backgroundColor = '#ffffff';
        bodyElem.style.height = '100vh';
        bodyElem.style.overflowY = 'auto';
        bodyElem.style.padding = '40px 30px';
        
        if (committeeElem) committeeElem.style.display = 'none';
        if (controlsElem) controlsElem.style.display = 'block';
        
        currentZoom = 1.3; // Default zoomed slightly
        applyZoom();
    } else {
        // Exit fullscreen
        if (document.exitFullscreen) {
            document.exitFullscreen();
        } else if (document.msExitFullscreen) {
            document.msExitFullscreen();
        } else if (document.mozCancelFullScreen) {
            document.mozCancelFullScreen();
        } else if (document.webkitExitFullscreen) {
            document.webkitExitFullscreen();
        }
    }
}

// Reset styles when exiting fullscreen via Escape key
document.addEventListener("fullscreenchange", resetPresentationStyles);
document.addEventListener("webkitfullscreenchange", resetPresentationStyles);
document.addEventListener("mozfullscreenchange", resetPresentationStyles);
document.addEventListener("MSFullscreenChange", resetPresentationStyles);

function resetPresentationStyles() {
    if (!document.fullscreenElement && !document.mozFullScreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
        var elem = document.getElementById("presentationCard");
        var bodyElem = document.getElementById("presentationBody");
        var committeeElem = document.getElementById("screenCommitteeSection");
        var controlsElem = document.getElementById("fullscreenControls");
        
        if(elem && bodyElem) {
            elem.style.backgroundColor = '';
            bodyElem.style.height = '';
            bodyElem.style.overflowY = '';
            bodyElem.style.padding = '';
            bodyElem.style.zoom = '';
            bodyElem.style.MozTransform = '';
        }
        
        if (committeeElem) committeeElem.style.display = '';
        if (controlsElem) controlsElem.style.display = 'none';
        currentZoom = 1;
    }
}
</script>
