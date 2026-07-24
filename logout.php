<?php
require_once 'config.php';
require_once 'vendor/autoload.php';
use Jumbojett\OpenIDConnectClient;

session_start();
$idToken = isset($_SESSION['id_token']) ? $_SESSION['id_token'] : null;

// Clear local session
session_unset();
session_destroy();

// Start a fresh session to hold the success message
session_start();
$_SESSION['success'] = "ออกจากระบบเรียบร้อยแล้ว";

$redirectUrl = BASE_URL . "/index.php";

if ($idToken) {
    try {
        $oidc = new OpenIDConnectClient(
            OIDC_PROVIDER_URL,
            OIDC_CLIENT_ID,
            OIDC_CLIENT_SECRET
        );
        $oidc->setVerifyHost(false);
        $oidc->setVerifyPeer(false);
        
        // This will redirect the user to Keycloak's logout page, which then redirects back to $redirectUrl
        $oidc->signOut($idToken, $redirectUrl);
        exit();
    } catch (Exception $e) {
        // Fallback if Keycloak signout fails
        header("Location: " . $redirectUrl);
        exit();
    }
} else {
    header("Location: " . $redirectUrl);
    exit();
}
?>
