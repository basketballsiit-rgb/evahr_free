<?php
require_once 'config.php';
require_once 'vendor/autoload.php';

use Jumbojett\OpenIDConnectClient;

// Set Keycloak configuration
$oidc = new OpenIDConnectClient(
    OIDC_PROVIDER_URL,
    OIDC_CLIENT_ID,
    OIDC_CLIENT_SECRET
);

// Optional: If you don't have a valid SSL certificate for your Keycloak server
$oidc->setVerifyHost(false);
$oidc->setVerifyPeer(false);

// Must match EXACTLY what was sent in index.php
$oidc->setRedirectURL(BASE_URL . '/callback.php');

try {
    // Authenticate with Keycloak
    $oidc->authenticate();
    
    // Get user info and preferred username
    $userInfo = $oidc->requestUserInfo();
    $username = $userInfo->preferred_username;
    
    // Look up user in local database
    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        // User found, create local session
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['firstname'] = $user['firstname'];
        $_SESSION['lastname'] = $user['lastname'];
        $_SESSION['profile_image'] = $user['profile_image'];
        $_SESSION['success'] = "เข้าสู่ระบบด้วย Keycloak สำเร็จ";
        
        // Save OIDC token to allow logout from Keycloak later
        $_SESSION['id_token'] = $oidc->getIdToken();
        
        // Redirect to forced profile if details are missing (except for admins)
        if ($user['role'] !== 'admin' && (empty($user['staff_type']) || empty($user['department_id']))) {
            header("Location: " . BASE_URL . "/staff/forced_profile.php");
            exit();
        }
        
        // Redirect based on role
        if ($user['role'] === 'admin') {
            header("Location: " . BASE_URL . "/admin/index.php");
        } elseif ($user['role'] === 'evaluator') {
            header("Location: " . BASE_URL . "/evaluator/index.php");
        } else {
            header("Location: " . BASE_URL . "/staff/index.php");
        }
        exit();
    } else {
        // Auto-provisioning: User not found in local db, let's create them!
        
        // Extract names from Keycloak
        $firstname = isset($userInfo->given_name) ? $userInfo->given_name : $username;
        $lastname = isset($userInfo->family_name) ? $userInfo->family_name : 'Keycloak';
        
        // Generate a random impossible password since they use Keycloak
        $random_pass = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        
        // Insert new user
        $insert_stmt = $pdo->prepare("INSERT INTO users (firstname, lastname, username, password, role) VALUES (?, ?, ?, ?, 'staff')");
        if ($insert_stmt->execute([$firstname, $lastname, $username, $random_pass])) {
            $new_user_id = $pdo->lastInsertId();
            
            // Create local session for the new user
            $_SESSION['user_id'] = $new_user_id;
            $_SESSION['role'] = 'staff';
            $_SESSION['firstname'] = $firstname;
            $_SESSION['lastname'] = $lastname;
            $_SESSION['profile_image'] = null;
            $_SESSION['success'] = "สร้างบัญชีใหม่จาก Keycloak เรียบร้อยแล้ว โปรดติดต่อผู้ดูแลระบบเพื่อกำหนดแผนกและตำแหน่งของคุณ";
            
            $_SESSION['id_token'] = $oidc->getIdToken();
            
            // Redirect to forced profile to complete setup
            header("Location: " . BASE_URL . "/staff/forced_profile.php");
            exit();
        } else {
            $_SESSION['error'] = "ไม่สามารถสร้างบัญชีผู้ใช้ใหม่โดยอัตโนมัติได้ ($username)";
            header("Location: " . BASE_URL . "/index.php");
            exit();
        }
    }

} catch (Exception $e) {
    // Authentication failed
    $_SESSION['error'] = "การยืนยันตัวตนกับ Keycloak ล้มเหลว: " . $e->getMessage();
    header("Location: " . BASE_URL . "/index.php");
    exit();
}
