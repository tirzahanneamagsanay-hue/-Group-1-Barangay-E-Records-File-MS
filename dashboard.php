<?php
// ============================================================
// dashboard.php - FIXED VERSION
// Admin dashboard with residents & cases overview
// ============================================================

require_once 'includes/auth.php';
requireLogin();

require_once 'includes/db.php';

$active_page = 'dashboard';

$conn = getConnection();

// Get total counts (Residents & Complaints)
$total_residents = (int) $conn->query("SELECT COUNT(*) AS c FROM residents WHERE is_active = 1")->fetch_assoc()['c'];

$case_stats = $conn->query("
    SELECT
        COUNT(*) AS total_complaints,
        SUM(status = 'Pending') AS pending_complaints,
        SUM(status = 'Resolved') AS resolved_complaints
    FROM cases
")->fetch_assoc();

$total_complaints    = (int) $case_stats['total_complaints'];
$pending_complaints  = (int) $case_stats['pending_complaints'];
$resolved_complaints = (int) $case_stats['resolved_complaints'];

// Handle search query
$search_term = isset($_GET['q']) ? trim($_GET['q']) : '';
$search_results = null;
$show_search_results = !empty($search_term);

if ($show_search_results) {
    $search_pattern = '%' . $search_term . '%';
    
    $stmt_residents = $conn->prepare("
        SELECT first_name, last_name, full_address, created_at
        FROM residents
        WHERE first_name LIKE ? OR last_name LIKE ? OR full_address LIKE ?
        ORDER BY created_at DESC
        LIMIT 20
    ");
    $stmt_residents->bind_param("sss", $search_pattern, $search_pattern, $search_pattern);
    $stmt_residents->execute();
    $search_residents = $stmt_residents->get_result();
    
    $stmt_cases = $conn->prepare("
        SELECT c.case_number, r.first_name, r.last_name, c.status, c.date_filed
        FROM cases c
        JOIN residents r ON c.complainant_id = r.id
        WHERE c.case_number LIKE ? 
           OR r.first_name LIKE ? 
           OR r.last_name LIKE ? 
           OR c.status LIKE ?
        ORDER BY c.created_at DESC
        LIMIT 20
    ");
    $stmt_cases->bind_param("ssss", $search_pattern, $search_pattern, $search_pattern, $search_pattern);
    $stmt_cases->execute();
    $search_cases = $stmt_cases->get_result();
    
    $search_results = [
        'residents' => $search_residents,
        'cases'     => $search_cases
    ];
} else {
    $recent_residents = $conn->query("
        SELECT first_name, last_name, full_address, created_at
        FROM residents
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $recent_cases = $conn->query("
        SELECT c.case_number, r.first_name, r.last_name, c.status, c.date_filed
        FROM cases c
        JOIN residents r ON c.complainant_id = r.id
        ORDER BY c.created_at DESC
        LIMIT 5
    ");
}

$conn->close();

function getBadgeClass(string $status): string {
    return match($status) {
        'Pending'             => 'badge-pending',
        'Resolved'            => 'badge-resolved',
        'Dismissed'           => 'badge-dismissed',
        'Under Investigation' => 'badge-under',
        'Closed'              => 'badge-closed',
        default               => 'badge-other',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — Barangay E-Records System</title>
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Source+Sans+3:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        body {
            font-family: 'Source Sans 3', sans-serif;
            background: #f0f3f9;
            color: #1a2a4a;
        }

        /* Sidebar styles live in includes/sidebar.php (unified design) */

        /* ---- Main Content ---- */
        .main {
            margin-left: 252px; /* matches --sb-w in sidebar.php */
            min-height: 100vh;
        }

        @media (max-width: 900px) {
            .main { margin-left: 0; }
        }

        .topbar {
            background: #fff;
            border-bottom: 1px solid #dde3f0;
            padding: 1rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 2rem;
        }

        .topbar h1 {
            font-family: 'Playfair Display', serif;
            font-size: 20px;
            color: #0a2a5e;
        }

        .search-form {
            display: flex;
            align-items: center;
            gap: 8px;
            flex: 1;
            max-width: 400px;
        }

        .search-input {
            flex: 1;
            padding: 8px 12px;
            border: 1px solid #dde3f0;
            border-radius: 8px;
            font-size: 13px;
            font-family: 'Source Sans 3', sans-serif;
        }

        .search-btn {
            background: #0a2a5e;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 500;
            transition: background 0.15s;
        }

        .search-btn:hover {
            background: #1a407a;
        }

        .search-clear {
            color: #0a2a5e;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
        }

        /* ---- Clock ---- */
        .clock-widget {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            flex-shrink: 0;
            background: #f7f9fc;
            border: 1px solid #dde3f0;
            border-radius: 10px;
            padding: 6px 14px;
            min-width: 130px;
        }

        .clock-time {
            font-size: 20px;
            font-weight: 700;
            color: #0a2a5e;
            letter-spacing: 0.04em;
            line-height: 1.2;
            font-variant-numeric: tabular-nums;
        }

        .clock-time .ampm {
            font-size: 11px;
            font-weight: 600;
            color: #c8a84b;
            margin-left: 3px;
            vertical-align: middle;
        }

        .clock-date {
            font-size: 10px;
            color: #8a98b4;
            letter-spacing: 0.04em;
            margin-top: 1px;
        }

        /* ---- Stat Cards ---- */
        .content {
            padding: 2rem;
        }

        .stat-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }

        .stat-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dde3f0;
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: all 0.2s;
        }

        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.05);
        }

        .stat-icon {
            width: 56px;
            height: 56px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
            flex-shrink: 0;
        }

        .stat-icon.blue  { background: #e6eefa; color: #0d47a1; }
        .stat-icon.gold  { background: #fef8e6; color: #b8860b; }
        .stat-icon.orange { background: #fff0e6; color: #d97706; }
        .stat-icon.green { background: #e6f5ee; color: #1a6e3a; }

        .stat-val {
            font-size: 28px;
            font-weight: 700;
            color: #0a2a5e;
            line-height: 1;
        }

        .stat-label {
            font-size: 13px;
            color: #7a8aaa;
            margin-top: 4px;
        }

        /* ---- Tables & Cards ---- */
        .section-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(450px, 1fr));
            gap: 2rem;
            margin-bottom: 2rem;
        }

        .table-card {
            background: #fff;
            border-radius: 12px;
            border: 1px solid #dde3f0;
            overflow: hidden;
        }

        .table-card-header {
            padding: 1rem 1.5rem;
            background: #f7f9fc;
            border-bottom: 1px solid #eef0f6;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .table-card-header h3 { font-size: 14px; font-weight: 600; color: #0a2a5e; }
        .table-card-header a  { font-size: 12px; color: #0a5aa1; text-decoration: none; font-weight: 500; }
        .table-card-header a:hover { text-decoration: underline; }

        .search-result-meta {
            padding: 0.75rem 1.5rem;
            background: #f0f3f9;
            font-size: 12px;
            color: #7a8aaa;
            border-bottom: 1px solid #eef0f6;
        }

        table { width: 100%; border-collapse: collapse; }
        thead tr { background: #f7f9fc; }

        th {
            padding: 10px 1.5rem;
            text-align: left;
            font-size: 11px;
            font-weight: 600;
            color: #8a98b4;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }

        td {
            padding: 12px 1.5rem;
            font-size: 13px;
            color: #1a2a4a;
            border-bottom: 1px solid #eef0f6;
        }

        tbody tr:hover { background: #f7f9fc; }
        tbody tr:last-child td { border-bottom: none; }

        /* ---- Badges ---- */
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; }
        .badge-pending    { background: #fff3e0; color: #b36200; }
        .badge-resolved   { background: #e6f5ee; color: #1a6e3a; }
        .badge-dismissed  { background: #f5f5f5; color: #555; }
        .badge-under      { background: #e6eefa; color: #1a3a7a; }
        .badge-closed     { background: #e8e8e8; color: #333; }
        .badge-other      { background: #f0f0f0; color: #555; }

        .no-records { padding: 2rem; text-align: center; color: #a8b4cc; font-size: 13px; }

        /* ---- Responsive ---- */
        @media (max-width: 1024px) {
            .section-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 768px) {
            .topbar { flex-wrap: wrap; }
            .search-form { max-width: 100%; order: 3; flex-basis: 100%; }
            .clock-widget { order: 2; }
            .stat-grid { grid-template-columns: repeat(2, 1fr); }
            .section-grid { grid-template-columns: 1fr; }
        }

        @media (max-width: 480px) {
            .topbar { padding: 1rem; }
            .content { padding: 1rem; }
            .stat-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<!-- ---- Sidebar ---- -->
<?php include 'includes/sidebar.php'; ?>

<!-- ---- Main Dashboard Area ---- -->
<div class="main">
    <div class="topbar">
        <h1>Dashboard</h1>
        
        

        <form method="GET" action="dashboard.php" class="search-form">
            <input type="text" name="q" class="search-input" placeholder="Search residents or cases..." value="<?= htmlspecialchars($search_term) ?>">
            <button type="submit" class="search-btn"><i class="fas fa-search"></i> Search</button>
            <?php if ($show_search_results): ?>
                <a href="dashboard.php" class="search-clear"><i class="fas fa-times-circle"></i> Clear</a>
            <?php endif; ?>
        </form>

        <!-- Live Clock -->
        <div class="clock-widget" aria-live="polite" aria-label="Current time">
            <div class="clock-time" id="clock-time">
                <span id="clock-hms">--:--:--</span><span class="ampm" id="clock-ampm"></span>
            </div>
            <div class="clock-date" id="clock-date">---, --- --, ----</div>
        </div>
    </div>

    <div class="content">

        <!-- Stat Cards -->
        <div class="stat-grid">
            <div class="stat-card">
                <div class="stat-icon blue"><i class="fas fa-users"></i></div>
                <div>
                    <div class="stat-val"><?= $total_residents ?></div>
                    <div class="stat-label">Total Residents</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon gold"><i class="fas fa-file-alt"></i></div>
                <div>
                    <div class="stat-val"><?= $total_complaints ?></div>
                    <div class="stat-label">Total Cases</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon orange"><i class="fas fa-clock"></i></div>
                <div>
                    <div class="stat-val"><?= $pending_complaints ?></div>
                    <div class="stat-label">Pending Cases</div>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
                <div>
                    <div class="stat-val"><?= $resolved_complaints ?></div>
                    <div class="stat-label">Resolved Cases</div>
                </div>
            </div>
        </div>

        <?php if ($show_search_results): ?>
            <!-- SEARCH RESULTS -->
            <div style="margin-bottom: 1.5rem;">
                <h3 style="font-weight: 600; font-size: 16px; color: #0a2a5e;">
                    Search results for: <strong>"<?= htmlspecialchars($search_term) ?>"</strong>
                </h3>
            </div>
            
            <div class="section-grid">
                <!-- Residents Search Results -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3><i class="fas fa-users"></i> Residents</h3>
                        <a href="residents.php">Manage all</a>
                    </div>
                    <?php if ($search_results['residents'] && $search_results['residents']->num_rows > 0): ?>
                        <div class="search-result-meta">
                            Found <?= $search_results['residents']->num_rows ?> resident(s)
                        </div>
                        <table>
                            <thead>
                                <tr><th>Name</th><th>Address</th><th>Date Added</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($r = $search_results['residents']->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                    <td><?= htmlspecialchars(strlen($r['full_address']) > 30 ? substr($r['full_address'], 0, 30) . '…' : $r['full_address']) ?></td>
                                    <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">No residents match your search.</div>
                    <?php endif; ?>
                </div>

                <!-- Cases Search Results -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3><i class="fas fa-clipboard-list"></i> Cases</h3>
                        <a href="cases.php">View all</a>
                    </div>
                    <?php if ($search_results['cases'] && $search_results['cases']->num_rows > 0): ?>
                        <div class="search-result-meta">
                            Found <?= $search_results['cases']->num_rows ?> case(s)
                        </div>
                        <table>
                            <thead>
                                <tr><th>Case No.</th><th>Complainant</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($c = $search_results['cases']->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['case_number']) ?></td>
                                    <td><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                                    <td><span class="badge <?= getBadgeClass($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">No cases match your search.</div>
                    <?php endif; ?>
                </div>
            </div>

        <?php else: ?>
            <!-- DEFAULT DASHBOARD VIEW -->
            <div class="section-grid">

                <!-- Recent Residents -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3><i class="fas fa-users"></i> Recent Residents</h3>
                        <a href="residents.php">View all</a>
                    </div>
                    <?php if ($recent_residents && $recent_residents->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Name</th><th>Address</th><th>Date Added</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($r = $recent_residents->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?></td>
                                    <td><?= htmlspecialchars(strlen($r['full_address']) > 30 ? substr($r['full_address'], 0, 30) . '…' : $r['full_address']) ?></td>
                                    <td><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">No residents added yet.</div>
                    <?php endif; ?>
                </div>

                <!-- Recent Cases -->
                <div class="table-card">
                    <div class="table-card-header">
                        <h3><i class="fas fa-clipboard-list"></i> Recent Cases</h3>
                        <a href="cases.php">View all</a>
                    </div>
                    <?php if ($recent_cases && $recent_cases->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr><th>Case No.</th><th>Complainant</th><th>Status</th></tr>
                            </thead>
                            <tbody>
                            <?php while ($c = $recent_cases->fetch_assoc()): ?>
                                <tr>
                                    <td><?= htmlspecialchars($c['case_number']) ?></td>
                                    <td><?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name']) ?></td>
                                    <td><span class="badge <?= getBadgeClass($c['status']) ?>"><?= htmlspecialchars($c['status']) ?></span></td>
                                </tr>
                            <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-records">No cases filed yet.</div>
                    <?php endif; ?>
                </div>

            </div>
        <?php endif; ?>

    </div>
</div>

<script>
    const DAYS  = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const MONTHS = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];

    function updateClock() {
        const now  = new Date();
        let   h    = now.getHours();
        const ampm = h >= 12 ? 'PM' : 'AM';
        h = h % 12 || 12;
        const mm   = String(now.getMinutes()).padStart(2, '0');
        const ss   = String(now.getSeconds()).padStart(2, '0');

        document.getElementById('clock-hms').textContent  = `${String(h).padStart(2,'0')}:${mm}:${ss}`;
        document.getElementById('clock-ampm').textContent = ampm;

        const dateStr = `${DAYS[now.getDay()]}, ${MONTHS[now.getMonth()]} ${now.getDate()}, ${now.getFullYear()}`;
        document.getElementById('clock-date').textContent = dateStr;
    }

    updateClock();
    setInterval(updateClock, 1000);
</script>

</body>
</html>