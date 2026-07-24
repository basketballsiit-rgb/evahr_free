<?php
require_once '../config.php';
// Auth Check
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    $_SESSION['error'] = 'กรุณาเข้าสู่ระบบในฐานะผู้ดูแลระบบ';
    header("Location: " . BASE_URL . "/index.php");
    exit();
}

$page_title = 'System Settings';

// Handle Setting Updates
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    if ($_POST['action'] == 'update_settings') {
        $success_msg = [];
        
        // Handle System Name
        if (isset($_POST['system_name']) && trim($_POST['system_name']) !== '') {
            $sys_name = trim($_POST['system_name']);
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_name', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$sys_name, $sys_name]);
            $success_msg[] = 'อัปเดตชื่อสถานศึกษาเรียบร้อยแล้ว';
        }

        // Handle Logo Upload
        if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../assets/img/';
            $fileName = 'custom_logo_' . time() . '.' . pathinfo($_FILES['logo_upload']['name'], PATHINFO_EXTENSION);
            $uploadFile = $uploadDir . $fileName;

            if (move_uploaded_file($_FILES['logo_upload']['tmp_name'], $uploadFile)) {
                // Save settings to database
                $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_logo', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $stmt->execute([$fileName, $fileName]);
                
                $success_msg[] = 'อัปเดตโลโก้ระบบเรียบร้อยแล้ว';
            } else {
                $_SESSION['error'] = 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์';
            }
        }
        
        if (!empty($success_msg)) {
            $_SESSION['success'] = implode('<br>', $success_msg);
        } elseif (!isset($_SESSION['error'])) {
            $_SESSION['success'] = 'ไม่มีการเปลี่ยนแปลงข้อมูล';
        }
    }
    
    if ($_POST['action'] == 'update_theme') {
        if (isset($_POST['system_theme']) && !empty($_POST['system_theme'])) {
            $selected_theme = $_POST['system_theme'];
            $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES ('system_theme', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$selected_theme, $selected_theme]);
            
            $_SESSION['success'] = 'อัปเดตธีมระบบเรียบร้อยแล้ว';
        }
    }
    
    header("Location: settings.php");
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
                    <h1 class="m-0 text-dark">ตั้งค่าระบบ (System Settings)</h1>
                </div>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <section class="content">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6">
                    <div class="card card-primary card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title">ตั้งค่าทั่วไปและโลโก้ (General & Logo)</h3>
                        </div>
                        <form action="settings.php" method="POST" enctype="multipart/form-data">
                            <input type="hidden" name="action" value="update_settings">
                            <div class="card-body text-center">
                                <div class="form-group text-left">
                                    <label for="system_name">ชื่อสถานศึกษา (School Name)</label>
                                    <input type="text" class="form-control" id="system_name" name="system_name" value="<?php echo htmlspecialchars(getSystemName()); ?>" placeholder="เช่น วิทยาลัยสารพัดช่างน่าน">
                                </div>
                                <hr>
                                <div class="mb-4 mt-3">
                                    <p class="text-muted">โลโก้ปัจจุบัน:</p>
                                    <img src="<?php echo getSystemLogo(); ?>" alt="Current Logo" class="img-thumbnail" style="max-height: 150px; object-fit: contain;">
                                </div>
                                
                                <div class="form-group text-left">
                                    <label for="logo_upload">อัปโหลดโลโก้ใหม่ (PNG, JPG กว้าง x ยาว สัดส่วนเท่ากัน)</label>
                                    <div class="custom-file">
                                        <input type="file" class="custom-file-input" id="logo_upload" name="logo_upload" accept="image/png, image/jpeg, image/jpg">
                                        <label class="custom-file-label" for="logo_upload">เลือกไฟล์...</label>
                                    </div>
                                    <small class="form-text text-muted">หากไม่ต้องการเปลี่ยนโลโก้ ให้เว้นว่างไว้</small>
                                </div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> บันทึกการเปลี่ยนแปลง</button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card card-success card-outline shadow">
                        <div class="card-header">
                            <h3 class="card-title">ตั้งค่าธีมสีระบบ (System Theme)</h3>
                        </div>
                        <form action="settings.php" method="POST">
                            <input type="hidden" name="action" value="update_theme">
                            <div class="card-body">
                                <p class="text-muted mb-4">ธีมที่เลือกจะถูกนำไปใช้งานกับทุกคนในระบบ (Global Theme)</p>
                                
                                <?php $current_theme = getSystemTheme(); ?>
                                
                                <style>
                                    .theme-selector-label {
                                        cursor: pointer;
                                        display: flex;
                                        align-items: center;
                                        padding: 10px;
                                        border: 2px solid transparent;
                                        border-radius: 8px;
                                        transition: all 0.3s;
                                    }
                                    .theme-selector-label:hover {
                                        background-color: rgba(0,0,0,0.05);
                                    }
                                    .theme-selector-input:checked + .theme-selector-label {
                                        border-color: #28a745;
                                        background-color: rgba(40,167,69,0.1);
                                    }
                                    .theme-color-preview {
                                        width: 40px;
                                        height: 40px;
                                        border-radius: 50%;
                                        margin-right: 15px;
                                        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
                                    }
                                </style>

                                <div class="row">
                                    <div class="col-sm-6 mb-2">
                                        <b class="text-muted d-block mb-3">โทนสีพาสเทลยอดนิยม</b>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_default" value="default" class="d-none theme-selector-input" <?php echo $current_theme == 'default' ? 'checked' : ''; ?>>
                                            <label for="theme_default" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #ff6a88 0%, #ffb7c5 100%);"></div>
                                                <span>Sakura (ชมพู)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_mint" value="mint" class="d-none theme-selector-input" <?php echo $current_theme == 'mint' ? 'checked' : ''; ?>>
                                            <label for="theme_mint" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #1abc9c 0%, #a8e6cf 100%);"></div>
                                                <span>Mint Breeze (เขียว)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_lavender" value="lavender" class="d-none theme-selector-input" <?php echo $current_theme == 'lavender' ? 'checked' : ''; ?>>
                                            <label for="theme_lavender" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #9b59b6 0%, #dcd3ff 100%);"></div>
                                                <span>Lavender Dream (ม่วง)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_peach" value="peach" class="d-none theme-selector-input" <?php echo $current_theme == 'peach' ? 'checked' : ''; ?>>
                                            <label for="theme_peach" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #ff8c6b 0%, #ffd3b6 100%);"></div>
                                                <span>Peach Sunrise (ส้ม)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_blue" value="blue" class="d-none theme-selector-input" <?php echo $current_theme == 'blue' ? 'checked' : ''; ?>>
                                            <label for="theme_blue" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #07689f 0%, #a2d5f2 100%);"></div>
                                                <span>Baby Blue (ฟ้า)</span>
                                            </label>
                                        </div>
                                    </div>
                                    
                                    <div class="col-sm-6 mb-2">
                                        <b class="text-muted d-block mb-3">โทนสีระดับองค์กร (ใหม่)</b>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_ocean" value="ocean" class="d-none theme-selector-input" <?php echo $current_theme == 'ocean' ? 'checked' : ''; ?>>
                                            <label for="theme_ocean" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #0b3d91 0%, #1a90d9 100%);"></div>
                                                <span>Ocean Depths (น้ำเงิน)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_sunset" value="sunset" class="d-none theme-selector-input" <?php echo $current_theme == 'sunset' ? 'checked' : ''; ?>>
                                            <label for="theme_sunset" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #ff5e62 0%, #ff9966 100%);"></div>
                                                <span>Sunset Glow (แดงส้ม)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_forest" value="forest" class="d-none theme-selector-input" <?php echo $current_theme == 'forest' ? 'checked' : ''; ?>>
                                            <label for="theme_forest" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #138808 0%, #8db600 100%);"></div>
                                                <span>Forest Canopy (เขียวตอง)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_midnight" value="midnight" class="d-none theme-selector-input" <?php echo $current_theme == 'midnight' ? 'checked' : ''; ?>>
                                            <label for="theme_midnight" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #4b0082 0%, #8a2be2 100%);"></div>
                                                <span>Midnight (ม่วงเข้ม)</span>
                                            </label>
                                        </div>
                                        <div class="form-group mb-0">
                                            <input type="radio" name="system_theme" id="theme_corporate" value="corporate" class="d-none theme-selector-input" <?php echo $current_theme == 'corporate' ? 'checked' : ''; ?>>
                                            <label for="theme_corporate" class="theme-selector-label">
                                                <div class="theme-color-preview" style="background: linear-gradient(135deg, #4a5568 0%, #2b6cb0 100%);"></div>
                                                <span>Corporate Slate (เทา-กรม)</span>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div class="card-footer text-right">
                                <button type="submit" class="btn btn-success"><i class="fas fa-magic"></i> ตั้งค่าระบบเป็นธีมนี้</button>
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
    // Show selected file name
    document.querySelector('.custom-file-input').addEventListener('change', function(e) {
        var fileName = document.getElementById("logo_upload").files[0].name;
        var nextSibling = e.target.nextElementSibling;
        nextSibling.innerText = fileName;
    });
</script>
