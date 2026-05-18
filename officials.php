<?php
// ============================================================
// officials.php - BARANGAY OFFICIALS MANAGEMENT
// Matching the design of residents.php and cases.php
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'officials';

function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
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

// API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    $action = $_POST['action'];

    // ADD OFFICIAL
    if ($action === 'add') {
        $full_name = trim($_POST['full_name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $position_order = (int)($_POST['position_order'] ?? 0);
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $term_start = $_POST['term_start'] ?? '';
        $term_end = !empty($_POST['term_end']) ? $_POST['term_end'] : null;
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $bio = trim($_POST['bio'] ?? '');

        if ($full_name === '' || $position === '' || $term_start === '') {
            echo json_encode(['ok' => false, 'msg' => 'Full name, position, and term start are required.']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "INSERT INTO officials (full_name, position, position_order, contact_number, email, term_start, term_end, is_current, bio) 
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssissssis', 
            $full_name, $position, $position_order, $contact_number, $email, 
            $term_start, $term_end, $is_current, $bio
        );

        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'OFFICIAL_ADDED', "Added official: $full_name as $position");
        }

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Official added successfully.' : 'Database error: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // EDIT OFFICIAL
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $full_name = trim($_POST['full_name'] ?? '');
        $position = trim($_POST['position'] ?? '');
        $position_order = (int)($_POST['position_order'] ?? 0);
        $contact_number = trim($_POST['contact_number'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $term_start = $_POST['term_start'] ?? '';
        $term_end = !empty($_POST['term_end']) ? $_POST['term_end'] : null;
        $is_current = isset($_POST['is_current']) ? 1 : 0;
        $bio = trim($_POST['bio'] ?? '');

        if ($id <= 0 || $full_name === '' || $position === '' || $term_start === '') {
            echo json_encode(['ok' => false, 'msg' => 'Required fields missing.']);
            $conn->close(); exit;
        }

        $stmt = $conn->prepare(
            "UPDATE officials 
             SET full_name = ?, position = ?, position_order = ?, contact_number = ?, email = ?, 
                 term_start = ?, term_end = ?, is_current = ?, bio = ?, updated_at = NOW() 
             WHERE id = ?"
        );
        $stmt->bind_param('ssissssisi', 
            $full_name, $position, $position_order, $contact_number, $email, 
            $term_start, $term_end, $is_current, $bio, $id
        );

        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'OFFICIAL_UPDATED', "Updated official ID $id: $full_name");
        }

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Official updated successfully.' : 'Update failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // DELETE OFFICIAL
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid official ID.']);
            $conn->close(); exit;
        }
        
        $stmt = $conn->prepare("DELETE FROM officials WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'OFFICIAL_DELETED', "Deleted official ID $id");
        }
        
        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Official deleted successfully.' : 'Delete failed: ' . $stmt->error,
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // GET SINGLE OFFICIAL
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid official ID.']);
            $conn->close(); exit;
        }
        $stmt = $conn->prepare("SELECT * FROM officials WHERE id = ? LIMIT 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close(); $conn->close();
        echo json_encode(['ok' => (bool)$row, 'data' => $row]);
        exit;
    }

    // TOGGLE CURRENT STATUS
    if ($action === 'toggle_current') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid official ID.']);
            $conn->close(); exit;
        }
        
        $stmt = $conn->prepare("UPDATE officials SET is_current = NOT is_current, updated_at = NOW() WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        
        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? 'Status toggled successfully.' : 'Update failed.',
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    echo json_encode(['ok' => false, 'msg' => 'Unknown action.']);
    $conn->close(); exit;
}

// HTML PAGE (GET)
$conn = getConnection();

$officials = $conn->query("
    SELECT * FROM officials 
    ORDER BY is_current DESC, position_order ASC, term_start DESC
")->fetch_all(MYSQLI_ASSOC);

$current_officials = array_filter($officials, fn($o) => $o['is_current'] == 1);
$former_officials = array_filter($officials, fn($o) => $o['is_current'] == 0);

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Barangay Officials — Barangay E-Records</title>
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
        
        .officials-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 1.5rem;
            padding: 1.5rem;
        }
        
        .official-card {
            background: #fff;
            border: 1px solid #eef0f6;
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.2s;
        }
        .official-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            transform: translateY(-2px);
        }
        
        .official-header {
            background: linear-gradient(135deg, #0a2a5e 0%, #1a407a 100%);
            padding: 1rem;
            text-align: center;
            border-bottom: 3px solid #c8a84b;
        }
        .official-header.former {
            background: linear-gradient(135deg, #555 0%, #777 100%);
        }
        .official-avatar {
            width: 70px;
            height: 70px;
            background: rgba(200,168,75,0.15);
            border: 2px solid #c8a84b;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 0.75rem;
            font-size: 28px;
            color: #f0d080;
        }
        .official-name {
            font-size: 16px;
            font-weight: 600;
            color: white;
            margin-bottom: 4px;
        }
        .official-position {
            font-size: 12px;
            color: rgba(255,255,255,0.8);
        }
        
        .official-body {
            padding: 1rem;
        }
        .official-detail {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
            font-size: 12px;
            color: #4a5a7a;
        }
        .official-detail i {
            width: 18px;
            color: #c8a84b;
        }
        .official-term {
            background: #f7f9fc;
            padding: 6px 10px;
            border-radius: 6px;
            margin: 10px 0;
            font-size: 11px;
            text-align: center;
            color: #2a4a6e;
        }
        .badge-current {
            display: inline-block;
            background: #e6f5ee;
            color: #1a6e3a;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .badge-former {
            display: inline-block;
            background: #f5f5f5;
            color: #777;
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 600;
        }
        .card-actions {
            padding: 0.75rem 1rem;
            border-top: 1px solid #eef0f6;
            display: flex;
            gap: 8px;
            justify-content: flex-end;
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
        .btn-sm { padding: 4px 12px; font-size: 12px; }
        .btn-toggle { background: #2d6a4f; }
        .btn-toggle:hover { background: #1e5a3f; }
        
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
            max-width: 550px;
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
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
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
        .form-group textarea { resize: vertical; min-height: 60px; }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
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
            .officials-grid { grid-template-columns: 1fr; }
            .form-row { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h1><i class="fas fa-user-tie"></i> Barangay Officials Directory</h1>
    </div>
    <div class="content">

        <!-- Add Official Button -->
        <div style="margin-bottom: 1.5rem;">
            <button id="openAddModalBtn"><i class="fas fa-plus-circle"></i> Add New Official</button>
        </div>

        <!-- Current Officials -->
        <div class="card">
            <div class="card-header">
                <i class="fas fa-star" style="color: #c8a84b;"></i> Current Officials 
                <span style="font-size: 12px; font-weight: normal; margin-left: 8px;">(<?= count($current_officials) ?> officials)</span>
            </div>
            <div class="officials-grid">
                <?php if (count($current_officials) > 0): ?>
                    <?php foreach ($current_officials as $official): ?>
                        <div class="official-card" data-id="<?= $official['id'] ?>">
                            <div class="official-header">
                                <div class="official-avatar">
                                    <i class="fas fa-user-circle"></i>
                                </div>
                                <div class="official-name"><?= htmlspecialchars($official['full_name']) ?></div>
                                <div class="official-position"><?= htmlspecialchars($official['position']) ?></div>
                            </div>
                            <div class="official-body">
                                <div class="official-detail">
                                    <i class="fas fa-phone"></i>
                                    <span><?= htmlspecialchars($official['contact_number'] ?? 'No contact number') ?></span>
                                </div>
                                <div class="official-detail">
                                    <i class="fas fa-envelope"></i>
                                    <span><?= htmlspecialchars($official['email'] ?? 'No email') ?></span>
                                </div>
                                <div class="official-term">
                                    <i class="fas fa-calendar-alt"></i> Term: 
                                    <?= date('F Y', strtotime($official['term_start'])) ?> - 
                                    <?= $official['term_end'] ? date('F Y', strtotime($official['term_end'])) : 'Present' ?>
                                </div>
                                <?php if (!empty($official['bio'])): ?>
                                    <div class="official-detail">
                                        <i class="fas fa-info-circle"></i>
                                        <span><?= htmlspecialchars(substr($official['bio'], 0, 80)) ?>...</span>
                                    </div>
                                <?php endif; ?>
                                <div style="margin-top: 8px;">
                                    <span class="badge-current"><i class="fas fa-check-circle"></i> Currently Serving</span>
                                </div>
                            </div>
                            <div class="card-actions">
                                <button class="editBtn btn-sm" data-id="<?= $official['id'] ?>"><i class="fas fa-edit"></i> Edit</button>
                                <button class="deleteBtn btn-sm btn-danger" data-id="<?= $official['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div style="grid-column: 1/-1; text-align: center; padding: 2rem; color: #999;">
                        No current officials recorded. Click "Add New Official" to get started.
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Former Officials -->
        <?php if (count($former_officials) > 0): ?>
        <div class="card">
            <div class="card-header">
                <i class="fas fa-history"></i> Former Officials 
                <span style="font-size: 12px; font-weight: normal;">(<?= count($former_officials) ?> officials)</span>
            </div>
            <div class="officials-grid">
                <?php foreach ($former_officials as $official): ?>
                    <div class="official-card" data-id="<?= $official['id'] ?>">
                        <div class="official-header former">
                            <div class="official-avatar">
                                <i class="fas fa-user-circle"></i>
                            </div>
                            <div class="official-name"><?= htmlspecialchars($official['full_name']) ?></div>
                            <div class="official-position"><?= htmlspecialchars($official['position']) ?></div>
                        </div>
                        <div class="official-body">
                            <div class="official-detail">
                                <i class="fas fa-phone"></i>
                                <span><?= htmlspecialchars($official['contact_number'] ?? 'No contact') ?></span>
                            </div>
                            <div class="official-term">
                                <i class="fas fa-calendar-alt"></i> Term: 
                                <?= date('F Y', strtotime($official['term_start'])) ?> - 
                                <?= $official['term_end'] ? date('F Y', strtotime($official['term_end'])) : 'Unknown' ?>
                            </div>
                            <div style="margin-top: 8px;">
                                <span class="badge-former"><i class="fas fa-clock"></i> Former Official</span>
                            </div>
                        </div>
                        <div class="card-actions">
                            <button class="editBtn btn-sm" data-id="<?= $official['id'] ?>"><i class="fas fa-edit"></i> Edit</button>
                            <button class="deleteBtn btn-sm btn-danger" data-id="<?= $official['id'] ?>"><i class="fas fa-trash-alt"></i> Delete</button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

    </div>
</div>

<!-- Modal for Add/Edit Official -->
<div id="officialModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <span id="modalTitle"><i class="fas fa-user-tie"></i> Add New Official</span>
            <span class="close-modal">&times;</span>
        </div>
        <form id="officialForm">
            <input type="hidden" id="officialId">
            <div class="form-grid">
                <div class="form-group"><label>Full Name *</label><input type="text" id="full_name" required placeholder="e.g., Juan M. Dela Cruz"></div>
                <div class="form-group"><label>Position *</label><input type="text" id="position" required placeholder="e.g., Punong Barangay"></div>
                <div class="form-group"><label>Position Order</label><input type="number" id="position_order" value="0" placeholder="Display order"></div>
                <div class="form-group"><label>Contact Number</label><input type="text" id="contact_number" placeholder="09XX-XXX-XXXX"></div>
                <div class="form-group"><label>Email</label><input type="email" id="email" placeholder="official@barangay.gov.ph"></div>
                <div class="form-group"><label>Term Start *</label><input type="date" id="term_start" required></div>
                <div class="form-group"><label>Term End</label><input type="date" id="term_end"></div>
                <div class="form-group"><label><input type="checkbox" id="is_current" checked> Currently Serving</label></div>
                <div class="form-group"><label>Bio / Notes</label><textarea id="bio" rows="3" placeholder="Committee assignments, achievements, etc."></textarea></div>
            </div>
            <div style="padding: 1rem 1.5rem 1.5rem; text-align: right;">
                <button type="button" id="cancelModalBtn"><i class="fas fa-times"></i> Cancel</button>
                <button type="submit" id="saveBtn"><i class="fas fa-save"></i> Save Official</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script>
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
    document.getElementById('officialForm').addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('officialId').value;
        const action = id ? 'edit' : 'add';

        const formData = new FormData();
        formData.append('full_name', document.getElementById('full_name').value.trim());
        formData.append('position', document.getElementById('position').value.trim());
        formData.append('position_order', document.getElementById('position_order').value);
        formData.append('contact_number', document.getElementById('contact_number').value.trim());
        formData.append('email', document.getElementById('email').value.trim());
        formData.append('term_start', document.getElementById('term_start').value);
        formData.append('term_end', document.getElementById('term_end').value);
        formData.append('is_current', document.getElementById('is_current').checked ? 1 : 0);
        formData.append('bio', document.getElementById('bio').value.trim());
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

    // Open modal for Add
    document.getElementById('openAddModalBtn').onclick = () => {
        document.getElementById('officialForm').reset();
        document.getElementById('officialId').value = '';
        document.getElementById('is_current').checked = true;
        document.getElementById('term_start').valueAsDate = new Date();
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-user-tie"></i> Add New Official';
        document.getElementById('officialModal').style.display = 'flex';
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
                document.getElementById('officialId').value = d.id;
                document.getElementById('full_name').value = d.full_name;
                document.getElementById('position').value = d.position;
                document.getElementById('position_order').value = d.position_order;
                document.getElementById('contact_number').value = d.contact_number || '';
                document.getElementById('email').value = d.email || '';
                document.getElementById('term_start').value = d.term_start;
                document.getElementById('term_end').value = d.term_end || '';
                document.getElementById('is_current').checked = d.is_current == 1;
                document.getElementById('bio').value = d.bio || '';
                document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit"></i> Edit Official';
                document.getElementById('officialModal').style.display = 'flex';
            } else {
                showToast('Could not load official data.', 'err');
            }
        };
    });

    // Delete buttons
    document.querySelectorAll('.deleteBtn').forEach(btn => {
        btn.onclick = async () => {
            const officialName = btn.closest('.official-card').querySelector('.official-name').innerText;
            if (!confirm('Are you sure you want to delete ' + officialName + '? This action cannot be undone.')) return;
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
        document.getElementById('officialModal').style.display = 'none';
    }
    document.querySelectorAll('.close-modal, #cancelModalBtn').forEach(el => el.addEventListener('click', closeModal));
    window.onclick = (e) => { if (e.target === document.getElementById('officialModal')) closeModal(); };
</script>
</body>
</html>