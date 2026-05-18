<?php
// ============================================================
// reports.php - ANALYTICS & EXPORT MODULE
// Generate reports and export data matching your database
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'reports';

$conn = getConnection();

// Get statistics from your tables
$total_residents = (int)$conn->query("SELECT COUNT(*) FROM residents WHERE is_active = 1")->fetch_row()[0];
$total_cases = (int)$conn->query("SELECT COUNT(*) FROM cases")->fetch_row()[0];
$total_documents = (int)$conn->query("SELECT COUNT(*) FROM documents")->fetch_row()[0];
$total_blotter = (int)$conn->query("SELECT COUNT(*) FROM blotter")->fetch_row()[0];
$total_officials = (int)$conn->query("SELECT COUNT(*) FROM officials WHERE is_current = 1")->fetch_row()[0];

// Gender distribution
$gender_stats = $conn->query("SELECT gender, COUNT(*) as count FROM residents WHERE is_active = 1 GROUP BY gender")->fetch_all(MYSQLI_ASSOC);

// Monthly cases (last 6 months)
$monthly_cases = $conn->query("
    SELECT DATE_FORMAT(date_filed, '%Y-%m') as month, COUNT(*) as count 
    FROM cases 
    WHERE date_filed >= DATE_SUB(NOW(), INTERVAL 6 MONTH) 
    GROUP BY DATE_FORMAT(date_filed, '%Y-%m') 
    ORDER BY month DESC
")->fetch_all(MYSQLI_ASSOC);

// Case status breakdown
$case_status = $conn->query("SELECT status, COUNT(*) as count FROM cases GROUP BY status")->fetch_all(MYSQLI_ASSOC);

// Document status
$doc_status = $conn->query("SELECT status, COUNT(*) as count FROM documents GROUP BY status")->fetch_all(MYSQLI_ASSOC);

// Blotter status
$blotter_status = $conn->query("SELECT status, COUNT(*) as count FROM blotter GROUP BY status")->fetch_all(MYSQLI_ASSOC);

// Recent activity (last 10)
$recent_activity = $conn->query("SELECT * FROM activity_logs ORDER BY created_at DESC LIMIT 10")->fetch_all(MYSQLI_ASSOC);

$conn->close();

// Handle export
if (isset($_GET['export'])) {
    $type = $_GET['export'];
    $format = $_GET['format'] ?? 'csv';
    
    $conn = getConnection();
    
    switch($type) {
        case 'residents':
            $data = $conn->query("SELECT id, first_name, last_name, gender, full_address, contact_number, email, created_at FROM residents WHERE is_active = 1")->fetch_all(MYSQLI_ASSOC);
            $filename = "residents_export_" . date('Y-m-d');
            break;
        case 'cases':
            $data = $conn->query("SELECT c.case_number, c.date_filed, r.first_name, r.last_name, c.respondent_name, c.nature, c.status FROM cases c JOIN residents r ON c.complainant_id = r.id")->fetch_all(MYSQLI_ASSOC);
            $filename = "cases_export_" . date('Y-m-d');
            break;
        case 'documents':
            $data = $conn->query("SELECT d.document_number, d.document_type, r.first_name, r.last_name, d.status, d.requested_date, d.amount FROM documents d JOIN residents r ON d.resident_id = r.id")->fetch_all(MYSQLI_ASSOC);
            $filename = "documents_export_" . date('Y-m-d');
            break;
        case 'blotter':
            $data = $conn->query("SELECT blotter_number, incident_type, complainant_name, respondent_name, incident_date, status FROM blotter")->fetch_all(MYSQLI_ASSOC);
            $filename = "blotter_export_" . date('Y-m-d');
            break;
        default:
            exit;
    }
    
    $conn->close();
    
    if ($format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        if (!empty($data)) {
            fputcsv($output, array_keys($data[0]));
            foreach ($data as $row) {
                fputcsv($output, $row);
            }
        }
        fclose($output);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Reports & Analytics - Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Source Sans 3', sans-serif; background: #f0f3f9; color: #1a2a4a; }
        
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 240px; background: #0a2a5e; display: flex; flex-direction: column; border-right: 4px solid #c8a84b; }
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
        .topbar { background: #fff; border-bottom: 1px solid #dde3f0; padding: 1rem 2rem; }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 20px; color: #0a2a5e; }
        .content { padding: 2rem; }
        
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; padding: 1.5rem; text-align: center; }
        .stat-number { font-size: 32px; font-weight: 700; color: #0a2a5e; }
        .stat-label { font-size: 12px; color: #7a8aaa; margin-top: 4px; }
        
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .chart-card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; padding: 1.5rem; }
        .chart-card h3 { margin-bottom: 1rem; font-size: 14px; color: #0a2a5e; }
        
        .export-buttons { display: flex; gap: 10px; margin-bottom: 1.5rem; flex-wrap: wrap; }
        button, .btn { background: #0a2a5e; color: white; border: none; padding: 8px 16px; border-radius: 40px; cursor: pointer; font-size: 13px; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; }
        button:hover { background: #1a407a; }
        
        .card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; overflow: hidden; }
        .card-header { padding: 1rem 1.5rem; background: #f7f9fc; border-bottom: 1px solid #eef0f6; font-weight: 600; }
        
        .activity-item { padding: 0.75rem 1.5rem; border-bottom: 1px solid #eef0f6; }
        .activity-action { font-weight: 600; font-size: 13px; }
        .activity-details { font-size: 12px; color: #6c7e9a; margin-top: 2px; }
        .activity-time { font-size: 11px; color: #9ca3af; margin-top: 2px; }
        
        @media (max-width: 768px) { .sidebar { width: 100%; position: relative; height: auto; } .main { margin-left: 0; } }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar"><h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1></div>
    <div class="content">
        
        <div class="stat-grid">
            <div class="stat-card"><div class="stat-number"><?= $total_residents ?></div><div class="stat-label">Active Residents</div></div>
            <div class="stat-card"><div class="stat-number"><?= $total_cases ?></div><div class="stat-label">Total Cases</div></div>
            <div class="stat-card"><div class="stat-number"><?= $total_documents ?></div><div class="stat-label">Document Requests</div></div>
            <div class="stat-card"><div class="stat-number"><?= $total_blotter ?></div><div class="stat-label">Blotter Reports</div></div>
            <div class="stat-card"><div class="stat-number"><?= $total_officials ?></div><div class="stat-label">Current Officials</div></div>
        </div>

        <div class="export-buttons">
            <h3 style="width:100%; margin-bottom:5px;">Export Data (CSV)</h3>
            <a href="?export=residents&format=csv" class="btn"><i class="fas fa-download"></i> Export Residents</a>
            <a href="?export=cases&format=csv" class="btn"><i class="fas fa-download"></i> Export Cases</a>
            <a href="?export=documents&format=csv" class="btn"><i class="fas fa-download"></i> Export Documents</a>
            <a href="?export=blotter&format=csv" class="btn"><i class="fas fa-download"></i> Export Blotter</a>
        </div>

        <div class="chart-grid">
            <div class="chart-card"><h3>Gender Distribution</h3><canvas id="genderChart"></canvas></div>
            <div class="chart-card"><h3>Case Status</h3><canvas id="caseStatusChart"></canvas></div>
            <div class="chart-card"><h3>Document Status</h3><canvas id="docStatusChart"></canvas></div>
            <div class="chart-card"><h3>Blotter Status</h3><canvas id="blotterStatusChart"></canvas></div>
        </div>

        <div class="card">
            <div class="card-header"><i class="fas fa-history"></i> Recent Activity Logs</div>
            <?php if (count($recent_activity) > 0): ?>
                <?php foreach ($recent_activity as $act): ?>
                <div class="activity-item">
                    <div class="activity-action"><i class="fas fa-circle" style="font-size: 8px; color: #c8a84b;"></i> <?= htmlspecialchars($act['action'] ?? 'N/A') ?></div>
                    <div class="activity-details"><?= htmlspecialchars($act['details'] ?? '') ?></div>
                    <div class="activity-time"><i class="far fa-clock"></i> <?= date('M j, Y g:i A', strtotime($act['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding: 2rem; text-align: center; color: #999;">No activity logs yet.</div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<script>
const genderData = <?= json_encode($gender_stats) ?>;
const caseStatusData = <?= json_encode($case_status) ?>;
const docStatusData = <?= json_encode($doc_status) ?>;
const blotterStatusData = <?= json_encode($blotter_status) ?>;

if (genderData.length) {
    new Chart(document.getElementById('genderChart'), { 
        type: 'pie', 
        data: { labels: genderData.map(g=>g.gender || 'Unknown'), datasets: [{ data: genderData.map(g=>g.count), backgroundColor: ['#0a2a5e','#c8a84b','#7a8aaa'] }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
}
if (caseStatusData.length) {
    new Chart(document.getElementById('caseStatusChart'), { 
        type: 'doughnut', 
        data: { labels: caseStatusData.map(c=>c.status), datasets: [{ data: caseStatusData.map(c=>c.count), backgroundColor: ['#b36200','#1a6e3a','#1a3a7a','#555','#777'] }] },
        options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
    });
}
if (docStatusData.length) {
    new Chart(document.getElementById('docStatusChart'), { 
        type: 'bar', 
        data: { labels: docStatusData.map(d=>d.status), datasets: [{ label: 'Count', data: docStatusData.map(d=>d.count), backgroundColor: '#0a2a5e' }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, precision: 0 } } }
    });
}
if (blotterStatusData.length) {
    new Chart(document.getElementById('blotterStatusChart'), { 
        type: 'bar', 
        data: { labels: blotterStatusData.map(b=>b.status.replace(/_/g,' ')), datasets: [{ label: 'Count', data: blotterStatusData.map(b=>b.count), backgroundColor: '#c8a84b' }] },
        options: { responsive: true, scales: { y: { beginAtZero: true, precision: 0 } } }
    });
}
</script>
</body>
</html>