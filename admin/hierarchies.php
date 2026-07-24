<?php
require_once '../config.php';
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'Unauthorized access';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'Evaluation Hierarchies';

// Handle Add/Edit
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    $evaluatee_job_id = (int)$_POST['evaluatee_job_id'];
    $evaluator_job_id = (int)$_POST['evaluator_job_id'];
    $level = (int)$_POST['level'];
    
    if($evaluatee_job_id === $evaluator_job_id) {
        $_SESSION['error'] = "ไม่สามารถตั้งให้ตำแหน่งประเมินตนเองได้";
        header("Location: hierarchies.php");
        exit();
    }

    if ($_POST['action'] == 'add') {
        try {
            $stmt = $pdo->prepare("INSERT INTO evaluation_hierarchies (evaluatee_job_id, evaluator_job_id, level) VALUES (?, ?, ?)");
            $stmt->execute([$evaluatee_job_id, $evaluator_job_id, $level]);
            $_SESSION['success'] = "กำหนดสายประเมินสำเร็จ";
        } catch(PDOException $e) {
            // Error 1062 is duplicate entry
            if($e->getCode() == 23000) {
                $_SESSION['error'] = "สายการประเมินนี้ถูกกำหนดไว้แล้ว";
            } else {
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
    } elseif ($_POST['action'] == 'edit') {
        $id = $_POST['id'];
        try {
            $stmt = $pdo->prepare("UPDATE evaluation_hierarchies SET evaluatee_job_id = ?, evaluator_job_id = ?, level = ? WHERE id = ?");
            $stmt->execute([$evaluatee_job_id, $evaluator_job_id, $level, $id]);
            $_SESSION['success'] = "แก้ไขสายประเมินสำเร็จ";
        } catch(PDOException $e) {
            if($e->getCode() == 23000) {
                $_SESSION['error'] = "บันทึกซ้ำซ้อน ไม่สามารถแก้ไขเป็นค่านี้ได้";
            } else {
                $_SESSION['error'] = "Error: " . $e->getMessage();
            }
        }
    }
    header("Location: hierarchies.php");
    exit();
}

// Handle Delete
if (isset($_GET['delete'])) {
    $id = $_GET['delete'];
    try {
        $stmt = $pdo->prepare("DELETE FROM evaluation_hierarchies WHERE id = ?");
        $stmt->execute([$id]);
        $_SESSION['success'] = "ลบสายประเมินสำเร็จ";
    } catch(PDOException $e) {
        $_SESSION['error'] = "Error: " . $e->getMessage();
    }
    header("Location: hierarchies.php");
    exit();
}

// Fetch Hierarchies by Job
$hierarchies = $pdo->query("SELECT h.*, 
                            j1.title as e1_title, j1.job_level as e1_lvl, 
                            j2.title as e2_title, j2.job_level as e2_lvl 
                            FROM evaluation_hierarchies h 
                            JOIN jobs j1 ON h.evaluatee_job_id = j1.id 
                            JOIN jobs j2 ON h.evaluator_job_id = j2.id 
                            ORDER BY h.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch Job Candidates
$all_jobs = $pdo->query("SELECT * FROM jobs ORDER BY job_level ASC, title ASC")->fetchAll(PDO::FETCH_ASSOC);

include '../includes/header.php';
include '../includes/sidebar.php';
?>

<div class="content-wrapper">
    <div class="content-header">
        <div class="container-fluid">
            <div class="row mb-2">
                <div class="col-sm-6">
                    <h1 class="m-0 text-dark"><i class="fas fa-sitemap text-primary mr-2"></i> จัดสายการประเมิน</h1>
                </div>
                <div class="col-sm-6 text-right">
                    <button class="btn btn-primary btn-sm mt-2" data-toggle="modal" data-target="#addModal">
                        <i class="fas fa-plus"></i> เพิ่มสายการประเมิน
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
                        <i class="fas fa-info-circle"></i> <b>การรับการประเมิน:</b> สามารถตั้งค่าได้ว่าใคร (ผู้รับการประเมิน) จะต้องถูกประเมินโดยใคร (ประธาน/กรรมการ)
                    </div>
                    <table class="table table-bordered table-striped" id="hTable">
                        <thead>
                            <tr class="bg-primary text-white">
                                <th width="5%">ลำดับ</th>
                                <th>รหัสตำแหน่ง (รับการประเมิน)</th>
                                <th>รหัสตำแหน่ง (ผู้ประเมิน)</th>
                                <th>ระดับ (Level)</th>
                                <th width="15%">จัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($hierarchies as $i => $h): ?>
                            <tr>
                                <td class="text-center"><?php echo $i + 1; ?></td>
                                <td><?php echo htmlspecialchars($h['e1_title']) . " (ระดับ " . htmlspecialchars($h['e1_lvl'] ?? '-') . ")"; ?></td>
                                <td><?php echo htmlspecialchars($h['e2_title']) . " (ระดับ " . htmlspecialchars($h['e2_lvl'] ?? '-') . ")"; ?></td>
                                <td>ระดับ <?php echo htmlspecialchars($h['level']); ?></td>
                                <td class="text-center">
                                    <button class="btn btn-warning btn-sm btn-edit" 
                                            data-id="<?php echo $h['id']; ?>" 
                                            data-ee="<?php echo $h['evaluatee_job_id']; ?>"
                                            data-er="<?php echo $h['evaluator_job_id']; ?>"
                                            data-lvl="<?php echo $h['level']; ?>"
                                            data-toggle="modal" data-target="#editModal">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-danger btn-sm btn-delete" data-id="<?php echo $h['id']; ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
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

<!-- Add Modal -->
<div class="modal fade" id="addModal" tabindex="-1" role="dialog" aria-hidden="true">
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="hierarchies.php" method="POST">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="fas fa-plus"></i> เพิ่มสายการประเมิน</h5>
                    <button type="button" class="close text-white" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="add">
                    <div class="form-group">
                        <label>ตำแหน่งที่รับการประเมิน (Evaluatee Job)</label>
                        <select name="evaluatee_job_id" class="form-control" required style="width:100%;">
                            <option value="">-- เลือกตำแหน่ง --</option>
                            <?php foreach($all_jobs as $j): ?>
                                <option value="<?php echo $j['id']; ?>">
                                    [ระดับ <?php echo htmlspecialchars($j['job_level'] ?? '-'); ?>] 
                                    <?php echo htmlspecialchars($j['title']); ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ตำแหน่งผู้ประเมิน (Evaluator Job)</label>
                        <select name="evaluator_job_id" class="form-control" required style="width:100%;">
                            <option value="">-- เลือกตำแหน่งผู้ประเมิน --</option>
                            <?php foreach($all_jobs as $j): ?>
                                <option value="<?php echo $j['id']; ?>">
                                    [ระดับ <?php echo htmlspecialchars($j['job_level'] ?? '-'); ?>] 
                                    <?php echo htmlspecialchars($j['title']); ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ระดับ (Level)</label>
                        <input type="number" name="level" class="form-control" value="1" required min="1" max="5">
                        <small class="text-muted">ใช้สำหรับกรณีมีผู้ประเมินหลายขั้นตามลำดับ</small>
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
    <div class="modal-dialog" role="document">
        <div class="modal-content">
            <form action="hierarchies.php" method="POST">
                <div class="modal-header bg-warning">
                    <h5 class="modal-title"><i class="fas fa-edit"></i> แก้ไขสายการประเมิน</h5>
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close">
                        <span aria-hidden="true">&times;</span>
                    </button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="form-group">
                        <label>ตำแหน่งที่รับการประเมิน (Evaluatee Job)</label>
                        <select name="evaluatee_job_id" id="edit_ee" class="form-control" required style="width:100%;">
                            <option value="">-- เลือกตำแหน่ง --</option>
                            <?php foreach($all_jobs as $j): ?>
                                <option value="<?php echo $j['id']; ?>">
                                    [ระดับ <?php echo htmlspecialchars($j['job_level'] ?? '-'); ?>] 
                                    <?php echo htmlspecialchars($j['title']); ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ตำแหน่งผู้ประเมิน (Evaluator Job)</label>
                        <select name="evaluator_job_id" id="edit_er" class="form-control" required style="width:100%;">
                            <option value="">-- เลือกตำแหน่ง --</option>
                            <?php foreach($all_jobs as $j): ?>
                                <option value="<?php echo $j['id']; ?>">
                                    [ระดับ <?php echo htmlspecialchars($j['job_level'] ?? '-'); ?>] 
                                    <?php echo htmlspecialchars($j['title']); ?> 
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>ระดับ (Level)</label>
                        <input type="number" name="level" id="edit_lvl" class="form-control" required min="1" max="5">
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
    $('#hTable').DataTable({
        "language": {
            "url": "//cdn.datatables.net/plug-ins/1.13.6/i18n/th.json"
        }
    });

    $('.btn-edit').click(function() {
        $('#edit_id').val($(this).data('id'));
        $('#edit_ee').val($(this).data('ee'));
        $('#edit_er').val($(this).data('er'));
        $('#edit_lvl').val($(this).data('lvl'));
    });

    $('.btn-delete').click(function() {
        var id = $(this).data('id');
        Swal.fire({
            title: 'ยืนยันการลบ?',
            text: "คุณต้องการลบสายการประเมินนี้ใช่หรือไม่!",
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#d33',
            cancelButtonColor: '#3085d6',
            confirmButtonText: 'ใช่, ฉันต้องการลบ',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed) {
                window.location.href = 'hierarchies.php?delete=' + id;
            }
        })
    });
});
</script>
