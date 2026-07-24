<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'User Management';

// Handle Form Actions
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];

    if ($action == 'add' || $action == 'edit') {
        $prefix = trim($_POST['prefix']);
        $firstname = trim($_POST['firstname']);
        $lastname = trim($_POST['lastname']);
        $username = trim($_POST['username']);
        $role = $_POST['role'];
        $staff_type = !empty($_POST['staff_type']) ? $_POST['staff_type'] : null;
        $department_id = !empty($_POST['department_id']) ? $_POST['department_id'] : null;
        $job_ids = isset($_POST['job_ids']) ? array_filter($_POST['job_ids']) : [];
        // Optional: keeping department_id for legacy or organizational purposes, but removing job_id from users insert if we want, or leaving it null.
        // We will leave users.job_id as null, and only use user_jobs.
        
        if ($action == 'add') {
            $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO users (prefix, firstname, lastname, username, password, role, staff_type, department_id, job_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)");
                $stmt->execute([$prefix, $firstname, $lastname, $username, $password, $role, $staff_type, $department_id]);
                $new_user_id = $pdo->lastInsertId();
                
                // Insert into user_jobs
                if (!empty($job_ids)) {
                    $stmt_job = $pdo->prepare("INSERT INTO user_jobs (user_id, job_id) VALUES (?, ?)");
                    foreach ($job_ids as $jid) {
                        $stmt_job->execute([$new_user_id, $jid]);
                    }
                }
                
                $pdo->commit();
                syncOpenRounds($pdo);
                $_SESSION['success'] = "เพิ่มบุคลากรสำเร็จ";
            } catch(PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        } elseif ($action == 'edit') {
            $id = $_POST['id'];
            try {
                $pdo->beginTransaction();
                if (!empty($_POST['password'])) {
                    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                    $stmt = $pdo->prepare("UPDATE users SET prefix=?, firstname=?, lastname=?, username=?, password=?, role=?, staff_type=?, department_id=?, job_id=NULL WHERE id=?");
                    $stmt->execute([$prefix, $firstname, $lastname, $username, $password, $role, $staff_type, $department_id, $id]);
                } else {
                    $stmt = $pdo->prepare("UPDATE users SET prefix=?, firstname=?, lastname=?, username=?, role=?, staff_type=?, department_id=?, job_id=NULL WHERE id=?");
                    $stmt->execute([$prefix, $firstname, $lastname, $username, $role, $staff_type, $department_id, $id]);
                }
                
                // Update user_jobs (delete old, insert new)
                $pdo->prepare("DELETE FROM user_jobs WHERE user_id = ?")->execute([$id]);
                if (!empty($job_ids)) {
                    $stmt_job = $pdo->prepare("INSERT INTO user_jobs (user_id, job_id) VALUES (?, ?)");
                    foreach ($job_ids as $jid) {
                        $stmt_job->execute([$id, $jid]);
                    }
                }
                
                $pdo->commit();
                syncOpenRounds($pdo);
                $_SESSION['success'] = "แก้ไขข้อมูลบุคลากรสำเร็จ";
            } catch(PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action == 'import') {
        if (isset($_FILES['csv_file']) && $_FILES['csv_file']['error'] == UPLOAD_ERR_OK) {
            $file = $_FILES['csv_file']['tmp_name'];
            $handle = fopen($file, "r");
            if ($handle !== FALSE) {
                // Skip UTF-8 BOM if present
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle); // not BOM
                }
                
                // Read header row
                fgetcsv($handle, 1000, ",");
                
                $successCount = 0;
                $errorCount = 0;
                
                $stmt_check = $pdo->prepare("SELECT id FROM users WHERE username = ?");
                $stmt_insert = $pdo->prepare("INSERT INTO users (prefix, firstname, lastname, username, password, role) VALUES (?, ?, ?, ?, ?, 'staff')");
                
                while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
                    // Expect format: ID Card, Prefix, Firstname, Lastname
                    if (count($data) >= 4) {
                        $username = trim($data[0]); // Using ID Card as Username
                        $prefix = trim($data[1]);
                        $firstname = trim($data[2]);
                        $lastname = trim($data[3]);
                        
                        if (!empty($username) && !empty($firstname) && !empty($lastname)) {
                            // Check existing
                            $stmt_check->execute([$username]);
                            if (!$stmt_check->fetch()) {
                                $password = password_hash($username, PASSWORD_DEFAULT);
                                try {
                                    $stmt_insert->execute([$prefix, $firstname, $lastname, $username, $password]);
                                    $successCount++;
                                } catch(PDOException $e) {
                                    $errorCount++;
                                }
                            } else {
                                $errorCount++; // username already exists
                            }
                        }
                    }
                }
                fclose($handle);
                syncOpenRounds($pdo);
                $_SESSION['success'] = "นำเข้าข้อมูลสำเร็จ $successCount รายการ (ข้าม/ผิดพลาด $errorCount รายการ)";
            } else {
                $_SESSION['error'] = "ไม่สามารถเปิดไฟล์ได้";
            }
        } else {
            $_SESSION['error'] = "กรุณาเลือกไฟล์ที่ถูกต้อง";
        }
    }
    header("Location: users.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        if ($id != $_SESSION['user_id']) { // Check can't delete self
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$id]);
            syncOpenRounds($pdo);
            $_SESSION['success'] = "ลบบุคลากรสำเร็จ";
        } else {
            $_SESSION['error'] = "ไม่สามารถลบบัญชีของตัวเองได้";
        }
    } catch(PDOException $e) {
        $_SESSION['error'] = "ไม่สามารถลบได้ เนื่องจากมีข้อมูลที่พึ่งพากันอยู่";
    }
    header("Location: users.php");
    exit();
}

// Fetch users and their jobs
$users = $pdo->query("SELECT u.*, d.name as department_name, 
                             GROUP_CONCAT(j.title SEPARATOR '<br>') as job_titles,
                             GROUP_CONCAT(j.id SEPARATOR ',') as job_ids_listed,
                             GROUP_CONCAT(j.department_id SEPARATOR ',') as job_depts_listed
                      FROM users u 
                      LEFT JOIN departments d ON u.department_id = d.id 
                      LEFT JOIN user_jobs uj ON u.id = uj.user_id
                      LEFT JOIN jobs j ON uj.job_id = j.id
                      GROUP BY u.id
                      ORDER BY u.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments & jobs for forms
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$jobs = $pdo->query("SELECT * FROM jobs ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);

$jobs_json = json_encode($jobs);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-users text-primary mr-2"></i> จัดการข้อมูลบุคลากร</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-success btn-sm mt-2" data-toggle="modal" data-target="#importModal">
                        <i class="fas fa-file-import"></i> นำเข้าด้วย CSV
                    </button>
                    <button class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#addModal">
                        <i class="fas fa-user-plus"></i> เพิ่มบุคลากรใหม่
                    </button>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-primary shadow">
                <div class="card-body">
                    <div class="table-responsive">
                    <table class="table table-bordered table-striped" id="userTable">
                        <thead>
                            <tr class="bg-primary text-white">
                                <th>ชื่อ-สกุล</th>
                                <th>Username</th>
                                <th>ระดับผู้ใช้งาน</th>
                                <th>ฝ่าย/แผนก</th>
                                <th>ตำแหน่ง</th>
                                <th width="15%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['prefix'] . $user['firstname'] . ' ' . $user['lastname']); ?></td>
                                <td><?php echo htmlspecialchars($user['username']); ?></td>
                                <td>
                                    <?php
                                        if($user['role'] == 'admin') {
                                            echo '<span class="badge badge-danger">ผู้ดูแลระบบ</span>';
                                        } else {
                                            if ($user['staff_type'] == 'teacher') {
                                                echo '<span class="badge badge-success px-2 py-1">ครูพิเศษสอน</span>';
                                            } elseif ($user['staff_type'] == 'general') {
                                                echo '<span class="badge badge-secondary px-2 py-1">บุคลากรทั่วไป</span>';
                                            } elseif ($user['staff_type'] == 'gov_teacher') {
                                                echo '<span class="badge badge-indigo px-2 py-1">พนักงานราชการสายการสอน</span>';
                                            } else {
                                                echo '<span class="badge badge-info px-2 py-1">เจ้าหน้าที่งาน</span>';
                                            }
                                        }
                                        
                                        if($user['role'] == 'evaluator') {
                                            echo '<br><span class="badge badge-warning mt-1"><i class="fas fa-star"></i> สิทธิ์กรรมการ</span>';
                                        }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($user['department_name'] ?? '-'); ?></td>
                                <td><?php echo !empty($user['job_titles']) ? $user['job_titles'] : '-'; ?></td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm btn-edit" 
                                            data-id="<?php echo $user['id']; ?>" 
                                            data-prefix="<?php echo htmlspecialchars($user['prefix']); ?>"
                                            data-first="<?php echo htmlspecialchars($user['firstname']); ?>"
                                            data-last="<?php echo htmlspecialchars($user['lastname']); ?>"
                                            data-username="<?php echo htmlspecialchars($user['username']); ?>"
                                            data-role="<?php echo $user['role']; ?>"
                                            data-stafftype="<?php echo $user['staff_type']; ?>"
                                            data-dept="<?php echo $user['department_id']; ?>"
                                            data-jobs="<?php echo $user['job_ids_listed']; ?>"
                                            data-jobdepts="<?php echo $user['job_depts_listed']; ?>"
                                            data-toggle="modal" data-target="#editModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if($user['id'] != $_SESSION['user_id']): ?>
                                    <button class="btn btn-danger btn-sm btn-delete" data-id="<?php echo $user['id']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="users.php" method="POST" enctype="multipart/form-data">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="fas fa-file-excel"></i> นำเข้าข้อมูลด้วย CSV</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="import">
                    <div class="alert alert-info py-2 mb-3">
                        <small>
                            - รหัสผ่านเริ่มต้นจะถูกตั้งให้ตรงกับ <b>รหัสบัตรประชาชน</b> (คอลัมน์แรก) อัตโนมัติ<br>
                            - ระดับผู้ใช้เริ่มต้นจะเป็น <b>เจ้าหน้าที่</b><br>
                            - ใช้ไฟล์นามสกุล <b>.csv</b> (UTF-8) เท่านั้น
                        </small>
                    </div>
                    <div class="text-center mb-4">
                        <a href="<?php echo BASE_URL; ?>/assets/template/user_import_template.csv" class="btn btn-outline-info btn-sm" download>
                            <i class="fas fa-download"></i> โหลดไฟล์แม่แบบ (Template)
                        </a>
                    </div>
                    <div class="form-group">
                        <label>เลือกไฟล์ CSV</label>
                        <input type="file" name="csv_file" class="form-control-file border p-1 rounded" accept=".csv" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success"><i class="fas fa-upload"></i> เริ่มนำเข้าข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="users.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> เพิ่มบุคลากร</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="row">
                        <div class="col-md-2 form-group">
                            <label>คำนำหน้า</label>
                            <input type="text" name="prefix" class="form-control" required placeholder="นาย, นาง...">
                        </div>
                        <div class="col-md-5 form-group">
                            <label>ชื่อ</label>
                            <input type="text" name="firstname" class="form-control" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label>นามสกุล</label>
                            <input type="text" name="lastname" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Username (สำหรับเข้าระบบ)</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Password</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>ระดับผู้ใช้งาน</label>
                            <select name="role" class="form-control" required>
                                <option value="staff">เจ้าหน้าที่ (Staff)</option>
                                <option value="evaluator">กรรมการประเมิน (Evaluator)</option>
                                <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                            </select>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>ประเภทบุคลากร <span class="text-danger">*</span></label>
                            <div class="p-2 border rounded bg-light">
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="add_st_staff" name="staff_type" value="staff" required>
                                    <label for="add_st_staff" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        เจ้าหน้าที่งาน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="add_st_teacher" name="staff_type" value="teacher" required>
                                    <label for="add_st_teacher" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        ครูพิเศษสอน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="add_st_gov_teacher" name="staff_type" value="gov_teacher" required>
                                    <label for="add_st_gov_teacher" class="custom-control-label font-weight-bold text-indigo" style="cursor: pointer;">
                                        พนักงานราชการสายการสอน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="add_st_general" name="staff_type" value="general" required>
                                    <label for="add_st_general" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        บุคลากรทั่วไป
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <label>ฝ่าย/แผนก (สังกัดหลัก)</label>
                            <select name="department_id" id="add_dept" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <small class="text-muted">สังกัดหลักของบุคลากร</small>
                        </div>
                    </div>
                    <hr class="mt-0">
                    <h6 class="font-weight-bold text-primary mb-3">กำหนดตำแหน่งงานที่รับการประเมิน</h6>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกฝ่าย/แผนก</label>
                            <select id="add_job1_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกตำแหน่งงาน</label>
                            <select name="job_ids[]" id="add_job1" class="form-control">
                                <option value="">-- กรุณาเลือก --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกฝ่าย/แผนก</label>
                            <select id="add_job2_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="add_job2" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกฝ่าย/แผนก</label>
                            <select id="add_job3_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="add_job3" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog modal-lg" role="document">
        <div class="modal-content">
            <form action="users.php" method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> แก้ไขข้อมูลบุคลากร</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="row">
                        <div class="col-md-2 form-group">
                            <label>คำนำหน้า</label>
                            <input type="text" name="prefix" id="edit_prefix" class="form-control" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label>ชื่อ</label>
                            <input type="text" name="firstname" id="edit_first" class="form-control" required>
                        </div>
                        <div class="col-md-5 form-group">
                            <label>นามสกุล</label>
                            <input type="text" name="lastname" id="edit_last" class="form-control" required>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>Username</label>
                            <input type="text" name="username" id="edit_username" class="form-control" required readonly>
                            <small class="text-muted">ไม่สามารถเปลี่ยนรหัสผู้ใช้งานได้</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>Password (ปล่อยว่างถ้าไม่ต้องการเปลี่ยน)</label>
                            <input type="password" name="password" class="form-control">
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4 form-group">
                            <label>ระดับผู้ใช้งาน</label>
                            <select name="role" id="edit_role" class="form-control" required>
                                <option value="staff">เจ้าหน้าที่ (Staff)</option>
                                <option value="evaluator">กรรมการประเมิน (Evaluator)</option>
                                <option value="admin">ผู้ดูแลระบบ (Admin)</option>
                            </select>
                        </div>
                        <div class="col-md-8 form-group">
                            <label>ประเภทบุคลากร <span class="text-danger">*</span></label>
                            <div class="p-2 border rounded bg-light">
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="edit_st_staff" name="staff_type" value="staff" required>
                                    <label for="edit_st_staff" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        เจ้าหน้าที่งาน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="edit_st_teacher" name="staff_type" value="teacher" required>
                                    <label for="edit_st_teacher" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        ครูพิเศษสอน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="edit_st_gov_teacher" name="staff_type" value="gov_teacher" required>
                                    <label for="edit_st_gov_teacher" class="custom-control-label font-weight-bold text-indigo" style="cursor: pointer;">
                                        พนักงานราชการสายการสอน
                                    </label>
                                </div>
                                <div class="custom-control custom-radio custom-control-inline">
                                    <input class="custom-control-input" type="radio" id="edit_st_general" name="staff_type" value="general" required>
                                    <label for="edit_st_general" class="custom-control-label" style="font-weight: normal; cursor: pointer;">
                                        บุคลากรทั่วไป
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-12 form-group">
                            <label>ฝ่าย/แผนก (สังกัดหลัก)</label>
                            <select name="department_id" id="edit_dept" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <hr class="mt-0">
                    <h6 class="font-weight-bold text-warning mb-3">กำหนดตำแหน่งงานที่รับการประเมิน</h6>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกฝ่าย/แผนก</label>
                            <select id="edit_job1_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 1 : เลือกตำแหน่งงาน</label>
                            <select name="job_ids[]" id="edit_job1" class="form-control">
                                <option value="">-- กรุณาเลือก --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกฝ่าย/แผนก</label>
                            <select id="edit_job2_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 2 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="edit_job2" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกฝ่าย/แผนก</label>
                            <select id="edit_job3_dept" class="form-control">
                                <option value="">-- ทั้งหมด --</option>
                                <?php foreach($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>ตำแหน่งที่ 3 : เลือกตำแหน่งงาน <small class="text-muted">(ไม่บังคับ)</small></label>
                            <select name="job_ids[]" id="edit_job3" class="form-control">
                                <option value="">-- ไม่ระบุ --</option>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    $('#userTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        }
    });

    var allJobs = <?php echo $jobs_json; ?>;

    function populateJobsSelect(targetSelect, selectedJobId, deptId = null) {
        $(targetSelect).empty();
        $(targetSelect).append('<option value="">-- ไม่ระบุ / Please Select --</option>');
        var filteredJobs = allJobs;
        if(deptId && deptId !== '') {
            filteredJobs = allJobs.filter(function(job){
                return job.department_id == deptId;
            });
        }
        filteredJobs.forEach(function(job){
            var isSelected = (selectedJobId && selectedJobId.toString() === job.id.toString()) ? 'selected' : '';
            $(targetSelect).append('<option value="'+job.id+'" '+isSelected+'>'+job.title+'</option>');
        });
    }

    // Add Modal logic
    $('#add_job1_dept').change(function(){
        populateJobsSelect('#add_job1', '', $(this).val());
    });
    $('#add_job2_dept').change(function(){
        populateJobsSelect('#add_job2', '', $(this).val());
    });
    $('#add_job3_dept').change(function(){
        populateJobsSelect('#add_job3', '', $(this).val());
    });

    // Edit Modal logic
    $('#edit_job1_dept').change(function(){
        populateJobsSelect('#edit_job1', '', $(this).val());
    });
    $('#edit_job2_dept').change(function(){
        populateJobsSelect('#edit_job2', '', $(this).val());
    });
    $('#edit_job3_dept').change(function(){
        populateJobsSelect('#edit_job3', '', $(this).val());
    });

    $('.btn-edit').click(function() {
        var deptId = $(this).data('dept');
        var jobIdsStr = $(this).data('jobs');
        var jobDeptsStr = $(this).data('jobdepts');
        
        $('#edit_id').val($(this).data('id'));
        $('#edit_prefix').val($(this).data('prefix'));
        $('#edit_first').val($(this).data('first'));
        $('#edit_last').val($(this).data('last'));
        $('#edit_username').val($(this).data('username'));
        $('#edit_role').val($(this).data('role'));
        
        var st = $(this).data('stafftype');
        if (st === 'teacher') {
            $('#edit_st_teacher').prop('checked', true);
        } else if (st === 'gov_teacher') {
            $('#edit_st_gov_teacher').prop('checked', true);
        } else if (st === 'staff') {
            $('#edit_st_staff').prop('checked', true);
        } else if (st === 'general') {
            $('#edit_st_general').prop('checked', true);
        } else {
            $('#edit_st_staff').prop('checked', false);
            $('#edit_st_teacher').prop('checked', false);
            $('#edit_st_gov_teacher').prop('checked', false);
            $('#edit_st_general').prop('checked', false);
        }

        $('#edit_dept').val(deptId);
        
        var jobIds = jobIdsStr ? jobIdsStr.toString().split(',') : [];
        var jobDepts = jobDeptsStr ? jobDeptsStr.toString().split(',') : [];

        var job1_id = jobIds[0] || '';
        var job1_dept = jobDepts[0] || '';
        var job2_id = jobIds[1] || '';
        var job2_dept = jobDepts[1] || '';
        var job3_id = jobIds[2] || '';
        var job3_dept = jobDepts[2] || '';

        $('#edit_job1_dept').val(job1_dept);
        populateJobsSelect('#edit_job1', job1_id, job1_dept);

        $('#edit_job2_dept').val(job2_dept);
        populateJobsSelect('#edit_job2', job2_id, job2_dept);

        $('#edit_job3_dept').val(job3_dept);
        populateJobsSelect('#edit_job3', job3_id, job3_dept);
    });

    $('.btn-delete').click(function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบบัญชีผู้ใช้นี้ใช่หรือไม่!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ใช่, ฉันต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'users.php?delete=' + id;
            }
        })
    });
});
</script>
