<?php
require_once dirname(__FILE__) . '/../config.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

// Fetch current user
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header("Location: " . BASE_URL . "/logout.php");
    exit();
}

// If they have satisfied the profile requirements, redirect to dashboard
$needs_profile = false;
if ($_SESSION['role'] !== 'admin') {
    if (empty($user['department_id']) || empty($user['staff_type'])) {
        $needs_profile = true;
    } else if (!in_array($user['staff_type'], ['teacher', 'gov_teacher'])) {
        $job_chk = $pdo->prepare("SELECT COUNT(*) FROM user_jobs WHERE user_id = ?");
        $job_chk->execute([$user['id']]);
        if ($job_chk->fetchColumn() == 0) {
            $needs_profile = true;
        }
    }
}
if (!$needs_profile) {
    if ($_SESSION['role'] === 'admin') header("Location: " . BASE_URL . "/admin/index.php");
    elseif ($_SESSION['role'] === 'evaluator') header("Location: " . BASE_URL . "/evaluator/index.php");
    else header("Location: " . BASE_URL . "/staff/index.php");
    exit();
}

// Handle Form Submission
$error = '';
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_profile') {
    $prefix = trim($_POST['prefix']);
    $firstname = trim($_POST['firstname']);
    $lastname = trim($_POST['lastname']);
    $staff_type = $_POST['staff_type'];
    $department_id = $_POST['department_id'];
    $job_ids = isset($_POST['job_ids']) ? array_filter($_POST['job_ids']) : [];

    if (empty($firstname) || empty($lastname) || empty($staff_type) || empty($department_id)) {
        $error = 'กรุณากรอกข้อมูลให้ครบถ้วนทุกช่อง โดยเฉพาะประเภทบุคลากร และฝ่าย/แผนก';
    } elseif (!in_array($staff_type, ['teacher', 'gov_teacher']) && empty($job_ids)) {
        $error = 'กรุณาเลือกตำแหน่งงานอย่างน้อย 1 ตำแหน่ง';
    } else {
        try {
            $pdo->beginTransaction();
            // Update users table
            $upd = $pdo->prepare("UPDATE users SET prefix = ?, firstname = ?, lastname = ?, staff_type = ?, department_id = ? WHERE id = ?");
            $upd->execute([$prefix, $firstname, $lastname, $staff_type, $department_id, $user['id']]);

            // Insert jobs (for all staff types now, including teachers)
            $pdo->prepare("DELETE FROM user_jobs WHERE user_id = ?")->execute([$user['id']]);
            if (!empty($job_ids)) {
                $stmt_job = $pdo->prepare("INSERT INTO user_jobs (user_id, job_id) VALUES (?, ?)");
                foreach ($job_ids as $jid) {
                    $stmt_job->execute([$user['id'], $jid]);
                }
            }

            $pdo->commit();

            // Update session
            $_SESSION['prefix'] = $prefix;
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;

            // Redirect based on role
            if ($_SESSION['role'] === 'admin') header("Location: " . BASE_URL . "/admin/index.php");
            elseif ($_SESSION['role'] === 'evaluator') header("Location: " . BASE_URL . "/evaluator/index.php");
            else header("Location: " . BASE_URL . "/staff/index.php");
            exit();

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'เกิดข้อผิดพลาดในการบันทึกข้อมูล: ' . $e->getMessage();
        }
    }
}

// Fetch departments & jobs for forms
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$jobs = $pdo->query("SELECT * FROM jobs ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$jobs_json = json_encode($jobs);

// Fetch current jobs if any (e.g. from admin assignment, or from failed POST)
$current_jobs = [];
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['job_ids'])) {
    $current_jobs = array_filter($_POST['job_ids']);
} else {
    $uj_stmt = $pdo->prepare("SELECT job_id FROM user_jobs WHERE user_id = ?");
    $uj_stmt->execute([$user['id']]);
    $current_jobs = $uj_stmt->fetchAll(PDO::FETCH_COLUMN);
}
$job1_id = isset($current_jobs[0]) ? $current_jobs[0] : '';
$job2_id = isset($current_jobs[1]) ? $current_jobs[1] : '';
$job3_id = isset($current_jobs[2]) ? $current_jobs[2] : '';

// Do not include global header logic from includes/header.php to avoid infinite redirect
?>
<?php $system_theme = getSystemTheme(); ?>
<!DOCTYPE html>
<html lang="th" <?php echo $system_theme !== 'default' ? 'data-theme="'.$system_theme.'"' : ''; ?>>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ตั้งค่าโปรไฟล์เริ่มต้น (First-Time Setup)</title>
    <!-- Google Font: Sarabun & Kanit -->
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@300;400;500;600;700&family=Kanit:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <!-- Theme style -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <!-- SweetAlert2 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>/assets/css/index.css">
    <style>
        body { background-color: #f4f6f9; }
        .setup-container { max-width: 800px; margin: 40px auto; padding: 20px; }
        .req { color: red; }
    </style>
</head>
<body class="hold-transition">
<div class="setup-container">
    <div class="text-center mb-4">
        <img src="<?php echo getSystemLogo(); ?>" alt="Logo" style="width: 100px; height: 100px; object-fit: contain; margin-bottom: 15px; filter: drop-shadow(0px 2px 4px rgba(0,0,0,0.2));"><br>
        <h2 class="font-weight-bold" style="font-family: 'Kanit';">ยินดีต้อนรับสู่ระบบประเมินบุคลากร</h2>
        <p class="text-muted">กรุณากรอกข้อมูลโปรไฟล์ของท่านให้ครบถ้วนก่อนเริ่มต้นใช้งานระบบ</p>
    </div>

    <div class="card card-outline card-primary shadow-lg border-0" style="border-radius: 12px;">
        <div class="card-header bg-white">
            <h4 class="card-title text-primary font-weight-bold"><i class="fas fa-user-edit mr-2"></i> ข้อมูลส่วนตัวและตำแหน่งงาน</h4>
        </div>
        <form action="" method="POST">
            <input type="hidden" name="action" value="update_profile">
            <div class="card-body">
                <?php if ($error): ?>
                <div class="alert alert-danger"><i class="fas fa-exclamation-circle mr-1"></i> <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <div class="row">
                    <div class="col-md-3 form-group">
                        <label>คำนำหน้า <small class="text-muted">(ไม่บังคับ)</small></label>
                        <input type="text" name="prefix" class="form-control" value="<?php echo htmlspecialchars($user['prefix'] ?? ''); ?>" placeholder="นาย, นาง, นางสาว">
                    </div>
                    <div class="col-md-4 form-group">
                        <label>ชื่อ <span class="req">*</span></label>
                        <input type="text" name="firstname" class="form-control" value="<?php echo htmlspecialchars($user['firstname']); ?>" required>
                    </div>
                    <div class="col-md-5 form-group">
                        <label>นามสกุล <span class="req">*</span></label>
                        <input type="text" name="lastname" class="form-control" value="<?php echo htmlspecialchars($user['lastname']); ?>" required>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="col-md-6 form-group">
                        <label>ประเภทบุคลากร <span class="req">*</span></label>
                        <div class="p-3 border rounded bg-light">
                            <div class="custom-control custom-radio mb-2">
                                <input class="custom-control-input" type="radio" id="type_staff" name="staff_type" value="staff" required <?php echo ($user['staff_type'] == 'staff')?'checked':''; ?>>
                                <label for="type_staff" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                    <i class="fas fa-user-tie text-info mr-1"></i> <b>เจ้าหน้าที่งาน</b> (Support Staff)
                                </label>
                            </div>
                            <div class="custom-control custom-radio mb-2">
                                <input class="custom-control-input" type="radio" id="type_teacher" name="staff_type" value="teacher" required <?php echo ($user['staff_type'] == 'teacher')?'checked':''; ?>>
                                <label for="type_teacher" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                    <i class="fas fa-chalkboard-teacher text-success mr-1"></i> <b>ครูพิเศษสอน</b> (Contract Teacher)
                                </label>
                            </div>
                            <div class="custom-control custom-radio mb-2">
                                <input class="custom-control-input" type="radio" id="type_gov_teacher" name="staff_type" value="gov_teacher" required <?php echo ($user['staff_type'] == 'gov_teacher')?'checked':''; ?>>
                                <label for="type_gov_teacher" class="custom-control-label text-indigo" style="font-weight: bold; cursor: pointer;">
                                    <i class="fas fa-chalkboard-teacher mr-1"></i> พนักงานราชการสายการสอน
                                </label>
                            </div>
                            <div class="custom-control custom-radio">
                                <input class="custom-control-input" type="radio" id="type_general" name="staff_type" value="general" required <?php echo ($user['staff_type'] == 'general')?'checked':''; ?>>
                                <label for="type_general" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                    <i class="fas fa-user text-secondary mr-1"></i> <b>บุคลากรทั่วไป / ผู้ประเมิน</b> (General / Evaluator)
                                </label>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 form-group">
                        <label>ฝ่าย/แผนก (สังกัดหลัก) <span class="req">*</span></label>
                        <select name="department_id" id="main_dept" class="form-control mt-3" required>
                            <option value="">-- กรุณาเลือกสังกัด --</option>
                            <?php foreach($departments as $dept): ?>
                                <option value="<?php echo $dept['id']; ?>" data-name="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo ($user['department_id'] == $dept['id'])?'selected':''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div id="job_section">
                    <h6 class="font-weight-bold text-primary mt-4 mb-3 border-bottom pb-2">กำหนดตำแหน่งงานที่รับการประเมิน <span class="req">*</span></h6>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกฝ่าย/แผนก <span class="req">*</span></label>
                            <select id="job1_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกตำแหน่งงาน <span class="req">*</span></label>
                            <select name="job_ids[]" id="job1" class="form-control" required>
                                <option value="">-- กรุณาเลือก --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกฝ่าย/แผนก</label>
                            <select id="job2_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="job2" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกฝ่าย/แผนก</label>
                            <select id="job3_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="job3" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card-footer bg-white text-center py-4 text-right" style="border-radius: 0 0 12px 12px;">
                <button type="submit" class="btn btn-primary btn-lg px-5 shadow-sm" style="border-radius: 25px;"><i class="fas fa-check-circle mr-2"></i> บันทึกข้อมูลและเริ่มใช้งาน</button>
            </div>
        </form>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
$(document).ready(function() {
    var allJobs = <?php echo $jobs_json; ?>;
    var allDepts = <?php echo json_encode($departments); ?>;
    
    // User's current saved jobs
    var currentJob1 = '<?php echo $job1_id; ?>';
    var currentJob2 = '<?php echo $job2_id; ?>';
    var currentJob3 = '<?php echo $job3_id; ?>';

    function populateJobsSelect(targetSelect, deptId, selectedJobIdStr) {
        $(targetSelect).empty();
        $(targetSelect).append('<option value="">-- ไม่ระบุ / Please Select --</option>');
        var filteredJobs = allJobs;
        if(deptId && deptId !== '') {
            filteredJobs = allJobs.filter(function(job){
                return job.department_id == deptId;
            });
        }
        filteredJobs.forEach(function(job){
            var isSelected = (job.id == selectedJobIdStr) ? 'selected' : '';
            $(targetSelect).append('<option value="'+job.id+'" '+isSelected+'>'+job.title+'</option>');
        });
    }

    function filterDepartments(staffType) {
        var $mainDept = $('#main_dept');
        var currentVal = $mainDept.val();
        $mainDept.empty();
        $mainDept.append('<option value="">-- กรุณาเลือกสังกัด --</option>');

        allDepts.forEach(function(dept) {
            if (staffType === 'teacher' || staffType === 'gov_teacher') {
                // Show only departments with "แผนกวิชา" in their name
                if (dept.name.indexOf('แผนกวิชา') !== -1) {
                    $mainDept.append('<option value="'+dept.id+'">'+dept.name+'</option>');
                }
            } else {
                // Show all departments
                $mainDept.append('<option value="'+dept.id+'">'+dept.name+'</option>');
            }
        });

        // Restore previous value if still available
        if ($mainDept.find('option[value="'+currentVal+'"]').length > 0) {
            $mainDept.val(currentVal);
        }
    }

    function toggleFormByStaffType(staffType) {
        if (staffType === 'teacher' || staffType === 'gov_teacher') {
            // Teacher/Gov: show job positions (optional), filter departments
            $('#job_section').slideDown(300);
            $('#job1').prop('required', false);
            filterDepartments('teacher');
        } else {
            // Staff or General: show job positions (required), show all departments
            $('#job_section').slideDown(300);
            $('#job1').prop('required', true);
            filterDepartments('staff');
        }
    }

    // Listen for staff type radio change
    $('input[name="staff_type"]').change(function() {
        toggleFormByStaffType($(this).val());
    });

    // Initialize on page load
    var initialType = $('input[name="staff_type"]:checked').val();
    if (initialType) {
        toggleFormByStaffType(initialType);
    }

    $('#job1_dept').change(function(){ populateJobsSelect('#job1', $(this).val(), null); });
    $('#job2_dept').change(function(){ populateJobsSelect('#job2', $(this).val(), null); });
    $('#job3_dept').change(function(){ populateJobsSelect('#job3', $(this).val(), null); });

    // Initialize jobs if available
    function getJobDeptId(jobId) {
        if (!jobId) return '';
        for (var i=0; i<allJobs.length; i++) {
            if (allJobs[i].id == jobId) {
                return allJobs[i].department_id;
            }
        }
        return '';
    }

    var dept1 = getJobDeptId(currentJob1);
    var dept2 = getJobDeptId(currentJob2);
    var dept3 = getJobDeptId(currentJob3);
    
    if (dept1) $('#job1_dept').val(dept1);
    if (dept2) $('#job2_dept').val(dept2);
    if (dept3) $('#job3_dept').val(dept3);

    populateJobsSelect('#job1', dept1, currentJob1);
    populateJobsSelect('#job2', dept2, currentJob2);
    populateJobsSelect('#job3', dept3, currentJob3);
});
</script>
</body>
</html>

