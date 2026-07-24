<?php
require_once '../config.php';
// Auth Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบในฐานะผู้ดูแลระบบ';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Evaluation Settings';

// Handle Setting Updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'update_head_eval_setting') {
        $val = isset($_POST['enable_head_evaluation']) ? '1' : '0';
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('enable_head_evaluation', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$val, $val]);
        $_SESSION['success'] = 'บันทึกการตั้งค่าประเมินหัวหน้างานเรียบร้อยแล้ว';
    }
    
    if ($_POST['action'] == 'update_committee') {
        $type = isset($_POST['committee_type']) && in_array($_POST['committee_type'], ['staff', 'teacher', 'gov_teacher']) ? $_POST['committee_type'] : 'staff';
        $setting_key = 'report_committee_' . $type;
        $names = isset($_POST['committee_name']) ? $_POST['committee_name'] : [];
        $positions = isset($_POST['committee_position']) ? $_POST['committee_position'] : [];
        $committee = [];
        for ($i = 0; $i < count($names); $i++) {
            $n = trim($names[$i]);
            $p = trim($positions[$i]);
            if (!empty($n)) {
                $committee[] = ['name' => $n, 'position' => $p];
            }
        }
        $json = json_encode($committee, JSON_UNESCAPED_UNICODE);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$setting_key, $json, $json]);
        $_SESSION['success'] = 'บันทึกรายชื่อกรรมการกลั่นกรองเรียบร้อยแล้ว';
    }
    
    if ($_POST['action'] == 'update_central_evaluators') {
        $eval_ids = isset($_POST['central_evaluator_ids']) ? $_POST['central_evaluator_ids'] : [];
        $evaluators = [];
        foreach ($eval_ids as $eid) {
            $eid = (int)$eid;
            if ($eid > 0 && !in_array($eid, $evaluators)) {
                $evaluators[] = $eid;
            }
        }
        $json = json_encode($evaluators);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('central_teacher_evaluators', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$json, $json]);
        $_SESSION['success'] = 'บันทึกรายชื่อกรรมการกลางประเมินครูพิเศษสอนเรียบร้อยแล้ว';
    }
    
    if ($_POST['action'] == 'update_central_gov_teacher_evaluators') {
        $eval_ids = isset($_POST['central_gov_evaluator_ids']) ? $_POST['central_gov_evaluator_ids'] : [];
        $evaluators = [];
        foreach ($eval_ids as $eid) {
            $eid = (int)$eid;
            if ($eid > 0 && !in_array($eid, $evaluators)) {
                $evaluators[] = $eid;
            }
        }
        $json = json_encode($evaluators);
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('central_gov_teacher_evaluators', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$json, $json]);
        $_SESSION['success'] = 'บันทึกรายชื่อกรรมการกลางประเมินพนักงานราชการสายการสอนเรียบร้อยแล้ว';
    }
    
    header("Location: eval_settings.php");
    exit();
}

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<!-- Content Wrapper. Contains page content -->
<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark">ตั้งค่าการประเมิน (Evaluation Settings)</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            
            <!-- Evaluation Method Section -->
            <div class="row">
                <div class="col-md-12">
                    <div class="card card-primary card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-sitemap mr-1"></i> รูปแบบ/วิธีการประเมิน (Evaluation Method)</h3>
                        </div>
                        <div class="card-body">
                            <p class="text-muted mb-4">กำหนดวิธีการประเมินบุคลากรในระบบ <i>(ปัจจุบันระบบรองรับรูปแบบตามสายบังคับบัญชาเป็นค่าเริ่มต้น)</i></p>
                            
                            <div class="form-group">
                                <div class="custom-control custom-radio mb-3 p-3 border rounded" style="background-color: #f8f9fa;">
                                    <input class="custom-control-input" type="radio" id="evalMethodTopDown" name="eval_method" checked disabled>
                                    <label for="evalMethodTopDown" class="custom-control-label font-weight-bold text-primary" style="font-size: 1.1rem;">
                                        การประเมินตามสายบังคับบัญชา (Top-Down Evaluation)
                                    </label>
                                    <div class="mt-2 text-muted ml-4">
                                        <p class="mb-1"><i class="fas fa-check-circle text-success mr-1"></i> <b>คำอธิบาย:</b> การประเมินผลการปฏิบัติงานโดยผู้บังคับบัญชาประเมินผู้ใต้บังคับบัญชาตามลำดับชั้น</p>
                                        <ul class="mb-0">
                                            <li>เจ้าหน้าที่/ครู ➜ ประเมินโดย หัวหน้างาน/หัวหน้าแผนก ➜ ผ่านการกลั่นกรองโดย รองผู้อำนวยการ ➜ สรุปผลโดย ผู้อำนวยการ</li>
                                            <li>สามารถกำหนด "กรรมการกลาง" มาร่วมประเมินครูพิเศษสอนและพนักงานราชการได้</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Head of Work Evaluation Toggle Section -->
            <div class="row mt-3">
                <div class="col-md-12">
                    <div class="card card-purple card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-shield mr-1"></i> เลือกระดับที่ถูกประเมิน (หัวหน้างาน)</h3>
                        </div>
                        <form action="eval_settings.php" method="POST">
                            <input type="hidden" name="action" value="update_head_eval_setting">
                            <div class="card-body">
                                <p class="text-muted mb-4">กำหนดให้ <b>"หัวหน้างาน" (ระดับ 3)</b> ได้รับการประเมินจากรองผู้อำนวยการฝ่ายหรือไม่ หากปิดไว้ระบบข้ามบรรทัดอัตโนมัติจะไม่สร้างฟอร์มประเมินให้ตำแหน่งหัวหน้างานในรอบถัดไป (หรือรอบที่เปิดอยู่)</p>
                                
                                <?php
                                    $enable_head_eval = '1';
                                    try {
                                        $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enable_head_evaluation'");
                                        $cs->execute();
                                        $val = $cs->fetchColumn();
                                        if ($val !== false) $enable_head_eval = $val;
                                    } catch(Exception $e) {}
                                ?>
                                <div class="custom-control custom-switch custom-switch-lg" style="transform: scale(1.2); transform-origin: left center;">
                                    <input type="checkbox" class="custom-control-input" id="enableHeadEval" name="enable_head_evaluation" value="1" <?php echo ($enable_head_eval == '1') ? 'checked' : ''; ?>>
                                    <label class="custom-control-label" for="enableHeadEval" style="cursor:pointer;" id="headEvalLabel">
                                        <?php echo ($enable_head_eval == '1') ? '<span class="text-success ml-2">เปิดใช้งาน (ประเมินหัวหน้างาน)</span>' : '<span class="text-danger ml-2">ปิดใช้งาน (ไม่ประเมินหัวหน้างาน)</span>'; ?>
                                    </label>
                                </div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-warning font-weight-bold"><i class="fas fa-save"></i> บันทึกการตั้งค่า</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Committee Members Section -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card card-warning card-outline card-tabs shadow">
                        <div class="card-header p-0 pt-1 border-bottom-0">
                            <h3 class="card-title p-3"><i class="fas fa-users mr-1"></i> กรรมการกลั่นกรอง (สำหรับแสดงในรายงานสรุปผล)</h3>
                            <ul class="nav nav-tabs" id="committee-tabs" role="tablist">
                                <li class="nav-item">
                                    <a class="nav-link active" id="tab-staff" data-toggle="pill" href="#content-staff" role="tab">เจ้าหน้าที่งาน</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-teacher" data-toggle="pill" href="#content-teacher" role="tab">ครูพิเศษสอน</a>
                                </li>
                                <li class="nav-item">
                                    <a class="nav-link" id="tab-gov-teacher" data-toggle="pill" href="#content-gov-teacher" role="tab">พนักงานราชการสายการสอน</a>
                                </li>
                            </ul>
                        </div>
                        <div class="card-body">
                            <p class="text-muted">กำหนดรายชื่อและตำแหน่งกรรมการกลั่นกรอง เพื่อแสดงในส่วนท้ายของรายงานสรุปผลเมื่อสั่งพิมพ์ โดยแยกตามกลุ่มบุคลากร</p>
                            <div class="tab-content" id="committee-tabContent">
                                <?php 
                                    $types = [
                                        'staff' => 'เจ้าหน้าที่งาน', 
                                        'teacher' => 'ครูพิเศษสอน', 
                                        'gov_teacher' => 'พนักงานราชการสายการสอน'
                                    ];
                                    $is_first = true;
                                    foreach ($types as $type_key => $type_name): 
                                        $committee_json = '';
                                        try {
                                            $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
                                            $cs->execute(['report_committee_' . $type_key]);
                                            $committee_json = $cs->fetchColumn();
                                            if (!$committee_json && $type_key === 'staff') {
                                                // Legacy fallback
                                                $cs->execute(['report_committee']);
                                                $committee_json = $cs->fetchColumn();
                                            }
                                        } catch(Exception $e) {}
                                        $committee_list = $committee_json ? json_decode($committee_json, true) : [];
                                        if (!is_array($committee_list)) $committee_list = [];
                                ?>
                                <div class="tab-pane fade <?php echo $is_first ? 'show active' : ''; ?>" id="content-<?php echo str_replace('_', '-', $type_key); ?>" role="tabpanel">
                                    <form action="eval_settings.php" method="POST">
                                        <input type="hidden" name="action" value="update_committee">
                                        <input type="hidden" name="committee_type" value="<?php echo $type_key; ?>">
                                        
                                        <div class="committeeContainer">
                                            <?php if (count($committee_list) > 0): ?>
                                                <?php foreach ($committee_list as $idx => $cm): ?>
                                                <div class="row mb-2 committee-row">
                                                    <div class="col-md-1 text-center align-self-center">
                                                        <span class="badge badge-dark px-2 py-1 committee-num"><?php echo $idx + 1; ?></span>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="text" name="committee_name[]" class="form-control" placeholder="ชื่อ-นามสกุล" value="<?php echo htmlspecialchars($cm['name']); ?>" required>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <input type="text" name="committee_position[]" class="form-control" placeholder="ตำแหน่ง" value="<?php echo htmlspecialchars($cm['position']); ?>">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button type="button" class="btn btn-danger btn-sm btn-remove-committee"><i class="fas fa-trash"></i> ลบ</button>
                                                    </div>
                                                </div>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <div class="row mb-2 committee-row">
                                                    <div class="col-md-1 text-center align-self-center">
                                                        <span class="badge badge-dark px-2 py-1 committee-num">1</span>
                                                    </div>
                                                    <div class="col-md-4">
                                                        <input type="text" name="committee_name[]" class="form-control" placeholder="ชื่อ-นามสกุล" required>
                                                    </div>
                                                    <div class="col-md-5">
                                                        <input type="text" name="committee_position[]" class="form-control" placeholder="ตำแหน่ง">
                                                    </div>
                                                    <div class="col-md-2">
                                                        <button type="button" class="btn btn-danger btn-sm btn-remove-committee"><i class="fas fa-trash"></i> ลบ</button>
                                                    </div>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="btn btn-outline-success btn-sm mt-2 btnAddCommittee"><i class="fas fa-plus"></i> เพิ่มกรรมการ</button>
                                        
                                        <div class="text-right mt-3 pt-3 border-top">
                                            <button type="submit" class="btn btn-warning text-dark font-weight-bold"><i class="fas fa-save"></i> บันทึกกรรมการกลุ่ม <?php echo $type_name; ?></button>
                                        </div>
                                    </form>
                                </div>
                                <?php $is_first = false; endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Central Teacher Evaluators Section -->
            <div class="row mt-4">
                <div class="col-md-12">
                    <div class="card card-info card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-user-tie mr-1"></i> กรรมการกลางประเมินครูพิเศษสอน</h3>
                        </div>
                        <form action="eval_settings.php" method="POST">
                            <input type="hidden" name="action" value="update_central_evaluators">
                            <div class="card-body">
                                <p class="text-muted">กำหนดรายชื่อบุคลากรที่จะทำหน้าที่ประเมิน <b>ครูพิเศษสอนทุกคน</b> ในวิทยาลัยร่วมกับหัวหน้าแผนกวิชาของครูท่านนั้นๆ (เช่น รองผู้อำนวยการ, หัวหน้างาน ฯลฯ)</p>
                                <?php
                                    // Fetch users for dropdown
                                    $users_for_eval = $pdo->query("SELECT id, prefix, firstname, lastname FROM users WHERE role IN ('staff', 'evaluator') ORDER BY firstname ASC")->fetchAll(PDO::FETCH_ASSOC);

                                    $central_evals_json = '';
                                    try {
                                        $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_teacher_evaluators'");
                                        $cs->execute();
                                        $central_evals_json = $cs->fetchColumn();
                                    } catch(Exception $e) {}
                                    $central_evals_list = $central_evals_json ? json_decode($central_evals_json, true) : [];
                                    if (!is_array($central_evals_list)) $central_evals_list = [];
                                ?>
                                <div id="centralEvalsContainer">
                                    <?php if (count($central_evals_list) > 0): ?>
                                        <?php foreach ($central_evals_list as $idx => $eid): ?>
                                        <div class="row mb-2 central-eval-row">
                                            <div class="col-md-1 text-center align-self-center">
                                                <span class="badge badge-dark px-2 py-1 central-eval-num"><?php echo $idx + 1; ?></span>
                                            </div>
                                            <div class="col-md-9">
                                                <select name="central_evaluator_ids[]" class="form-control select2" required>
                                                    <option value="">-- เลือกกรรมการกลาง --</option>
                                                    <?php foreach($users_for_eval as $u): ?>
                                                        <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $eid ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($u['prefix'].$u['firstname'].' '.$u['lastname']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm btn-remove-central-eval"><i class="fas fa-trash"></i> ลบ</button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="row mb-2 central-eval-row">
                                            <div class="col-md-1 text-center align-self-center">
                                                <span class="badge badge-dark px-2 py-1 central-eval-num">1</span>
                                            </div>
                                            <div class="col-md-9">
                                                <select name="central_evaluator_ids[]" class="form-control select2">
                                                    <option value="">-- เลือกกรรมการกลาง (ถ้าไม่มี ปล่อยว่างไว้) --</option>
                                                    <?php foreach($users_for_eval as $u): ?>
                                                        <option value="<?php echo $u['id']; ?>">
                                                            <?php echo htmlspecialchars($u['prefix'].$u['firstname'].' '.$u['lastname']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm btn-remove-central-eval"><i class="fas fa-trash"></i> ลบ</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="btnAddCentralEval" class="btn btn-outline-info btn-sm mt-2"><i class="fas fa-plus"></i> เพิ่มกรรมการกลาง</button>
                                
                                <!-- Used by JS for new rows -->
                                <div id="usersOptionsTemplate" style="display:none;">
                                    <option value="">-- เลือกกรรมการกลาง --</option>
                                    <?php foreach($users_for_eval as $u): ?>
                                        <option value="<?php echo $u['id']; ?>">
                                            <?php echo htmlspecialchars($u['prefix'].$u['firstname'].' '.$u['lastname']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-info"><i class="fas fa-save"></i> บันทึกกรรมการกลาง</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Central Gov Teacher Evaluators Section -->
            <div class="row mt-4 mb-4">
                <div class="col-md-12">
                    <div class="card card-indigo card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title"><i class="fas fa-chalkboard-teacher mr-1"></i> กรรมการกลางประเมินพนักงานราชการสายการสอน</h3>
                        </div>
                        <form action="eval_settings.php" method="POST">
                            <input type="hidden" name="action" value="update_central_gov_teacher_evaluators">
                            <div class="card-body">
                                <p class="text-muted">กำหนดรายชื่อบุคลากรที่จะทำหน้าที่ประเมิน <b>พนักงานราชการสายการสอนทุกคน</b> ในวิทยาลัยร่วมกับหัวหน้าแผนกวิชาของพนักงานท่านนั้นๆ</p>
                                <?php
                                    $central_gov_evals_json = '';
                                    try {
                                        $cs = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'central_gov_teacher_evaluators'");
                                        $cs->execute();
                                        $central_gov_evals_json = $cs->fetchColumn();
                                    } catch(Exception $e) {}
                                    $central_gov_evals_list = $central_gov_evals_json ? json_decode($central_gov_evals_json, true) : [];
                                    if (!is_array($central_gov_evals_list)) $central_gov_evals_list = [];
                                ?>
                                <div id="centralGovEvalsContainer">
                                    <?php if (count($central_gov_evals_list) > 0): ?>
                                        <?php foreach ($central_gov_evals_list as $idx => $eid): ?>
                                        <div class="row mb-2 central-gov-eval-row">
                                            <div class="col-md-1 text-center align-self-center">
                                                <span class="badge badge-dark px-2 py-1 central-gov-eval-num"><?php echo $idx + 1; ?></span>
                                            </div>
                                            <div class="col-md-9">
                                                <select name="central_gov_evaluator_ids[]" class="form-control select2" required>
                                                    <option value="">-- เลือกกรรมการกลาง --</option>
                                                    <?php foreach($users_for_eval as $u): ?>
                                                        <option value="<?php echo $u['id']; ?>" <?php echo $u['id'] == $eid ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($u['prefix'].$u['firstname'].' '.$u['lastname']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm btn-remove-central-gov-eval"><i class="fas fa-trash"></i> ลบ</button>
                                            </div>
                                        </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="row mb-2 central-gov-eval-row">
                                            <div class="col-md-1 text-center align-self-center">
                                                <span class="badge badge-dark px-2 py-1 central-gov-eval-num">1</span>
                                            </div>
                                            <div class="col-md-9">
                                                <select name="central_gov_evaluator_ids[]" class="form-control select2">
                                                    <option value="">-- เลือกกรรมการกลาง (ถ้าไม่มี ปล่อยว่างไว้) --</option>
                                                    <?php foreach($users_for_eval as $u): ?>
                                                        <option value="<?php echo $u['id']; ?>">
                                                            <?php echo htmlspecialchars($u['prefix'].$u['firstname'].' '.$u['lastname']); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2">
                                                <button type="button" class="btn btn-danger btn-sm btn-remove-central-gov-eval"><i class="fas fa-trash"></i> ลบ</button>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                                <button type="button" id="btnAddCentralGovEval" class="btn btn-outline-indigo btn-sm mt-2"><i class="fas fa-plus"></i> เพิ่มกรรมการกลาง</button>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-indigo"><i class="fas fa-save"></i> บันทึกกรรมการกลาง (พนักงานราชการสายการสอน)</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </section>
</div>

<?php include '../includes/footer.php'; ?>

<!-- display SweetAlert messages if set -->
<?php if(isset($_SESSION['success'])): ?>
    <script>
        Swal.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: '<?php echo $_SESSION['success']; ?>'
        });
    </script>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>

<?php if(isset($_SESSION['error'])): ?>
    <script>
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?php echo $_SESSION['error']; ?>'
        });
    </script>
    <?php unset($_SESSION['error']); ?>
<?php endif; ?>

<script>
    // Committee member management
    function renumberCommittee(container) {
        $(container).find('.committee-row').each(function(i) {
            $(this).find('.committee-num').text(i + 1);
        });
    }

    $('.btnAddCommittee').click(function() {
        var container = $(this).closest('.tab-pane').find('.committeeContainer');
        var num = container.find('.committee-row').length + 1;
        var row = `<div class="row mb-2 committee-row">
            <div class="col-md-1 text-center align-self-center">
                <span class="badge badge-dark px-2 py-1 committee-num">${num}</span>
            </div>
            <div class="col-md-4">
                <input type="text" name="committee_name[]" class="form-control" placeholder="ชื่อ-นามสกุล" required>
            </div>
            <div class="col-md-5">
                <input type="text" name="committee_position[]" class="form-control" placeholder="ตำแหน่ง">
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm btn-remove-committee"><i class="fas fa-trash"></i> ลบ</button>
            </div>
        </div>`;
        container.append(row);
    });

    $(document).on('click', '.btn-remove-committee', function() {
        var container = $(this).closest('.committeeContainer');
        if (container.find('.committee-row').length > 1) {
            $(this).closest('.committee-row').remove();
            renumberCommittee(container);
        } else {
            Swal.fire('แจ้งเตือน', 'ต้องมีกรรมการอย่างน้อย 1 ท่าน', 'warning');
        }
    });

    // Central Evaluators management
    function renumberCentralEvals() {
        $('#centralEvalsContainer .central-eval-row').each(function(i) {
            $(this).find('.central-eval-num').text(i + 1);
        });
    }

    $('#btnAddCentralEval').click(function() {
        var num = $('#centralEvalsContainer .central-eval-row').length + 1;
        var options = $('#usersOptionsTemplate').html();
        var row = `<div class="row mb-2 central-eval-row">
            <div class="col-md-1 text-center align-self-center">
                <span class="badge badge-dark px-2 py-1 central-eval-num">${num}</span>
            </div>
            <div class="col-md-9">
                <select name="central_evaluator_ids[]" class="form-control select2" required>
                    ${options}
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm btn-remove-central-eval"><i class="fas fa-trash"></i> ลบ</button>
            </div>
        </div>`;
        $('#centralEvalsContainer').append(row);
        $('.select2').select2({ theme: 'bootstrap4' });
    });

    $(document).on('click', '.btn-remove-central-eval', function() {
        $(this).closest('.central-eval-row').remove();
        renumberCentralEvals();
    });

    // Central Gov Evaluators management
    function renumberCentralGovEvals() {
        $('#centralGovEvalsContainer .central-gov-eval-row').each(function(i) {
            $(this).find('.central-gov-eval-num').text(i + 1);
        });
    }

    $('#btnAddCentralGovEval').click(function() {
        var num = $('#centralGovEvalsContainer .central-gov-eval-row').length + 1;
        var options = $('#usersOptionsTemplate').html();
        var row = `<div class="row mb-2 central-gov-eval-row">
            <div class="col-md-1 text-center align-self-center">
                <span class="badge badge-dark px-2 py-1 central-gov-eval-num">${num}</span>
            </div>
            <div class="col-md-9">
                <select name="central_gov_evaluator_ids[]" class="form-control select2" required>
                    ${options}
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="btn btn-danger btn-sm btn-remove-central-gov-eval"><i class="fas fa-trash"></i> ลบ</button>
            </div>
        </div>`;
        $('#centralGovEvalsContainer').append(row);
        $('.select2').select2({ theme: 'bootstrap4' });
    });

    $(document).on('click', '.btn-remove-central-gov-eval', function() {
        $(this).closest('.central-gov-eval-row').remove();
        renumberCentralGovEvals();
    });
</script>
