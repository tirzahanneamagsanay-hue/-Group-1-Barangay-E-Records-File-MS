<?php
// ============================================================
// setup.php — Run ONCE only, then DELETE
// Creates default secretary account
// ============================================================

require_once 'includes/db.php';

$conn = getConnection();

if (!$conn) {
    die("Database connection failed.");
}

// Default credentials (CHANGE BEFORE DEPLOYMENT)
$full_name = 'Barangay Secretary';
$username  = 'admin';
$password  = 'admin1234';
$role      = 'secretary';

// Hash password (bcrypt)
$hashed = password_hash($password, PASSWORD_BCRYPT);

// Start transaction for safety
$conn->begin_transaction();

try {

    // Check if user already exists
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $username);
    $check->execute();
    $check->store_result();

    if ($check->num_rows > 0) {
        // Update instead of duplicate insert
        $update = $conn->prepare("
            UPDATE users 
            SET full_name = ?, password = ?, role = ?, is_active = 1
            WHERE username = ?
        ");
        $update->bind_param("ssss", $full_name, $hashed, $role, $username);
        $update->execute();
        $update->close();

    } else {
        // Insert new user
        $insert = $conn->prepare("
            INSERT INTO users (full_name, username, password, role)
            VALUES (?, ?, ?, ?)
        ");
        $insert->bind_param("ssss", $full_name, $username, $hashed, $role);
        $insert->execute();
        $insert->close();
    }

    $check->close();

    // Commit changes
    $conn->commit();

    echo "<h2 style='font-family:sans-serif;color:green'>
        ✓ Secretary account ready!<br><br>
        Username: <b>$username</b><br>
        Password: <b>$password</b><br><br>
        <span style='color:red'>IMPORTANT: DELETE setup.php after use!</span>
    </h2>";

} catch (Exception $e) {
    $conn->rollback();
    echo "<h2 style='color:red'>Setup failed: " . $e->getMessage() . "</h2>";
}

$conn->close();
?>