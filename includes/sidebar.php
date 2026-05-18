<?php
/**
 * includes/sidebar.php
 * Unified sidebar for Admin Dashboard
 * Uses the same navy/gold design tokens as super_admin.php
 *
 * Expects: $active_page (string) — e.g. 'dashboard', 'residents', 'cases', 'blotter', etc.
 */
$active_page = $active_page ?? '';

// Determine if the Cases & Complaints group should start open
$cases_group = in_array($active_page, ['cases', 'blotter']);
?>
<style>
/* ── SIDEBAR DESIGN TOKENS (shared with super_admin) ── */
:root {
    --sb-navy:       #0b1f45;
    --sb-navy-mid:   #14305e;
    --sb-navy-light: #1e4080;
    --sb-gold:       #c8a040;
    --sb-gold-light: #e8c96a;
    --sb-w:          252px;
}

/* ── SIDEBAR SHELL ── */
.sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: var(--sb-w);
    background: var(--sb-navy);
    display: flex;
    flex-direction: column;
    z-index: 200;
    border-right: 3px solid var(--sb-gold);
    overflow: hidden; /* no horizontal scrollbar / arrows */
}

/* ── BRAND ── */
.sidebar-brand {
    padding: 20px 20px 16px;
    border-bottom: 1px solid rgba(255,255,255,.08);
    flex-shrink: 0;
}

.brand-seal {
    width: 44px; height: 44px;
    background: rgba(200,160,64,.15);
    border: 1.5px solid var(--sb-gold);
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    color: var(--sb-gold);
    font-size: 18px;
    margin-bottom: 10px;
}

.brand-name {
    font-family: 'Playfair Display', 'DM Serif Display', serif;
    font-size: 13px;
    color: var(--sb-gold-light);
    line-height: 1.3;
}

.brand-sub {
    font-size: 10px;
    color: rgba(255,255,255,.35);
    letter-spacing: .08em;
    text-transform: uppercase;
    margin-top: 2px;
}

/* ── NAV ── */
.nav-section {
    flex: 1;
    padding: 12px 0;
    overflow-y: auto;
    overflow-x: hidden;    /* no horizontal arrows */
    scrollbar-width: none; /* Firefox: hide scrollbar */
}
.nav-section::-webkit-scrollbar { width: 0; } /* Chrome/Safari: hide scrollbar */

.nav-label {
    font-size: 9.5px;
    color: rgba(255,255,255,.28);
    letter-spacing: .14em;
    text-transform: uppercase;
    padding: 12px 20px 4px;
    font-weight: 600;
}

/* Shared base for nav-item and nav-group-toggle */
.nav-item,
.nav-group-toggle {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 9px 20px;
    color: rgba(255,255,255,.58);
    text-decoration: none;
    font-size: 13px;
    font-weight: 500;
    transition: all .15s;
    border-right: 3px solid transparent;
    margin-right: -3px;
    white-space: nowrap;
    overflow: hidden;
    cursor: pointer;
    user-select: none;
}

.nav-item i,
.nav-group-toggle i.nav-icon {
    width: 16px;
    text-align: center;
    font-size: 13px;
    flex-shrink: 0;
}

.nav-item:hover,
.nav-group-toggle:hover {
    background: rgba(200,160,64,.1);
    color: var(--sb-gold-light);
}

.nav-item.active {
    background: rgba(200,160,64,.14);
    color: var(--sb-gold-light);
    border-right-color: var(--sb-gold);
}

/* ── COLLAPSIBLE GROUP ── */
.nav-group-toggle .toggle-label { flex: 1; }

.nav-group-toggle .chevron {
    font-size: 11px;
    color: rgba(255,255,255,.35);
    transition: transform .2s;
    margin-left: auto;
    flex-shrink: 0;
}

.nav-group-toggle.open {
    color: var(--sb-gold-light);
}

.nav-group-toggle.open .chevron {
    transform: rotate(180deg);
    color: var(--sb-gold-light);
}

/* Sub-items drawer */
.nav-sub {
    max-height: 0;
    overflow: hidden;
    transition: max-height .25s ease;
}

.nav-sub.open { max-height: 200px; }

.nav-sub-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 20px 8px 46px;
    color: rgba(255,255,255,.5);
    text-decoration: none;
    font-size: 12.5px;
    font-weight: 500;
    transition: all .15s;
    border-right: 3px solid transparent;
    margin-right: -3px;
    white-space: nowrap;
}

.nav-sub-item i {
    width: 15px;
    text-align: center;
    font-size: 12px;
    flex-shrink: 0;
}

.nav-sub-item:hover {
    background: rgba(200,160,64,.08);
    color: var(--sb-gold-light);
}

.nav-sub-item.active {
    background: rgba(200,160,64,.14);
    color: var(--sb-gold-light);
    border-right-color: var(--sb-gold);
}

/* ── BOTTOM USER + LOGOUT ── */
.sidebar-bottom {
    border-top: 1px solid rgba(255,255,255,.08);
    padding: 14px 20px;
    flex-shrink: 0;
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
    color: var(--sb-gold-light);
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

.logout-btn:hover {
    background: rgba(200,30,30,.18);
    color: #ff9999;
    border-color: rgba(200,30,30,.3);
}

/* ── RESPONSIVE ── */
@media (max-width: 900px) {
    .sidebar { transform: translateX(-100%); transition: transform .25s; }
    .sidebar.open { transform: none; }
}
</style>

<aside class="sidebar" id="sidebar">
    <!-- Brand -->
    <div class="sidebar-brand">
        <div class="brand-seal"><i class="fas fa-landmark"></i></div>
        <div class="brand-name">Barangay E-Records</div>
        <div class="brand-sub">Admin Portal</div>
    </div>

    <!-- Navigation -->
    <nav class="nav-section">

        <div class="nav-label">Main</div>
        <a class="nav-item <?= $active_page === 'dashboard' ? 'active' : '' ?>" href="dashboard.php">
            <i class="fas fa-gauge-high"></i> Dashboard
        </a>

        <div class="nav-label">Records</div>
        <a class="nav-item <?= $active_page === 'residents' ? 'active' : '' ?>" href="residents.php">
            <i class="fas fa-users"></i> Residents
        </a>

        <!-- Cases & Complaints collapsible -->
        <div class="nav-group-toggle <?= $cases_group ? 'open' : '' ?>" id="casesToggle">
            <i class="fas fa-gavel nav-icon"></i>
            <span class="toggle-label">Cases &amp; Complaints</span>
            <i class="fas fa-chevron-down chevron"></i>
        </div>
        <div class="nav-sub <?= $cases_group ? 'open' : '' ?>" id="casesSub">
            <a class="nav-sub-item <?= $active_page === 'cases'   ? 'active' : '' ?>" href="cases.php">
                <i class="fas fa-clipboard-list"></i> Cases
            </a>
            <a class="nav-sub-item <?= $active_page === 'blotter' ? 'active' : '' ?>" href="blotter.php">
                <i class="fas fa-book"></i> Blotter
            </a>
        </div>

        <a class="nav-item <?= $active_page === 'documents' ? 'active' : '' ?>" href="documents.php">
            <i class="fas fa-file-lines"></i> Documents
        </a>
        <a class="nav-item <?= $active_page === 'officials' ? 'active' : '' ?>" href="officials.php">
            <i class="fas fa-user-tie"></i> Officials
        </a>

        <div class="nav-label">Reports</div>
        <a class="nav-item <?= $active_page === 'reports' ? 'active' : '' ?>" href="reports.php">
            <i class="fas fa-chart-line"></i> Generate Reports
        </a>

    </nav>

    <!-- User + Logout -->
    <div class="sidebar-bottom">
        <div class="sidebar-user">
            <div class="user-avatar">
                <?= strtoupper(substr($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'A', 0, 1)) ?>
            </div>
            <div>
                <div class="user-name"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Admin') ?></div>
                <div class="user-role">Administrator</div>
            </div>
        </div>
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-arrow-right-from-bracket"></i> Sign Out
        </a>
    </div>
</aside>

<script>
(function () {
    var toggle = document.getElementById('casesToggle');
    var sub    = document.getElementById('casesSub');
    if (!toggle || !sub) return;
    toggle.addEventListener('click', function () {
        var open = sub.classList.contains('open');
        sub.classList.toggle('open', !open);
        toggle.classList.toggle('open', !open);
    });
})();
</script>