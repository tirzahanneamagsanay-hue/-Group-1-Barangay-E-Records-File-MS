<?php
/**
 * Database Connection Handler
 * Windows/XAMPP Compatible
 */

function getConnection() {
    // XAMPP default credentials
    $host     = 'localhost';
    $username = 'root';
    $password = 'root';
    $database = 'barangay_db';
    
    $conn = new mysqli($host, $username, $password, $database);
    
    // Check connection
    if ($conn->connect_error) {
        error_log("Database Connection Error: " . $conn->connect_error);
        die("Database connection failed. Please check your database configuration.");
    }
    
    // Set charset to UTF-8
    $conn->set_charset("utf8mb4");
    
    return $conn;
}

// Test connection on first load (optional)
// Uncomment to debug connection issues
/*
$test = getConnection();
echo "✓ Database connected successfully";
$test->close();
*/
?>
