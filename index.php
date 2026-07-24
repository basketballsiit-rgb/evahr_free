<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

// Start session if not already started in config
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure error and success messages are displayed from session
$error = $_SESSION['error'] ?? '';
$success = $_SESSION['success'] ?? '';
unset($_SESSION['error']);
unset($_SESSION['success']);

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') header("Location: " . BASE_URL . "/admin/index.php");
    elseif ($_SESSION['role'] === 'evaluator') header("Location: " . BASE_URL . "/evaluator/index.php");
    else header("Location: " . BASE_URL . "/staff/index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['login_local'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
    } else {
        $stmt = $pdo->prepare("SELECT id, role, password, firstname, lastname, profile_image FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['firstname'] = $user['firstname'];
            $_SESSION['lastname'] = $user['lastname'];
            $_SESSION['profile_image'] = $user['profile_image'];
            
            if ($user['role'] === 'admin') header("Location: " . BASE_URL . "/admin/index.php");
            elseif ($user['role'] === 'evaluator') header("Location: " . BASE_URL . "/evaluator/index.php");
            else header("Location: " . BASE_URL . "/staff/index.php");
            exit();
        } else {
            $error = "ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง";
        }
    }
}
?>
<?php include 'includes/header.php'; ?>

<style>
  body.login-page {
    background: #f4f6f9; /* Override if needed */
    padding: 0;
    margin: 0;
    display: block; /* Override flex from AdminLTE login-page */
  }
  .login-split-container {
    display: flex;
    min-height: 100vh;
    width: 100%;
  }
  .login-left-side {
    flex: 1;
    background: linear-gradient(135deg, #1abc9c, #16a085); /* Default mint-like */
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 2rem;
    color: white;
    text-align: center;
  }
  .login-right-side {
    flex: 1;
    display: flex;
    justify-content: center;
    align-items: center;
    background: #ffffff;
    padding: 2rem;
  }
  .login-form-wrapper {
    width: 100%;
    max-width: 450px;
  }
  
  @media (max-width: 768px) {
    .login-split-container {
      flex-direction: column;
    }
    .login-left-side {
      padding: 3rem 1rem;
    }
    .login-right-side {
      padding: 3rem 1rem;
    }
  }
</style>

<div class="login-split-container">
  <div class="login-left-side" <?php echo getSystemTheme() === 'default' ? 'style="background: linear-gradient(135deg, #ff6a88, #ffb7c5);"' : (getSystemTheme() === 'ocean' ? 'style="background: linear-gradient(135deg, #0b3d91, #1a90d9);"' : (getSystemTheme() === 'sunset' ? 'style="background: linear-gradient(135deg, #ff5e62, #ff9966);"' : (getSystemTheme() === 'forest' ? 'style="background: linear-gradient(135deg, #138808, #8db600);"' : (getSystemTheme() === 'midnight' ? 'style="background: linear-gradient(135deg, #4b0082, #8a2be2);"' : (getSystemTheme() === 'corporate' ? 'style="background: linear-gradient(135deg, #4a5568, #2b6cb0);"' : (getSystemTheme() === 'lavender' ? 'style="background: linear-gradient(135deg, #9b59b6, #dcd3ff);"' : (getSystemTheme() === 'peach' ? 'style="background: linear-gradient(135deg, #ff8c6b, #ffd3b6);"' : (getSystemTheme() === 'blue' ? 'style="background: linear-gradient(135deg, #07689f, #a2d5f2);"' : '')))))))); ?>>
    <img src="<?php echo getSystemLogo(); ?>" alt="Logo" class="img-circle elevation-3 bg-white p-2 mb-4" style="width: 150px; height: 150px; object-fit: contain; border: 4px solid rgba(255,255,255,0.8);">
    <h1 class="font-weight-bold" style="font-family: 'Kanit', sans-serif; font-size: 2.5rem; text-shadow: 2px 2px 4px rgba(0,0,0,0.2); line-height: 1.3;">
      ระบบประเมินบุคลากรทางการศึกษา
    </h1>
    <h2 class="mt-3 font-weight-bold" style="font-family: 'Sarabun', sans-serif; font-size: 2rem; opacity: 1; text-shadow: 1px 1px 3px rgba(0,0,0,0.3);">
      <?php echo htmlspecialchars(getSystemName()); ?>
    </h2>
  </div>

  <div class="login-right-side">
    <div class="login-form-wrapper">
      <div class="card shadow-none border-0 m-0">
        <div class="card-body p-0">
          <h4 class="mb-4 text-center font-weight-bold" style="color: #333; font-family: 'Kanit', sans-serif;">เข้าสู่ระบบจัดการข้อมูล</h4>
          
          <form action="index.php" method="post">
             <div class="form-group mb-4">
               <label for="username" class="text-muted" style="font-weight: 500;">ชื่อผู้ใช้ (Username)</label>
               <div class="input-group">
                 <div class="input-group-prepend">
                   <span class="input-group-text" style="background: transparent; border-right: none; border-radius: 10px 0 0 10px;"><i class="fas fa-user text-primary"></i></span>
                 </div>
                 <input type="text" name="username" id="username" class="form-control form-control-lg" required style="border-left: none; border-radius: 0 10px 10px 0; box-shadow: none;">
               </div>
             </div>
             
             <div class="form-group mb-5">
               <label for="password" class="text-muted" style="font-weight: 500;">รหัสผ่าน (Password)</label>
               <div class="input-group">
                 <div class="input-group-prepend">
                   <span class="input-group-text" style="background: transparent; border-right: none; border-radius: 10px 0 0 10px;"><i class="fas fa-lock text-primary"></i></span>
                 </div>
                 <input type="password" name="password" id="password" class="form-control form-control-lg" required style="border-left: none; border-radius: 0 10px 10px 0; box-shadow: none;">
               </div>
             </div>
             
             <button type="submit" name="login_local" class="btn btn-primary btn-block btn-lg font-weight-bold shadow" style="border-radius: 10px; transition: all 0.3s;">
                 <i class="fas fa-sign-in-alt mr-2"></i> เข้าสู่ระบบ
             </button>
          </form>
        </div>
      </div>
    </div>
  </div>
</div>

<?php include 'includes/footer.php'; ?>

<?php if ($error): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'error',
            title: 'ผิดพลาด',
            text: '<?php echo $error; ?>',
            confirmButtonColor: 'var(--primary-color)'
        });
    });
</script>
<?php endif; ?>

<?php if ($success): ?>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        Swal.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: '<?php echo $success; ?>',
            timer: 2000,
            showConfirmButton: false
        });
    });
</script>
<?php endif; ?>
