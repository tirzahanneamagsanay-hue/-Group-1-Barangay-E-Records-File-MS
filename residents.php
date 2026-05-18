<?php
// ============================================================
// residents.php - BARANGAY RESIDENTS MANAGEMENT MODULE
// Full CRUD for residents with Purok organization (1-10)
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'residents';

// Helper functions
function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
}

function timeAgo($dt) {
    if (!$dt) return 'N/A';
    $diff = time() - strtotime($dt);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return round($diff / 60) . ' min ago';
    if ($diff < 86400) return round($diff / 3600) . ' hrs ago';
    if ($diff < 604800) return round($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($dt));
}

// Log activity helper
function logActivity($conn, $action, $details) {
    $user_id = $_SESSION['user_id'];
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param('iss', $user_id, $action, $details);
    $stmt->execute();
    $stmt->close();
}

// ============================================================
// API: Handle POST Actions (Resident CRUD)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    $action = $_POST['action'];

    // --- ADD RESIDENT ---
    if ($action === 'add') {
        $first_name    = trim($_POST['first_name'] ?? '');
        $last_name     = trim($_POST['last_name'] ?? '');
        $middle_name   = trim($_POST['middle_name'] ?? '');
        $full_address  = trim($_POST['full_address'] ?? '');
        $purok         = trim($_POST['purok'] ?? '');
        $birthdate     = trim($_POST['birthdate'] ?? '');
        $gender        = trim($_POST['gender'] ?? 'Male');
        $civil_status  = trim($_POST['civil_status'] ?? 'Single');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $occupation    = trim($_POST['occupation'] ?? '');

        $valid_puroks = ['Purok 1','Purok 2','Purok 3','Purok 4','Purok 5',
                         'Purok 6','Purok 7','Purok 8','Purok 9','Purok 10'];

        if ($first_name === '' || $last_name === '' || $full_address === '') {
            echo json_encode(['ok' => false, 'msg' => 'First name, last name, and full address are required.']);
            $conn->close(); exit;
        }

        if ($purok === '' || !in_array($purok, $valid_puroks)) {
            echo json_encode(['ok' => false, 'msg' => 'Please select a valid Purok (Purok 1–10).']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO residents (first_name, last_name, middle_name, full_address, purok, birthdate, gender, civil_status, contact_number, email, occupation, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $stmt->bind_param('sssssssssssi',
            $first_name, $last_name, $middle_name, $full_address, $purok,
            $birthdate, $gender, $civil_status,
            $contact_number, $email, $occupation,
            $_SESSION['user_id']
        );

        $ok = $stmt->execute();

        if ($ok) {
            logActivity($conn, 'RESIDENT_CREATED', "Added resident: $first_name $last_name ($purok)");
        }

        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Resident added successfully.' : 'Database error: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // --- EDIT RESIDENT ---
    if ($action === 'edit') {
        $id            = (int)($_POST['id'] ?? 0);
        $first_name    = trim($_POST['first_name'] ?? '');
        $last_name     = trim($_POST['last_name'] ?? '');
        $middle_name   = trim($_POST['middle_name'] ?? '');
        $full_address  = trim($_POST['full_address'] ?? '');
        $purok         = trim($_POST['purok'] ?? '');
        $birthdate     = trim($_POST['birthdate'] ?? '');
        $gender        = trim($_POST['gender'] ?? 'Male');
        $civil_status  = trim($_POST['civil_status'] ?? 'Single');
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email         = trim($_POST['email'] ?? '');
        $occupation    = trim($_POST['occupation'] ?? '');

        $valid_puroks = ['Purok 1','Purok 2','Purok 3','Purok 4','Purok 5',
                         'Purok 6','Purok 7','Purok 8','Purok 9','Purok 10'];

        if ($id <= 0 || $first_name === '' || $last_name === '' || $full_address === '') {
            echo json_encode(['ok' => false, 'msg' => 'Required fields missing.']);
            $conn->close(); exit;
        }

        if ($purok === '' || !in_array($purok, $valid_puroks)) {
            echo json_encode(['ok' => false, 'msg' => 'Please select a valid Purok (Purok 1–10).']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "UPDATE residents
             SET first_name=?, last_name=?, middle_name=?, full_address=?, purok=?, birthdate=?, gender=?, civil_status=?, contact_number=?, email=?, occupation=?, updated_at=NOW()
             WHERE id=?"
        );

        $stmt->bind_param('sssssssssssi',
            $first_name, $last_name, $middle_name, $full_address, $purok,
            $birthdate, $gender, $civil_status,
            $contact_number, $email, $occupation,
            $id
        );

        $ok = $stmt->execute();

        if ($ok) {
            logActivity($conn, 'RESIDENT_UPDATED', "Updated resident ID $id: $first_name $last_name ($purok)");
        }

        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Resident updated successfully.' : 'Update failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // --- DELETE RESIDENT ---
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid resident ID.']);
            $conn->close(); exit;
        }

        $getStmt = $conn->prepare("SELECT first_name, last_name FROM residents WHERE id = ?");
        $getStmt->bind_param('i', $id);
        $getStmt->execute();
        $residentData = $getStmt->get_result()->fetch_assoc();
        $getStmt->close();

        // BUG FIX: Hard DELETE breaks FK references in cases/documents/blotter.
        // Use soft delete (set is_active = 0) instead.
        $stmt = $conn->prepare("UPDATE residents SET is_active = 0, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();

        if ($ok) {
            $resName = ($residentData['first_name'] ?? 'Unknown') . ' ' . ($residentData['last_name'] ?? '');
            logActivity($conn, 'RESIDENT_DEACTIVATED', "Deactivated resident ID $id: $resName");
        }

        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Resident deactivated successfully.' : 'Deactivate failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // --- GET SINGLE RESIDENT FOR MODAL ---
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid resident ID.']);
            $conn->close(); exit;
        }
        $stmt = $conn->prepare("SELECT * FROM residents WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $conn->close();
        echo json_encode(['ok' => (bool)$row, 'data' => $row]);
        exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    $conn->close(); exit;
}

// ============================================================
// HTML PAGE: Display Residents List (GET)
// ============================================================
$conn = getConnection();

// Pagination
$per_page = 15;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;

// Search & Filter
$search        = trim($_GET['search'] ?? '');
$filter_gender = trim($_GET['gender'] ?? '');
$filter_status = trim($_GET['status'] ?? '');
$filter_purok  = trim($_GET['purok'] ?? '');

// Purok list (reused in filters + modal)
$purok_list = ['Purok 1','Purok 2','Purok 3','Purok 4','Purok 5',
               'Purok 6','Purok 7','Purok 8','Purok 9','Purok 10'];

// Build WHERE clause
$where  = [];
$params = [];
$types  = '';

if ($search) {
    $where[] = "(first_name LIKE ? OR last_name LIKE ? OR email LIKE ? OR contact_number LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like, $like]);
    $types .= 'ssss';
}
if ($filter_gender && in_array($filter_gender, ['Male', 'Female', 'Other'])) {
    $where[]  = "gender = ?";
    $params[] = $filter_gender;
    $types   .= 's';
}
if ($filter_status && in_array($filter_status, ['1', '0'])) {
    $where[]  = "is_active = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_purok && in_array($filter_purok, $purok_list)) {
    $where[]  = "purok = ?";
    $params[] = $filter_purok;
    $types   .= 's';
}

// Count total
$countSql = "SELECT COUNT(*) AS total FROM residents";
if ($where) $countSql .= " WHERE " . implode(" AND ", $where);
$countStmt = $conn->prepare($countSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();

$total_pages = max(1, (int)ceil($total / $per_page));

// Count per purok for the summary bar
$purokCounts = [];
foreach ($purok_list as $p) {
    $ps = $conn->prepare("SELECT COUNT(*) AS cnt FROM residents WHERE purok = ?");
    $ps->bind_param('s', $p);
    $ps->execute();
    $purokCounts[$p] = (int)$ps->get_result()->fetch_assoc()['cnt'];
    $ps->close();
}

// Fetch residents
$sql = "SELECT * FROM residents";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY purok, last_name, first_name LIMIT ? OFFSET ?";
$params[] = $per_page;
$params[] = $offset;
$types   .= 'ii';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$residents = $stmt->get_result();
$stmt->close();

$conn->close();

// Build query string helper for pagination links
function buildQuery($overrides = []) {
    $base = $_GET;
    unset($base['page']);
    $merged = array_merge($base, $overrides);
    $merged = array_filter($merged, fn($v) => $v !== '');
    return $merged ? '?' . http_build_query($merged) : '?';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Residents Management — Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Source Sans 3', sans-serif;
            background: #f0f3f9;
            color: #1a2a4a;
        }

        /* ── Sidebar ─────────────────────────────────── */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: 240px;
            background: #0a2a5e;
            display: flex; flex-direction: column;
            border-right: 4px solid #c8a84b;
            z-index: 100;
        }
        .sidebar-logo {
            padding: 1.5rem 1.25rem;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            text-align: center;
        }
        .sidebar-logo h2 {
            font-family: 'Playfair Display', serif;
            font-size: 14px; color: #f0d080;
        }
        .nav-section { padding: 1rem 0; flex: 1; }
        .nav-label {
            font-size: 10px; color: rgba(255,255,255,0.3);
            letter-spacing: 0.12em; text-transform: uppercase;
            padding: 0 1.25rem; margin-top: 12px;
        }
        .nav-item {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 1.25rem;
            color: rgba(255,255,255,0.65);
            text-decoration: none; font-size: 14px;
        }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: rgba(200,168,75,0.15); color: #f0d080; border-right: 3px solid #c8a84b; }
        .nav-item i { width: 20px; text-align: center; }
        .sidebar-user {
            padding: 1rem 1.25rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex; align-items: center; gap: 10px;
        }
        .avatar {
            width: 36px; height: 36px; border-radius: 50%;
            background: rgba(200,168,75,0.2); border: 1.5px solid #c8a84b;
            display: flex; align-items: center; justify-content: center;
            color: #f0d080; font-weight: 600;
        }
        .user-info p { font-size: 13px; color: #fff; font-weight: 500; }
        .user-info span { font-size: 11px; color: rgba(255,255,255,0.45); }
        .btn-logout {
            display: block; margin: 0.5rem 1rem 1rem;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 6px; padding: 8px;
            color: rgba(255,255,255,0.55); text-align: center;
            text-decoration: none; font-size: 12px;
        }
        .btn-logout:hover { background: rgba(255,0,0,0.15); color: #ff9999; }

        /* ── Main layout ─────────────────────────────── */
        .main { margin-left: 240px; min-height: 100vh; }
        .topbar {
            background: #fff; border-bottom: 1px solid #dde3f0;
            padding: 1rem 2rem;
            display: flex; align-items: center; justify-content: space-between;
        }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 20px; color: #0a2a5e; }
        .topbar h1 i { margin-right: 8px; color: #c8a84b; }
        .content { padding: 2rem; }

        /* ── Purok Summary Bar ───────────────────────── */
        .purok-summary {
            display: flex; flex-wrap: wrap; gap: 8px;
            margin-bottom: 1.5rem;
        }
        .purok-chip {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 6px 14px; border-radius: 20px;
            font-size: 12px; font-weight: 600;
            border: 1.5px solid transparent;
            cursor: pointer; text-decoration: none;
            transition: all 0.15s;
        }
        .purok-chip:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,0.12); }
        .purok-chip .chip-count {
            background: rgba(0,0,0,0.12);
            padding: 1px 7px; border-radius: 10px;
            font-size: 11px;
        }
        /* Alternating colors for puroks */
        .purok-chip:nth-child(1)  { background:#e8f0fe; color:#1a56db; border-color:#c3d7fe; }
        .purok-chip:nth-child(2)  { background:#fef3e2; color:#b45309; border-color:#fde68a; }
        .purok-chip:nth-child(3)  { background:#e8fdf0; color:#1a6e3a; border-color:#a7f3c3; }
        .purok-chip:nth-child(4)  { background:#fde8f8; color:#9d174d; border-color:#f9a8d4; }
        .purok-chip:nth-child(5)  { background:#eff6ff; color:#1e40af; border-color:#bfdbfe; }
        .purok-chip:nth-child(6)  { background:#fef9e7; color:#92400e; border-color:#fcd34d; }
        .purok-chip:nth-child(7)  { background:#f0fdf4; color:#166534; border-color:#86efac; }
        .purok-chip:nth-child(8)  { background:#fdf2f8; color:#86198f; border-color:#f0abfc; }
        .purok-chip:nth-child(9)  { background:#ede9fe; color:#5b21b6; border-color:#c4b5fd; }
        .purok-chip:nth-child(10) { background:#fff1f2; color:#be123c; border-color:#fda4af; }
        .purok-chip.active-chip   { opacity: 1; box-shadow: 0 0 0 2px #0a2a5e, 0 3px 10px rgba(0,0,0,0.12); }

        /* ── Card ─────────────────────────────────────── */
        .card {
            background: #fff; border-radius: 12px;
            border: 1px solid #dde3f0; margin-bottom: 2rem; overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.5rem; background: #f7f9fc;
            border-bottom: 1px solid #eef0f6; font-weight: 600;
        }

        /* ── Filters ─────────────────────────────────── */
        .filters {
            display: flex; flex-wrap: wrap; gap: 10px;
            margin-bottom: 1.5rem; align-items: center;
        }
        .filters input, .filters select {
            padding: 8px 12px; border: 1px solid #d0dae8;
            border-radius: 8px; font-size: 13px;
        }

        /* ── Buttons ─────────────────────────────────── */
        button, .btn {
            background: #0a2a5e; color: white;
            border: none; padding: 8px 16px;
            border-radius: 40px; cursor: pointer;
            font-size: 13px; font-weight: 500;
        }
        button i, .btn i { margin-right: 6px; }
        button:hover { background: #1a407a; }
        .btn-danger { background: #b33; }
        .btn-danger:hover { background: #a22; }
        .btn-clear { background: #999; }
        .btn-clear:hover { background: #777; }

        /* ── Table ───────────────────────────────────── */
        table { width: 100%; border-collapse: collapse; }
        th, td {
            padding: 12px 1rem; text-align: left;
            border-bottom: 1px solid #eef0f6; font-size: 13px;
        }
        th { background: #f7f9fc; font-weight: 600; color: #2a4a6e; }
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button { padding: 4px 12px; font-size: 12px; }

        /* ── Purok badge in table ─────────────────────── */
        .purok-badge {
            display: inline-block; padding: 2px 10px;
            border-radius: 20px; font-size: 11px; font-weight: 700;
            white-space: nowrap;
        }
        .purok-badge[data-purok="Purok 1"]  { background:#e8f0fe; color:#1a56db; }
        .purok-badge[data-purok="Purok 2"]  { background:#fef3e2; color:#b45309; }
        .purok-badge[data-purok="Purok 3"]  { background:#e8fdf0; color:#1a6e3a; }
        .purok-badge[data-purok="Purok 4"]  { background:#fde8f8; color:#9d174d; }
        .purok-badge[data-purok="Purok 5"]  { background:#eff6ff; color:#1e40af; }
        .purok-badge[data-purok="Purok 6"]  { background:#fef9e7; color:#92400e; }
        .purok-badge[data-purok="Purok 7"]  { background:#f0fdf4; color:#166534; }
        .purok-badge[data-purok="Purok 8"]  { background:#fdf2f8; color:#86198f; }
        .purok-badge[data-purok="Purok 9"]  { background:#ede9fe; color:#5b21b6; }
        .purok-badge[data-purok="Purok 10"] { background:#fff1f2; color:#be123c; }

        /* ── Status badge ─────────────────────────────── */
        .status-badge {
            display: inline-block; padding: 2px 9px;
            border-radius: 20px; font-size: 11px; font-weight: 600;
        }
        .status-active   { background: #e6f5ee; color: #1a6e3a; }
        .status-inactive { background: #f5f5f5; color: #555; }

        /* ── Pagination ──────────────────────────────── */
        .pagination {
            display: flex; align-items: center; justify-content: space-between;
            padding: 1rem 1.25rem; border-top: 1px solid #eef0f6;
            font-size: 12px; flex-wrap: wrap; gap: 0.5rem;
        }
        .page-links { display: flex; gap: 4px; }
        .page-links a, .page-links span {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px; border-radius: 6px;
            text-decoration: none; color: #4a5a7a;
            border: 1px solid #dde3f0; background: #fff;
        }
        .page-links span.current { background: #0a2a5e; color: #f0d080; border-color: #0a2a5e; }
        .page-links a:hover { background: #f0f3f9; }

        /* ── Modal ───────────────────────────────────── */
        .modal {
            display: none; position: fixed; top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center; justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: white; max-width: 640px; width: 90%;
            border-radius: 16px; max-height: 90vh; overflow-y: auto;
        }
        .modal-header {
            padding: 1rem 1.5rem; border-bottom: 1px solid #ddd;
            font-weight: bold; display: flex; justify-content: space-between;
        }
        .close-modal { cursor: pointer; font-size: 20px; }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem; padding: 1.5rem;
        }
        .form-group label {
            display: block; font-size: 12px; font-weight: 600;
            color: #2a5a7a; margin-bottom: 4px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 8px 10px;
            border: 1px solid #d0dae8; border-radius: 8px;
            font-family: inherit; font-size: 13px;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }

        /* Purok select highlight */
        #purok { font-weight: 600; }

        /* full-width span inside grid */
        .full-width { grid-column: 1 / -1; }

        /* ── Toast ───────────────────────────────────── */
        #toast {
            position: fixed; bottom: 1.5rem; right: 1.5rem;
            padding: 10px 18px; border-radius: 8px;
            font-size: 13px; font-weight: 600; color: #fff;
            box-shadow: 0 8px 24px rgba(0,0,0,0.2);
            opacity: 0; transform: translateY(8px);
            transition: opacity 0.2s, transform 0.2s;
            z-index: 9999; pointer-events: none;
        }
        #toast.show { opacity: 1; transform: translateY(0); }
        #toast.ok  { background: #1a6e3a; }
        #toast.err { background: #a82020; }

        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; height: auto; }
            .main { margin-left: 0; }
        }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h1><i class="fas fa-users"></i> Residents Management</h1>
    </div>
    <div class="content">

        <!-- Add Resident Button -->
        <div style="margin-bottom: 1.5rem;">
            <button id="openAddModalBtn"><i class="fas fa-plus-circle"></i> Add New Resident</button>
        </div>

        <!-- Purok Summary Chips -->
        <div class="purok-summary">
            <?php foreach ($purok_list as $p):
                $isActive = ($filter_purok === $p);
                $url = buildQuery(['purok' => $p]);
            ?>
            <a href="<?= $url ?>"
               class="purok-chip <?= $isActive ? 'active-chip' : '' ?>"
               title="Filter by <?= $p ?>">
                <i class="fas fa-map-marker-alt"></i>
                <?= $p ?>
                <span class="chip-count"><?= $purokCounts[$p] ?></span>
            </a>
            <?php endforeach; ?>
            <?php if ($filter_purok): ?>
                <a href="<?= buildQuery(['purok' => '']) ?>" class="btn btn-clear" style="text-decoration:none; font-size:12px; padding:6px 14px;">
                    <i class="fas fa-times"></i> All Puroks
                </a>
            <?php endif; ?>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; flex:1; align-items:center;">
                <?php if ($filter_purok): ?>
                    <input type="hidden" name="purok" value="<?= htmlspecialchars($filter_purok) ?>">
                <?php endif; ?>
                <input type="text" name="search" placeholder="Search name, email, or phone…" value="<?= htmlspecialchars($search) ?>">
                <select name="gender">
                    <option value="">All genders</option>
                    <option value="Male"   <?= $filter_gender === 'Male'   ? 'selected' : '' ?>>Male</option>
                    <option value="Female" <?= $filter_gender === 'Female' ? 'selected' : '' ?>>Female</option>
                    <option value="Other"  <?= $filter_gender === 'Other'  ? 'selected' : '' ?>>Other</option>
                </select>
                <select name="status">
                    <option value="">All statuses</option>
                    <option value="1" <?= $filter_status === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $filter_status === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
                <?php if ($search || $filter_gender || $filter_status || $filter_purok): ?>
                    <a href="residents.php" class="btn btn-clear" style="text-decoration:none;"><i class="fas fa-times"></i> Clear All</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Residents List -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-list"></i>
                <?php if ($filter_purok): ?>
                    Residents in <strong><?= htmlspecialchars($filter_purok) ?></strong>
                <?php else: ?>
                    All Residents
                <?php endif; ?>
                <span style="font-weight:400; color:#555;">(<?= $total ?> total)</span>
            </div>
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Full Name</th>
                            <th>Purok</th>
                            <th>Gender</th>
                            <th>Address</th>
                            <th>Contact</th>
                            <th>Status</th>
                            <th>Registered</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($residents && $residents->num_rows > 0): ?>
                            <?php while ($r = $residents->fetch_assoc()): ?>
                            <tr data-id="<?= $r['id'] ?>">
                                <td><?= $r['id'] ?></td>
                                <td><strong><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></strong></td>
                                <td>
                                    <?php $purokVal = htmlspecialchars($r['purok'] ?? '—'); ?>
                                    <?php if ($r['purok']): ?>
                                        <span class="purok-badge" data-purok="<?= $purokVal ?>"><?= $purokVal ?></span>
                                    <?php else: ?>
                                        <span style="color:#aaa;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($r['gender']) ?></td>
                                <td><?= htmlspecialchars(strlen($r['full_address']) > 35 ? substr($r['full_address'], 0, 35) . '…' : $r['full_address']) ?></td>
                                <td><?= htmlspecialchars($r['contact_number'] ?? '—') ?></td>
                                <td>
                                    <span class="status-badge <?= $r['is_active'] ? 'status-active' : 'status-inactive' ?>">
                                        <?= $r['is_active'] ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                <td class="action-buttons">
                                    <button class="editBtn" data-id="<?= $r['id'] ?>"><i class="fas fa-edit"></i> Edit</button>
                                    <button class="deleteBtn btn-danger" data-id="<?= $r['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="9" style="text-align:center; padding:2rem; color:#888;">
                                    <i class="fas fa-inbox" style="font-size:2rem; display:block; margin-bottom:.5rem; color:#ccc;"></i>
                                    No residents found. <?= (!$search && !$filter_gender && !$filter_status && !$filter_purok) ? 'Click "Add New Resident" to get started.' : 'Try adjusting your filters.' ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <span>Page <?= $page ?> of <?= $total_pages ?> — <?= $total ?> record<?= $total !== 1 ? 's' : '' ?></span>
                <div class="page-links">
                    <?php if ($page > 1): ?>
                        <a href="<?= buildQuery(['page' => 1]) ?>">&laquo;</a>
                        <a href="<?= buildQuery(['page' => $page - 1]) ?>">&lsaquo;</a>
                    <?php endif; ?>
                    <?php
                    $start = max(1, $page - 2);
                    $end   = min($total_pages, $page + 2);
                    for ($p = $start; $p <= $end; $p++):
                        if ($p == $page): ?>
                            <span class="current"><?= $p ?></span>
                        <?php else: ?>
                            <a href="<?= buildQuery(['page' => $p]) ?>"><?= $p ?></a>
                        <?php endif;
                    endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="<?= buildQuery(['page' => $page + 1]) ?>">&rsaquo;</a>
                        <a href="<?= buildQuery(['page' => $total_pages]) ?>">&raquo;</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /content -->
</div><!-- /main -->

<!-- ============================================================
     Modal: Add / Edit Resident
     ============================================================ -->
<div id="residentModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle"><i class="fas fa-user-plus"></i> Add New Resident</span>
            <span class="close-modal">&times;</span>
        </div>
        <form id="residentForm">
            <input type="hidden" id="residentId">
            <div class="form-grid">

                <!-- Row 1: Names -->
                <div class="form-group">
                    <label><i class="fas fa-user"></i> First Name *</label>
                    <input type="text" id="first_name" required placeholder="e.g. Juan">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Last Name *</label>
                    <input type="text" id="last_name" required placeholder="e.g. dela Cruz">
                </div>
                <div class="form-group">
                    <label>Middle Name</label>
                    <input type="text" id="middle_name" placeholder="e.g. Santos">
                </div>

                <!-- Row 2: Address + Purok -->
                <div class="form-group">
                    <label><i class="fas fa-home"></i> Full Address *</label>
                    <input type="text" id="full_address" required placeholder="Street / Sitio, City">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-map-marker-alt"></i> Purok *</label>
                    <select id="purok" required>
                        <option value="">— Select Purok —</option>
                        <?php foreach ($purok_list as $p): ?>
                            <option value="<?= $p ?>"><?= $p ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Row 3: Personal -->
                <div class="form-group">
                    <label><i class="fas fa-birthday-cake"></i> Birthdate</label>
                    <input type="date" id="birthdate">
                </div>
                <div class="form-group">
                    <label>Gender</label>
                    <select id="gender">
                        <option>Male</option>
                        <option>Female</option>
                        <option>Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Civil Status</label>
                    <select id="civil_status">
                        <option>Single</option>
                        <option>Married</option>
                        <option>Widowed</option>
                        <option>Separated</option>
                        <option>Divorced</option>
                    </select>
                </div>

                <!-- Row 4: Contact -->
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Contact Number</label>
                    <input type="tel" id="contact_number" placeholder="09XX-XXX-XXXX">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email</label>
                    <input type="email" id="email" placeholder="juan@example.com">
                </div>
                <div class="form-group">
                    <label><i class="fas fa-briefcase"></i> Occupation</label>
                    <input type="text" id="occupation" placeholder="e.g. Farmer, Teacher">
                </div>

            </div><!-- /form-grid -->

            <div style="padding: 1rem 1.5rem 1.5rem; text-align:right; display:flex; gap:8px; justify-content:flex-end; border-top:1px solid #eef0f6;">
                <button type="button" id="cancelModalBtn" class="btn-clear"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="saveBtn"><i class="fas fa-save"></i> Save Resident</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script>
    // ── API helper ──────────────────────────────────────────
    async function apiRequest(action, formData) {
        formData.append('action', action);
        const res = await fetch(window.location.href, { method: 'POST', body: formData });
        return res.json();
    }

    function showToast(msg, type) {
        const t = document.getElementById('toast');
        t.textContent = msg;
        t.className = 'show ' + (type === 'ok' ? 'ok' : 'err');
        clearTimeout(t._timer);
        t._timer = setTimeout(() => { t.className = ''; }, 3000);
    }

    // ── Modal helpers ───────────────────────────────────────
    function openModal() { document.getElementById('residentModal').style.display = 'flex'; }
    function closeModal() { document.getElementById('residentModal').style.display = 'none'; }

    document.querySelectorAll('.close-modal, #cancelModalBtn')
        .forEach(el => el.addEventListener('click', closeModal));
    window.addEventListener('click', e => {
        if (e.target === document.getElementById('residentModal')) closeModal();
    });

    // ── Add Resident ────────────────────────────────────────
    document.getElementById('openAddModalBtn').onclick = () => {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-plus"></i> Add New Resident';
        document.getElementById('residentForm').reset();
        document.getElementById('residentId').value = '';
        openModal();
    };

    // ── Edit Resident ───────────────────────────────────────
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.onclick = async () => {
            const id = btn.getAttribute('data-id');
            const fd = new FormData();
            fd.append('id', id);
            const result = await apiRequest('get', fd);
            if (result.ok && result.data) {
                const d = result.data;
                document.getElementById('residentId').value       = d.id;
                document.getElementById('first_name').value       = d.first_name   || '';
                document.getElementById('last_name').value        = d.last_name    || '';
                document.getElementById('middle_name').value      = d.middle_name  || '';
                document.getElementById('full_address').value     = d.full_address || '';
                document.getElementById('purok').value            = d.purok        || '';
                document.getElementById('birthdate').value        = d.birthdate    || '';
                document.getElementById('gender').value           = d.gender       || 'Male';
                document.getElementById('civil_status').value     = d.civil_status || 'Single';
                document.getElementById('contact_number').value   = d.contact_number || '';
                document.getElementById('email').value            = d.email        || '';
                document.getElementById('occupation').value       = d.occupation   || '';
                document.getElementById('modalTitle').innerHTML   = '<i class="fas fa-edit"></i> Edit Resident';
                openModal();
            } else {
                showToast('Could not load resident data.', 'err');
            }
        };
    });

    // ── Delete Resident ─────────────────────────────────────
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.onclick = async () => {
            const resName = btn.closest('tr').querySelector('td:nth-child(2)').innerText;
            if (!confirm(`Are you sure you want to delete "${resName}"?\nThis action cannot be undone.`)) return;
            const fd = new FormData();
            fd.append('id', btn.getAttribute('data-id'));
            const result = await apiRequest('delete', fd);
            showToast(result.msg, result.ok ? 'ok' : 'err');
            if (result.ok) setTimeout(() => location.reload(), 800);
        };
    });

    // ── Form Submit (Add / Edit) ────────────────────────────
    document.getElementById('residentForm').addEventListener('submit', async e => {
        e.preventDefault();

        const id     = document.getElementById('residentId').value;
        const action = id ? 'edit' : 'add';
        const fd     = new FormData();

        const fields = ['first_name','last_name','middle_name','full_address','purok',
                        'birthdate','gender','civil_status','contact_number','email','occupation'];

        fields.forEach(f => fd.append(f, document.getElementById(f).value.trim()));
        if (id) fd.append('id', id);

        const result = await apiRequest(action, fd);
        if (result.ok) {
            showToast(result.msg, 'ok');
            closeModal();
            setTimeout(() => location.reload(), 800);
        } else {
            showToast(result.msg || 'Operation failed.', 'err');
        }
    });
</script>
</body>
</html>