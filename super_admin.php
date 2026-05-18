<?php
require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

// Only super admins can access
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'superadmin') {
    header('Location: dashboard.php');
    exit;
}

function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
}

function timeAgo($dt) {
    if (!$dt) return 'N/A';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return round($diff / 60) . 'm ago';
    if ($diff < 86400) return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('M j, Y', strtotime($dt));
}

function logActivity($conn, $action, $details) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iss', $user_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}

// ── Load all settings from DB into an associative array ──
function getSettings($conn): array {
    $result = $conn->query("SELECT setting_key, setting_value FROM system_settings");
    $settings = [];
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }
    return $settings;
}

$page = $_GET['page'] ?? 'dashboard';
$valid_pages = ['dashboard', 'users', 'analytics', 'settings'];
if (!in_array($page, $valid_pages)) $page = 'dashboard';

// ─── API: POST actions ────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    $action = $_POST['action'];

    // ADD USER
    if ($action === 'add') {
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'user';
        $status   = $_POST['status'] ?? 'active';

        if (!$username || !$email || !$password) {
            echo json_encode(['ok' => false, 'msg' => 'Username, email and password are required.']);
            $conn->close(); exit;
        }

        $check = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND (deleted_at IS NULL)");
        $check->bind_param('ss', $email, $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['ok' => false, 'msg' => 'Username or email already exists.']);
            $conn->close(); exit;
        }
        $check->close();

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, role, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())");
        $stmt->bind_param('sssss', $username, $email, $hash, $role, $status);
        $ok = $stmt->execute();
        if ($ok) logActivity($conn, 'USER_CREATED', "Created user: $username ($email) as $role");
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'User added successfully.' : 'Database error: ' . $stmt->error]);
        $stmt->close(); $conn->close(); exit;
    }

    // EDIT USER
    if ($action === 'edit') {
        $id       = (int)($_POST['id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'user';
        $status   = $_POST['status'] ?? 'active';

        if ($id <= 0 || !$username || !$email) {
            echo json_encode(['ok' => false, 'msg' => 'Required fields missing.']);
            $conn->close(); exit;
        }
        if ($id == $_SESSION['user_id'] && $role !== $_SESSION['role']) {
            echo json_encode(['ok' => false, 'msg' => 'You cannot change your own role.']);
            $conn->close(); exit;
        }

        $check = $conn->prepare("SELECT id FROM users WHERE (email = ? OR username = ?) AND id != ? AND (deleted_at IS NULL)");
        $check->bind_param('ssi', $email, $username, $id);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            echo json_encode(['ok' => false, 'msg' => 'Another user with that username or email already exists.']);
            $conn->close(); exit;
        }
        $check->close();

        if (!empty($password)) {
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, password=?, role=?, status=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param('sssssi', $username, $email, $hash, $role, $status, $id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET username=?, email=?, role=?, status=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param('ssssi', $username, $email, $role, $status, $id);
        }
        $ok = $stmt->execute();
        if ($ok) logActivity($conn, 'USER_UPDATED', "Updated user ID $id: $username ($email) - Role: $role, Status: $status");
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'User updated successfully.' : 'Update failed: ' . $stmt->error]);
        $stmt->close(); $conn->close(); exit;
    }

    // SOFT DELETE USER
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Invalid user ID.']); $conn->close(); exit; }
        if ($id == $_SESSION['user_id']) { echo json_encode(['ok' => false, 'msg' => 'You cannot delete your own account.']); $conn->close(); exit; }

        $uStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
        $uStmt->bind_param('i', $id);
        $uStmt->execute();
        $uData = $uStmt->get_result()->fetch_assoc();
        $uStmt->close();

        $stmt = $conn->prepare("UPDATE users SET deleted_at = NOW(), status = 'inactive', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        if ($ok) logActivity($conn, 'USER_SOFT_DELETED', "Soft-deleted user ID $id: " . ($uData['username'] ?? 'Unknown'));
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'User removed successfully.' : 'Delete failed: ' . $stmt->error]);
        $stmt->close(); $conn->close(); exit;
    }

    // RESTORE USER
    if ($action === 'restore') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Invalid user ID.']); $conn->close(); exit; }

        $stmt = $conn->prepare("UPDATE users SET deleted_at = NULL, status = 'active', updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        if ($ok) logActivity($conn, 'USER_RESTORED', "Restored user ID $id");
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'User restored successfully.' : 'Restore failed: ' . $stmt->error]);
        $stmt->close(); $conn->close(); exit;
    }

    // TOGGLE STATUS
    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Invalid user ID.']); $conn->close(); exit; }
        if ($id == $_SESSION['user_id']) { echo json_encode(['ok' => false, 'msg' => 'You cannot change your own status.']); $conn->close(); exit; }

        $cur = $conn->prepare("SELECT status, username FROM users WHERE id = ?");
        $cur->bind_param('i', $id);
        $cur->execute();
        $row = $cur->get_result()->fetch_assoc();
        $cur->close();
        if (!$row) { echo json_encode(['ok' => false, 'msg' => 'User not found.']); $conn->close(); exit; }

        $current   = $row['status'];
        $newStatus = ($current === 'active') ? 'inactive' : 'active';
        $stmt      = $conn->prepare("UPDATE users SET status=?, updated_at=NOW() WHERE id=?");
        $stmt->bind_param('si', $newStatus, $id);
        $ok = $stmt->execute();
        if ($ok) logActivity($conn, 'STATUS_CHANGED', "Changed {$row['username']} status: $current → $newStatus");
        echo json_encode(['ok' => $ok, 'newStatus' => $newStatus, 'msg' => $ok ? "Status set to $newStatus." : 'Update failed.']);
        $stmt->close(); $conn->close(); exit;
    }

    // GET SINGLE USER
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT id, username, email, role, status FROM users WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $conn->close();
        echo json_encode(['ok' => (bool)$user, 'data' => $user]);
        exit;
    }

    // SAVE SETTINGS — persists all settings to system_settings table
    if ($action === 'save_settings') {
        $allowed_fields = [
            'barangay_name', 'city', 'contact_email', 'contact_phone',
            'max_users_per_admin', 'session_timeout',
            'allow_self_registration', 'require_email_verification', 'activity_logging',
            'password_min_length', 'require_uppercase', 'require_numbers', 'two_factor_auth',
            'notify_new_user', 'notify_user_banned', 'weekly_summary', 'notification_email'
        ];

        $stmt = $conn->prepare(
            "INSERT INTO system_settings (setting_key, setting_value)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()"
        );

        if (!$stmt) {
            echo json_encode(['ok' => false, 'msg' => 'DB error: ' . $conn->error]);
            $conn->close(); exit;
        }

        foreach ($allowed_fields as $key) {
            // Checkboxes not submitted = unchecked = '0'
            $value = $_POST[$key] ?? '0';
            // Sanitize: strip tags for text fields
            $value = strip_tags(trim($value));
            $stmt->bind_param('ss', $key, $value);
            $stmt->execute();
        }
        $stmt->close();

        logActivity($conn, 'SETTINGS_UPDATED', 'System settings were updated');
        echo json_encode(['ok' => true, 'msg' => 'Settings saved successfully.']);
        $conn->close(); exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    $conn->close(); exit;
}

// ─── Load page data ───────────────────────────────────────────────────────────
$conn = getConnection();

// Load persisted settings
$settings = getSettings($conn);

// Stats
$stats = $conn->query("
    SELECT
        COUNT(*) AS total,
        SUM(role IN ('admin','superadmin')) AS admins,
        SUM(status = 'active' AND deleted_at IS NULL) AS active,
        SUM(status = 'banned') AS banned,
        SUM(status = 'inactive') AS inactive,
        SUM(created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND deleted_at IS NULL) AS new_week,
        SUM(deleted_at IS NOT NULL) AS deleted_count
    FROM users
")->fetch_assoc();

// Users list
$search       = trim($_GET['search'] ?? '');
$filterRole   = $_GET['role'] ?? '';
$filterStatus = $_GET['status'] ?? '';
$showDeleted  = isset($_GET['show_deleted']) && $_GET['show_deleted'] === '1';

$where  = [];
$params = [];
$types  = '';

if (!$showDeleted) {
    $where[] = "deleted_at IS NULL";
} else {
    $where[] = "deleted_at IS NOT NULL";
}

if ($search) {
    $where[] = "(username LIKE ? OR email LIKE ?)";
    $like     = "%$search%";
    $params[] = $like;
    $params[] = $like;
    $types   .= 'ss';
}
if ($filterRole && in_array($filterRole, ['superadmin', 'admin', 'secretary', 'user'])) {
    $where[] = "role = ?"; $params[] = $filterRole; $types .= 's';
}
if ($filterStatus && in_array($filterStatus, ['active', 'inactive', 'banned'])) {
    $where[] = "status = ?"; $params[] = $filterStatus; $types .= 's';
}

$sql  = "SELECT * FROM users";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY created_at DESC";

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Activity logs
$aStmt = $conn->prepare("SELECT a.*, u.username FROM activity_logs a LEFT JOIN users u ON a.user_id = u.id ORDER BY a.created_at DESC LIMIT 50");
$aStmt->execute();
$activities = $aStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$aStmt->close();

// Analytics
$dailyUsers         = $conn->query("SELECT DATE(created_at) as date, COUNT(*) as count FROM users WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND deleted_at IS NULL GROUP BY DATE(created_at) ORDER BY date ASC")->fetch_all(MYSQLI_ASSOC);
$roleDistribution   = $conn->query("SELECT role, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY role")->fetch_all(MYSQLI_ASSOC);
$statusDistribution = $conn->query("SELECT status, COUNT(*) as count FROM users WHERE deleted_at IS NULL GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$weeklySignups      = $conn->query("
    SELECT WEEK(created_at) as week_num, COUNT(*) as count
    FROM users
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 8 WEEK) AND deleted_at IS NULL
    GROUP BY WEEK(created_at)
    ORDER BY week_num ASC
")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// ── Page meta for topbar title ──
$pageMeta = [
    'dashboard' => ['icon' => 'gauge-high',  'label' => 'Dashboard'],
    'users'     => ['icon' => 'users-gear',   'label' => 'User Management'],
    'analytics' => ['icon' => 'chart-bar',    'label' => 'Analytics'],
    'settings'  => ['icon' => 'sliders',      'label' => 'Settings'],
];
$meta = $pageMeta[$page] ?? $pageMeta['dashboard'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin — Barangay System</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --navy:        #0b1f45;
            --navy-mid:    #14305e;
            --navy-light:  #1e4080;
            --gold:        #c8a040;
            --gold-light:  #e8c96a;
            --gold-pale:   #f5e9c0;
            --cream:       #f9f6ef;
            --white:       #ffffff;
            --gray-50:     #f7f8fb;
            --gray-100:    #edf0f7;
            --gray-200:    #d8dde9;
            --gray-400:    #8896b3;
            --gray-600:    #4e5f7a;
            --text:        #1a2944;
            --green:       #1a7a4a;
            --red:         #a82020;
            --amber:       #a06010;
            --sidebar-w:   252px;
            --radius:      10px;
            --shadow-sm:   0 1px 4px rgba(11,31,69,.06);
            --shadow-md:   0 4px 16px rgba(11,31,69,.10);
            --shadow-lg:   0 8px 32px rgba(11,31,69,.14);
        }

        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--text);
            font-size: 14px;
            line-height: 1.5;
        }

        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: var(--gray-200); border-radius: 99px; }

        /* ── SIDEBAR ── */
        .sidebar {
            position: fixed;
            inset: 0 auto 0 0;
            width: var(--sidebar-w);
            background: var(--navy);
            display: flex;
            flex-direction: column;
            z-index: 200;
            border-right: 3px solid var(--gold);
        }

        .sidebar-brand {
            padding: 20px 20px 16px;
            border-bottom: 1px solid rgba(255,255,255,.08);
        }

        .brand-seal {
            width: 44px; height: 44px;
            background: rgba(200,160,64,.15);
            border: 1.5px solid var(--gold);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold);
            font-size: 18px;
            margin-bottom: 10px;
        }

        .brand-name {
            font-family: 'DM Serif Display', serif;
            font-size: 13px;
            color: var(--gold-light);
            line-height: 1.3;
        }

        .brand-sub {
            font-size: 10px;
            color: rgba(255,255,255,.35);
            letter-spacing: .08em;
            text-transform: uppercase;
            margin-top: 2px;
        }

        .nav-section { flex: 1; padding: 12px 0; overflow-y: auto; }

        .nav-label {
            font-size: 9.5px;
            color: rgba(255,255,255,.28);
            letter-spacing: .14em;
            text-transform: uppercase;
            padding: 12px 20px 4px;
            font-weight: 600;
        }

        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 20px;
            color: rgba(255,255,255,.58);
            text-decoration: none;
            font-size: 13px;
            font-weight: 500;
            transition: all .15s;
            cursor: pointer;
            border-right: 3px solid transparent;
            margin-right: -3px;
        }

        .nav-item i { width: 16px; text-align: center; font-size: 13px; }
        .nav-item:hover { background: rgba(200,160,64,.1); color: var(--gold-light); }
        .nav-item.active { background: rgba(200,160,64,.14); color: var(--gold-light); border-right-color: var(--gold); }

        .sidebar-bottom {
            border-top: 1px solid rgba(255,255,255,.08);
            padding: 14px 20px;
        }

        .sidebar-user {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }

        .user-avatar {
            width: 34px; height: 34px;
            background: rgba(200,160,64,.18);
            border: 1.5px solid rgba(200,160,64,.5);
            border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            color: var(--gold-light);
            font-weight: 600;
            font-size: 13px;
            flex-shrink: 0;
        }

        .user-name { font-size: 12px; color: #fff; font-weight: 500; }
        .user-role { font-size: 10px; color: rgba(255,255,255,.38); }

        .logout-btn {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            width: 100%;
            padding: 7px;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 6px;
            color: rgba(255,255,255,.45);
            text-decoration: none;
            font-size: 12px;
            transition: all .15s;
        }

        .logout-btn:hover { background: rgba(200,30,30,.18); color: #ff9999; border-color: rgba(200,30,30,.3); }

        /* ── MAIN LAYOUT ── */
        .main { margin-left: var(--sidebar-w); min-height: 100vh; display: flex; flex-direction: column; }

        .topbar {
            background: var(--white);
            border-bottom: 1px solid var(--gray-100);
            padding: 0 28px;
            height: 60px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky; top: 0; z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .page-title {
            font-family: 'DM Serif Display', serif;
            font-size: 19px;
            color: var(--navy);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .page-title i { color: var(--gold); font-size: 15px; }

        .clock-widget { display: flex; flex-direction: column; align-items: flex-end; }
        #clock-time { font-family: 'DM Serif Display', serif; font-size: 20px; color: var(--navy); letter-spacing: .02em; line-height: 1; }
        #clock-date { font-size: 11px; color: var(--gray-400); margin-top: 2px; }

        .content { padding: 24px 28px; flex: 1; }

        /* ── STAT CARDS ── */
        .stats-grid { display: grid; grid-template-columns: repeat(5, 1fr); gap: 14px; margin-bottom: 24px; }

        .stat-card {
            background: var(--white);
            border: 1px solid var(--gray-100);
            border-radius: var(--radius);
            padding: 16px 18px;
            position: relative;
            overflow: hidden;
            transition: box-shadow .2s, transform .2s;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: var(--gold);
            border-radius: var(--radius) var(--radius) 0 0;
        }

        .stat-card:hover { box-shadow: var(--shadow-md); transform: translateY(-2px); }
        .stat-card.red::before   { background: var(--red); }
        .stat-card.green::before { background: var(--green); }
        .stat-card.amber::before { background: var(--amber); }
        .stat-card.navy::before  { background: var(--navy-light); }

        .stat-label { font-size: 10.5px; color: var(--gray-400); text-transform: uppercase; letter-spacing: .08em; font-weight: 600; }
        .stat-value { font-size: 30px; font-weight: 700; color: var(--navy); margin: 4px 0 2px; line-height: 1; }
        .stat-note  { font-size: 11px; color: var(--gray-400); }
        .stat-icon  { position: absolute; right: 14px; bottom: 14px; font-size: 24px; color: var(--gray-100); }

        /* ── CARDS ── */
        .card { background: var(--white); border: 1px solid var(--gray-100); border-radius: var(--radius); margin-bottom: 20px; box-shadow: var(--shadow-sm); }

        .card-head {
            padding: 14px 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .card-head-title { font-weight: 600; font-size: 14px; color: var(--navy); display: flex; align-items: center; gap: 8px; }
        .card-head-title i { color: var(--gold); }
        .card-body { padding: 20px; }

        /* ── TABLES ── */
        .tbl-wrap { overflow-x: auto; }

        table.data-table { width: 100%; border-collapse: collapse; }

        .data-table th, .data-table td {
            padding: 10px 14px;
            text-align: left;
            font-size: 13px;
            border-bottom: 1px solid var(--gray-100);
            white-space: nowrap;
        }

        .data-table thead th {
            background: var(--gray-50);
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .07em;
            color: var(--gray-600);
        }

        .data-table tbody tr { transition: background .1s; }
        .data-table tbody tr:hover { background: var(--gray-50); }

        /* ── BADGES ── */
        .badge { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; line-height: 1.4; }
        .badge-superadmin { background: #f3e6ff; color: #6318a8; }
        .badge-admin      { background: #e0eeff; color: #1040a0; }
        .badge-user       { background: #e3f8ed; color: #18703e; }

        .status-pill { display: inline-flex; align-items: center; gap: 4px; padding: 3px 9px; border-radius: 99px; font-size: 11px; font-weight: 600; cursor: pointer; transition: opacity .15s; }
        .status-pill:hover { opacity: .75; }
        .status-pill::before { content: ''; width: 6px; height: 6px; border-radius: 50%; }
        .sp-active   { background: #e3f8ed; color: #18703e; }
        .sp-active::before   { background: #18703e; }
        .sp-inactive { background: #fff4e0; color: #a06010; }
        .sp-inactive::before { background: #a06010; }
        .sp-banned   { background: #ffecec; color: #a82020; }
        .sp-banned::before   { background: #a82020; }

        /* ── FILTERS ── */
        .filter-bar { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 16px; }

        .filter-bar input,
        .filter-bar select {
            padding: 7px 11px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            background: var(--white);
            color: var(--text);
            outline: none;
            transition: border-color .15s;
        }

        .filter-bar input:focus,
        .filter-bar select:focus { border-color: var(--gold); }

        /* ── BUTTONS ── */
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 15px;
            border: none;
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all .15s;
            white-space: nowrap;
            text-decoration: none;
        }

        .btn-primary { background: var(--navy); color: #fff; }
        .btn-primary:hover { background: var(--navy-light); }
        .btn-gold { background: var(--gold); color: var(--navy); }
        .btn-gold:hover { background: var(--gold-light); }
        .btn-ghost { background: var(--gray-100); color: var(--gray-600); }
        .btn-ghost:hover { background: var(--gray-200); }
        .btn-danger { background: #ffeaea; color: var(--red); }
        .btn-danger:hover { background: #ffd0d0; }
        .btn-success { background: #e3f8ed; color: var(--green); }
        .btn-success:hover { background: #c8f0d8; }
        .btn-sm { padding: 4px 10px; font-size: 12px; border-radius: 6px; }
        .btn-icon { padding: 6px 8px; }

        /* ── CHARTS ── */
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 16px; margin-bottom: 20px; }

        .chart-card { background: var(--white); border: 1px solid var(--gray-100); border-radius: var(--radius); padding: 18px 20px; }

        .chart-title { font-size: 13px; font-weight: 600; color: var(--navy); margin-bottom: 14px; display: flex; align-items: center; gap: 6px; }
        .chart-title i { color: var(--gold); font-size: 12px; }
        canvas { max-height: 220px; }

        /* ── ACTIVITY ── */
        .activity-list { display: flex; flex-direction: column; gap: 0; }

        .activity-row { display: flex; gap: 12px; padding: 12px 0; border-bottom: 1px solid var(--gray-100); align-items: flex-start; }
        .activity-row:last-child { border-bottom: none; }

        .act-dot {
            width: 32px; height: 32px;
            border-radius: 50%;
            background: var(--gray-50);
            border: 1px solid var(--gray-100);
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
        }

        .act-dot.created  { background: #e3f8ed; color: var(--green); border-color: #c8f0d8; }
        .act-dot.updated  { background: #e0eeff; color: #1040a0; border-color: #c8ddff; }
        .act-dot.deleted  { background: #ffecec; color: var(--red); border-color: #ffd0d0; }
        .act-dot.status   { background: #fff4e0; color: var(--amber); border-color: #ffe0a0; }
        .act-dot.restored { background: #f3e6ff; color: #6318a8; border-color: #e0c8ff; }

        .act-action { font-size: 12px; font-weight: 600; color: var(--navy); margin-bottom: 2px; }
        .act-detail { font-size: 12px; color: var(--gray-600); margin-bottom: 2px; }
        .act-meta   { font-size: 11px; color: var(--gray-400); }

        /* ── MODAL ── */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(11,31,69,.45);
            backdrop-filter: blur(2px);
            align-items: center;
            justify-content: center;
            z-index: 500;
        }

        .modal-overlay.open { display: flex; }

        .modal-box {
            background: var(--white);
            border-radius: 14px;
            width: 90%;
            max-width: 480px;
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            animation: modalIn .18s ease;
        }

        @keyframes modalIn {
            from { opacity: 0; transform: translateY(-16px) scale(.97); }
            to   { opacity: 1; transform: none; }
        }

        .modal-head {
            padding: 16px 20px;
            border-bottom: 1px solid var(--gray-100);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-weight: 600;
            font-size: 15px;
            color: var(--navy);
        }

        .modal-close {
            width: 28px; height: 28px;
            border-radius: 50%;
            background: var(--gray-100);
            border: none; cursor: pointer;
            display: flex; align-items: center; justify-content: center;
            color: var(--gray-600);
            font-size: 15px;
            transition: all .15s;
        }

        .modal-close:hover { background: var(--gray-200); }
        .modal-body { padding: 20px; }
        .modal-foot { padding: 14px 20px; border-top: 1px solid var(--gray-100); display: flex; justify-content: flex-end; gap: 8px; }

        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
        .form-group { margin-bottom: 14px; }
        .form-group:last-child { margin-bottom: 0; }

        .form-label { display: block; font-size: 12px; font-weight: 600; color: var(--gray-600); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .06em; }

        .form-control {
            width: 100%;
            padding: 8px 11px;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: var(--text);
            outline: none;
            transition: border-color .15s;
            background: var(--white);
        }

        .form-control:focus { border-color: var(--gold); box-shadow: 0 0 0 3px rgba(200,160,64,.12); }

        /* ── PAGES ── */
        .page-section { display: none; }
        .page-section.active { display: block; }

        /* ── TOAST ── */
        #toast {
            position: fixed;
            bottom: 24px; right: 24px;
            padding: 11px 18px;
            border-radius: 10px;
            background: var(--navy);
            color: #fff;
            font-size: 13px;
            font-weight: 500;
            box-shadow: var(--shadow-lg);
            opacity: 0;
            transform: translateY(10px);
            transition: opacity .2s, transform .2s;
            pointer-events: none;
            z-index: 900;
            display: flex;
            align-items: center;
            gap: 8px;
            max-width: 320px;
        }

        #toast.show { opacity: 1; transform: none; }
        #toast.success { background: #145c35; }
        #toast.error   { background: #8b1a1a; }

        /* ── SETTINGS ── */
        .settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }

        .settings-section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .08em;
            color: var(--gray-400);
            margin-bottom: 14px;
            padding-bottom: 8px;
            border-bottom: 1px solid var(--gray-100);
        }

        .toggle-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid var(--gray-50);
        }

        .toggle-row:last-child { border-bottom: none; }
        .toggle-label { font-size: 13px; font-weight: 500; }
        .toggle-desc  { font-size: 11px; color: var(--gray-400); }

        .toggle-switch { position: relative; width: 38px; height: 22px; flex-shrink: 0; }
        .toggle-switch input { display: none; }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: var(--gray-200);
            border-radius: 99px;
            cursor: pointer;
            transition: background .2s;
        }

        .toggle-slider::after {
            content: '';
            position: absolute;
            top: 3px; left: 3px;
            width: 16px; height: 16px;
            border-radius: 50%;
            background: #fff;
            box-shadow: 0 1px 4px rgba(0,0,0,.2);
            transition: transform .2s;
        }

        .toggle-switch input:checked + .toggle-slider { background: var(--green); }
        .toggle-switch input:checked + .toggle-slider::after { transform: translateX(16px); }

        /* ── DELETED ROWS ── */
        .deleted-row td { opacity: .6; text-decoration: line-through; }
        .deleted-row:hover td { opacity: .8; text-decoration: none; }

        @media (max-width: 900px) {
            .sidebar { transform: translateX(-100%); transition: transform .25s; }
            .sidebar.open { transform: none; }
            .main { margin-left: 0; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .settings-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ── Sidebar ── -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-seal"><i class="fas fa-landmark"></i></div>
        <div class="brand-name">Barangay E-Records</div>
        <div class="brand-sub">Super Admin Portal</div>
    </div>

    <nav class="nav-section">
        <div class="nav-label">Overview</div>
        <a class="nav-item <?= $page === 'dashboard' ? 'active' : '' ?>" href="?page=dashboard">
            <i class="fas fa-gauge-high"></i> Dashboard
        </a>
        <a class="nav-item <?= $page === 'analytics' ? 'active' : '' ?>" href="?page=analytics">
            <i class="fas fa-chart-bar"></i> Analytics
        </a>

        <div class="nav-label">Management</div>
        <a class="nav-item <?= $page === 'users' ? 'active' : '' ?>" href="?page=users">
            <i class="fas fa-users-gear"></i> User Management
        </a>

        <div class="nav-label">System</div>
        <a class="nav-item <?= $page === 'settings' ? 'active' : '' ?>" href="?page=settings">
            <i class="fas fa-sliders"></i> Settings
        </a>
    </nav>

    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <div class="user-avatar"><?= strtoupper(substr($_SESSION['full_name'] ?? 'SA', 0, 1)) ?></div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Super Admin') ?></div>
                <div class="user-role">Super Administrator</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn"><i class="fas fa-arrow-right-from-bracket"></i> Sign Out</a>
    </div>
</aside>

<!-- ── Main ── -->
<div class="main">

    <header class="topbar">
        <!-- ✅ FIXED: Page title now reflects the active $page from PHP -->
        <div class="page-title" id="pageTitle">
            <i class="fas fa-<?= $meta['icon'] ?>"></i>
            <span><?= $meta['label'] ?></span>
        </div>
        <div class="clock-widget">
            <div id="clock-time">00:00:00</div>
            <div id="clock-date">Loading…</div>
        </div>
    </header>

    <div class="content">

        <!-- ═══ PAGE: DASHBOARD ═══ -->
        <div class="page-section <?= $page === 'dashboard' ? 'active' : '' ?>" id="page-dashboard">

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-note">All registered accounts</div>
                    <i class="fas fa-users stat-icon"></i>
                </div>
                <div class="stat-card green">
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?= $stats['active'] ?></div>
                    <div class="stat-note"><?= round(($stats['active'] / max($stats['total'],1)) * 100) ?>% of total</div>
                    <i class="fas fa-circle-check stat-icon"></i>
                </div>
                <div class="stat-card navy">
                    <div class="stat-label">Admins</div>
                    <div class="stat-value"><?= $stats['admins'] ?></div>
                    <div class="stat-note">Admin accounts only</div>
                    <i class="fas fa-user-shield stat-icon"></i>
                </div>
                <div class="stat-card red">
                    <div class="stat-label">Banned</div>
                    <div class="stat-value"><?= $stats['banned'] ?></div>
                    <div class="stat-note">Suspended accounts</div>
                    <i class="fas fa-ban stat-icon"></i>
                </div>
                <div class="stat-card amber">
                    <div class="stat-label">New (7d)</div>
                    <div class="stat-value"><?= $stats['new_week'] ?></div>
                    <div class="stat-note">Recent registrations</div>
                    <i class="fas fa-user-plus stat-icon"></i>
                </div>
            </div>

            <div class="chart-grid">
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-pie"></i> Role Distribution</div>
                    <canvas id="dash-roleChart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-pie"></i> Status Distribution</div>
                    <canvas id="dash-statusChart"></canvas>
                </div>
                <div class="chart-card" style="grid-column: span 2;">
                    <div class="chart-title"><i class="fas fa-chart-line"></i> New Registrations – Last 30 Days</div>
                    <canvas id="dash-dailyChart"></canvas>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-head-title"><i class="fas fa-clock-rotate-left"></i> Recent Activity</div>
                    <a href="?page=analytics" class="btn btn-ghost btn-sm">View All</a>
                </div>
                <div class="card-body">
                    <div class="activity-list">
                        <?php foreach (array_slice($activities, 0, 6) as $act):
                            $dotClass = 'default';
                            if (str_contains($act['action'], 'CREATED'))        $dotClass = 'created';
                            elseif (str_contains($act['action'], 'UPDATED'))    $dotClass = 'updated';
                            elseif (str_contains($act['action'], 'DELETED'))    $dotClass = 'deleted';
                            elseif (str_contains($act['action'], 'STATUS'))     $dotClass = 'status';
                            elseif (str_contains($act['action'], 'RESTORED'))   $dotClass = 'restored';
                        ?>
                            <div class="activity-row">
                                <div class="act-dot <?= $dotClass ?>">
                                    <i class="fas fa-<?= $dotClass === 'created' ? 'plus' : ($dotClass === 'deleted' ? 'trash' : ($dotClass === 'status' ? 'toggle-on' : ($dotClass === 'restored' ? 'rotate-left' : 'pen'))) ?>"></i>
                                </div>
                                <div>
                                    <div class="act-action"><?= htmlspecialchars($act['action']) ?></div>
                                    <div class="act-detail"><?= htmlspecialchars($act['details']) ?></div>
                                    <div class="act-meta"><i class="fas fa-user" style="font-size:10px;"></i> <?= htmlspecialchars($act['username'] ?? 'System') ?> &middot; <?= timeAgo($act['created_at']) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($activities)): ?>
                            <p style="color:var(--gray-400); text-align:center; padding: 20px 0;">No activity yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ PAGE: USER MANAGEMENT ═══ -->
        <div class="page-section <?= $page === 'users' ? 'active' : '' ?>" id="page-users">

            <div class="filter-bar">
                <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; flex:1;">
                    <input type="hidden" name="page" value="users">
                    <input class="form-control" style="width:200px;" type="text" name="search" placeholder="Search name or email…" value="<?= htmlspecialchars($search) ?>">
                    <select class="form-control" style="width:130px;" name="role">
                        <option value="">All Roles</option>
                        <option value="superadmin" <?= $filterRole === 'superadmin' ? 'selected' : '' ?>>Super Admin</option>
                        <option value="admin" <?= $filterRole === 'admin' ? 'selected' : '' ?>>Admin</option>
                        <option value="secretary" <?= $filterRole === 'secretary' ? 'selected' : '' ?>>Secretary</option>
                        <option value="user" <?= $filterRole === 'user' ? 'selected' : '' ?>>User</option>
                    </select>
                    <select class="form-control" style="width:130px;" name="status">
                        <option value="">All Status</option>
                        <option value="active" <?= $filterStatus === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="inactive" <?= $filterStatus === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        <option value="banned" <?= $filterStatus === 'banned' ? 'selected' : '' ?>>Banned</option>
                    </select>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-magnifying-glass"></i> Search</button>
                    <?php if ($search || $filterRole || $filterStatus): ?>
                        <a href="?page=users" class="btn btn-ghost">Clear</a>
                    <?php endif; ?>
                </form>

                <div style="display:flex; gap:8px;">
                    <?php if ($showDeleted): ?>
                        <a href="?page=users" class="btn btn-ghost btn-sm"><i class="fas fa-users"></i> Active Users</a>
                    <?php else: ?>
                        <a href="?page=users&show_deleted=1" class="btn btn-ghost btn-sm" style="color:var(--red);">
                            <i class="fas fa-trash-can"></i> Deleted (<?= $stats['deleted_count'] ?>)
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-gold" id="openAddModalBtn"><i class="fas fa-user-plus"></i> Add User</button>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-head-title">
                        <i class="fas fa-<?= $showDeleted ? 'trash-can' : 'users' ?>"></i>
                        <?= $showDeleted ? 'Deleted Users' : 'All Users' ?>
                        <span style="background:var(--gray-100);color:var(--gray-600);padding:2px 8px;border-radius:99px;font-size:11px;font-weight:600;"><?= count($users) ?></span>
                    </div>
                </div>
                <div class="tbl-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>User</th>
                                <th>Email</th>
                                <th>Role</th>
                                <th>Status</th>
                                <th>Joined</th>
                                <th>Updated</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($users) > 0): ?>
                                <?php foreach ($users as $u): $isSelf = ($u['id'] == $_SESSION['user_id']); ?>
                                    <tr <?= $u['deleted_at'] ? 'class="deleted-row"' : '' ?>>
                                        <td style="color:var(--gray-400);"><?= $u['id'] ?></td>
                                        <td>
                                            <div style="display:flex;align-items:center;gap:8px;">
                                                <div style="width:28px;height:28px;border-radius:50%;background:var(--navy);color:#fff;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;flex-shrink:0;">
                                                    <?= strtoupper(substr($u['username'],0,1)) ?>
                                                </div>
                                                <span><?= htmlspecialchars($u['username']) ?></span>
                                                <?php if ($isSelf): ?>
                                                    <span style="font-size:10px;color:var(--gold);font-weight:600;">(You)</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td style="color:var(--gray-600);"><?= htmlspecialchars($u['email']) ?></td>
                                        <td><span class="badge badge-<?= $u['role'] ?>"><?= ucfirst($u['role']) ?></span></td>
                                        <td>
                                            <?php if (!$isSelf && !$u['deleted_at']): ?>
                                                <span class="status-pill sp-<?= $u['status'] ?>" onclick="toggleStatus(<?= $u['id'] ?>)" title="Click to toggle">
                                                    <?= ucfirst($u['status']) ?>
                                                </span>
                                            <?php else: ?>
                                                <span class="status-pill sp-<?= $u['status'] ?>"><?= ucfirst($u['status']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="color:var(--gray-400);"><?= timeAgo($u['created_at']) ?></td>
                                        <td style="color:var(--gray-400);"><?= timeAgo($u['updated_at']) ?></td>
                                        <td>
                                            <div style="display:flex;gap:5px;">
                                                <?php if ($u['deleted_at']): ?>
                                                    <button class="btn btn-success btn-sm restoreBtn" data-id="<?= $u['id'] ?>" data-username="<?= htmlspecialchars($u['username']) ?>">
                                                        <i class="fas fa-rotate-left"></i> Restore
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-ghost btn-sm editBtn"
                                                        data-id="<?= $u['id'] ?>"
                                                        data-username="<?= htmlspecialchars($u['username']) ?>"
                                                        data-email="<?= htmlspecialchars($u['email']) ?>"
                                                        data-role="<?= $u['role'] ?>"
                                                        data-status="<?= $u['status'] ?>">
                                                        <i class="fas fa-pen"></i> Edit
                                                    </button>
                                                    <?php if (!$isSelf): ?>
                                                        <button class="btn btn-danger btn-sm deleteBtn"
                                                            data-id="<?= $u['id'] ?>"
                                                            data-username="<?= htmlspecialchars($u['username']) ?>">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="8" style="text-align:center;padding:32px;color:var(--gray-400);">
                                    <i class="fas fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                    No users found.
                                </td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- ═══ PAGE: ANALYTICS ═══ -->
        <div class="page-section <?= $page === 'analytics' ? 'active' : '' ?>" id="page-analytics">

            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value"><?= $stats['total'] ?></div>
                    <div class="stat-note">Non-deleted accounts</div>
                    <i class="fas fa-users stat-icon"></i>
                </div>
                <div class="stat-card green">
                    <div class="stat-label">Active Rate</div>
                    <div class="stat-value"><?= round(($stats['active'] / max($stats['total'],1)) * 100) ?>%</div>
                    <div class="stat-note"><?= $stats['active'] ?> active users</div>
                    <i class="fas fa-signal stat-icon"></i>
                </div>
                <div class="stat-card navy">
                    <div class="stat-label">Admin Ratio</div>
                    <div class="stat-value"><?= round(($stats['admins'] / max($stats['total'],1)) * 100) ?>%</div>
                    <div class="stat-note"><?= $stats['admins'] ?> admin accounts</div>
                    <i class="fas fa-user-shield stat-icon"></i>
                </div>
                <div class="stat-card red">
                    <div class="stat-label">Removed</div>
                    <div class="stat-value"><?= $stats['deleted_count'] ?></div>
                    <div class="stat-note">Soft-deleted users</div>
                    <i class="fas fa-trash stat-icon"></i>
                </div>
                <div class="stat-card amber">
                    <div class="stat-label">This Week</div>
                    <div class="stat-value"><?= $stats['new_week'] ?></div>
                    <div class="stat-note">New registrations</div>
                    <i class="fas fa-calendar-week stat-icon"></i>
                </div>
            </div>

            <div class="chart-grid" style="grid-template-columns: 1fr 1fr 1fr;">
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-pie"></i> User Roles</div>
                    <canvas id="ana-roleChart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-pie"></i> Account Status</div>
                    <canvas id="ana-statusChart"></canvas>
                </div>
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-chart-bar"></i> Weekly Signups</div>
                    <canvas id="ana-weeklyChart"></canvas>
                </div>
            </div>

            <div class="chart-card" style="margin-bottom:20px;">
                <div class="chart-title"><i class="fas fa-chart-area"></i> Daily Registrations – Last 30 Days</div>
                <canvas id="ana-dailyChart" style="max-height:260px;"></canvas>
            </div>

            <div class="card">
                <div class="card-head">
                    <div class="card-head-title"><i class="fas fa-list-check"></i> Activity Log</div>
                    <span style="font-size:12px;color:var(--gray-400);">Last 50 entries</span>
                </div>
                <div class="card-body">
                    <div class="activity-list">
                        <?php foreach ($activities as $act):
                            $dotClass = 'default';
                            if (str_contains($act['action'], 'CREATED'))        $dotClass = 'created';
                            elseif (str_contains($act['action'], 'UPDATED'))    $dotClass = 'updated';
                            elseif (str_contains($act['action'], 'DELETED'))    $dotClass = 'deleted';
                            elseif (str_contains($act['action'], 'STATUS'))     $dotClass = 'status';
                            elseif (str_contains($act['action'], 'RESTORED'))   $dotClass = 'restored';
                        ?>
                            <div class="activity-row">
                                <div class="act-dot <?= $dotClass ?>">
                                    <i class="fas fa-<?= $dotClass === 'created' ? 'plus' : ($dotClass === 'deleted' ? 'trash' : ($dotClass === 'status' ? 'toggle-on' : ($dotClass === 'restored' ? 'rotate-left' : 'pen'))) ?>"></i>
                                </div>
                                <div>
                                    <div class="act-action"><?= htmlspecialchars($act['action']) ?></div>
                                    <div class="act-detail"><?= htmlspecialchars($act['details']) ?></div>
                                    <div class="act-meta">
                                        <i class="fas fa-user" style="font-size:10px;"></i> <?= htmlspecialchars($act['username'] ?? 'System') ?>
                                        &middot; <?= timeAgo($act['created_at']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                        <?php if (empty($activities)): ?>
                            <p style="color:var(--gray-400);text-align:center;padding:20px 0;">No activity yet.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ PAGE: SETTINGS ═══ -->
        <div class="page-section <?= $page === 'settings' ? 'active' : '' ?>" id="page-settings">
            <div class="settings-grid">

                <!-- General -->
                <div class="card">
                    <div class="card-head"><div class="card-head-title"><i class="fas fa-building-columns"></i> General</div></div>
                    <div class="card-body">
                        <div class="settings-section-title">Barangay Information</div>
                        <div class="form-group">
                            <label class="form-label">Barangay Name</label>
                            <input class="form-control" type="text" name="barangay_name"
                                   value="<?= htmlspecialchars($settings['barangay_name'] ?? 'Barangay Example') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Municipality / City</label>
                            <input class="form-control" type="text" name="city"
                                   value="<?= htmlspecialchars($settings['city'] ?? 'Sample City') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Email</label>
                            <input class="form-control" type="email" name="contact_email"
                                   value="<?= htmlspecialchars($settings['contact_email'] ?? 'admin@barangay.local') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Contact Number</label>
                            <input class="form-control" type="text" name="contact_phone"
                                   value="<?= htmlspecialchars($settings['contact_phone'] ?? '+63 000 000 0000') ?>">
                        </div>
                    </div>
                </div>

                <!-- Access Control -->
                <div class="card">
                    <div class="card-head"><div class="card-head-title"><i class="fas fa-lock"></i> Access Control</div></div>
                    <div class="card-body">
                        <div class="settings-section-title">User Limits &amp; Policies</div>
                        <div class="form-group">
                            <label class="form-label">Max Users per Admin</label>
                            <input class="form-control" type="number" name="max_users_per_admin"
                                   value="<?= htmlspecialchars($settings['max_users_per_admin'] ?? '50') ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Session Timeout (minutes)</label>
                            <input class="form-control" type="number" name="session_timeout"
                                   value="<?= htmlspecialchars($settings['session_timeout'] ?? '60') ?>">
                        </div>
                        <div class="settings-section-title" style="margin-top:16px;">Feature Toggles</div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Allow Self-Registration</div>
                                <div class="toggle-desc">Let users sign up without admin approval</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="allow_self_registration"
                                       <?= ($settings['allow_self_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Require Email Verification</div>
                                <div class="toggle-desc">Enforce verified email before login</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="require_email_verification"
                                       <?= ($settings['require_email_verification'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Activity Logging</div>
                                <div class="toggle-desc">Record all admin actions</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="activity_logging"
                                       <?= ($settings['activity_logging'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Security -->
                <div class="card">
                    <div class="card-head"><div class="card-head-title"><i class="fas fa-shield-halved"></i> Security</div></div>
                    <div class="card-body">
                        <div class="settings-section-title">Password Policy</div>
                        <div class="form-group">
                            <label class="form-label">Minimum Password Length</label>
                            <input class="form-control" type="number" name="password_min_length"
                                   value="<?= htmlspecialchars($settings['password_min_length'] ?? '8') ?>" min="6" max="32">
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Require Uppercase</div>
                                <div class="toggle-desc">At least one uppercase letter</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="require_uppercase"
                                       <?= ($settings['require_uppercase'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Require Numbers</div>
                                <div class="toggle-desc">At least one digit</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="require_numbers"
                                       <?= ($settings['require_numbers'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Two-Factor Auth</div>
                                <div class="toggle-desc">Require 2FA for admin accounts</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="two_factor_auth"
                                       <?= ($settings['two_factor_auth'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- Notifications -->
                <div class="card">
                    <div class="card-head"><div class="card-head-title"><i class="fas fa-bell"></i> Notifications</div></div>
                    <div class="card-body">
                        <div class="settings-section-title">Alert Preferences</div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Email on New User</div>
                                <div class="toggle-desc">Notify when someone registers</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="notify_new_user"
                                       <?= ($settings['notify_new_user'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Email on User Banned</div>
                                <div class="toggle-desc">Notify when an account is banned</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="notify_user_banned"
                                       <?= ($settings['notify_user_banned'] ?? '0') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="toggle-row">
                            <div>
                                <div class="toggle-label">Weekly Summary Report</div>
                                <div class="toggle-desc">Receive a weekly digest email</div>
                            </div>
                            <label class="toggle-switch">
                                <input type="checkbox" data-key="weekly_summary"
                                       <?= ($settings['weekly_summary'] ?? '1') === '1' ? 'checked' : '' ?>>
                                <span class="toggle-slider"></span>
                            </label>
                        </div>
                        <div class="form-group" style="margin-top:14px;">
                            <label class="form-label">Notification Email</label>
                            <input class="form-control" type="email" name="notification_email"
                                   value="<?= htmlspecialchars($settings['notification_email'] ?? 'superadmin@barangay.local') ?>">
                        </div>
                    </div>
                </div>

            </div><!-- /settings-grid -->

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:4px;">
                <button class="btn btn-ghost" id="discardSettingsBtn"><i class="fas fa-rotate-left"></i> Discard</button>
                <button class="btn btn-gold" id="saveSettingsBtn"><i class="fas fa-floppy-disk"></i> Save Settings</button>
            </div>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- ── Add / Edit User Modal ── -->
<div class="modal-overlay" id="userModal">
    <div class="modal-box">
        <div class="modal-head">
            <span id="modalTitle">Add User</span>
            <button class="modal-close" data-close="userModal">&times;</button>
        </div>
        <form id="userForm">
            <input type="hidden" id="userId">
            <div class="modal-body">
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Username *</label>
                        <input class="form-control" type="text" id="fUsername" required autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label">Email *</label>
                        <input class="form-control" type="email" id="fEmail" required autocomplete="off">
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <input class="form-control" type="password" id="fPassword" autocomplete="new-password" placeholder="Leave blank to keep current (edit)">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label">Role</label>
                        <select class="form-control" id="fRole">
                            <option value="user">User</option>
                            <option value="secretary">Secretary</option>
                            <option value="admin">Admin</option>
                            <option value="superadmin">Super Admin</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Status</label>
                        <select class="form-control" id="fStatus">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="banned">Banned</option>
                        </select>
                    </div>
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn btn-ghost" data-close="userModal">Cancel</button>
                <button type="submit" class="btn btn-gold" id="saveUserBtn"><i class="fas fa-floppy-disk"></i> Save User</button>
            </div>
        </form>
    </div>
</div>

<!-- ── Delete Confirmation Modal ── -->
<div class="modal-overlay" id="deleteModal">
    <div class="modal-box">
        <div class="modal-head">
            <span>Remove User</span>
            <button class="modal-close" data-close="deleteModal">&times;</button>
        </div>
        <div class="modal-body">
            <div style="text-align:center;padding:10px 0 4px;">
                <div style="width:56px;height:56px;background:#ffecec;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;color:var(--red);font-size:22px;">
                    <i class="fas fa-user-slash"></i>
                </div>
                <div style="font-size:15px;font-weight:600;color:var(--navy);margin-bottom:6px;">Soft Delete User</div>
                <div style="font-size:13px;color:var(--gray-600);">
                    This will remove <strong id="deleteUserName"></strong> from the active user list.<br>
                    The account can be restored later from the <em>Deleted Users</em> view.
                </div>
            </div>
        </div>
        <div class="modal-foot">
            <button class="btn btn-ghost" data-close="deleteModal">Cancel</button>
            <button class="btn btn-danger" id="confirmDeleteBtn"><i class="fas fa-trash"></i> Remove User</button>
        </div>
    </div>
</div>

<!-- ── Toast ── -->
<div id="toast"><i class="fas fa-circle-check" id="toastIcon"></i> <span id="toastMsg"></span></div>

<!-- ───────────────── SCRIPTS ───────────────── -->
<script>
    const roleDist   = <?= json_encode($roleDistribution) ?>;
    const statusDist = <?= json_encode($statusDistribution) ?>;
    const dailyData  = <?= json_encode($dailyUsers) ?>;
    const weeklyData = <?= json_encode($weeklySignups) ?>;

    // ── Live Clock ──
    function updateClock() {
        const now = new Date();
        const pad = n => String(n).padStart(2, '0');
        document.getElementById('clock-time').textContent =
            `${pad(now.getHours())}:${pad(now.getMinutes())}:${pad(now.getSeconds())}`;
        const days   = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        document.getElementById('clock-date').textContent =
            `${days[now.getDay()]}, ${months[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()}`;
    }
    updateClock();
    setInterval(updateClock, 1000);

    // ── Toast ──
    function showToast(msg, type = 'success') {
        const t = document.getElementById('toast');
        const icons = { success: 'circle-check', error: 'circle-xmark', info: 'circle-info' };
        document.getElementById('toastIcon').className = `fas fa-${icons[type] || 'circle-check'}`;
        document.getElementById('toastMsg').textContent = msg;
        t.className = `show ${type}`;
        clearTimeout(t._timer);
        t._timer = setTimeout(() => t.className = '', 3200);
    }

    // ── Modal helpers ──
    function openModal(id)  { document.getElementById(id).classList.add('open'); }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    document.querySelectorAll('[data-close]').forEach(el => {
        el.addEventListener('click', () => closeModal(el.dataset.close));
    });

    document.querySelectorAll('.modal-overlay').forEach(m => {
        m.addEventListener('click', e => { if (e.target === m) closeModal(m.id); });
    });

    // ── API helper ──
    async function apiPost(data) {
        const fd = new FormData();
        for (const [k, v] of Object.entries(data)) fd.append(k, v);
        const res = await fetch(window.location.href, { method: 'POST', body: fd });
        return res.json();
    }

    // ── Add User ──
    document.getElementById('openAddModalBtn')?.addEventListener('click', () => {
        document.getElementById('modalTitle').textContent = 'Add User';
        document.getElementById('userForm').reset();
        document.getElementById('userId').value = '';
        document.getElementById('fPassword').placeholder = 'Password (required)';
        openModal('userModal');
    });

    // ── Edit buttons ──
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.getElementById('modalTitle').textContent = 'Edit User';
            document.getElementById('userId').value    = btn.dataset.id;
            document.getElementById('fUsername').value = btn.dataset.username;
            document.getElementById('fEmail').value    = btn.dataset.email;
            document.getElementById('fRole').value     = btn.dataset.role;
            document.getElementById('fStatus').value   = btn.dataset.status;
            document.getElementById('fPassword').value = '';
            document.getElementById('fPassword').placeholder = 'Leave blank to keep current';
            openModal('userModal');
        });
    });

    // ── Save user form ──
    document.getElementById('userForm').addEventListener('submit', async e => {
        e.preventDefault();
        const id  = document.getElementById('userId').value;
        const btn = document.getElementById('saveUserBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
        const result = await apiPost({
            action:   id ? 'edit' : 'add',
            id,
            username: document.getElementById('fUsername').value.trim(),
            email:    document.getElementById('fEmail').value.trim(),
            password: document.getElementById('fPassword').value,
            role:     document.getElementById('fRole').value,
            status:   document.getElementById('fStatus').value,
        });
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save User';
        if (result.ok) {
            showToast(result.msg, 'success');
            closeModal('userModal');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(result.msg, 'error');
        }
    });

    // ── Toggle status ──
    window.toggleStatus = async function(userId) {
        const result = await apiPost({ action: 'toggle_status', id: userId });
        if (result.ok) {
            showToast(result.msg, 'success');
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(result.msg, 'error');
        }
    };

    // ── Soft Delete ──
    let pendingDeleteId = null;
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.addEventListener('click', () => {
            pendingDeleteId = btn.dataset.id;
            document.getElementById('deleteUserName').textContent = btn.dataset.username;
            openModal('deleteModal');
        });
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', async () => {
        if (!pendingDeleteId) return;
        const result = await apiPost({ action: 'delete', id: pendingDeleteId });
        pendingDeleteId = null;
        if (result.ok) {
            showToast(result.msg, 'success');
            closeModal('deleteModal');
            setTimeout(() => location.reload(), 900);
        } else {
            showToast(result.msg, 'error');
        }
    });

    // ── Restore ──
    document.querySelectorAll('.restoreBtn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const result = await apiPost({ action: 'restore', id: btn.dataset.id });
            if (result.ok) {
                showToast(result.msg, 'success');
                setTimeout(() => location.reload(), 900);
            } else {
                showToast(result.msg, 'error');
            }
        });
    });

    // ── Save Settings ──
    document.getElementById('saveSettingsBtn')?.addEventListener('click', async () => {
        const btn = document.getElementById('saveSettingsBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

        const data = { action: 'save_settings' };

        // Text / number / email inputs
        document.querySelectorAll('#page-settings input[name], #page-settings select[name]').forEach(el => {
            data[el.name] = el.value;
        });

        // Checkboxes: explicitly send '1' or '0'
        document.querySelectorAll('#page-settings input[type="checkbox"][data-key]').forEach(el => {
            data[el.dataset.key] = el.checked ? '1' : '0';
        });

        const result = await apiPost(data);
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-floppy-disk"></i> Save Settings';
        showToast(result.ok ? 'Settings saved successfully.' : result.msg, result.ok ? 'success' : 'error');
    });

    // ── Discard Settings ──
    document.getElementById('discardSettingsBtn')?.addEventListener('click', () => {
        location.reload();
    });

    // ── Charts ──
    const CHART_DEFAULTS = {
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', padding: 14, font: { family: 'DM Sans', size: 12 } } } },
        animation: { duration: 600 }
    };

    const ROLE_COLORS   = ['#6318a8','#1040a0','#18703e'];
    const STATUS_COLORS = ['#18703e','#a06010','#a82020','#888'];

    function makeDoughnut(id, dist, labelKey, colorPalette) {
        const el = document.getElementById(id);
        if (!el || !dist.length) return;
        new Chart(el, {
            type: 'doughnut',
            data: {
                labels: dist.map(r => r[labelKey].charAt(0).toUpperCase() + r[labelKey].slice(1)),
                datasets: [{ data: dist.map(r => r.count), backgroundColor: colorPalette, borderColor: '#fff', borderWidth: 3, hoverOffset: 6 }]
            },
            options: { ...CHART_DEFAULTS, cutout: '62%' }
        });
    }

    function makeLineChart(id, data) {
        const el = document.getElementById(id);
        if (!el || !data.length) return;
        new Chart(el, {
            type: 'line',
            data: {
                labels: data.map(d => {
                    const dt = new Date(d.date);
                    return `${dt.getMonth()+1}/${dt.getDate()}`;
                }),
                datasets: [{
                    label: 'New Users',
                    data: data.map(d => d.count),
                    borderColor: '#0b1f45',
                    backgroundColor: 'rgba(11,31,69,.06)',
                    fill: true, tension: 0.45,
                    pointBackgroundColor: '#c8a040',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                }]
            },
            options: {
                ...CHART_DEFAULTS,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.04)' } }, x: { grid: { display: false } } }
            }
        });
    }

    function makeBarChart(id, data) {
        const el = document.getElementById(id);
        if (!el || !data.length) return;
        new Chart(el, {
            type: 'bar',
            data: {
                labels: data.map((d,i) => `W${i+1}`),
                datasets: [{
                    label: 'Signups',
                    data: data.map(d => d.count),
                    backgroundColor: 'rgba(11,31,69,.75)',
                    borderRadius: 5,
                    borderSkipped: false,
                }]
            },
            options: {
                ...CHART_DEFAULTS,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, grid: { color: 'rgba(0,0,0,.04)' } }, x: { grid: { display: false } } }
            }
        });
    }

    setTimeout(() => {
        makeDoughnut('dash-roleChart',   roleDist,   'role',   ROLE_COLORS);
        makeDoughnut('dash-statusChart', statusDist, 'status', STATUS_COLORS);
        makeLineChart('dash-dailyChart', dailyData);

        makeDoughnut('ana-roleChart',   roleDist,   'role',   ROLE_COLORS);
        makeDoughnut('ana-statusChart', statusDist, 'status', STATUS_COLORS);
        makeLineChart('ana-dailyChart', dailyData);
        makeBarChart('ana-weeklyChart', weeklyData);
    }, 80);
</script>
</body>
</html>