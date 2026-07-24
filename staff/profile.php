<?php
require_once '../config.php';
// Allows any logged in user actually, but primarily situated in staff
if (!isset($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Update Profile';
$user_id = $_SESSION['user_id'];

// Handle Profile Update (Restricted to Image Only)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_image') {
    
    // Handle File Upload
    $profile_image = null;
    if (isset($_FILES['profile_image']) && $_FILES['profile_image']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_image']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (in_array($ext, $allowed)) {
            $new_filename = 'avatar_' . $user_id . '_' . time() . '.' . $ext;
            $destination = '../assets/img/' . $new_filename;
            if (move_uploaded_file($_FILES['profile_image']['tmp_name'], $destination)) {
                $profile_image = $new_filename;
                $_SESSION['profile_image'] = $profile_image; // Update session
            }
        } else {
            $_SESSION['error'] = "อัพโหลดได้เฉพาะไฟล์รูปภาพ (jpg, jpeg, png, gif)";
        }
    }

    if ($profile_image) {
        try {
            $stmt = $pdo->prepare("UPDATE users SET profile_image=? WHERE id=?");
            $stmt->execute([$profile_image, $user_id]);
            $_SESSION['success'] = "บันทึกรูปโปรไฟล์สำเร็จ";
        } catch(Exception $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    } else {
        if (!isset($_SESSION['error'])) {
            $_SESSION['error'] = "กรุณาเลือกไฟล์รูปภาพก่อนกดบันทึก";
        }
    }
    
    // Refresh page to show new data
    header("Location: profile.php");
    exit();
}

$stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$stmt->execute([$user_id]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch departments
$departments = $pdo->query("SELECT id, name FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch jobs
$jobs = $pdo->query("SELECT id, department_id, title FROM jobs ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
$jobs_json = json_encode($jobs, JSON_UNESCAPED_UNICODE);

// Fetch user's current jobs
$stmt_uj = $pdo->prepare("SELECT job_id FROM user_jobs WHERE user_id = ? ORDER BY id ASC");
$stmt_uj->execute([$user_id]);
$user_jobs = $stmt_uj->fetchAll(PDO::FETCH_COLUMN);

$job1_id = isset($user_jobs[0]) ? $user_jobs[0] : '';
$job2_id = isset($user_jobs[1]) ? $user_jobs[1] : '';
$job3_id = isset($user_jobs[2]) ? $user_jobs[2] : '';

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-user-edit text-primary mr-2"></i> จัดการข้อมูลส่วนตัว (Profile)</h1>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-4">
                    <!-- Profile Image Card -->
                    <div class="card card-primary card-outline shadow">
                        <div class="card-body box-profile">
                            <div class="text-center mb-3">
                                <?php if($user['profile_image']): ?>
                                    <img class="profile-user-img img-fluid img-circle shadow" src="<?php echo BASE_URL; ?>/assets/img/<?php echo htmlspecialchars($user['profile_image']); ?>" alt="User profile picture" style="height:120px; width:120px; object-fit:cover;">
                                <?php else: ?>
                                    <img class="profile-user-img img-fluid img-circle shadow" src="https://ui-avatars.com/api/?name=<?php echo urlencode($user['firstname']); ?>&background=random&color=fff&rounded=true" alt="User profile picture" style="height:120px; width:120px; object-fit:cover;">
                                <?php endif; ?>
                            </div>
                            <h3 class="profile-username text-center"><?php echo htmlspecialchars($user['prefix'] . $user['firstname'] . ' ' . $user['lastname']); ?></h3>
                            <p class="text-muted text-center"><?php echo htmlspecialchars($user['username']); ?></p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-8">
                    <!-- Update Form Card -->
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h3 class="card-title">ข้อมูลส่วนตัว</h3>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> หากต้องการปรับปรุงข้อมูลส่วนตัว ข้อมูลตำแหน่ง หรือรหัสผ่าน กรุณาแจ้งผู้ดูแลระบบเท่านั้น
                            </div>
                            <form action="profile.php" method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="update_image">
                                
                                <div class="row">
                                    <div class="col-md-6 form-group">
                                        <label>ชื่อตัว</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['firstname']); ?>" readonly>
                                    </div>
                                    <div class="col-md-6 form-group">
                                        <label>นามสกุล</label>
                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($user['lastname']); ?>" readonly>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>ประเภทบุคลากร</label>
                                    <div class="p-2 border rounded bg-light">
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input class="custom-control-input" type="radio" id="st_staff" required <?php echo ($user['staff_type'] == 'staff')?'checked':''; ?> disabled>
                                            <label for="st_staff" class="custom-control-label" style="font-weight: normal;">
                                                <i class="fas fa-user-tie text-info mr-1"></i> เจ้าหน้าที่งาน
                                            </label>
                                        </div>
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input class="custom-control-input" type="radio" id="st_teacher" required <?php echo ($user['staff_type'] == 'teacher')?'checked':''; ?> disabled>
                                            <label for="st_teacher" class="custom-control-label" style="font-weight: normal;">
                                                <i class="fas fa-chalkboard-teacher text-success mr-1"></i> ครูพิเศษสอน
                                            </label>
                                        </div>
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input class="custom-control-input" type="radio" id="st_gov_teacher" required <?php echo ($user['staff_type'] == 'gov_teacher')?'checked':''; ?> disabled>
                                            <label for="st_gov_teacher" class="custom-control-label text-indigo font-weight-bold">
                                                <i class="fas fa-chalkboard-teacher mr-1"></i> พนักงานราชการสายการสอน
                                            </label>
                                        </div>
                                        <div class="custom-control custom-radio custom-control-inline">
                                            <input class="custom-control-input" type="radio" id="st_general" required <?php echo ($user['staff_type'] == 'general')?'checked':''; ?> disabled>
                                            <label for="st_general" class="custom-control-label" style="font-weight: normal;">
                                                <i class="fas fa-user text-secondary mr-1"></i> บุคลากรทั่วไป (ไม่มีสายงาน)
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label>ฝ่าย/แผนก (สังกัดหลัก)</label>
                                    <select id="main_dept" class="form-control" disabled>
                                        <option value="">-- กรุณาเลือกสังกัด --</option>
                                        <?php foreach($departments as $dept): ?>
                                            <option value="<?php echo $dept['id']; ?>" data-name="<?php echo htmlspecialchars($dept['name']); ?>" <?php echo ($user['department_id'] == $dept['id'])?'selected':''; ?>><?php echo htmlspecialchars($dept['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                
                                <div id="job_section" class="border rounded p-3 bg-light mb-4">
                                    <h6 class="font-weight-bold text-primary mb-3 border-bottom pb-2">ตำแหน่งงานที่รับการประเมิน</h6>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 1 : เลือกฝ่าย/แผนก</label>
                                            <select id="job1_dept" class="form-control" disabled>
                                                <option value="">-- ทั้งหมด --</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 1 : ตำแหน่งงาน</label>
                                            <select id="job1" class="form-control" disabled>
                                                <option value="">-- กรุณาเลือก --</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 2 : เลือกฝ่าย/แผนก</label>
                                            <select id="job2_dept" class="form-control" disabled>
                                                <option value="">-- ทั้งหมด --</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 2 : ตำแหน่งงาน</label>
                                            <select id="job2" class="form-control" disabled>
                                                <option value="">-- ไม่ระบุ --</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 3 : เลือกฝ่าย/แผนก</label>
                                            <select id="job3_dept" class="form-control" disabled>
                                                <option value="">-- ทั้งหมด --</option>
                                                <?php foreach($departments as $dept): ?>
                                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-6 form-group">
                                            <label>ตำแหน่งที่ 3 : ตำแหน่งงาน</label>
                                            <select id="job3" class="form-control" disabled>
                                                <option value="">-- ไม่ระบุ --</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="form-group border-top pt-3">
                                    <label>เปลี่ยนรูปภาพโปรไฟล์ (jpg, png)</label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="profile_image" name="profile_image" accept="image/*">
                                        <label class="custom-file-label" for="profile_image">เลือกไฟล์รูปภาพ</label>
                                    </div>
                                </div>
                                
                                <button type="submit" class="btn btn-primary mt-3"><i class="fas fa-upload mr-1"></i> อัพโหลดรูปใหม่</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>
<script>
$(document).ready(function() {
    // Show file name in custom file input
    $('.custom-file-input').on('change',function(){
        var fileName = $(this).val().split('\\').pop();
        $(this).siblings('.custom-file-label').addClass("selected").html(fileName);
    });

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
            // Teacher/Gov: hide job positions, filter departments
            $('#job_section').slideUp(300);
            $('#job1').prop('required', false);
            filterDepartments('teacher');
        } else {
            // Staff or General: show job positions, show all departments
            $('#job_section').slideDown(300);
            $('#job1').prop('required', true);
            filterDepartments('staff');
        }
    }

    // Listen for staff type radio change
    $('input[name="staff_type"]').change(function() {
        toggleFormByStaffType($(this).val());
    });

    // Handle department filtering for jobs
    $('#job1_dept').change(function(){ populateJobsSelect('#job1', $(this).val(), null); });
    $('#job2_dept').change(function(){ populateJobsSelect('#job2', $(this).val(), null); });

    // Initialize on page load
    var initialType = $('input[name="staff_type"]:checked').val();
    if (initialType) {
        toggleFormByStaffType(initialType);
    }
    
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
