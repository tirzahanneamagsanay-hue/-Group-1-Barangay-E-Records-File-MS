<?php
// ============================================================
// documents.php - BARANGAY DOCUMENTS & CERTIFICATES MODULE
// Matches your exact database schema
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

function generateDocumentNumber() {
    $prefix = 'BRGY-DOC';
    $year = date('Y');
    $month = date('m');
    $random = strtoupper(substr(uniqid(), -6));
    return $prefix . '-' . $year . $month . '-' . $random;
}

function logActivity($conn, $action, $details) {
    $user_id = $_SESSION['user_id'] ?? 0;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt = $conn->prepare("INSERT INTO activity_logs (user_id, action, details, ip_address, created_at) VALUES (?, ?, ?, ?, NOW())");
    $stmt->bind_param('isss', $user_id, $action, $details, $ip);
    $stmt->execute();
    $stmt->close();
}

// API Handler
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    $conn = getConnection();
    $action = $_POST['action'];

    // ADD DOCUMENT REQUEST
    if ($action === 'add') {
        $resident_id = (int)($_POST['resident_id'] ?? 0);
        $document_type = $_POST['document_type'] ?? '';
        $purpose = trim($_POST['purpose'] ?? '');
        $amount = (float)($_POST['amount'] ?? 0);
        $notes = !empty(trim($_POST['notes'] ?? '')) ? trim($_POST['notes']) : null;

        if ($resident_id <= 0 || !$document_type) {
            echo json_encode(['ok' => false, 'msg' => 'Resident and document type are required.']);
            $conn->close(); exit;
        }

        $document_number = generateDocumentNumber();
        $requested_date = date('Y-m-d');

        $stmt = $conn->prepare("
            INSERT INTO documents (document_number, resident_id, document_type, purpose, amount, status, requested_date, notes, created_by) 
            VALUES (?, ?, ?, ?, ?, 'pending', ?, ?, ?)
        ");
        $stmt->bind_param('sisdsssi', 
            $document_number, $resident_id, $document_type, $purpose, $amount, $requested_date, $notes, $_SESSION['user_id']
        );

        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'DOCUMENT_REQUESTED', "Document $document_number requested for resident ID $resident_id");
        }

        echo json_encode([
            'ok' => $ok,
            'msg' => $ok ? "Document requested. Reference: $document_number" : 'Database error: ' . $stmt->error,
            'document_number' => $document_number
        ]);
        $stmt->close(); $conn->close(); exit;
    }

    // UPDATE DOCUMENT STATUS
    if ($action === 'update_status') {
        $id = (int)($_POST['id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $notes = !empty(trim($_POST['notes'] ?? '')) ? trim($_POST['notes']) : null;

        if ($id <= 0 || !$status) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid request.']);
            $conn->close(); exit;
        }

        $released_date = ($status === 'released') ? date('Y-m-d') : null;
        
        $stmt = $conn->prepare("
            UPDATE documents 
            SET status = ?, released_date = COALESCE(?, released_date), notes = COALESCE(?, notes), updated_at = NOW() 
            WHERE id = ?
        ");
        $stmt->bind_param('sssi', $status, $released_date, $notes, $id);
        $ok = $stmt->execute();
        
        if ($ok) {
            logActivity($conn, 'DOCUMENT_UPDATED', "Document ID $id status changed to $status");
        }
        
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Document status updated.' : 'Update failed.']);
        $stmt->close(); $conn->close(); exit;
    }

    // DELETE DOCUMENT
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid document ID.']);
            $conn->close(); exit;
        }
        
        // Get document number for logging
        $docStmt = $conn->prepare("SELECT document_number FROM documents WHERE id = ?");
        $docStmt->bind_param('i', $id);
        $docStmt->execute();
        $doc = $docStmt->get_result()->fetch_assoc();
        $docStmt->close();
        
        $stmt = $conn->prepare("DELETE FROM documents WHERE id = ?");
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        
        if ($ok && $doc) {
            logActivity($conn, 'DOCUMENT_DELETED', "Deleted document {$doc['document_number']}");
        }
        
        echo json_encode(['ok' => $ok, 'msg' => $ok ? 'Document deleted.' : 'Delete failed.']);
        $stmt->close(); $conn->close(); exit;
    }

    // GET SINGLE DOCUMENT
    if ($action === 'get') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['ok' => false, 'msg' => 'Invalid ID.']);
            $conn->close(); exit;
        }
        $stmt = $conn->prepare("
            SELECT d.*, r.first_name, r.last_name, r.full_address 
            FROM documents d 
            JOIN residents r ON d.resident_id = r.id 
            WHERE d.id = ?
        ");
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

// Pagination
$per_page = 5;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $per_page;

// Search & Filters
$search = trim($_GET['search'] ?? '');
$filter_status = $_GET['status'] ?? '';
$filter_type = $_GET['type'] ?? '';

$where = [];
$params = [];
$types = '';

if ($search) {
    $where[] = "(d.document_number LIKE ? OR r.first_name LIKE ? OR r.last_name LIKE ?)";
    $like = "%$search%";
    $params = array_merge($params, [$like, $like, $like]);
    $types .= 'sss';
}

if ($filter_status) {
    $where[] = "d.status = ?";
    $params[] = $filter_status;
    $types .= 's';
}

if ($filter_type) {
    $where[] = "d.document_type = ?";
    $params[] = $filter_type;
    $types .= 's';
}

// Count total
$countSql = "SELECT COUNT(*) AS total FROM documents d JOIN residents r ON d.resident_id = r.id";
if ($where) $countSql .= " WHERE " . implode(" AND ", $where);
$countStmt = $conn->prepare($countSql);
if ($params) $countStmt->bind_param($types, ...$params);
$countStmt->execute();
$total = (int)$countStmt->get_result()->fetch_assoc()['total'];
$countStmt->close();
$total_pages = max(1, ceil($total / $per_page));

// Fetch documents
$sql = "SELECT d.*, r.first_name, r.last_name, r.full_address 
        FROM documents d 
        JOIN residents r ON d.resident_id = r.id";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY d.requested_date DESC LIMIT ? OFFSET ?";

$params[] = $per_page;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$documents = $stmt->get_result();
$stmt->close();

// Get residents for dropdown
$residents = $conn->query("SELECT id, first_name, last_name FROM residents WHERE is_active = 1 ORDER BY last_name, first_name")->fetch_all(MYSQLI_ASSOC);
$conn->close();

function getStatusBadge($status) {
    $badges = [
        'pending' => 'badge-pending',
        'approved' => 'badge-approved',
        'released' => 'badge-released',
        'cancelled' => 'badge-cancelled'
    ];
    return $badges[$status] ?? 'badge-other';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Documents & Certificates - Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Source Sans 3', sans-serif; background: #f0f3f9; color: #1a2a4a; }
        
        /* Sidebar */
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 240px; background: #0a2a5e; display: flex; flex-direction: column; border-right: 4px solid #c8a84b; z-index: 100; }
        .sidebar-logo { padding: 1.5rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .sidebar-logo h2 { font-family: 'Playfair Display', serif; font-size: 14px; color: #f0d080; }
        .nav-section { padding: 1rem 0; flex: 1; }
        .nav-label { font-size: 10px; color: rgba(255,255,255,0.3); letter-spacing: 0.12em; text-transform: uppercase; padding: 0 1.25rem; margin-top: 12px; }
        .nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 1.25rem; color: rgba(255,255,255,0.65); text-decoration: none; font-size: 14px; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: rgba(200,168,75,0.15); color: #f0d080; border-right: 3px solid #c8a84b; }
        .nav-item i { width: 20px; text-align: center; }
        .sidebar-user { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 10px; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(200,168,75,0.2); border: 1.5px solid #c8a84b; display: flex; align-items: center; justify-content: center; color: #f0d080; font-weight: 600; }
        .user-info p { font-size: 13px; color: #fff; font-weight: 500; }
        .user-info span { font-size: 11px; color: rgba(255,255,255,0.45); }
        .btn-logout { display: block; margin: 0.5rem 1rem 1rem; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 8px; color: rgba(255,255,255,0.55); text-align: center; text-decoration: none; font-size: 12px; }
        .btn-logout:hover { background: rgba(255,0,0,0.15); color: #ff9999; }

        .main { margin-left: 240px; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #dde3f0; padding: 1rem 2rem; display: flex; align-items: center; justify-content: space-between; }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 20px; color: #0a2a5e; }
        .content { padding: 2rem; }
        
        .card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; margin-bottom: 2rem; overflow: hidden; }
        .card-header { padding: 1rem 1.5rem; background: #f7f9fc; border-bottom: 1px solid #eef0f6; font-weight: 600; }
        
        .filters { display: flex; gap: 10px; margin-bottom: 1.5rem; flex-wrap: wrap; align-items: center; }
        .filters input, .filters select { padding: 8px 12px; border: 1px solid #d0dae8; border-radius: 8px; font-size: 13px; }
        
        button, .btn { background: #0a2a5e; color: white; border: none; padding: 8px 16px; border-radius: 40px; cursor: pointer; font-size: 13px; font-weight: 500; }
        button i, .btn i { margin-right: 6px; }
        button:hover { background: #1a407a; }
        .btn-danger { background: #b33; }
        .btn-danger:hover { background: #a22; }
        
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 12px 1rem; text-align: left; border-bottom: 1px solid #eef0f6; font-size: 13px; }
        th { background: #f7f9fc; font-weight: 600; color: #2a4a6e; }
        .action-buttons { display: flex; gap: 8px; }
        .action-buttons button { padding: 4px 12px; font-size: 12px; }
        
        .badge { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending { background: #fff3e0; color: #b36200; }
        .badge-approved { background: #e6eefa; color: #1a3a7a; }
        .badge-released { background: #e6f5ee; color: #1a6e3a; }
        .badge-cancelled { background: #f5f5f5; color: #555; }
        
        .pagination { display: flex; align-items: center; justify-content: space-between; padding: 1rem 1.25rem; border-top: 1px solid #eef0f6; font-size: 12px; }
        .page-links { display: flex; gap: 6px; align-items: center; }
        .page-links a, .page-links span {
            display: inline-flex; align-items: center; justify-content: center;
            width: 32px; height: 32px; border-radius: 8px; text-decoration: none;
            color: #4a5a7a; border: 1px solid #dde3f0; background: #fff;
            font-weight: 500; transition: all 0.2s ease;
        }
        .page-links span.current {
        background: #0a2a5e; color: #fff; border-color: #0a2a5e;
        box-shadow: 0 0 8px rgba(10, 42, 94, 0.6);
        font-weight: 700;
    }
    .page-links a:hover {
        background: #0a2a5e; color: #fff; border-color: #0a2a5e;
        box-shadow: 0 0 8px rgba(10, 42, 94, 0.4);
    
    } 
        .page-links .page-arrow { font-size: 16px; font-weight: bold; }
        .page-links .page-arrow.disabled { opacity: 0.3; cursor: not-allowed; pointer-events: none; }   
        
        .modal { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; max-width: 600px; width: 90%; border-radius: 16px; max-height: 90vh; overflow-y: auto; }
        .modal-header { padding: 1rem 1.5rem; border-bottom: 1px solid #ddd; font-weight: bold; display: flex; justify-content: space-between; background: #0a2a5e; color: #f0d080; }
        .close-modal { cursor: pointer; font-size: 20px; color: white; }
        .form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 1rem; padding: 1.5rem; }
        .form-group label { display: block; font-size: 12px; font-weight: 600; color: #2a5a7a; margin-bottom: 4px; }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 10px; border: 1px solid #d0dae8; border-radius: 8px; font-family: inherit; font-size: 13px; }
        
        #toast { position: fixed; bottom: 1.5rem; right: 1.5rem; padding: 10px 18px; border-radius: 8px; font-size: 13px; font-weight: 600; color: #fff; box-shadow: 0 8px 24px rgba(0,0,0,0.2); opacity: 0; transform: translateY(8px); transition: opacity 0.2s, transform 0.2s; z-index: 9999; pointer-events: none; }
        #toast.show { opacity: 1; transform: translateY(0); }
        #toast.ok { background: #1a6e3a; }
        #toast.err { background: #a82020; }
        
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; height: auto; } .main { margin-left: 0; } }
    </style>
</head>
<body>

<?php $active_page = 'documents'; include 'includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar"><h1><i class="fas fa-file-alt"></i> Documents & Certificates</h1></div>
    <div class="content">
        <div style="margin-bottom: 1.5rem;"><button id="openAddModalBtn"><i class="fas fa-plus-circle"></i> Request Document</button></div>
        
        <div class="filters">
            <form method="GET" style="display: flex; gap: 8px; flex-wrap: wrap; flex: 1;">
                <input type="text" name="search" placeholder="Search document # or resident..." value="<?= htmlspecialchars($search) ?>">
                <select name="status">
                    <option value="">All Status</option>
                    <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="approved" <?= $filter_status === 'approved' ? 'selected' : '' ?>>Approved</option>
                    <option value="released" <?= $filter_status === 'released' ? 'selected' : '' ?>>Released</option>
                    <option value="cancelled" <?= $filter_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
                <select name="type">
                    <option value="">All Types</option>
                    <option>Barangay Clearance</option>
                    <option>Barangay ID</option>
                    <option>Certificate of Residency</option>
                    <option>Certificate of Indigency</option>
                    <option>Business Clearance</option>
                </select>
                <button type="submit"><i class="fas fa-search"></i> Filter</button>
                <?php if ($search || $filter_status || $filter_type): ?>
                    <a href="documents.php" class="btn" style="background:#ccc; color:#333;">Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-list"></i> All Document Requests (<?= $total ?> total)</div>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr><th>Doc #</th><th>Resident</th><th>Type</th><th>Purpose</th><th>Amount</th><th>Status</th><th>Requested</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php if ($documents && $documents->num_rows > 0): ?>
                            <?php while ($d = $documents->fetch_assoc()): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($d['document_number']) ?></strong></td>
                                <td><?= htmlspecialchars($d['last_name'] . ', ' . $d['first_name']) ?></td>
                                <td><?= htmlspecialchars($d['document_type']) ?></td>
                                <td><?= htmlspecialchars(substr($d['purpose'] ?? '', 0, 40)) ?></td>
                                <td>₱<?= number_format($d['amount'] ?? 0, 2) ?></td>
                                <td><span class="badge <?= getStatusBadge($d['status']) ?>"><?= ucfirst($d['status']) ?></span></td>
                                <td><?= date('M j, Y', strtotime($d['requested_date'])) ?></td>
                                <td class="action-buttons">
                                    <button class="editBtn" data-id="<?= $d['id'] ?>"><i class="fas fa-edit"></i> Update</button>
                                    <button class="deleteBtn btn-danger" data-id="<?= $d['id'] ?>"><i class="fas fa-trash"></i> Delete</button>
                                    <button class="printBtn" data-id="<?= $d['id'] ?>" onclick="printDocument(this)" title="Print <?= htmlspecialchars($d['document_type']) ?>"><i class="fas fa-print"></i> Print</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr><td colspan="8" style="text-align:center; padding: 2rem;">No document requests found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($total_pages > 1): ?>
<div class="pagination">
    <span>Page <?= $page ?> of <?= $total_pages ?></span>
    <div class="page-links">
        <?php
        $base = '?page=';
        $extra = ($search ? '&search=' . urlencode($search) : '') . ($filter_status ? '&status=' . urlencode($filter_status) : '') . ($filter_type ? '&type=' . urlencode($filter_type) : '');
        ?>
        <!-- Previous Arrow -->
        <?php if ($page > 1): ?>
            <a href="<?= $base . ($page - 1) . $extra ?>" class="page-arrow" title="Previous">&#8592;</a>
        <?php else: ?>
            <span class="page-arrow disabled">&#8592;</span>
        <?php endif; ?>

        <!-- Page Numbers -->
        <?php for ($p = 1; $p <= $total_pages; $p++): ?>
            <?php if ($p == $page): ?>
                <span class="current"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= $base . $p . $extra ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <!-- Next Arrow -->
        <?php if ($page < $total_pages): ?>
            <a href="<?= $base . ($page + 1) . $extra ?>" class="page-arrow" title="Next">&#8594;</a>
        <?php else: ?>
            <span class="page-arrow disabled">&#8594;</span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
        </div>
    </div>
</div>

<!-- Modal -->
<div id="docModal" class="modal">
    <div class="modal-content">
        <div class="modal-header"><span id="modalTitle">Request Document</span><span class="close-modal">&times;</span></div>
        <form id="docForm">
            <input type="hidden" id="docId">
            <div class="form-grid">
                <div class="form-group"><label>Resident *</label><select id="resident_id" required><option value="">Select Resident</option><?php foreach ($residents as $r): ?><option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['last_name'] . ', ' . $r['first_name']) ?></option><?php endforeach; ?></select></div>
                <div class="form-group"><label>Document Type *</label><select id="document_type" required><option>Barangay Clearance</option><option>Barangay ID</option><option>Certificate of Residency</option><option>Certificate of Indigency</option><option>Business Clearance</option><option>Other</option></select></div>
                <div class="form-group"><label>Purpose</label><textarea id="purpose" rows="2" placeholder="e.g., Employment requirement, School requirement..."></textarea></div>
                <div class="form-group"><label>Amount (₱)</label><input type="number" id="amount" step="0.01" value="0"></div>
                <div class="form-group"><label>Status</label><select id="status"><option value="pending">Pending</option><option value="approved">Approved</option><option value="released">Released</option><option value="cancelled">Cancelled</option></select></div>
                <div class="form-group"><label>Notes</label><textarea id="notes" rows="2" placeholder="Additional notes..."></textarea></div>
            </div>
            <div style="padding: 1rem 1.5rem 1.5rem; text-align: right;">
                <button type="button" id="cancelModalBtn">Cancel</button>
                <button type="submit">Save Document</button>
            </div>
        </form>
    </div>
</div>

<div id="toast"></div>

<script>
async function apiRequest(action, formData) { formData.append('action', action); const res = await fetch(window.location.href, {method:'POST', body:formData}); return res.json(); }
function showToast(msg, isError) { let t=document.getElementById('toast'); t.textContent=msg; t.style.background=isError?'#a82020':'#1a6e3a'; t.classList.add('show'); setTimeout(()=>t.classList.remove('show'),3000); }
function refreshPage() { location.reload(); }
function printDocument(btn) {
    const id = btn.dataset.id;
    // Opens the real certificate in a new tab — browser print dialog fires automatically
    window.open('print_certificate.php?id=' + id, '_blank');
}

document.getElementById('docForm').addEventListener('submit', async (e) => {
    e.preventDefault(); const id = document.getElementById('docId').value; const action = id ? 'update_status' : 'add';
    const fd = new FormData();
    if (!id) { fd.append('resident_id', document.getElementById('resident_id').value); fd.append('document_type', document.getElementById('document_type').value); fd.append('purpose', document.getElementById('purpose').value); fd.append('amount', document.getElementById('amount').value); }
    if (id) { fd.append('id', id); fd.append('status', document.getElementById('status').value); fd.append('notes', document.getElementById('notes').value); }
    const result = await apiRequest(action, fd); showToast(result.msg, !result.ok); if (result.ok) { closeModal(); setTimeout(refreshPage, 800); }
});

document.getElementById('openAddModalBtn').onclick = () => { document.getElementById('docForm').reset(); document.getElementById('docId').value = ''; document.getElementById('status').value = 'pending'; document.getElementById('modalTitle').innerHTML = 'Request Document'; document.getElementById('docModal').style.display = 'flex'; };
document.querySelectorAll('.editBtn').forEach(btn => btn.onclick = async () => { const fd = new FormData(); fd.append('id', btn.dataset.id); const res = await apiRequest('get', fd); if (res.data) { const d = res.data; document.getElementById('docId').value = d.id; document.getElementById('resident_id').value = d.resident_id; document.getElementById('document_type').value = d.document_type; document.getElementById('purpose').value = d.purpose || ''; document.getElementById('amount').value = d.amount || 0; document.getElementById('status').value = d.status; document.getElementById('notes').value = d.notes || ''; document.getElementById('modalTitle').innerHTML = 'Update Document Status'; document.getElementById('docModal').style.display = 'flex'; } });
document.querySelectorAll('.deleteBtn').forEach(btn => btn.onclick = async () => { if (!confirm('Delete this document record?')) return; const fd = new FormData(); fd.append('id', btn.dataset.id); const res = await apiRequest('delete', fd); showToast(res.msg, !res.ok); if (res.ok) setTimeout(refreshPage, 800); });
function closeModal() { document.getElementById('docModal').style.display = 'none'; }
document.querySelectorAll('.close-modal, #cancelModalBtn').forEach(el => el.addEventListener('click', closeModal));
</script>
</body>
</html>