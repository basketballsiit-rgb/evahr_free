<?php
// config.php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

$host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'npcjob';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create database if it doesn't exist
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$db_name`");
    
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Utility function for base URL
function getBaseUrl() {
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
    $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
    $path = dirname($_SERVER['PHP_SELF']);
    // Normalize path to prevent double slashes, but keep it accurate
    if ($path === '/' || $path === '\\') {
        $path = '';
    } else {
        $path = str_replace('\\', '/', $path);
    }
    return $protocol . "://" . $host . "/npcjob_free";
}

define('BASE_URL', getBaseUrl());

// Keycloak OIDC Settings
define('OIDC_PROVIDER_URL', 'https://service.npc.ac.th/realms/NPC-SSO');
define('OIDC_CLIENT_ID', 'npcjob');
define('OIDC_CLIENT_SECRET', 'kfT23u9DYhaJETCjHmV1B3mTw1jyTcK2');

// Get system logo
function getSystemLogo() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_logo'");
        $stmt->execute();
        $logo = $stmt->fetchColumn();
        if ($logo && file_exists(__DIR__ . '/assets/img/' . $logo)) {
            return BASE_URL . '/assets/img/' . $logo;
        }
    } catch(PDOException $e) {
        // Table might not exist yet during initial setup, fail silently and return default
    }
    return BASE_URL . '/assets/img/logo.png'; // default fallback
}

// Get system name (School Name)
function getSystemName() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_name'");
        $stmt->execute();
        $name = $stmt->fetchColumn();
        if ($name) {
            return $name;
        }
    } catch(PDOException $e) {
        // Fail silently
    }
    return 'วิทยาลัยสารพัดช่างน่าน'; // default fallback
}

// Get system theme
function getSystemTheme() {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'system_theme'");
        $stmt->execute();
        $theme = $stmt->fetchColumn();
        if ($theme) {
            return $theme;
        }
    } catch(PDOException $e) {
        // Fail silently
    }
    return 'default'; // fallback
}

// Check if user is a Director (Level 1)
function isDirector($pdo, $user_id) {
    if (!$user_id) return false;
    
    // Check cached session
    if (isset($_SESSION['is_director'])) {
        return $_SESSION['is_director'];
    }
    
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM user_jobs uj JOIN jobs j ON uj.job_id = j.id WHERE uj.user_id = ? AND j.job_level = 1");
        $stmt->execute([$user_id]);
        $is_dir = $stmt->fetchColumn() > 0;
        $_SESSION['is_director'] = $is_dir;
        return $is_dir;
    } catch (Exception $e) {
        return false;
    }
}

// Function to calculate the maximum raw score possible based on current evaluation criteria
function getMaxRawScore($pdo, $staff_type = 'staff') {
    if ($staff_type) {
        $stmt = $pdo->prepare("SELECT id, parent_id, max_score FROM evaluation_criteria WHERE target_group = 'both' OR target_group = ?");
        $stmt->execute([$staff_type]);
    } else {
        $stmt = $pdo->query("SELECT id, parent_id, max_score FROM evaluation_criteria");
    }
    $all_criteria = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
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
    
    return array_sum(array_column($main_categories, 'calculated_max'));
}

// Check if user is eligible to evaluate
function isJobEvaluator($pdo, $user_id) {
    if (!$user_id) return false;
    $stmt = $pdo->prepare("
        SELECT j.job_level 
        FROM user_jobs uj 
        JOIN jobs j ON uj.job_id = j.id 
        WHERE uj.user_id = ?
    ");
    $stmt->execute([$user_id]);
    $levels = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    // Level 1, 2, 3 can evaluate. Level 4 (staff) cannot.
    foreach ($levels as $level) {
        if (in_array($level, [1, 2, 3, '1', '2', '3'], true)) {
            return true;
        }
    }
    return false;
}

// Function to dynamically sync evaluator assignments for all open rounds based on latest job/user data
function syncOpenRounds($pdo) {
    try {
        // Fix DB schema to allow multiple instances per user for different jobs
        try { $pdo->exec("ALTER TABLE evaluation_instances DROP INDEX uq_inst;"); } catch (Exception $e) {}
        try { $pdo->exec("ALTER TABLE evaluation_instances ADD UNIQUE KEY uq_inst_job (round_id, evaluatee_id, evaluated_job_id);"); } catch (Exception $e) {}
        
        // Ensure every user has an instance for EVERY job they hold in open rounds, and clean up obsolete ones
        $open_rounds = $pdo->query("SELECT id FROM evaluation_rounds WHERE status = 'open'")->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($open_rounds)) {
            // Fetch evaluation toggle for Head of Work
            $enable_head_eval = '1';
            try {
                $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enable_head_evaluation'");
                $cs->execute();
                $val = $cs->fetchColumn();
                if ($val !== false) $enable_head_eval = $val;
            } catch(Exception $e) {}

            $all_jobs_info = $pdo->query("SELECT id, job_level FROM jobs")->fetchAll(PDO::FETCH_ASSOC);
            $job_levels = [];
            foreach ($all_jobs_info as $jinfo) {
                $job_levels[$jinfo['id']] = $jinfo['job_level'];
            }

            // Fetch all current users and jobs for validation
            $users_query = $pdo->query("SELECT id, staff_type, department_id FROM users WHERE department_id IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
            $users_map = [];
            foreach ($users_query as $u) {
                $users_map[$u['id']] = $u;
            }
            
            $user_jobs = $pdo->query("SELECT user_id, job_id FROM user_jobs")->fetchAll(PDO::FETCH_ASSOC);
            $jobs_by_user = [];
            foreach ($user_jobs as $uj) {
                $jobs_by_user[$uj['user_id']][] = $uj['job_id'];
            }
            
            // 1. Cleanup obsolete instances in open rounds (e.g. user changed profile)
            $all_instances_open = $pdo->query("
                SELECT ei.id, ei.evaluatee_id, ei.evaluated_job_id
                FROM evaluation_instances ei
                JOIN evaluation_rounds r ON ei.round_id = r.id
                WHERE r.status = 'open'
            ")->fetchAll(PDO::FETCH_ASSOC);

            $delete_inst = $pdo->prepare("DELETE FROM evaluation_instances WHERE id = ?");
            foreach ($all_instances_open as $inst) {
                $uid = $inst['evaluatee_id'];
                $jid = $inst['evaluated_job_id'];
                
                $should_keep = false;
                if (isset($users_map[$uid])) {
                    $u_data = $users_map[$uid];
                    if (in_array($u_data['staff_type'], ['teacher', 'gov_teacher'])) {
                        if ($jid === null) $should_keep = true;
                    } else {
                        $my_jobs = $jobs_by_user[$uid] ?? [];
                        if ($jid !== null && in_array($jid, $my_jobs)) {
                            // Check if this job is Head of Work AND it is disabled
                            $jlvl = $job_levels[$jid] ?? 4;
                            if ($jlvl == 1 || $jlvl == 2) {
                                $should_keep = false;
                            } else if ($enable_head_eval == '0' && $jlvl == 3) {
                                $should_keep = false;
                            } else {
                                $should_keep = true;
                            }
                        }
                    }
                }
                
                if (!$should_keep) {
                    $delete_inst->execute([$inst['id']]);
                }
            }

            // 1.5 Cleanup duplicate instances caused by previous NULL job_id bugs
            $duplicate_check = $pdo->query("
                SELECT MIN(id) as keep_id, round_id, evaluatee_id, evaluated_job_id, COUNT(*) as cnt
                FROM evaluation_instances
                GROUP BY round_id, evaluatee_id, evaluated_job_id
                HAVING cnt > 1
            ")->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($duplicate_check as $dup) {
                $dr_id = $dup['round_id'];
                $dev_id = $dup['evaluatee_id'];
                $djob_id = $dup['evaluated_job_id'];
                $dkeep_id = $dup['keep_id'];
                
                if ($djob_id === null) {
                    $pdo->prepare("DELETE FROM evaluation_instances WHERE round_id = ? AND evaluatee_id = ? AND evaluated_job_id IS NULL AND id != ?")->execute([$dr_id, $dev_id, $dkeep_id]);
                } else {
                    $pdo->prepare("DELETE FROM evaluation_instances WHERE round_id = ? AND evaluatee_id = ? AND evaluated_job_id = ? AND id != ?")->execute([$dr_id, $dev_id, $djob_id, $dkeep_id]);
                }
            }

            // Get all existing instances to avoid manual duplicate insertion
            $existing_inst_query = $pdo->query("SELECT round_id, evaluatee_id, evaluated_job_id FROM evaluation_instances")->fetchAll(PDO::FETCH_ASSOC);
            $existing_inst_map = [];
            foreach ($existing_inst_query as $ex) {
                // Key format: "round_id-evaluatee_id-job_id_or_null"
                $key = $ex['round_id'] . '-' . $ex['evaluatee_id'] . '-' . ($ex['evaluated_job_id'] ?? 'null');
                $existing_inst_map[$key] = true;
            }

            // 2. Create missing instances
            $insert_inst = $pdo->prepare("INSERT INTO evaluation_instances (round_id, evaluatee_id, evaluated_job_id) VALUES (?, ?, ?)");
            foreach ($open_rounds as $rid) {
                foreach ($users_map as $uid => $u_data) {
                    if ($u_data['staff_type'] === 'teacher' || $u_data['staff_type'] === 'gov_teacher') {
                        $key = $rid . '-' . $uid . '-null';
                        if (!isset($existing_inst_map[$key])) {
                            $insert_inst->execute([$rid, $uid, null]);
                            $existing_inst_map[$key] = true;
                        }
                    } else {
                        $my_jobs = $jobs_by_user[$uid] ?? [];
                        foreach ($my_jobs as $my_jid) {
                            $jlvl = $job_levels[$my_jid] ?? 4;
                            if ($jlvl == 1 || $jlvl == 2) continue;
                            if ($enable_head_eval == '0' && $jlvl == 3) {
                                continue;
                            }
                            $key = $rid . '-' . $uid . '-' . $my_jid;
                            if (!isset($existing_inst_map[$key])) {
                                $insert_inst->execute([$rid, $uid, $my_jid]);
                                $existing_inst_map[$key] = true;
                            }
                        }
                    }
                }
            }
        }

        // Find all active instances in open rounds (including newly created ones)
        $instances = $pdo->query("
            SELECT ei.id as instance_id, ei.evaluatee_id, ei.evaluated_job_id, r.id as round_id
            FROM evaluation_instances ei
            JOIN evaluation_rounds r ON ei.round_id = r.id
            WHERE r.status = 'open'
        ")->fetchAll(PDO::FETCH_ASSOC);

        if (empty($instances)) return;

        // Fetch all jobs map
        $all_jobs_query = $pdo->query("SELECT id, department_id, job_level, title FROM jobs")->fetchAll(PDO::FETCH_ASSOC);
        $jobs_map = [];
        foreach ($all_jobs_query as $j) {
            $jobs_map[$j['id']] = $j;
        }

        // Find all users and their jobs
        $all_users_jobs = $pdo->query("SELECT user_id, job_id FROM user_jobs")->fetchAll(PDO::FETCH_ASSOC);
        
        // Find valid active user IDs
        $all_user_ids = $pdo->query("SELECT id FROM users")->fetchAll(PDO::FETCH_COLUMN);

        $insert_ev = $pdo->prepare("INSERT INTO evaluation_evaluator_status (evaluation_instance_id, evaluator_id, status) VALUES (?, ?, 'pending')");
        $delete_ev = $pdo->prepare("DELETE FROM evaluation_evaluator_status WHERE id = ?");

        // Fetch Central Teacher Evaluators once
        $central_evals_list = [];
        $central_gov_teacher_evals_list = [];
        try {
            $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_teacher_evaluators'");
            $cs->execute();
            $central_evals_json = $cs->fetchColumn();
            $central_evals_list = $central_evals_json ? json_decode($central_evals_json, true) : [];
            if (!is_array($central_evals_list)) $central_evals_list = [];

            $csg = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_gov_teacher_evaluators'");
            $csg->execute();
            $central_gov_teacher_evals_json = $csg->fetchColumn();
            $central_gov_teacher_evals_list = $central_gov_teacher_evals_json ? json_decode($central_gov_teacher_evals_json, true) : [];
            if (!is_array($central_gov_teacher_evals_list)) $central_gov_teacher_evals_list = [];
        } catch(Exception $e) {}

        foreach ($instances as $inst) {
            $instance_id = $inst['instance_id'];
            $staff_id = $inst['evaluatee_id'];
            $my_job_id = $inst['evaluated_job_id'];
            
            $valid_evaluators = [];

            if ($my_job_id === null) {
                // --- Teacher & Gov Teacher Logic ---
                $stmt_teacher = $pdo->prepare("SELECT department_id, staff_type FROM users WHERE id = ? AND staff_type IN ('teacher', 'gov_teacher')");
                $stmt_teacher->execute([$staff_id]);
                $teacher = $stmt_teacher->fetch(PDO::FETCH_ASSOC);
                
                if (!$teacher || !$teacher['department_id']) continue;
                $t_dept = $teacher['department_id'];
                
                // Find Head of Dept in same department
                if ($teacher['staff_type'] !== 'gov_teacher') {
                    foreach ($all_users_jobs as $uj) {
                        $uid = $uj['user_id'];
                        $jid = $uj['job_id'];
                        if ($uid === $staff_id) continue;
                        
                        // Prevent teachers/gov_teachers from being 'Head of Dept' evaluators even if they self-assign
                        $is_teaching_staff = false;
                        foreach ($all_users_jobs as $uj_check) {
                             if ($uj_check['user_id'] == $uid) {
                                  // Can check the user type if we had mapping, but it's simpler
                             }
                        }
                        
                        if (isset($jobs_map[$jid])) {
                            if ($jobs_map[$jid]['department_id'] == $t_dept && strpos($jobs_map[$jid]['title'], 'หัวหน้าแผนกวิชา') !== false) {
                                if (!in_array($uid, $valid_evaluators)) {
                                    $valid_evaluators[] = $uid;
                                }
                            }
                        }
                    }
                }
                
                // Add Central Evaluators based on type
                $target_central_evals = ($teacher['staff_type'] === 'gov_teacher') ? $central_gov_teacher_evals_list : $central_evals_list;
                foreach ($target_central_evals as $cid) {
                    if (!in_array($cid, $valid_evaluators) && $cid != $staff_id) {
                        $valid_evaluators[] = $cid;
                    }
                }
            } else {
                // --- Staff Logic ---
                if (!isset($jobs_map[$my_job_id])) continue;

            $my_dept = $jobs_map[$my_job_id]['department_id'];
            $my_level = $jobs_map[$my_job_id]['job_level'];
            
            // Build lookup: which L3 job titles does each user hold in the evaluated staff's department?
            // This helps detect when someone is a L3 head of a DIFFERENT work area
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
            
            // 1. Calculate the CORRECT valid evaluators list
            $valid_evaluators = [];
            
            $my_work_name = trim(str_replace(['เจ้าหน้าที่งาน', 'เจ้าหน้าที่'], '', $jobs_map[$my_job_id]['title']));
            
            foreach ($all_users_jobs as $potential_eval_uj) {
                $evaluator_user_id = $potential_eval_uj['user_id'];
                $evaluator_job_id = $potential_eval_uj['job_id'];
                
                // Don't evaluate yourself
                if ($evaluator_user_id === $staff_id) continue;
                
                if (isset($jobs_map[$evaluator_job_id])) {
                    $eval_dept = $jobs_map[$evaluator_job_id]['department_id'];
                    $eval_level = $jobs_map[$evaluator_job_id]['job_level'];
                    
                    if ( ($eval_level == 1 && $my_level == 2) || ($eval_dept == $my_dept && $eval_level > 1 && $eval_level < $my_level) ) {
                        
                        $is_valid_hierarchy = true;
                        
                        if ($my_level == 4) {
                            // Constraint for evaluating L4 staff:
                            // If evaluator holds ANY L3 job in the same department, 
                            // at least one of those L3 titles must match the evaluated work area.
                            // This prevents heads of unrelated work areas from evaluating through their L2 job.
                            if (isset($user_l3_titles_in_dept[$evaluator_user_id])) {
                                $has_matching_l3 = false;
                                foreach ($user_l3_titles_in_dept[$evaluator_user_id] as $l3_title) {
                                    $l3_work = trim(str_replace(['หัวหน้างาน', 'หัวหน้า'], '', $l3_title));
                                    if ($l3_work === $my_work_name) {
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

                        if ($is_valid_hierarchy && !in_array($evaluator_user_id, $valid_evaluators)) {
                            $valid_evaluators[] = $evaluator_user_id;
                        }
                    }
                }
            }
            } // End of Staff Logic

            // 2. Fetch the CURRENTLY ASSIGNED evaluators for this instance
            $get_assigned = $pdo->prepare("SELECT id, evaluator_id, status FROM evaluation_evaluator_status WHERE evaluation_instance_id = ?");
            $get_assigned->execute([$instance_id]);
            $assigned = $get_assigned->fetchAll(PDO::FETCH_ASSOC);

            $assigned_map = []; // [evaluator_id => [id, status]]
            foreach ($assigned as $a) {
                $assigned_map[$a['evaluator_id']] = $a;
            }

            // 3. Add MISSING valid evaluators
            $added_new = false;
            foreach ($valid_evaluators as $v_id) {
                if (in_array($v_id, $all_user_ids) && !isset($assigned_map[$v_id])) {
                    $insert_ev->execute([$instance_id, $v_id]);
                    $added_new = true;
                }
            }
            
            // 3b. If we added new evaluators to a completed instance, reopen it
            if ($added_new) {
                $reopen = $pdo->prepare("UPDATE evaluation_instances SET status = 'pending_evaluator' WHERE id = ? AND status = 'completed'");
                $reopen->execute([$instance_id]);
            }

            // 4. Remove INVALID evaluators (ONLY if they are still 'pending' - don't destroy historical completed scores)
            foreach ($assigned as $a) {
                $e_id = $a['evaluator_id'];
                if (!in_array($e_id, $valid_evaluators)) {
                    if ($a['status'] === 'pending') {
                        $delete_ev->execute([$a['id']]);
                    }
                }
            }
            
            // 5. Check if instance is now complete because pending evaluators were removed
            $check_pending = $pdo->prepare("SELECT COUNT(*) FROM evaluation_evaluator_status WHERE evaluation_instance_id = ? AND status = 'pending'");
            $check_pending->execute([$instance_id]);
            
            if ($check_pending->fetchColumn() == 0) {
                $check_completed = $pdo->prepare("SELECT COUNT(*) FROM evaluation_evaluator_status WHERE evaluation_instance_id = ? AND status = 'completed'");
                $check_completed->execute([$instance_id]);
                if ($check_completed->fetchColumn() > 0) {
                    $calc_avg = $pdo->prepare("SELECT AVG(total_score) FROM evaluation_evaluator_status WHERE evaluation_instance_id = ? AND status = 'completed'");
                    $calc_avg->execute([$instance_id]);
                    $avg = $calc_avg->fetchColumn();
                    
                    $update_inst = $pdo->prepare("UPDATE evaluation_instances SET status = 'completed', total_score_average = ? WHERE id = ?");
                    $update_inst->execute([$avg, $instance_id]);
                }
            }
        }
        
    } catch(PDOException $e) {
        error_log("syncOpenRounds failed: " . $e->getMessage());
    }
}
?>
