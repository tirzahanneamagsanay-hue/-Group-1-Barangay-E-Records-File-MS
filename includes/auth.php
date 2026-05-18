<?php
/**
 * Authentication Handler
 * Windows/XAMPP Compatible
 * 
 * FIXED: Consistent session variable naming (full_name, not fullname)
 */

// Start session safely (check if already started)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * Check if user is logged in
 */
function isLoggedIn() {
    return isset($_SESSION['user_id']) && isset($_SESSION['full_name']);
}

/**
 * Require login - redirect if not authenticated
 */
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php");
        exit();
    }
}

/**
 * Check if user has specific role
 */
function hasRole($required_role) {
    if (!isLoggedIn()) return false;
    
    $user_role = $_SESSION['role'] ?? null;
    
    // Single role check
    if (is_string($required_role)) {
        return $user_role === $required_role;
    }
    
    // Multiple roles allowed
    if (is_array($required_role)) {
        return in_array($user_role, $required_role);
    }
    
    return false;
}

/**
 * Require specific role
 */
function requireRole($required_role) {
    if (!hasRole($required_role)) {
        header("Location: dashboard.php");
        exit();
    }
}

/**
 * Logout and destroy session
 */
function logout() {
    // Clear all session variables
    $_SESSION = [];
    
    // Destroy session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy session
    session_destroy();
    
    // Redirect to login
    header("Location: login.php");
    exit();
}

/**
 * Get current user ID
 */
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

/**
 * Get current user full name
 */
function getCurrentUserName() {
    return $_SESSION['full_name'] ?? 'User';
}

/**
 * Get current user role
 */
function getCurrentUserRole() {
    return $_SESSION['role'] ?? null;
}
?>
