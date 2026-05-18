<?php
// ============================================================
// blotter.php - BARANGAY BLOTTER / INCIDENT REPORTS
// Matching the design of residents.php and cases.php
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'blotter';

function generateBlotterNumber() {
    $prefix = 'BLOTTER';
    $year = date('Y');
    $month = date('m');
    $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    return $prefix . '-' . $year . $month . '-' . $random;
}

function logActivity($conn, $action, $details) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    if ($stmt) {
        $stmt->bind_param('isss', $user_id, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
}

// API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    $action = $_POST['action'];

    // ADD BLOTTER
    if ($action === 'add') {
        $complainant_id = (int)($_POST['complainant_id'] ?? 0);
        $complainant_name = trim($_POST['complainant_name'] ?? '');
        $respondent_name = trim($_POST['respondent_name'] ?? '');
        $incident_type = $_POST['incident_type'] ?? '';
        $incident_date = $_POST['incident_date'] ?? '';
        $incident_time = !empty($_POST['incident_time']) ? $_POST['incident_time'] : null;
        $location = trim($_POST['location'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $investigating_officer = trim($_POST['investigating_officer'] ?? '');

        if (!$complainant_name || !$respondent_name || !$incident_type || !$incident_date || !$description) {
            echo json_encode(['ok' => false, 'msg' => 'Complainant name, respondent name, incident type, date, and description are required.']);
            $conn->close(); exit;
        }

        $blotter_number = generateBlotterNumber();
        
        $stmt = $conn->prepare(
            "INSERT INTO blotter (blotter_number, incident_type, complainant_id, complainant_name, respondent_name, incident_date, incident_time, location, description, status, investigating_officer, created_by) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'reported', ?, ?)"
        );
        $stmt->bind_param('ssisssssssi', 
            $blotter_number, $incident_type, $complainant_id, $complainant_name, $respondent_name, 
            $incident_date, $incident_time, $location, $description, $investigating_officer, $_SESSION['user_id']
        );
        
        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'BLOTTER_CREATED', "Blotter $blotter_number: $incident_type");
        }
        
        echo json_encode([
            'ok' => $ok, 
            'msg' => $ok ? "Blotter report filed. Reference: $blotter_number" : 'Database error: ' . $stmt->error,
            'blotter_number' => $blotter_number
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // UPDATE BLOTTER
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $resolution = trim($_POST['resolution'] ?? '');
        $investigating_officer = trim($_POST['investigating_officer'] ?? '');

        if ($id <= 0 || !$status) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid request.']);
            $conn->close(); exit;
        }

        $resolved_date = ($status === 'resolved') ? date('Y-m-d') : null;
        
        $stmt = $conn->prepare(
            "UPDATE blotter 
             SET status = ?, resolution = ?, investigating_officer = COALESCE(?, investigating_officer), resolved_date = COALESCE(?, resolved_date), updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param('ssssi', $status, $resolution, $investigating_officer, $resolved_date, $id);
        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'BLOTTER_UPDATED', "Blotter ID $id status changed to $status");
        }
        
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Blotter updated successfully.' : 'Update failed: ' . $stmt->error]);
        $stmt->close(); $conn->close(); exit;
    }

    // DELETE BLOTTER
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid blotter ID.']);
            $conn->close(); exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM blotter WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'BLOTTER_DELETED', "Deleted blotter ID $id");
        }
        
        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Blotter deleted successfully.' : 'Delete failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // GET SINGLE BLOTTER
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid blotter ID.']);
            $conn->close(); exit;
        }
        
        $stmt = $conn->prepare("SELECT * FROM blotter WHERE id = ? LIMIT 1");
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

// HTML PAGE (GET)
$conn = getConnection();

$per_page = 15;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = [];
$params = [];
$types = '';

if ($search) {
    $where[] = "(blotter_number LIKE ? OR complainant_name LIKE ? OR respondent_name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}

if ($filter_status) {
    $where[] = "status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_type) {
    $where[] = "incident_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

$countSql = "SELECT COUNT(*) AS total FROM blotter";
if ($where) $countSql .= " WHERE " . implode(" AND ", $where);
$countStmt = $conn->prepare($countSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$total_pages = max(1, ceil($total / $per_page));

$sql = "SELECT * FROM blotter";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY incident_date DESC, created_at DESC LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if ($params) $stmt->bind_param($types, ...$params);
$stmt->execute();
$blotters = $stmt->get_result();
$stmt->close();

$residents = $conn->query("SELECT id, first_name, last_name FROM residents ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();

function getBadgeClass(string $status): string {
    return match($status) {
        'reported'            => 'badge-pending',
        'under_investigation' => 'badge-under',
        'resolved'            => 'badge-resolved',
        'dismissed'           => 'badge-dismissed',
        'referred_to_police'  => 'badge-other',
        default               => 'badge-other',
    };
}

function formatStatus($status) {
    return str_replace('_', ' ', ucfirst($status));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blotter - Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Source Sans 3', sans-serif;
            background: #f0f3f9;
            color: #1a2a4a;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed; left: 0; top: 0; bottom: 0;
            width: 240px;
            background: #0a2a5e;
            display: flex;
            flex-direction: column;
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
            font-size: 14px;
            color: #f0d080;
        }
        .nav-section { padding: 1rem 0; flex: 1; }
        .nav-label {
            font-size: 10px;
            color: rgba(255,255,255,0.3);
            letter-spacing: 0.12em;
            text-transform: uppercase;
            padding: 0 1.25rem;
            margin-top: 12px;
        }
        .nav-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 1.25rem;
            color: rgba(255,255,255,0.65);
            text-decoration: none;
            font-size: 14px;
        }
        .nav-item i { width: 20px; text-align: center; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: rgba(200,168,75,0.15); color: #f0d080; border-right: 3px solid #c8a84b; }
        
        .sidebar-user {
            padding: 1rem 1.25rem;
            border-top: 1px solid rgba(255,255,255,0.1);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .avatar {
            width: 36px; height: 36px;
            border-radius: 50%;
            background: rgba(200,168,75,0.2);
            border: 1.5px solid #c8a84b;
            display: flex; align-items: center; justify-content: center;
            color: #f0d080;
            font-weight: 600;
        }
        .user-info p { font-size: 13px; color: #fff; font-weight: 500; }
        .user-info span { font-size: 11px; color: rgba(255,255,255,0.45); }
        .btn-logout {
            display: block;
            margin: 0.5rem 1rem 1rem;
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.12);
            border-radius: 6px;
            padding: 8px;
            color: rgba(255,255,255,0.55);
            text-align: center;
            text-decoration: none;
            font-size: 12px;
        }
        .btn-logout:hover { background: rgba(255,0,0,0.15); color: #ff9999; }

        /* Main Content */
        .main { margin-left: 240px; min-height: 100vh; }
        .topbar {
            background: #fff;
            border-bottom: 1px solid #dde3f0;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 20px; color: #0a2a5e; }
        .topbar h1 i { margin-right: 8px; color: #c8a84b; }
        .content { padding: 2rem; }
        
        .card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dde3f0;
            margin-bottom: 2rem;
            overflow: hidden;
        }
        .card-header {
            padding: 1rem 1.5rem;
            background: #f7f9fc;
            border-bottom: 1px solid #eef0f6;
            font-weight: 600;
        }
        
        .filters {
            display: flex;
            gap: 10px;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            align-items: center;
        }
        .filters input, .filters select {
            padding: 8px 12px;
            border: 1px solid #d0dae8;
            border-radius: 8px;
            font-size: 13px;
        }
        
        button, .btn {
            background: #0a2a5e;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 40px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
        }
        button i, .btn i { margin-right: 6px; }
        button:hover { background: #1a407a; }
        .btn-danger { background: #b33; }
        .btn-danger:hover { background: #a22; }
        .btn-clear { background: #999; }
        .btn-clear:hover { background: #777; }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th, td {
            padding: 12px 1rem;
            text-align: left;
            border-bottom: 1px solid #eef0f6;
            font-size: 13px;
        }
        th {
            background: #f7f9fc;
            font-weight: 600;
            color: #2a4a6e;
        }
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        .action-buttons button {
            padding: 4px 12px;
            font-size: 12px;
        }
        
        .badge {
            display: inline-block;
            padding: 2px 9px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-pending    { background: #fff3e0; color: #b36200; }
        .badge-resolved   { background: #e6f5ee; color: #1a6e3a; }
        .badge-dismissed  { background: #f5f5f5; color: #555; }
        .badge-under      { background: #e6eefa; color: #1a3a7a; }
        .badge-other      { background: #f0f0f0; color: #555; }
        
        .pagination {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 1rem 1.25rem;
            border-top: 1px solid #eef0f6;
            font-size: 12px;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .page-links { display: flex; gap: 4px; }
        .page-links a, .page-links span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 30px; height: 30px;
            border-radius: 6px;
            text-decoration: none;
            color: #4a5a7a;
            border: 1px solid #dde3f0;
            background: #fff;
        }
        .page-links span.current { background: #0a2a5e; color: #f0d080; border-color: #0a2a5e; }
        .page-links a:hover { background: #f0f3f9; }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0; left: 0;
            width: 100%; height: 100%;
            background: rgba(0,0,0,0.5);
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: white;
            max-width: 650px;
            width: 90%;
            border-radius: 16px;
            max-height: 90vh;
            overflow-y: auto;
        }
        .modal-header {
            padding: 1rem 1.5rem;
            border-bottom: 1px solid #ddd;
            font-weight: bold;
            display: flex;
            justify-content: space-between;
            background: #0a2a5e;
            color: #f0d080;
        }
        .close-modal {
            cursor: pointer;
            font-size: 20px;
            color: white;
        }
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1rem;
            padding: 1.5rem;
        }
        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: #2a5a7a;
            margin-bottom: 4px;
        }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%;
            padding: 8px 10px;
            border: 1px solid #d0dae8;
            border-radius: 8px;
            font-family: inherit;
            font-size: 13px;
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        
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
        #toast.ok   { background: #1a6e3a; }
        #toast.err  { background: #a82020; }
        
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
        <h1><i class="fas fa-clipboard-list"></i> Blotter & Incident Reports</h1>
    </div>
    <div class="content">

        <!-- Add Blotter Button -->
        <div style="margin-bottom: 1.5rem;">
            <button id="openAddModalBtn"><i class="fas fa-plus-circle"></i> File Blotter Report</button>
        </div>

        <!-- Filters -->
        <div class="filters">
            <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; flex: 1;">
                <input type="text" name="search" placeholder="Search blotter # or name..." value="<?= htmlspecialchars($search) ?>">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="reported" <?= $filter_status === 'reported' ? 'selected' : '' ?>>Reported</option>
                    <option value="under_investigation" <?= $filter_status === 'under_investigation' ? 'selected' : '' ?>>Under Investigation</option>
                    <option value="resolved" <?= $filter_status === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                    <option value="dismissed" <?= $filter_status === 'dismissed' ? 'selected' : '' ?>>Dismissed</option>
                    <option value="referred_to_police" <?= $filter_status === 'referred_to_police' ? 'selected' : '' ?>>Referred to Police</option>
                </select>
                <select name="type">
                    <option value="">All Types</option>
                    <option value="Theft" <?= $filter_type === 'Theft' ? 'selected' : '' ?>>Theft</option>
                    <option value="Physical Injury" <?= $filter_type === 'Physical Injury' ? 'selected' : '' ?>>Physical Injury</option>
                    <option value="Verbal Argument" <?= $filter_type === 'Verbal Argument' ? 'selected' : '' ?>>Verbal Argument</option>
                    <option value="Threat" <?= $filter_type === 'Threat' ? 'selected' : '' ?>>Threat</option>
                    <option value="Property Damage" <?= $filter_type === 'Property Damage' ? 'selected' : '' ?>>Property Damage</option>
                    <option value="Noise Complaint" <?= $filter_type === 'Noise Complaint' ? 'selected' : '' ?>>Noise Complaint</option>
                    <option value="Others" <?= $filter_type === 'Others' ? 'selected' : '' ?>>Others</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
                <?php if ($search || $filter_status || $filter_type): ?>
                    <a href="blotter.php" class="btn btn-clear" style="text-decoration: none;"><i class="fas fa-times"></i> Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Blotter List -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> All Blotter Reports (<?= $total ?> total)</div>
            <div style="overflow-x: auto;">
                <table class="blotter-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Blotter #</th><th>Complainant</th><th>Respondent</th>
                            <th>Type</th><th>Status</th><th>Incident Date</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($blotters && $blotters->num_rows > 0): ?>
                            <?php while ($b = $blotters->fetch_assoc()): ?>
                            <tr data-id="<?= $b['id'] ?>">
                                <td><?= $b['id'] ?></td>
                                <td><strong><?= htmlspecialchars($b['blotter_number']) ?></strong></td>
                                <td><?= htmlspecialchars($b['complainant_name']) ?></td>
                                <td><?= htmlspecialchars($b['respondent_name']) ?></td>
                                <td><?= htmlspecialchars($b['incident_type']) ?></td>
                                <td><span class="badge <?= getBadgeClass($b['status']) ?>"><?= formatStatus($b['status']) ?></span></td>
                                <td><?= $b['incident_date'] ? date('M j, Y', strtotime($b['incident_date'])) : '—' ?></td>
                                <td class="action-buttons">
                                    <button class="editBtn" data-id="<?= $b['id'] ?>"><i class="fas fa-edit"></i> Update</button>
                                    <button class="deleteBtn btn-danger" data-id="<?= $b['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;">No blotter reports found. Click "File Blotter Report" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div class="pagination">
                <span>Page <?= $page ?> of <?= $total_pages ?></span>
                <div class="page-links">
                    <?php if ($page > 1): ?><a href="?page=<?= $page-1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_status ? '&status=' . urlencode($filter_status) : '' ?><?= $filter_type ? '&type=' . urlencode($filter_type) : '' ?>">&laquo;</a><?php endif;
                    $start = max(1, $page-3); $end = min($total_pages, $page+3);
                    if ($start > 1): ?><a href="?page=1">1</a><?php if ($start > 2): ?><span class="dots">&hellip;</span><?php endif; endif;
                    for ($p = $start; $p <= $end; $p++):
                        if ($p == $page): ?><span class="current"><?= $p ?></span><?php
                        else: ?><a href="?page=<?= $p ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_status ? '&status=' . urlencode($filter_status) : '' ?><?= $filter_type ? '&type=' . urlencode($filter_type) : '' ?>"><?= $p ?></a><?php endif;
                    endfor;
                    if ($end < $total_pages): if ($end < $total_pages-1): ?><span class="dots">&hellip;</span><?php endif;
                        ?><a href="?page=<?= $total_pages ?>"><?= $total_pages ?></a><?php endif;
                    if ($page < $total_pages): ?><a href="?page=<?= $page+1 ?><?= $search ? '&search=' . urlencode($search) : '' ?><?= $filter_status ? '&status=' . urlencode($filter_status) : '' ?><?= $filter_type ? '&type=' . urlencode($filter_type) : '' ?>">&raquo;</a><?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal for Add/Edit Blotter -->
<div id="blotterModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle"><i class="fas fa-clipboard-list"></i> File Blotter Report</span>
            <span class="close-modal">&times;</span>
        </div>
        <form id="blotterForm">
            <input type="hidden" id="blotterId">
            <div class="form-grid">
                <div class="form-group"><label>Complainant Name *</label><input type="text" id="complainant_name" required placeholder="Full name of complainant"></div>
                <div class="form-group"><label>Complainant (Resident)</label>
                    <select id="complainant_id">
                        <option value="0">— Non-resident / Walk-in —</option>
                        <?php foreach ($residents as $res): ?>
                            <option value="<?= $res['id'] ?>"><?= htmlspecialchars($res['last_name'] . ', ' . $res['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Respondent Name *</label><input type="text" id="respondent_name" required placeholder="Full name of respondent"></div>
                <div class="form-group"><label>Incident Type *</label>
                    <select id="incident_type" required>
                        <option>Theft</option><option>Physical Injury</option><option>Verbal Argument</option>
                        <option>Threat</option><option>Property Damage</option><option>Noise Complaint</option><option>Others</option>
                    </select>
                </div>
                <div class="form-group"><label>Incident Date *</label><input type="date" id="incident_date" required></div>
                <div class="form-group"><label>Incident Time</label><input type="time" id="incident_time"></div>
                <div class="form-group"><label>Location</label><input type="text" id="location" placeholder="Where did it happen?"></div>
                <div class="form-group"><label>Description *</label><textarea id="description" rows="3" required placeholder="Detailed account of the incident..."></textarea></div>
                <div class="form-group"><label>Investigating Officer</label><input type="text" id="investigating_officer" placeholder="Barangay Tanod / Officer assigned"></div>
                <div class="form-group" id="statusGroup" style="display:none;"><label>Status</label>
                    <select id="status">
                        <option value="reported">Reported</option>
                        <option value="under_investigation">Under Investigation</option>
                        <option value="resolved">Resolved</option>
                        <option value="dismissed">Dismissed</option>
                        <option value="referred_to_police">Referred to Police</option>
                    </select>
                </div>
                <div class="form-group" id="resolutionGroup" style="display:none;"><label>Resolution / Action Taken</label><textarea id="resolution" rows="2" placeholder="How was this resolved?"></textarea></div>
            </div>
            <div style="padding: 1rem 1.5rem 1.5rem; text-align: right;">
                <button type="button" id="cancelModalBtn"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="saveBtn"><i class="fas fa-save"></i> Save Report</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script>
    let isEdit = false;
    
    async function apiRequest(action, formData) {
        formData.append('action', action);
        const res = await fetch(window.location.href, {
            method: 'POST',
            body: formData
        });
        return res.json();
    }

    function refreshPage() { location.reload(); }

    function showToast(msg, type) {
        let toast = document.getElementById('toast');
        toast.textContent = msg;
        toast.className = 'show ' + (type === 'ok' ? 'ok' : 'err');
        clearTimeout(toast._timer);
        toast._timer = setTimeout(() => { toast.className = ''; }, 3000);
    }

    // Form submission
    document.getElementById('blotterForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('blotterId').value;
        const action = id ? 'edit' : 'add';

        const formData = new FormData();
        if (!id) {
            formData.append('complainant_name', document.getElementById('complainant_name').value.trim());
            formData.append('complainant_id', document.getElementById('complainant_id').value);
            formData.append('respondent_name', document.getElementById('respondent_name').value.trim());
            formData.append('incident_type', document.getElementById('incident_type').value);
            formData.append('incident_date', document.getElementById('incident_date').value);
            formData.append('incident_time', document.getElementById('incident_time').value);
            formData.append('location', document.getElementById('location').value.trim());
            formData.append('description', document.getElementById('description').value.trim());
            formData.append('investigating_officer', document.getElementById('investigating_officer').value.trim());
        } else {
            formData.append('id', id);
            formData.append('status', document.getElementById('status').value);
            formData.append('resolution', document.getElementById('resolution').value.trim());
            formData.append('investigating_officer', document.getElementById('investigating_officer').value.trim());
        }

        const result = await apiRequest(action, formData);
        if (result.ok) {
            showToast(result.msg, 'ok');
            closeModal();
            setTimeout(refreshPage, 800);
        } else {
            showToast(result.msg || 'Operation failed.', 'err');
        }
    });

    // Open modal for Add
    document.getElementById('openAddModalBtn').onclick = () => {
        isEdit = false;
        document.getElementById('blotterForm').reset();
        document.getElementById('blotterId').value = '';
        document.getElementById('statusGroup').style.display = 'none';
        document.getElementById('resolutionGroup').style.display = 'none';
        document.getElementById('incident_date').valueAsDate = new Date();
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-clipboard-list"></i> File Blotter Report';
        document.getElementById('blotterModal').style.display = 'flex';
    };

    // Edit buttons
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.onclick = async () => {
            const id = btn.getAttribute('data-id');
            const formData = new FormData();
            formData.append('id', id);
            const result = await apiRequest('get', formData);
            if (result.ok && result.data) {
                const d = result.data;
                document.getElementById('blotterId').value = d.id;
                document.getElementById('complainant_name').value = d.complainant_name;
                document.getElementById('complainant_id').value = d.complainant_id || 0;
                document.getElementById('respondent_name').value = d.respondent_name;
                document.getElementById('incident_type').value = d.incident_type;
                document.getElementById('incident_date').value = d.incident_date;
                document.getElementById('incident_time').value = d.incident_time || '';
                document.getElementById('location').value = d.location || '';
                document.getElementById('description').value = d.description;
                document.getElementById('investigating_officer').value = d.investigating_officer || '';
                document.getElementById('status').value = d.status;
                document.getElementById('resolution').value = d.resolution || '';
                document.getElementById('statusGroup').style.display = 'block';
                document.getElementById('resolutionGroup').style.display = 'block';
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Update Blotter Report';
                document.getElementById('blotterModal').style.display = 'flex';
            } else {
                showToast('Could not load blotter data.', 'err');
            }
        };
    });

    // Delete buttons
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.onclick = async () => {
            if (!confirm('Are you sure you want to delete this blotter report?')) return;
            const id = btn.getAttribute('data-id');
            const formData = new FormData();
            formData.append('id', id);
            const result = await apiRequest('delete', formData);
            showToast(result.msg, result.ok ? 'ok' : 'err');
            if (result.ok) setTimeout(refreshPage, 800);
        };
    });

    // Close modal
    function closeModal() {
        document.getElementById('blotterModal').style.display = 'none';
    }
    document.querySelectorAll('.close-modal, #cancelModalBtn').forEach(el => el.addEventListener('click', closeModal));
    window.onclick = (e) => { if (e.target === document.getElementById('blotterModal')) closeModal(); };
</script>
</body>
</html>