<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Evaluation Criteria';

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    $max_score = isset($_POST['max_score']) ? (float)$_POST['max_score'] : 0.00;
    $sort_order = (int)$_POST['sort_order'];
    $target_group = isset($_POST['target_group']) ? $_POST['target_group'] : 'both';
    $parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
    
    if ($_POST['action'] == 'add_main') {
        try {
            // Main criteria have no parent_id
            $stmt = $pdo->prepare("INSERT INTO evaluation_criteria (parent_id, title, description, max_score, sort_order, target_group) VALUES (NULL, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $max_score, $sort_order, $target_group]);
            $_SESSION['success'] = "เพิ่มหัวข้อหลักสำเร็จ";
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] == 'add_sub') {
        try {
            if (empty($parent_id)) {
                throw new Exception("ต้องเลือกหมวดหมู่หลักสำหรับประเด็นย่อย");
            }
            // Sub criteria must have a parent_id, max_score might be set manually but ideally they sum up for the parent
            $stmt = $pdo->prepare("INSERT INTO evaluation_criteria (parent_id, title, description, max_score, sort_order, target_group) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$parent_id, $title, $description, $max_score, $sort_order, $target_group]);
            $_SESSION['success'] = "เพิ่มประเด็นย่อยสำเร็จ";
        } catch(Exception $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        
        // Prevent setting a master category as its own parent
        if ($parent_id == $id) {
            $parent_id = null;
        }

        try {
            $stmt = $pdo->prepare("UPDATE evaluation_criteria SET parent_id=?, title=?, description=?, max_score=?, sort_order=?, target_group=? WHERE id=?");
            $stmt->execute([$parent_id, $title, $description, $max_score, $sort_order, $target_group, $id]);
            $_SESSION['success'] = "แก้ไขหัวข้อประเมินสำเร็จ";
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    header("Location: criteria.php");
    exit();
}

if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM evaluation_criteria WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = "ลบหัวข้อประเมินสำเร็จ";
    } catch(PDOException $e) {
        $_SESSION['error'] = "ไม่สามารถลบได้ เนื่องจากถูกใช้งานในการประเมินแล้ว";
    }
    header("Location: criteria.php");
    exit();
}

// Fetch all criteria but organize them: Main Categories first, then their sub-criteria
$all_criteria = $pdo->query("SELECT * FROM evaluation_criteria ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// Group them map
$main_categories = [];
$sub_criteria = [];

foreach ($all_criteria as $c) {
    if ($c['parent_id'] === null) {
        // Default to its own max_score. Will be overridden if it has children.
        $c['calculated_max'] = $c['max_score'];
        $main_categories[$c['id']] = $c;
    } else {
        $sub_criteria[$c['parent_id']][] = $c;
    }
}

// Calculate the actual max_score sum for categories WITH children
foreach ($sub_criteria as $p_id => $subs) {
    if (isset($main_categories[$p_id])) {
        $main_categories[$p_id]['calculated_max'] = 0; // Reset first
        foreach ($subs as $s) {
            $main_categories[$p_id]['calculated_max'] += $s['max_score'];
        }
    }
}
include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-list-alt text-primary mr-2"></i> จัดการหัวข้อและเกณฑ์การประเมิน</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#addMainModal">
                        <i class="fas fa-plus"></i> เพิ่มหัวข้อหลัก
                    </button>
                    <button class="btn btn-info btn-sm mt-2" data-toggle="modal" data-target="#addSubModal">
                        <i class="fas fa-plus-circle"></i> เพิ่มประเด็นย่อย
                    </button>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="card card-outline card-primary shadow">
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> <b>เกณฑ์การประเมิน:</b> ชุดหัวข้อนี้จะถูกนำไปใช้ในทุกรอบการประเมิน
                    </div>
                    <ul class="nav nav-tabs mb-3" id="criteriaTabs" role="tablist">
                        <li class="nav-item">
                            <a class="nav-link active" id="staff-tab" data-toggle="tab" href="#staff-criteria" role="tab" aria-controls="staff-criteria" aria-selected="true">
                                <i class="fas fa-user-tie text-info"></i> เจ้าหน้าที่ และ บุคลากรทั่วไป
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="teacher-tab" data-toggle="tab" href="#teacher-criteria" role="tab" aria-controls="teacher-criteria" aria-selected="false">
                                <i class="fas fa-chalkboard-teacher text-success"></i> ครูพิเศษสอน
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" id="gov-teacher-tab" data-toggle="tab" href="#gov-teacher-criteria" role="tab" aria-controls="gov-teacher-criteria" aria-selected="false">
                                <i class="fas fa-chalkboard-teacher text-indigo"></i> พนักงานราชการสายการสอน
                            </a>
                        </li>
                    </ul>

                    <div class="tab-content" id="criteriaTabsContent">
                        <!-- Staff & General Tab -->
                        <div class="tab-pane fade show active" id="staff-criteria" role="tabpanel" aria-labelledby="staff-tab">
                            <div class="accordion" id="accordionStaff">
                                <?php 
                                $staff_mains = array_filter($main_categories, function($cat) {
                                    return in_array($cat['target_group'], ['staff', 'general', 'both']);
                                });
                                if (empty($staff_mains)) echo '<div class="alert alert-light">ไม่มีข้อมูลหัวข้อการประเมิน</div>';
                                foreach($staff_mains as $m_cat): 
                                ?>
                                    <?php include 'criteria_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Teacher Tab -->
                        <div class="tab-pane fade" id="teacher-criteria" role="tabpanel" aria-labelledby="teacher-tab">
                            <div class="accordion" id="accordionTeacher">
                                <?php 
                                $teacher_mains = array_filter($main_categories, function($cat) {
                                    return in_array($cat['target_group'], ['teacher', 'both']);
                                });
                                if (empty($teacher_mains)) echo '<div class="alert alert-light">ไม่มีข้อมูลหัวข้อการประเมิน</div>';
                                foreach($teacher_mains as $m_cat): 
                                ?>
                                    <?php include 'criteria_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Gov Teacher Tab -->
                        <div class="tab-pane fade" id="gov-teacher-criteria" role="tabpanel" aria-labelledby="gov-teacher-tab">
                            <div class="accordion" id="accordionGovTeacher">
                                <?php 
                                $gov_teacher_mains = array_filter($main_categories, function($cat) {
                                    return in_array($cat['target_group'], ['gov_teacher', 'both']);
                                });
                                if (empty($gov_teacher_mains)) echo '<div class="alert alert-light">ไม่มีข้อมูลหัวข้อการประเมิน</div>';
                                foreach($gov_teacher_mains as $m_cat): 
                                ?>
                                    <?php include 'criteria_card_template.php'; ?>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>

<!-- Add Main Modal -->
<div class="modal fade" id="addMainModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="criteria.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> เพิ่มหัวข้อหลัก</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_main">
                    
                    <div class="form-group">
                        <label>หัวข้อการประเมินหลัก (Main Criteria Title)</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>คำอธิบายเพิ่มเติม / แนวทางการให้คะแนน</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>คะแนนเต็ม (Max Score)</label>
                            <input type="number" step="0.01" name="max_score" class="form-control" value="10">
                            <small class="text-muted">หากมีการเพิ่มประเด็นย่อยในภายหลัง คะแนนเต็มจะถูกคำนวณจากประเด็นย่อยแทน</small>
                        </div>
                        <div class="col-md-6 form-group">
                            <label>กลุ่มเป้าหมาย (Target Group)</label>
                            <select name="target_group" class="form-control" required>
                                <option value="both">ทั้งหมด (ทุกคน)</option>
                                <option value="staff">เฉพาะ เจ้าหน้าที่งาน</option>
                                <option value="teacher">เฉพาะ ครูพิเศษสอน</option>
                                <option value="gov_teacher">เฉพาะ พนักงานราชการสายการสอน</option>
                                <option value="general">เฉพาะ บุคลากรทั่วไป</option>
                            </select>
                            <small class="text-muted">เลือกกลุ่มผู้ประเมินที่จะใช้เกณฑ์นี้</small>
                        </div>
                        <div class="col-md-12 form-group mt-2">
                            <label>ลำดับแสดงผล (Sort Order)</label>
                            <input type="number" name="sort_order" class="form-control" required value="1">
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

<!-- Add Sub Modal -->
<div class="modal fade" id="addSubModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="criteria.php" method="POST">
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title"><i class="fas fa-plus-circle"></i> เพิ่มประเด็นย่อย</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add_sub">
                    <div class="form-group">
                        <label>สังกัดหัวข้อหลัก (Parent Category)</label>
                        <select name="parent_id" class="form-control" required>
                            <option value="">-- เลือกหัวข้อหลัก --</option>
                            <?php foreach($main_categories as $m_cat): ?>
                                <option value="<?php echo $m_cat['id']; ?>">
                                    <?php echo htmlspecialchars($m_cat['sort_order'] . '. ' . $m_cat['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ประเด็นย่อย (Sub Criteria Title)</label>
                        <input type="text" name="title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>คำอธิบายเพิ่มเติม / แนวทางการให้คะแนน</label>
                        <textarea name="description" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group">
                            <label>คะแนนเต็ม (Max Score)</label>
                            <input type="number" step="0.01" name="max_score" class="form-control" value="5" required>
                        </div>
                        <div class="col-md-6 form-group">
                             <label>กลุ่มเป้าหมาย (รับค่าจากหมวดหมู่หลัก)</label>
                             <select name="target_group" class="form-control">
                                <option value="both">ทั้งหมด / ตามหมวดหลัก</option>
                                <option value="staff">เฉพาะ เจ้าหน้าที่งาน</option>
                                <option value="teacher">เฉพาะ ครูพิเศษสอน</option>
                                <option value="gov_teacher">เฉพาะ พนักงานราชการสายการสอน</option>
                                <option value="general">เฉพาะ บุคลากรทั่วไป</option>
                             </select>
                        </div>
                        <div class="col-md-12 form-group mt-2">
                            <label>ลำดับแสดงผล (Sort Order)</label>
                            <input type="number" name="sort_order" class="form-control" required value="1">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-info">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="criteria.php" method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> แก้ไขหัวข้อประเมิน</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label>หมวดหมู่หลัก (Parent Category)</label>
                        <select name="parent_id" id="edit_parent" class="form-control">
                            <option value="">-- ตั้งเป็นหัวข้อหลัก --</option>
                            <?php foreach($main_categories as $m_cat): ?>
                                <option value="<?php echo $m_cat['id']; ?>">
                                    <?php echo htmlspecialchars($m_cat['sort_order'] . '. ' . $m_cat['title']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>หัวข้อ/ประเด็นการประเมิน (Criteria Title)</label>
                        <input type="text" name="title" id="edit_title" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>คำอธิบายเพิ่มเติม / แนวทางการให้คะแนน</label>
                        <textarea name="description" id="edit_desc" class="form-control" rows="3"></textarea>
                    </div>
                    <div class="row">
                        <div class="col-md-6 form-group" id="edit_max_score_group">
                            <label>คะแนนเต็ม (Max Score)</label>
                            <input type="number" step="0.01" name="max_score" id="edit_max" class="form-control">
                        </div>
                        <div class="col-md-6 form-group">
                            <label>กลุ่มเป้าหมาย (Target Group)</label>
                            <select name="target_group" id="edit_target" class="form-control" required>
                                <option value="both">ทั้งหมด (ทุกคน)</option>
                                <option value="staff">เฉพาะ เจ้าหน้าที่งาน</option>
                                <option value="teacher">เฉพาะ ครูพิเศษสอน</option>
                                <option value="gov_teacher">เฉพาะ พนักงานราชการสายการสอน</option>
                                <option value="general">เฉพาะ บุคลากรทั่วไป</option>
                            </select>
                        </div>
                        <div class="col-md-12 form-group mt-2">
                            <label>ลำดับแสดงผล (Sort Order)</label>
                            <input type="number" name="sort_order" id="edit_sort" class="form-control" required>
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
    $('.btn-edit').click(function() {
        var parentId = $(this).data('parent');
        $('#edit_id').val($(this).data('id'));
        $('#edit_parent').val(parentId);
        $('#edit_title').val($(this).data('title'));
        $('#edit_desc').val($(this).data('desc'));
        $('#edit_max').val($(this).data('max'));
        $('#edit_sort').val($(this).data('sort'));
        $('#edit_target').val($(this).data('targetgroup'));
        
        // Prevent setting a master category as its own child in the dropdown visually
        $('#edit_parent option').show();
        $('#edit_parent option[value="'+$(this).data('id')+'"]').hide();
    });

    $('.btn-delete').click(function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบหัวข้อประเมินนี้ใช่หรือไม่!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ใช่, ฉันต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'criteria.php?delete=' + id;
            }
        })
    });
});
</script>
