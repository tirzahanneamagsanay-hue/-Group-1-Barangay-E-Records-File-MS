<?php
// ============================================================
// reports.php - ANALYTICS & EXPORT MODULE (DEBUG VERSION)
// ============================================================

// Enable error reporting
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Check if includes exist
$required_files = [
    'includes/auth.php',
    'includes/db.php',
    'includes/sidebar.php'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        die("<h2 style='color:red;'>ERROR: Missing file: {$file}</h2>
             <p>Please check if this file exists in your project.</p>
             <p>Current directory: " . __DIR__ . "</p>");
    }
}

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$active_page = 'reports';
$conn = getConnection();

// Check database connection
if (!$conn) {
    die("<h2 style='color:red;'>ERROR: Database connection failed</h2>
         <p>Please check your database credentials in includes/db.php</p>");
}

// Input validation for export
$export_type = isset($_GET['export']) ? strtolower(trim($_GET['export'])) : null;
$export_format = isset($_GET['format']) ? strtolower(trim($_GET['format'])) : 'csv';

$allowed_formats = ['csv'];
$allowed_exports = ['residents', 'cases', 'documents', 'blotter', 'officials', 'activity'];

if ($export_type && (!in_array($export_format, $allowed_formats) || !in_array($export_type, $allowed_exports))) {
    exit('Invalid export parameters');
}

// ============================================================
// STATISTICS SECTION
// ============================================================

$total_residents = 0;
$total_cases = 0;
$total_documents = 0;
$total_blotter = 0;
$total_officials = 0;

// Fetch statistics with error handling
$queries = [
    'total_residents' => "SELECT COUNT(*) as count FROM residents WHERE is_active = 1",
    'total_cases' => "SELECT COUNT(*) as count FROM cases",
    'total_documents' => "SELECT COUNT(*) as count FROM documents",
    'total_blotter' => "SELECT COUNT(*) as count FROM blotter",
    'total_officials' => "SELECT COUNT(*) as count FROM officials WHERE is_current = 1"
];

foreach ($queries as $var => $query) {
    $result = $conn->query($query);
    if (!$result) {
        error_log("Query error for {$var}: " . $conn->error);
        $$var = 0;
    } else {
        $row = $result->fetch_assoc();
        $$var = (int)($row['count'] ?? 0);
    }
}

// Gender distribution
$gender_stats = [];
$result = $conn->query("SELECT gender, COUNT(*) as count FROM residents WHERE is_active = 1 GROUP BY gender ORDER BY count DESC");
if ($result) {
    $gender_stats = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Monthly cases (last 6 months)
$monthly_cases = [];
$result = $conn->query("
    SELECT DATE_FORMAT(date_filed, '%Y-%m') as month, COUNT(*) as count 
    FROM cases 
    WHERE date_filed >= DATE_SUB(NOW(), INTERVAL 6 MONTH) 
    GROUP BY DATE_FORMAT(date_filed, '%Y-%m') 
    ORDER BY month DESC
");
if ($result) {
    $monthly_cases = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Case status breakdown
$case_status = [];
$result = $conn->query("SELECT status, COUNT(*) as count FROM cases GROUP BY status ORDER BY count DESC");
if ($result) {
    $case_status = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Document status
$doc_status = [];
$result = $conn->query("SELECT status, COUNT(*) as count FROM documents GROUP BY status ORDER BY count DESC");
if ($result) {
    $doc_status = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Blotter status
$blotter_status = [];
$result = $conn->query("SELECT status, COUNT(*) as count FROM blotter GROUP BY status ORDER BY count DESC");
if ($result) {
    $blotter_status = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Case priority
$case_priority = [];
$result = $conn->query("SELECT priority, COUNT(*) as count FROM cases GROUP BY priority ORDER BY FIELD(priority, 'Critical', 'High', 'Medium', 'Low')");
if ($result) {
    $case_priority = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// Recent activity
$recent_activity = [];
$result = $conn->query("
    SELECT al.*, u.username, u.full_name 
    FROM activity_logs al 
    LEFT JOIN users u ON al.user_id = u.id 
    ORDER BY al.created_at DESC 
    LIMIT 10
");
if ($result) {
    $recent_activity = $result->fetch_all(MYSQLI_ASSOC) ?? [];
}

// ============================================================
// EXPORT HANDLING
// ============================================================

if ($export_type) {
    $data = [];
    $filename = "";
    
    switch($export_type) {
        case 'residents':
            $query = "
                SELECT 
                    id,
                    first_name,
                    last_name,
                    middle_name,
                    gender,
                    birthdate,
                    age,
                    civil_status,
                    full_address,
                    purok,
                    barangay,
                    city,
                    zipcode,
                    contact_number,
                    email,
                    occupation,
                    created_at
                FROM residents 
                WHERE is_active = 1
                ORDER BY last_name, first_name ASC
            ";
            $filename = "residents_export_" . date('Y-m-d_Hi');
            break;
            
        case 'cases':
            $query = "
                SELECT 
                    c.case_number,
                    c.date_filed,
                    r.first_name,
                    r.last_name,
                    c.respondent_name,
                    c.nature,
                    c.status,
                    c.priority,
                    c.date_resolved,
                    c.description
                FROM cases c 
                LEFT JOIN residents r ON c.complainant_id = r.id
                ORDER BY c.date_filed DESC
            ";
            $filename = "cases_export_" . date('Y-m-d_Hi');
            break;
            
        case 'documents':
            $query = "
                SELECT 
                    d.document_number,
                    d.document_type,
                    r.first_name,
                    r.last_name,
                    r.contact_number,
                    d.purpose,
                    d.status,
                    d.requested_date,
                    d.released_date,
                    d.amount
                FROM documents d 
                LEFT JOIN residents r ON d.resident_id = r.id
                ORDER BY d.requested_date DESC
            ";
            $filename = "documents_export_" . date('Y-m-d_Hi');
            break;
            
        case 'blotter':
            $query = "
                SELECT 
                    b.blotter_number,
                    b.incident_type,
                    b.complainant_name,
                    b.respondent_name,
                    b.incident_date,
                    b.incident_time,
                    b.location,
                    b.description,
                    b.status,
                    b.resolved_date
                FROM blotter b
                ORDER BY b.incident_date DESC
            ";
            $filename = "blotter_export_" . date('Y-m-d_Hi');
            break;
            
        case 'officials':
            $query = "
                SELECT 
                    full_name,
                    position,
                    position_order,
                    contact_number,
                    email,
                    term_start,
                    term_end,
                    is_current,
                    bio
                FROM officials
                WHERE is_current = 1
                ORDER BY position_order ASC
            ";
            $filename = "officials_export_" . date('Y-m-d_Hi');
            break;
            
        case 'activity':
            $query = "
                SELECT 
                    al.created_at,
                    u.full_name as user,
                    al.action,
                    al.details,
                    al.ip_address
                FROM activity_logs al
                LEFT JOIN users u ON al.user_id = u.id
                ORDER BY al.created_at DESC
                LIMIT 500
            ";
            $filename = "activity_logs_export_" . date('Y-m-d_Hi');
            break;
            
        default:
            exit('Invalid export type');
    }
    
    $result = $conn->query($query);
    if (!$result) {
        error_log("Export query error: " . $conn->error);
        exit('Error fetching export data: ' . $conn->error);
    }
    
    $data = $result->fetch_all(MYSQLI_ASSOC) ?? [];
    
    $conn->close();
    
    if (!empty($data)) {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        
        fputcsv($output, array_keys($data[0]));
        foreach ($data as $row) {
            fputcsv($output, $row);
        }
        
        fclose($output);
        exit;
    } else {
        exit('No data available for export');
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports & Analytics - Barangay E-Records</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Source Sans 3', sans-serif; background: #f0f3f9; color: #1a2a4a; }
        
        .sidebar { position: fixed; left: 0; top: 0; bottom: 0; width: 240px; background: #0a2a5e; display: flex; flex-direction: column; border-right: 4px solid #c8a84b; z-index: 100; }
        .sidebar-logo { padding: 1.5rem 1.25rem; border-bottom: 1px solid rgba(255,255,255,0.1); text-align: center; }
        .sidebar-logo h2 { font-family: 'Playfair Display', serif; font-size: 14px; color: #f0d080; }
        .nav-section { padding: 1rem 0; flex: 1; overflow-y: auto; }
        .nav-label { font-size: 10px; color: rgba(255,255,255,0.3); letter-spacing: 0.12em; text-transform: uppercase; padding: 0 1.25rem; margin-top: 12px; }
        .nav-item { display: flex; align-items: center; gap: 10px; padding: 10px 1.25rem; color: rgba(255,255,255,0.65); text-decoration: none; font-size: 14px; transition: all 0.3s ease; }
        .nav-item:hover { background: rgba(255,255,255,0.08); color: #fff; }
        .nav-item.active { background: rgba(200,168,75,0.15); color: #f0d080; border-right: 3px solid #c8a84b; }
        .nav-item i { width: 20px; text-align: center; }
        .sidebar-user { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; gap: 10px; }
        .avatar { width: 36px; height: 36px; border-radius: 50%; background: rgba(200,168,75,0.2); border: 1.5px solid #c8a84b; display: flex; align-items: center; justify-content: center; color: #f0d080; font-weight: 600; }
        .user-info p { font-size: 13px; color: #fff; font-weight: 500; }
        .user-info span { font-size: 11px; color: rgba(255,255,255,0.45); }
        .btn-logout { display: block; margin: 0.5rem 1rem 1rem; background: rgba(255,255,255,0.07); border: 1px solid rgba(255,255,255,0.12); border-radius: 6px; padding: 8px; color: rgba(255,255,255,0.55); text-align: center; text-decoration: none; font-size: 12px; transition: all 0.3s ease; }
        .btn-logout:hover { background: rgba(255,0,0,0.15); color: #ff9999; }
        
        .main { margin-left: 240px; min-height: 100vh; }
        .topbar { background: #fff; border-bottom: 1px solid #dde3f0; padding: 1rem 2rem; display: flex; justify-content: space-between; align-items: center; }
        .topbar h1 { font-family: 'Playfair Display', serif; font-size: 20px; color: #0a2a5e; }
        .content { padding: 2rem; }
        
        .stat-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 1rem; margin-bottom: 2rem; }
        .stat-card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; padding: 1.5rem; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.05); transition: transform 0.3s ease, box-shadow 0.3s ease; }
        .stat-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .stat-number { font-size: 32px; font-weight: 700; color: #0a2a5e; }
        .stat-label { font-size: 12px; color: #7a8aaa; margin-top: 4px; font-weight: 500; }
        
        .section-title { font-size: 16px; font-weight: 600; color: #0a2a5e; margin: 2rem 0 1rem 0; display: flex; align-items: center; gap: 8px; }
        
        .export-buttons { display: flex; gap: 10px; margin-bottom: 1.5rem; flex-wrap: wrap; padding: 1rem; background: #f7f9fc; border-radius: 8px; border-left: 4px solid #c8a84b; }
        .export-buttons h3 { width: 100%; margin-bottom: 10px; font-size: 14px; color: #0a2a5e; }
        
        .chart-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(350px, 1fr)); gap: 1.5rem; margin-bottom: 2rem; }
        .chart-card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; padding: 1.5rem; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .chart-card h3 { margin-bottom: 1rem; font-size: 14px; color: #0a2a5e; font-weight: 600; }
        
        button, .btn { background: #0a2a5e; color: white; border: none; padding: 8px 16px; border-radius: 40px; cursor: pointer; font-size: 13px; font-weight: 500; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: all 0.3s ease; }
        button:hover { background: #1a407a; transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        button:active { transform: translateY(0); }
        
        .card { background: #fff; border-radius: 12px; border: 1px solid #dde3f0; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .card-header { padding: 1rem 1.5rem; background: #f7f9fc; border-bottom: 1px solid #eef0f6; font-weight: 600; color: #0a2a5e; }
        
        .activity-item { padding: 0.75rem 1.5rem; border-bottom: 1px solid #eef0f6; transition: background 0.2s ease; }
        .activity-item:hover { background: #f9fafb; }
        .activity-item:last-child { border-bottom: none; }
        .activity-action { font-weight: 600; font-size: 13px; color: #0a2a5e; }
        .activity-user { font-size: 12px; color: #7a8aaa; margin-top: 2px; }
        .activity-details { font-size: 12px; color: #6c7e9a; margin-top: 2px; }
        .activity-time { font-size: 11px; color: #9ca3af; margin-top: 4px; }
        
        .no-data { padding: 2rem; text-align: center; color: #999; }
        
        @media (max-width: 768px) {
            .sidebar { width: 100%; position: relative; height: auto; flex-direction: row; }
            .nav-section { flex-direction: row; display: flex; flex: 1; }
            .main { margin-left: 0; }
            .topbar { flex-direction: column; gap: 1rem; align-items: flex-start; }
            .chart-grid { grid-template-columns: 1fr; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>

<?php include 'includes/sidebar.php'; ?>

<div class="main">
    <div class="topbar">
        <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
        <span style="color: #7a8aaa; font-size: 12px;">Last updated: <?php echo date('M j, Y g:i A'); ?></span>
    </div>
    
    <div class="content">
        
        <h2 class="section-title"><i class="fas fa-chart-pie"></i> Overview</h2>
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-number"><?= $total_residents ?></div>
                <div class="stat-label">Active Residents</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_cases ?></div>
                <div class="stat-label">Total Cases</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_documents ?></div>
                <div class="stat-label">Document Requests</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_blotter ?></div>
                <div class="stat-label">Blotter Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-number"><?= $total_officials ?></div>
                <div class="stat-label">Current Officials</div>
            </div>
        </div>

        <h2 class="section-title"><i class="fas fa-download"></i> Export Data</h2>
        <div class="export-buttons">
            <h3>Export Data (CSV Format)</h3>
            <a href="?export=residents&format=csv" class="btn"><i class="fas fa-download"></i> Export Residents</a>
            <a href="?export=cases&format=csv" class="btn"><i class="fas fa-download"></i> Export Cases</a>
            <a href="?export=documents&format=csv" class="btn"><i class="fas fa-download"></i> Export Documents</a>
            <a href="?export=blotter&format=csv" class="btn"><i class="fas fa-download"></i> Export Blotter</a>
            <a href="?export=officials&format=csv" class="btn"><i class="fas fa-download"></i> Export Officials</a>
            <a href="?export=activity&format=csv" class="btn"><i class="fas fa-download"></i> Export Activity Logs</a>
        </div>

        <h2 class="section-title"><i class="fas fa-chart-bar"></i> Analytics</h2>
        <div class="chart-grid">
            <div class="chart-card">
                <h3><i class="fas fa-venus-mars"></i> Gender Distribution</h3>
                <canvas id="genderChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-tasks"></i> Case Status</h3>
                <canvas id="caseStatusChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-exclamation-circle"></i> Case Priority</h3>
                <canvas id="casePriorityChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-file-alt"></i> Document Status</h3>
                <canvas id="docStatusChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-book"></i> Blotter Status</h3>
                <canvas id="blotterStatusChart"></canvas>
            </div>
            <div class="chart-card">
                <h3><i class="fas fa-calendar"></i> Cases (Last 6 Months)</h3>
                <canvas id="monthlyChart"></canvas>
            </div>
        </div>

        <h2 class="section-title"><i class="fas fa-history"></i> Recent System Activity</h2>
        <div class="card">
            <?php if (count($recent_activity) > 0): ?>
                <?php foreach ($recent_activity as $act): ?>
                <div class="activity-item">
                    <div class="activity-action"><i class="fas fa-circle" style="font-size: 8px; color: #c8a84b;"></i> <?= htmlspecialchars($act['action'] ?? 'N/A') ?></div>
                    <div class="activity-user">By: <?= htmlspecialchars($act['full_name'] ?? $act['username'] ?? 'System') ?></div>
                    <div class="activity-details"><?= htmlspecialchars($act['details'] ?? '') ?></div>
                    <div class="activity-time"><i class="far fa-clock"></i> <?= date('M j, Y g:i A', strtotime($act['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="no-data">No activity logs yet.</div>
            <?php endif; ?>
        </div>
        
    </div>
</div>

<script>
const chartConfig = {
    responsive: true,
    maintainAspectRatio: true,
    plugins: {
        legend: { 
            position: 'bottom',
            labels: { font: { size: 12 }, padding: 15 }
        }
    }
};

const genderData = <?= json_encode($gender_stats) ?>;
if (genderData.length) {
    new Chart(document.getElementById('genderChart'), { 
        type: 'pie', 
        data: { 
            labels: genderData.map(g => g.gender || 'Unknown'), 
            datasets: [{ 
                data: genderData.map(g => g.count), 
                backgroundColor: ['#0a2a5e', '#c8a84b', '#7a8aaa', '#b36200'],
                borderColor: '#fff',
                borderWidth: 2
            }] 
        },
        options: chartConfig
    });
}

const caseStatusData = <?= json_encode($case_status) ?>;
if (caseStatusData.length) {
    new Chart(document.getElementById('caseStatusChart'), { 
        type: 'doughnut', 
        data: { 
            labels: caseStatusData.map(c => c.status), 
            datasets: [{ 
                data: caseStatusData.map(c => c.count), 
                backgroundColor: ['#1a6e3a', '#1a3a7a', '#b36200', '#d9534f', '#555'],
                borderColor: '#fff',
                borderWidth: 2
            }] 
        },
        options: chartConfig
    });
}

const casePriorityData = <?= json_encode($case_priority) ?>;
if (casePriorityData.length) {
    new Chart(document.getElementById('casePriorityChart'), { 
        type: 'bar', 
        data: { 
            labels: casePriorityData.map(c => c.priority), 
            datasets: [{ 
                label: 'Number of Cases',
                data: casePriorityData.map(c => c.count), 
                backgroundColor: '#c8a84b',
                borderRadius: 4
            }] 
        },
        options: {
            ...chartConfig,
            scales: { 
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
}

const docStatusData = <?= json_encode($doc_status) ?>;
if (docStatusData.length) {
    new Chart(document.getElementById('docStatusChart'), { 
        type: 'bar', 
        data: { 
            labels: docStatusData.map(d => d.status), 
            datasets: [{ 
                label: 'Number of Requests',
                data: docStatusData.map(d => d.count), 
                backgroundColor: '#0a2a5e',
                borderRadius: 4
            }] 
        },
        options: {
            ...chartConfig,
            scales: { 
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
}

const blotterStatusData = <?= json_encode($blotter_status) ?>;
if (blotterStatusData.length) {
    new Chart(document.getElementById('blotterStatusChart'), { 
        type: 'bar', 
        data: { 
            labels: blotterStatusData.map(b => b.status.replace(/_/g, ' ')), 
            datasets: [{ 
                label: 'Number of Reports',
                data: blotterStatusData.map(b => b.count), 
                backgroundColor: '#1a6e3a',
                borderRadius: 4
            }] 
        },
        options: {
            ...chartConfig,
            scales: { 
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
}

const monthlyCasesData = <?= json_encode($monthly_cases) ?>;
if (monthlyCasesData.length) {
    new Chart(document.getElementById('monthlyChart'), { 
        type: 'line', 
        data: { 
            labels: monthlyCasesData.map(m => m.month), 
            datasets: [{ 
                label: 'Cases Filed',
                data: monthlyCasesData.map(m => m.count), 
                borderColor: '#0a2a5e',
                backgroundColor: 'rgba(10, 42, 94, 0.1)',
                borderWidth: 2,
                fill: true,
                tension: 0.4,
                pointBackgroundColor: '#c8a84b',
                pointBorderColor: '#0a2a5e',
                pointBorderWidth: 2,
                pointRadius: 5
            }] 
        },
        options: {
            ...chartConfig,
            scales: { 
                y: { beginAtZero: true, ticks: { stepSize: 1 } }
            }
        }
    });
}
</script>
</body>
</html>