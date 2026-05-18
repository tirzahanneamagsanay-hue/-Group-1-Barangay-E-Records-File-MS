<?php


require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'cases';

//  Helper: empty string → null
function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
}


//  API: handle POST actions, return JSON
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn   = getConnection();
    $action = $_POST['action'];

    // ---- ADD ----
    if ($action === 'add') {
        $case_number     = trim($_POST['case_number']      ?? '');
        $complainant_id  = (int) ($_POST['complainant_id'] ?? 0);
        $respondent_name = trim($_POST['respondent_name']  ?? '');
        $nature          = trim($_POST['nature']           ?? 'Dispute');
        $status          = trim($_POST['status']           ?? 'Pending');
        $date_val        = trim($_POST['date_filed']       ?? '');
        $det_val         = trim($_POST['details']          ?? '');

        if ($case_number === '' || $complainant_id <= 0 || $date_val === '' || $respondent_name === '') {
            echo json_encode(['ok' => false, 'msg' => 'Case number, complainant, respondent name, and date filed are required.']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO cases (case_number, date_filed, complainant_id, respondent_name, nature, status, details)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssissss',
            $case_number,
            $date_val,
            $complainant_id,
            $respondent_name,
            $nature,
            $status,
            $det_val
        );

        $ok = $stmt->execute();
        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Case filed successfully.' : 'Database error: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // ---- EDIT ----
    if ($action === 'edit') {
        $id              = (int) ($_POST['id']              ?? 0);
        $case_number     = trim($_POST['case_number']       ?? '');
        $complainant_id  = (int) ($_POST['complainant_id']  ?? 0);
        $respondent_name = trim($_POST['respondent_name']   ?? '');
        $nature          = trim($_POST['nature']            ?? 'Dispute');
        $status          = trim($_POST['status']            ?? 'Pending');
        $date_val        = trim($_POST['date_filed']        ?? '');
        $det_val         = trim($_POST['details']           ?? '');

        if ($id <= 0 || $case_number === '' || $complainant_id <= 0 || $date_val === '' || $respondent_name === '') {
            echo json_encode(['ok' => false, 'msg' => 'Required fields missing.']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "UPDATE cases
             SET case_number     = ?,
                 date_filed      = ?,
                 complainant_id  = ?,
                 respondent_name = ?,
                 nature          = ?,
                 status          = ?,
                 details         = NULLIF(?, '')
             WHERE id = ?"
        );
        $stmt->bind_param('ssissssi',
            $case_number,
            $date_val,
            $complainant_id,
            $respondent_name,
            $nature,
            $status,
            $det_val,
            $id
        );

        $ok = $stmt->execute();
        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Case updated successfully.' : 'Update failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // ---- DELETE ----
    if ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid case ID.']);
            $conn->close(); exit;
        }
        $stmt = $conn->prepare("DELETE FROM cases WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        echo json_encode([
            'ok'  => $ok,
            'msg' => $ok ? 'Case deleted successfully.' : 'Delete failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // ---- GET single case for edit form ----
    if ($action === 'get') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid case ID.']);
            $conn->close(); exit;
        }
        $stmt = $conn->prepare("SELECT * FROM cases WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $conn->close();
        echo json_encode(['ok' => (bool) $row, 'data' => $row]);
        exit;
    }

    // ---- GENERATE next case number ----
    if ($action === 'generate_case_number') {
        $year = date('Y');
        $stmt = $conn->prepare(
            "SELECT case_number FROM cases
             WHERE case_number LIKE ?
             ORDER BY case_number DESC
             LIMIT 1"
        );
        $pattern = "BRY-{$year}-%";
        $stmt->bind_param('s', $pattern);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row) {
            // Extract the sequence number from the last case (e.g. BRY-2025-042 → 42)
            $parts = explode('-', $row['case_number']);
            $seq   = (int) end($parts);
        } else {
            $seq = 0;
        }

        $next = sprintf('BRY-%s-%03d', $year, $seq + 1);
        echo json_encode(['ok' => true, 'case_number' => $next]);
        $conn->close(); exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    $conn->close(); exit;
}

//  HTML page (GET requests)
$conn     = getConnection();
$per_page = 15; // BUG FIX: was 5, too few per page

$page     = max(1, (int) ($_GET['page'] ?? 1));
$offset   = ($page - 1) * $per_page;


$search        = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_nature = $_GET['nature'] ?? '';

$where  = [];
$params = [];
$types  = '';

if ($search) {
    $where[]  = "(c.case_number LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ? OR c.respondent_name LIKE ?)";
    $like     = "%$search%";
    $params   = array_merge($params, [$like, $like, $like, $like]);
    $types   .= 'ssss';
}
if ($filter_status) {
    $where[]  = "c.status = ?";
    $params[] = $filter_status;
    $types   .= 's';
}
if ($filter_nature) {
    $where[]  = "c.nature = ?";
    $params[] = $filter_nature;
    $types   .= 's';
}

$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $conn->prepare("SELECT COUNT(*) AS c FROM cases c JOIN residents r ON c.complainant_id = r.id" . $whereSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total       = (int) $countStmt->get_result()->fetch_assoc()['c'];
$countStmt->close();
$total_pages = max(1, (int) ceil($total / $per_page));

$fetchParams = array_merge($params, [$per_page, $offset]);
$fetchTypes  = $types . 'ii';
$data_stmt   = $conn->prepare(
    "SELECT c.*, r.first_name, r.last_name
     FROM cases c JOIN residents r ON c.complainant_id = r.id"
    . $whereSql .
    " ORDER BY c.created_at DESC LIMIT ? OFFSET ?"
);
$data_stmt->bind_param($fetchTypes, ...$fetchParams);
$data_stmt->execute();
$cases = $data_stmt->get_result();
$data_stmt->close();

// BUG FIX: $all_residents was used in the modal dropdown but never fetched — added here
$all_residents = $conn->query(
    "SELECT id, first_name, last_name FROM residents WHERE is_active = 1 ORDER BY last_name, first_name"
)->fetch_all(MYSQLI_ASSOC);

$conn->close();

function getBadgeClass(string $status): string {
    return match ($status) {
        'Pending'             => 'badge-pending',
        'Resolved'            => 'badge-resolved',
        'Dismissed'           => 'badge-dismissed',
        'Under Investigation' => 'badge-under',
        default               => 'badge-other',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cases &amp; Complaints — Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Font Awesome 6 (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Source Sans 3', sans-serif;
            background: #f0f3f9;
            color: #1a2a4a;
        }
        /* Sidebar (same as residents) */
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
        .nav-item i {
            width: 20px;
            text-align: center;
        }
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
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
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
        .case-table {
            width: 100%;
            border-collapse: collapse;
        }
        .case-table th, .case-table td {
            padding: 12px 1rem;
            text-align: left;
            border-bottom: 1px solid #eef0f6;
            font-size: 13px;
        }
        .case-table th {
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
        .page-links { display: flex; gap: 6px; align-items: center; }
.page-links a, .page-links span {
    display: inline-flex; align-items: center; justify-content: center;
    width: 32px; height: 32px; border-radius: 8px; text-decoration: none;
    color: #4a5a7a; border: 1px solid #dde3f0; background: #fff;
    font-weight: 500; transition: all 0.2s ease;
}
.page-links a:hover {
    background: #0a2a5e; color: #fff; border-color: #0a2a5e;
    box-shadow: 0 0 8px rgba(10, 42, 94, 0.4);
}
.page-links span.current {
    background: #0a2a5e; color: #fff; border-color: #0a2a5e;
    box-shadow: 0 0 8px rgba(10, 42, 94, 0.6);
    font-weight: 700;
}
.page-links .page-arrow { font-size: 16px; font-weight: bold; }
.page-links .page-arrow.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }
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
            max-width: 700px;
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
        }
        .close-modal {
            cursor: pointer;
            font-size: 20px;
        }
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
        <h1><i class="fas fa-gavel"></i> Cases & Complaints</h1>
    </div>
    <div class="content">

        <!-- Add Case Button -->
        <div style="margin-bottom: 1.5rem;">
            <button id="openAddModalBtn"><i class="fas fa-plus-circle"></i> File New Case</button>
        </div>
<!-- Search & Filter -->
<div style="display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center;">
    <form method="GET" style="display:flex; gap:8px; flex-wrap:wrap; flex:1;">
        <input type="text" name="search" placeholder="Search case #, complainant, respondent..." value="<?= htmlspecialchars($search) ?>" style="padding:8px 12px; border:1px solid #d0dae8; border-radius:8px; font-size:13px; min-width:240px;">
        <select name="status" style="padding:8px 12px; border:1px solid #d0dae8; border-radius:8px; font-size:13px;">
            <option value="">All Status</option>
            <option value="Pending"             <?= $filter_status === 'Pending'             ? 'selected' : '' ?>>Pending</option>
            <option value="Under Investigation" <?= $filter_status === 'Under Investigation' ? 'selected' : '' ?>>Under Investigation</option>
            <option value="Resolved"            <?= $filter_status === 'Resolved'            ? 'selected' : '' ?>>Resolved</option>
            <option value="Dismissed"           <?= $filter_status === 'Dismissed'           ? 'selected' : '' ?>>Dismissed</option>
        </select>
        <select name="nature" style="padding:8px 12px; border:1px solid #d0dae8; border-radius:8px; font-size:13px;">
            <option value="">All Nature</option>
            <option value="Dispute"          <?= $filter_nature === 'Dispute'          ? 'selected' : '' ?>>Dispute</option>
            <option value="Noise Complaint"  <?= $filter_nature === 'Noise Complaint'  ? 'selected' : '' ?>>Noise Complaint</option>
            <option value="Physical Assault" <?= $filter_nature === 'Physical Assault' ? 'selected' : '' ?>>Physical Assault</option>
            <option value="Property Damage"  <?= $filter_nature === 'Property Damage'  ? 'selected' : '' ?>>Property Damage</option>
            <option value="Theft"            <?= $filter_nature === 'Theft'            ? 'selected' : '' ?>>Theft</option>
            <option value="Other"            <?= $filter_nature === 'Other'            ? 'selected' : '' ?>>Other</option>
        </select>
        <button type="submit"><i class="fas fa-search"></i> Filter</button>
        <?php if ($search || $filter_status || $filter_nature): ?>
            <a href="cases.php" style="background:#ccc; color:#333; padding:8px 16px; border-radius:40px; text-decoration:none; font-size:13px;">Clear</a>
        <?php endif; ?>
    </form>
</div>
        <!-- Cases List -->
        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> All Cases</div>
            <div style="overflow-x: auto;">
                <table class="case-table">
                    <thead>
                        <tr>
                            <th>ID</th><th>Case No.</th><th>Complainant</th><th>Respondent</th>
                            <th>Nature</th><th>Status</th><th>Date Filed</th><th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($cases && $cases->num_rows > 0): ?>
                            <?php while ($c = $cases->fetch_assoc()): ?>
                            <tr data-id="<?= $c['id'] ?>">
                                <td><?= $c['id'] ?></td>
                                <td><?= htmlspecialchars($c['case_number']) ?></td>
                                <td><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                                <td><?= htmlspecialchars($c['respondent_name'] ?? '—') ?></td>
                                <td><?= htmlspecialchars($c['nature'] ?? '—') ?></td>
                                <td><span class="badge <?= getBadgeClass($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                                <td><?= $c['date_filed'] ? date('M j, Y', strtotime($c['date_filed'])) : '—' ?></td>
                                <td class="action-buttons">
                                    <button class="editBtn" data-id="<?= $c['id'] ?>"><i class="fas fa-edit"></i> Edit</button>
                                    <button class="deleteBtn btn-danger" data-id="<?= $c['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center;">No cases found. Click "File New Case" to add one.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
           <?php if ($total_pages > 1): ?>
<div class="pagination">
    <span>Page <?= $page ?> of <?= $total_pages ?> (<?= $total ?> total)</span>
    <div class="page-links">
        <?php
        $extra = ($search        ? '&search=' . urlencode($search)        : '')
               . ($filter_status ? '&status=' . urlencode($filter_status) : '')
               . ($filter_nature ? '&nature=' . urlencode($filter_nature) : '');
        ?>
        <?php if ($page > 1): ?>
            <a href="?page=<?= $page - 1 . $extra ?>" class="page-arrow">&#8592;</a>
        <?php else: ?>
            <span class="page-arrow disabled">&#8592;</span>
        <?php endif; ?>

        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <?php if ($p == $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="?page=<?= $p . $extra ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($page < $total_pages): ?>
            <a href="?page=<?= $page + 1 . $extra ?>" class="page-arrow">&#8594;</a>
        <?php else: ?>
            <span class="page-arrow disabled">&#8594;</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal for Add/Edit Case -->
<div id="caseModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle"><i class="fas fa-file-alt"></i> File New Case</span>
            <span class="close-modal">&times;</span>
        </div>
        <form id="caseForm">
            <input type="hidden" id="caseId">
            <div class="form-grid">
                <div class="form-group">
                    <label>Case Number *</label>
                    <div style="display:flex; gap:6px; align-items:center;">
                        <input type="text" id="case_number" required placeholder="Generating…"
                               style="flex:1; background:#f0f3f9; font-weight:600; color:#0a2a5e; letter-spacing:0.04em;"
                               readonly>
                        <button type="button" id="regenCaseNumBtn" title="Generate a new case number"
                                style="padding:8px 10px; border-radius:8px; flex-shrink:0; font-size:13px;">
                            <i class="fas fa-sync-alt"></i>
                        </button>
                    </div>
                    <small style="color:#7a8aaa; font-size:11px; margin-top:3px; display:block;">
                        Auto-generated. Click <i class="fas fa-sync-alt"></i> to refresh.
                    </small>
                </div>
                <div class="form-group"><label>Date Filed *</label><input type="date" id="date_filed" required></div>
                <div class="form-group"><label>Complainant *</label>
                    <select id="complainant_id" required>
                        <option value="">— Select Resident —</option>
                        <?php foreach ($all_residents as $res): ?>
                            <option value="<?= $res['id'] ?>"><?= htmlspecialchars($res['last_name'] . ', ' . $res['first_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group"><label>Respondent Name *</label><input type="text" id="respondent_name" required placeholder="Full name of respondent"></div>
                <div class="form-group"><label>Nature of Case</label>
                    <select id="nature">
                        <option>Dispute</option><option>Noise Complaint</option><option>Physical Assault</option>
                        <option>Property Damage</option><option>Theft</option><option>Other</option>
                    </select>
                </div>
                <div class="form-group"><label>Status</label>
                    <select id="status">
                        <option>Pending</option><option>Under Investigation</option><option>Resolved</option><option>Dismissed</option>
                    </select>
                </div>
                <div class="form-group"><label>Details</label><textarea id="details" rows="3"></textarea></div>
            </div>
            <div style="padding: 1rem 1.5rem 1.5rem; text-align: right;">
                <button type="button" id="cancelModalBtn"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="saveBtn"><i class="fas fa-save"></i> Save Case</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script>
    // Helper: fetch JSON from API
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

    // Add / Edit form submission
    document.getElementById('caseForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('caseId').value;
        const action = id ? 'edit' : 'add';

        const formData = new FormData();
        formData.append('case_number', document.getElementById('case_number').value.trim());
        formData.append('date_filed', document.getElementById('date_filed').value);
        formData.append('complainant_id', document.getElementById('complainant_id').value);
        formData.append('respondent_name', document.getElementById('respondent_name').value.trim());
        formData.append('nature', document.getElementById('nature').value);
        formData.append('status', document.getElementById('status').value);
        formData.append('details', document.getElementById('details').value.trim());
        if (id) formData.append('id', id);

        const result = await apiRequest(action, formData);
        if (result.ok) {
            showToast(result.msg, 'ok');
            closeModal();
            setTimeout(refreshPage, 800);
        } else {
            showToast(result.msg || 'Operation failed.', 'err');
        }
    });

    // Generate next case number from server
    async function generateCaseNumber() {
        const input = document.getElementById('case_number');
        const btn   = document.getElementById('regenCaseNumBtn');
        input.value = '';
        input.placeholder = 'Generating…';
        if (btn) btn.disabled = true;
        try {
            const fd = new FormData();
            fd.append('action', 'generate_case_number');
            const res  = await fetch(window.location.href, { method: 'POST', body: fd });
            const data = await res.json();
            if (data.ok) {
                input.value = data.case_number;
                input.placeholder = '';
            } else {
                input.placeholder = 'Error — enter manually';
                input.removeAttribute('readonly');
            }
        } catch (err) {
            input.placeholder = 'Error — enter manually';
            input.removeAttribute('readonly');
        } finally {
            if (btn) btn.disabled = false;
        }
    }

    // Open modal for Add
    document.getElementById('openAddModalBtn').onclick = async () => {
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-file-alt"></i> File New Case';
        document.getElementById('caseForm').reset();
        document.getElementById('caseId').value = '';
        document.getElementById('date_filed').value = new Date().toISOString().split('T')[0];
        // Lock the field and auto-generate number
        document.getElementById('case_number').setAttribute('readonly', true);
        document.getElementById('case_number').style.background = '#f0f3f9';
        if (document.getElementById('regenCaseNumBtn')) {
            document.getElementById('regenCaseNumBtn').style.display = '';
        }
        document.getElementById('caseModal').style.display = 'flex';
        await generateCaseNumber();
    };

    // Regen button
    document.getElementById('regenCaseNumBtn').addEventListener('click', generateCaseNumber);

    // Edit buttons
    document.querySelectorAll('.editBtn').forEach(btn => {
        btn.onclick = async () => {
            const id = btn.getAttribute('data-id');
            const formData = new FormData();
            formData.append('id', id);
            const result = await apiRequest('get', formData);
            if (result.ok && result.data) {
                const d = result.data;
                document.getElementById('caseId').value = d.id;
                document.getElementById('case_number').value = d.case_number;
                // In edit mode: allow manual correction, hide regen button
                document.getElementById('case_number').removeAttribute('readonly');
                document.getElementById('case_number').style.background = '';
                if (document.getElementById('regenCaseNumBtn')) {
                    document.getElementById('regenCaseNumBtn').style.display = 'none';
                }
                document.getElementById('date_filed').value = d.date_filed;
                document.getElementById('complainant_id').value = d.complainant_id;
                document.getElementById('respondent_name').value = d.respondent_name || '';
                document.getElementById('nature').value = d.nature || 'Dispute';
                document.getElementById('status').value = d.status;
                document.getElementById('details').value = d.details || '';
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Case';
                document.getElementById('caseModal').style.display = 'flex';
            } else {
                showToast('Could not load case data.', 'err');
            }
        };
    });

    // Delete buttons
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.onclick = async () => {
            if (!confirm('Are you sure you want to delete this case?')) return;
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
        document.getElementById('caseModal').style.display = 'none';
    }
    document.querySelectorAll('.close-modal, #cancelModalBtn').forEach(el => el.addEventListener('click', closeModal));
    window.onclick = (e) => { if (e.target === document.getElementById('caseModal')) closeModal(); };
</script>
</body>
</html>