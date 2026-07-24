<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Job Management';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $department_id = $_POST['department_id'];
    $title = trim($_POST['title']);
    $description = trim($_POST['description']);
    
    // Auto-determine job level based on title
    $job_level = 4; // Default to staff
    if (strpos($title, 'ผู้อำนวยการ') !== false && strpos($title, 'รอง') === false) {
        $job_level = 1;
    } elseif (strpos($title, 'รองผู้อำนวยการ') !== false) {
        $job_level = 2;
    } elseif (strpos($title, 'หัวหน้า') !== false) {
        $job_level = 3;
    }

    if ($_POST['action'] == 'add') {
        try {
            $stmt = $pdo->prepare("INSERT INTO jobs (department_id, title, job_level, description) VALUES (?, ?, ?, ?)");
            $stmt->execute([$department_id, $title, $job_level, $description]);
            syncOpenRounds($pdo);
            $_SESSION['success'] = "เพิ่มตำแหน่งงานสำเร็จ";
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE jobs SET department_id = ?, title = ?, job_level = ?, description = ? WHERE id = ?");
            $stmt->execute([$department_id, $title, $job_level, $description, $id]);
            syncOpenRounds($pdo);
            $_SESSION['success'] = "แก้ไขตำแหน่งงานสำเร็จ";
        } catch(PDOException $e) {
            $_SESSION['error'] = "Error: " . $e->getMessage();
        }
    }
    header("Location: jobs.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ?");
        $stmt->execute([$id]);
        syncOpenRounds($pdo);
        $_SESSION['success'] = "ลบตำแหน่งงานสำเร็จ";
    } catch(PDOException $e) {
        $_SESSION['error'] = "ไม่สามารถลบได้ เนื่องจากอาจมีข้อมูลที่พึ่งพากันอยู่";
    }
    header("Location: jobs.php");
    exit();
}

// Fetch all jobs with department names
$jobs = $pdo->query("SELECT j.*, d.name as department_name FROM jobs j JOIN departments d ON j.department_id = d.id ORDER BY d.name ASC, j.job_level ASC, j.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch departments for dropdowns and grouping
$departments = $pdo->query("SELECT * FROM departments ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Group jobs by department id
$jobs_by_dept = [];
foreach ($departments as $d) {
    $jobs_by_dept[$d['id']] = [];
}
foreach ($jobs as $j) {
    if (isset($jobs_by_dept[$j['department_id']])) {
        $jobs_by_dept[$j['department_id']][] = $j;
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
                    <h1 class="m-0 text-dark"><i class="fas fa-briefcase text-primary mr-2"></i> จัดการงาน/ตำแหน่ง</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary btn-sm mt-2 font-weight-bold shadow-sm" data-toggle="modal" data-target="#addModal" data-deptid="">
                        <i class="fas fa-plus mr-1"></i> เพิ่มตำแหน่งงาน (ทั่วไป)
                    </button>
                </div>
            </div>
        </div>
    </div>

    <section class="content">
        <div class="container-fluid">
            <div class="alert alert-info shadow-sm mb-4 border-info">
                <i class="fas fa-info-circle mr-2"></i> ข้อมูลตำแหน่งและงานต่างๆ ถูกจัดกลุ่มตามฝ่าย/แผนก เพื่อให้ง่ายต่อการบริหารจัดการและเห็นภาพรวม
            </div>

            <div class="accordion" id="departmentAccordion">
                <?php foreach($departments as $i => $dept): ?>
                <div class="card card-outline card-primary shadow-sm mb-3" style="border-radius: 8px; overflow: hidden;">
                    <div class="card-header bg-white d-flex justify-content-between align-items-center" id="heading<?php echo $dept['id']; ?>">
                        <h2 class="mb-0 w-100">
                            <button class="btn btn-link btn-block text-left text-dark font-weight-bold d-flex flex-wrap align-items-center text-decoration-none" type="button" data-toggle="collapse" data-target="#collapse<?php echo $dept['id']; ?>" aria-expanded="true" aria-controls="collapse<?php echo $dept['id']; ?>" style="font-size: 1.15rem;">
                                <div class="flex-grow-1">
                                    <i class="fas fa-building text-primary mr-2"></i> <?php echo htmlspecialchars($dept['name']); ?>
                                </div>
                                <span class="badge badge-primary badge-pill ml-2 px-3 align-self-center"><?php echo count($jobs_by_dept[$dept['id']]); ?> ตำแหน่ง</span>
                                <i class="fas fa-chevron-down ml-3 text-muted" style="transition: transform 0.3s; font-size: 0.9rem;"></i>
                            </button>
                        </h2>
                    </div>

                    <div id="collapse<?php echo $dept['id']; ?>" class="collapse <?php echo $i === 0 ? 'show' : ''; ?>" aria-labelledby="heading<?php echo $dept['id']; ?>" data-parent="#departmentAccordion">
                        <div class="card-body bg-light p-3">
                            <div class="mb-3 text-right">
                                <button class="btn btn-success btn-sm font-weight-bold shadow-sm" data-toggle="modal" data-target="#addModal" data-deptid="<?php echo $dept['id']; ?>">
                                    <i class="fas fa-plus-circle mr-1"></i> เพิ่มตำแหน่งงานในฝ่ายนี้
                                </button>
                            </div>
                            
                            <?php if(count($jobs_by_dept[$dept['id']]) > 0): ?>
                            <div class="table-responsive bg-white rounded shadow-sm border">
                                <table class="table table-hover table-striped mb-0 dept-job-table">
                                    <thead>
                                        <tr class="bg-primary text-white">
                                            <th width="10%" class="text-center font-weight-normal border-0 text-sm">Level</th>
                                            <th class="font-weight-normal border-0 text-sm">ชื่อตำแหน่ง/งาน</th>
                                            <th class="font-weight-normal border-0 text-sm">รายละเอียด</th>
                                            <th width="15%" class="text-center font-weight-normal border-0 text-sm">จัดการ</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach($jobs_by_dept[$dept['id']] as $job): ?>
                                        <tr>
                                            <td class="text-center align-middle">
                                                <span class="badge badge-dark px-2 py-1" style="font-size: 0.9rem;">ระดับ <?php echo htmlspecialchars($job['job_level']); ?></span>
                                            </td>
                                            <td class="align-middle font-weight-bold text-dark">
                                                <?php echo htmlspecialchars($job['title']); ?>
                                            </td>
                                            <td class="align-middle text-muted text-sm">
                                                <?php echo $job['description'] ? htmlspecialchars($job['description']) : '<i>ไม่มีคำอธิบาย</i>'; ?>
                                            </td>
                                            <td class="text-center align-middle">
                                                <button class="btn btn-outline-warning btn-sm btn-edit shadow-sm mb-1" style="width: 38px;"
                                                        data-id="<?php echo $job['id']; ?>" 
                                                        data-department_id="<?php echo $job['department_id']; ?>"
                                                        data-title="<?php echo htmlspecialchars($job['title']); ?>"
                                                        data-description="<?php echo htmlspecialchars($job['description']); ?>"
                                                        title="แก้ไขตำแหน่ง"
                                                        data-toggle="modal" data-target="#editModal">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <button class="btn btn-outline-danger btn-sm btn-delete shadow-sm mb-1" style="width: 38px;" title="ลบตำแหน่ง" data-id="<?php echo $job['id']; ?>">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <?php else: ?>
                            <div class="text-center py-4 bg-white rounded shadow-sm border text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 text-light"></i><br>
                                ยังไม่มีตำแหน่งงานในฝ่ายนี้
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
        </div>
    </section>
</div>

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form action="jobs.php" method="POST">
                <div class="modal-header bg-primary text-white border-0">
                    <h5 class="modal-title font-weight-bold"><i class="fas fa-plus-circle mr-2"></i> เพิ่มตำแหน่งงานใหม่</h5>
                    <button type="button" class="close text-white opacity-75" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body bg-light">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body p-3">
                            <div class="form-group mb-0">
                                <label class="text-dark font-weight-bold">สังกัดฝ่าย/แผนก</label>
                                <select name="department_id" id="add_department_id" class="form-control" style="border: 2px solid #e9ecef;" required>
                                    <option value="">-- เลือกฝ่าย/แผนก --</option>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-3">
                            <div class="form-group">
                                <label class="text-dark font-weight-bold">ชื่อตำแหน่ง/งาน <span class="text-danger">*</span></label>
                                <input type="text" name="title" class="form-control" style="border: 2px solid #e9ecef;" required placeholder="ระบุตำแหน่ง เช่น หัวหน้างานวิชาการ, เจ้าหน้าที่การเงิน">
                            </div>
                            <div class="form-group mb-0">
                                <label class="text-dark font-weight-bold">รายละเอียด/หน้าที่ (ออปชั่น)</label>
                                <textarea name="description" class="form-control" style="border: 2px solid #e9ecef;" rows="2" placeholder="อธิบายหน้าที่ความรับผิดชอบคร่าวๆ..."></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0">
                    <button type="button" class="btn btn-light shadow-sm" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary shadow-sm px-4"><i class="fas fa-save mr-1"></i> บันทึกข้อมูล</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal fade" id="editModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content border-0 shadow-lg">
            <form action="jobs.php" method="POST">
                <div class="modal-header bg-warning border-0">
                    <h5 class="modal-title font-weight-bold text-dark"><i class="fas fa-edit mr-2"></i> แก้ไขตำแหน่งงาน</h5>
                    <button type="button" class="close opacity-75" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body bg-light">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    
                    <div class="card shadow-sm border-0 mb-3">
                        <div class="card-body p-3">
                            <div class="form-group mb-0">
                                <label class="text-dark font-weight-bold">สังกัดฝ่าย/แผนก</label>
                                <select name="department_id" id="edit_department_id" class="form-control" style="border: 2px solid #e9ecef;" required>
                                    <?php foreach($departments as $dept): ?>
                                        <option value="<?php echo $dept['id']; ?>"><?php echo htmlspecialchars($dept['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="card shadow-sm border-0">
                        <div class="card-body p-3">
                            <div class="form-group">
                                <label class="text-dark font-weight-bold">ชื่อตำแหน่ง/งาน <span class="text-danger">*</span></label>
                                <input type="text" name="title" id="edit_title" class="form-control" style="border: 2px solid #e9ecef;" required>
                            </div>
                            <div class="form-group mb-0">
                                <label class="text-dark font-weight-bold">รายละเอียด/หน้าที่</label>
                                <textarea name="description" id="edit_description" class="form-control" style="border: 2px solid #e9ecef;" rows="2"></textarea>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-white border-top-0">
                    <button type="button" class="btn btn-light shadow-sm" data-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning text-dark font-weight-bold shadow-sm px-4"><i class="fas fa-save mr-1"></i> บันทึกการเปลี่ยนแปลง</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    // Initialize standard DataTables for the nested tables securely if they get lengthy
    // For now, since they are nested, native responsive layout is often sufficient, 
    // but if requested we can render them as tiny tables
    $('.dept-job-table').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        },
        "paging": false,
        "lengthChange": false,
        "searching": false,
        "info": false,
        "columnDefs": [
            { "orderable": false, "targets": 3 }
        ],
        "order": [[ 0, "asc" ]] // Order by Level by default within department
    });
    
    // Add chevron rotation on collapse
    $('.collapse').on('show.bs.collapse', function () {
        $(this).parent().find('.fa-chevron-down').css('transform', 'rotate(180deg)');
    }).on('hide.bs.collapse', function () {
        $(this).parent().find('.fa-chevron-down').css('transform', 'rotate(0deg)');
    });
    
    // Set default chevron states based on 'show' class
    $('.collapse.show').parent().find('.fa-chevron-down').css('transform', 'rotate(180deg)');

    // Pre-fill department on Add Mode if specific button clicked
    $('#addModal').on('show.bs.modal', function (event) {
        var button = $(event.relatedTarget);
        var deptId = button.data('deptid');
        if (deptId) {
            $('#add_department_id').val(deptId);
        } else {
            $('#add_department_id').val('');
        }
    });

    $('.btn-edit').click(function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_department_id').val($(this).data('department_id'));
        $('#edit_title').val($(this).data('title'));
        $('#edit_description').val($(this).data('description'));
    });

    $('.btn-delete').click(function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบข้อมูลนี้ใช่หรือไม่!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ใช่, ฉันต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'jobs.php?delete=' + id;
            }
        })
    });
});
</script>
