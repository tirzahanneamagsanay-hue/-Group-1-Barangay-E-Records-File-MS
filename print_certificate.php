<?php
// ============================================================
// print_certificate.php — Barangay Certificate Generator
// Generates print-ready certificates for:
//   - Barangay Clearance
//   - Certificate of Residency
//   - Certificate of Indigency
//
// Usage: print_certificate.php?id=<document_id>
//        print_certificate.php?id=<document_id>&preview=1  (no auto-print)
// ============================================================

require_once 'includes/auth.php';
requireLogin();
require_once 'includes/db.php';

$doc_id  = (int)($_GET['id'] ?? 0);
$preview = isset($_GET['preview']);   // preview=1 skips auto-print

if ($doc_id <= 0) {
    http_response_code(400);
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Invalid document ID.</p>');
}

$conn = getConnection();

// ── Load document + resident ──────────────────────────────────
$stmt = $conn->prepare("
    SELECT
        d.id,
        d.document_number,
        d.document_type,
        d.purpose,
        d.amount,
        d.status,
        d.requested_date,
        d.released_date,
        d.notes,
        r.first_name,
        r.last_name,
        r.gender,
        r.civil_status,
        r.birthdate,
        r.full_address,
        r.contact_number
    FROM documents d
    JOIN residents  r ON d.resident_id = r.id
    WHERE d.id = ?
    LIMIT 1
");
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    $conn->close();
    http_response_code(404);
    die('<p style="font-family:sans-serif;color:red;padding:2rem">Document not found.</p>');
}

// ── Load barangay settings ────────────────────────────────────
$settings = [];
$sres = $conn->query("SELECT setting_key, setting_value FROM system_settings");
if ($sres) {
    while ($row = $sres->fetch_assoc()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

// ── Load current Punong Barangay ──────────────────────────────
$captain_row = $conn->query("
    SELECT full_name, position
    FROM   officials
    WHERE  is_current = 1
      AND  (LOWER(position) LIKE '%punong%' OR LOWER(position) LIKE '%captain%' OR LOWER(position) LIKE '%chairman%')
    ORDER BY position_order ASC
    LIMIT 1
")->fetch_assoc();

$conn->close();

// ── Derived values ────────────────────────────────────────────
$brgy_name   = $settings['barangay_name'] ?? 'Barangay __________';
$city_name   = $settings['city']          ?? '__________';
$captain     = $captain_row['full_name']  ?? '__________________________';
$captain_pos = $captain_row['position']   ?? 'Punong Barangay';

$resident_name    = strtoupper($doc['first_name'] . ' ' . $doc['last_name']);
$resident_address = $doc['full_address'] ?? $brgy_name . ', ' . $city_name;
$doc_type         = $doc['document_type'];
$purpose          = $doc['purpose'] ?: 'General Purposes';
$date_issued      = date('F j, Y');
$doc_number       = $doc['document_number'];

// Age calculation
$age = '';
if (!empty($doc['birthdate'])) {
    $bd  = new DateTime($doc['birthdate']);
    $now = new DateTime();
    $age = $bd->diff($now)->y . ' years old';
}

$civil_status = ucfirst($doc['civil_status'] ?? '');
$gender       = ucfirst($doc['gender'] ?? '');

// Certificate-specific validity text
$validity = match (true) {
    str_contains($doc_type, 'Clearance')   => 'This certificate is valid for six (6) months from date of issue.',
    str_contains($doc_type, 'Residency')   => 'This certificate is valid for six (6) months from date of issue.',
    str_contains($doc_type, 'Indigency')   => 'This certificate is valid for three (3) months from date of issue.',
    default                                => 'This certificate is valid for six (6) months from date of issue.',
};

// Control number (short readable format)
$ctrl_no = strtoupper(substr($doc_number, -8));

// ── Certificate body text per type ───────────────────────────
function buildBody(array $d, string $name, string $addr, string $purpose,
                   string $age, string $civil, string $gender,
                   string $brgy, string $city): string
{
    $type = $d['document_type'];

    if (str_contains($type, 'Clearance')) {
        return "
        <p>This is to certify that <strong>{$name}</strong>, {$age},
        {$civil}, {$gender}, a bonafide resident of <strong>{$addr}</strong>,
        is personally known to this Office and that he/she has no pending
        criminal case or derogatory record filed against him/her in this Barangay.</p>

        <p>This certification is issued upon the request of the above-named person
        for <strong>{$purpose}</strong> and for whatever legal purpose it may serve.</p>";
    }

    if (str_contains($type, 'Residency')) {
        return "
        <p>This is to certify that <strong>{$name}</strong>, {$age},
        {$civil}, {$gender}, is a bonafide resident of
        <strong>{$addr}</strong> and has been residing in said address
        for more than six (6) months.</p>

        <p>This certification is issued upon the request of the above-named person
        for <strong>{$purpose}</strong> and for whatever legal purpose it may serve.</p>";
    }

    if (str_contains($type, 'Indigency')) {
        return "
        <p>This is to certify that <strong>{$name}</strong>, {$age},
        {$civil}, {$gender}, a bonafide resident of <strong>{$addr}</strong>,
        belongs to an indigent family in this Barangay and is not a recipient of
        any government assistance nor has the financial capacity to avail of
        the requested service on his/her own.</p>

        <p>This certification is issued upon the request of the above-named person
        for <strong>{$purpose}</strong> and for whatever legal purpose it may serve.</p>";
    }

    // Generic fallback
    return "
    <p>This is to certify that <strong>{$name}</strong>, {$age},
    {$civil}, {$gender}, a bonafide resident of <strong>{$addr}</strong>,
    has requested a <strong>{$type}</strong> from this Office.</p>

    <p>This certification is issued for <strong>{$purpose}</strong> and for
    whatever legal purpose it may serve.</p>";
}

$body_html = buildBody($doc, $resident_name, $resident_address, $purpose,
                       $age, $civil_status, $gender, $brgy_name, $city_name);

// ── Log the print action ──────────────────────────────────────
$conn2 = getConnection();
$uid   = (int)($_SESSION['user_id'] ?? 0);
$ip    = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$logst = $conn2->prepare(
    "INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
     VALUES (?, 'CERTIFICATE_PRINTED', ?, ?, NOW())"
);
$detail = "Printed {$doc_type}: {$doc_number}";
$logst->bind_param('iss', $uid, $detail, $ip);
$logst->execute();
$logst->close();
$conn2->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($doc_type) ?> — <?= htmlspecialchars($doc_number) ?></title>
<link href="https://fonts.googleapis.com/css2?family=IM+Fell+English:ital@0;1&family=Source+Sans+3:ital,wght@0,400;0,600;0,700;1,400&display=swap" rel="stylesheet">
<style>
/* ── Screen toolbar ──────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: 'Source Sans 3', sans-serif;
    background: #e8ecf5;
    min-height: 100vh;
}

/* Toolbar — hidden when printing */
.toolbar {
    background: #0a2a5e;
    border-bottom: 3px solid #c8a84b;
    padding: 0.75rem 2rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    position: sticky;
    top: 0;
    z-index: 50;
}

.toolbar-title {
    font-size: 14px;
    color: #f0d080;
    font-weight: 600;
}

.toolbar-title span {
    color: rgba(255,255,255,0.55);
    font-weight: 400;
    font-size: 12px;
    margin-left: 8px;
}

.toolbar-actions { display: flex; gap: 8px; }

.btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: background 0.15s;
}

.btn-print  { background: #c8a84b; color: #0a2a5e; }
.btn-print:hover { background: #f0d080; }
.btn-back   { background: rgba(255,255,255,0.1); color: #fff; border: 1px solid rgba(255,255,255,0.2); }
.btn-back:hover { background: rgba(255,255,255,0.18); }

/* Document wrapper */
.page-wrap {
    display: flex;
    justify-content: center;
    padding: 2rem;
}

/* ── The certificate itself ──────────────────────────────── */
.certificate {
    background: #fff;
    width: 215.9mm;   /* Letter width */
    min-height: 279.4mm; /* Letter height */
    padding: 18mm 20mm 20mm;
    position: relative;
    box-shadow: 0 8px 40px rgba(0,0,0,0.18);
    display: flex;
    flex-direction: column;
}

/* ── Decorative border system ── */
.cert-border-outer {
    position: absolute;
    inset: 8mm;
    border: 2.5pt solid #0a2a5e;
    pointer-events: none;
}

.cert-border-inner {
    position: absolute;
    inset: 10mm;
    border: 1pt solid #c8a84b;
    pointer-events: none;
}

/* Corner ornaments */
.cert-border-outer::before,
.cert-border-outer::after,
.cert-corner-bl,
.cert-corner-br {
    content: '';
    position: absolute;
    width: 14px;
    height: 14px;
    border-color: #c8a84b;
    border-style: solid;
}
.cert-border-outer::before { top: -3px; left: -3px; border-width: 3px 0 0 3px; }
.cert-border-outer::after  { top: -3px; right: -3px; border-width: 3px 3px 0 0; }
.cert-corner-bl { bottom: -3px; left: -3px; border-width: 0 0 3px 3px; }
.cert-corner-br { bottom: -3px; right: -3px; border-width: 0 3px 3px 0; }

/* ── Header ── */
.cert-header {
    text-align: center;
    margin-bottom: 6mm;
    padding-top: 4mm;
}

.republic-line {
    font-size: 9pt;
    letter-spacing: 0.08em;
    color: #4a5a7a;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.brgy-logos {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 16px;
    margin: 6px 0;
}

.seal-circle {
    width: 64px;
    height: 64px;
    border-radius: 50%;
    border: 2.5px solid #0a2a5e;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    background: #f7f9fc;
}

.seal-circle svg { width: 44px; height: 44px; }

.brgy-name-block { flex: 1; }

.brgy-label {
    font-size: 8pt;
    color: #7a8aaa;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

.brgy-name {
    font-family: 'IM Fell English', serif;
    font-size: 22pt;
    color: #0a2a5e;
    line-height: 1.1;
    font-weight: normal;
}

.city-name {
    font-size: 11pt;
    color: #2a4a6e;
    font-weight: 600;
    margin-top: 2px;
}

/* Divider */
.cert-divider {
    display: flex;
    align-items: center;
    gap: 8px;
    margin: 5mm 0 4mm;
}

.cert-divider-line { flex: 1; height: 2px; background: #0a2a5e; }
.cert-divider-diamond {
    width: 10px; height: 10px;
    background: #c8a84b;
    transform: rotate(45deg);
    flex-shrink: 0;
}

/* ── Certificate type banner ── */
.cert-type-banner {
    text-align: center;
    margin-bottom: 6mm;
}

.cert-type-label {
    font-size: 8pt;
    letter-spacing: 0.2em;
    color: #7a8aaa;
    text-transform: uppercase;
    margin-bottom: 2px;
}

.cert-type-title {
    font-family: 'IM Fell English', serif;
    font-size: 26pt;
    color: #0a2a5e;
    letter-spacing: 0.04em;
}

/* ── Body text ── */
.cert-body {
    flex: 1;
    font-size: 11.5pt;
    line-height: 1.85;
    color: #1a2a4a;
    text-align: justify;
    padding: 0 4mm;
}

.cert-body p {
    margin-bottom: 4mm;
    text-indent: 12mm;
}

.cert-body strong { font-weight: 700; }

/* ── OR Number / Amount box ── */
.cert-or-box {
    display: flex;
    gap: 10mm;
    margin: 4mm 4mm 6mm;
    padding: 3mm 5mm;
    border: 1px solid #dde3f0;
    border-radius: 6px;
    background: #f7f9fc;
    font-size: 9pt;
    color: #4a5a7a;
}

.cert-or-box .or-item { display: flex; flex-direction: column; gap: 2px; }
.cert-or-box .or-label { font-size: 8pt; text-transform: uppercase; letter-spacing: 0.06em; color: #8a98b4; }
.cert-or-box .or-val { font-weight: 700; color: #0a2a5e; font-size: 10pt; }

/* ── Validity note ── */
.cert-validity {
    text-align: center;
    font-size: 9pt;
    color: #7a8aaa;
    font-style: italic;
    margin: 2mm 0 6mm;
}

/* ── Signature block ── */
.cert-sig-area {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 6mm;
    padding: 0 4mm;
    margin-top: auto;
}

.sig-block { text-align: center; }

.sig-line {
    border-bottom: 1.5px solid #1a2a4a;
    margin: 0 auto 3px;
    width: 80%;
    min-height: 36px;
}

.sig-name {
    font-weight: 700;
    font-size: 11pt;
    color: #0a2a5e;
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.sig-title {
    font-size: 9pt;
    color: #7a8aaa;
    margin-top: 2px;
}

/* ── Thumbmark box ── */
.thumbmark-box {
    border: 1px solid #c8a84b;
    border-radius: 4px;
    width: 50mm;
    height: 30mm;
    margin: 0 auto;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-direction: column;
    color: #c8a84b;
    font-size: 8pt;
    gap: 3px;
    letter-spacing: 0.04em;
}

.thumbmark-box svg { width: 22px; height: 22px; opacity: 0.5; }

/* ── Footer strip ── */
.cert-footer {
    margin-top: 6mm;
    padding-top: 3mm;
    border-top: 1px solid #c8a84b;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    font-size: 8pt;
    color: #8a98b4;
}

.cert-footer .ctrl { font-family: monospace; font-size: 9pt; color: #0a2a5e; font-weight: 600; }

/* ── PRINT STYLES ──────────────────────────────────────── */
@media print {
    @page {
        size: Letter portrait;
        margin: 0;
    }

    html, body {
        background: white !important;
        margin: 0;
        padding: 0;
        -webkit-print-color-adjust: exact;
        print-color-adjust: exact;
    }

    .toolbar  { display: none !important; }
    .page-wrap { padding: 0; }

    .certificate {
        box-shadow: none;
        width: 100%;
        min-height: 100vh;
        padding: 18mm 20mm 20mm;
    }
}
</style>
</head>
<body>

<?php if (!$preview): ?>
<!-- Toolbar (hidden on print) -->
<div class="toolbar">
    <div class="toolbar-title">
        <?= htmlspecialchars($doc_type) ?>
        <span><?= htmlspecialchars($doc_number) ?></span>
    </div>
    <div class="toolbar-actions">
        <a href="documents.php" class="btn btn-back">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M19 12H5M12 5l-7 7 7 7"/></svg>
            Back
        </a>
        <button class="btn btn-print" onclick="window.print()">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Certificate
        </button>
    </div>
</div>
<?php endif; ?>

<div class="page-wrap">
<div class="certificate">

    <!-- Decorative borders -->
    <div class="cert-border-outer">
        <div class="cert-corner-bl"></div>
        <div class="cert-corner-br"></div>
    </div>
    <div class="cert-border-inner"></div>

    <!-- ── HEADER ── -->
    <div class="cert-header">
        <div class="republic-line">Republic of the Philippines</div>

        <div class="brgy-logos">
            <!-- Philippine seal SVG (simplified) -->
            <div class="seal-circle" title="Republic of the Philippines">
                <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="46" fill="none" stroke="#0a2a5e" stroke-width="3"/>
                    <circle cx="50" cy="50" r="38" fill="none" stroke="#c8a84b" stroke-width="1.5"/>
                    <!-- Sun rays -->
                    <?php for ($i = 0; $i < 8; $i++): ?>
                    <?php $angle = $i * 45 - 90; $r1 = 20; $r2 = 32; ?>
                    <line x1="<?= 50 + $r1 * cos(deg2rad($angle)) ?>" y1="<?= 50 + $r1 * sin(deg2rad($angle)) ?>"
                          x2="<?= 50 + $r2 * cos(deg2rad($angle)) ?>" y2="<?= 50 + $r2 * sin(deg2rad($angle)) ?>"
                          stroke="#c8a84b" stroke-width="2.5" stroke-linecap="round"/>
                    <?php endfor; ?>
                    <circle cx="50" cy="50" r="12" fill="#0a2a5e"/>
                    <circle cx="50" cy="50" r="7" fill="#c8a84b"/>
                    <!-- Three stars -->
                    <?php
                    $stars = [[50, 18], [30, 68], [70, 68]];
                    foreach ($stars as [$sx, $sy]):
                    ?>
                    <polygon points="<?= "$sx," . ($sy-5) . " " . ($sx+2) . "," . ($sy-1) . " " . ($sx+5) . "," . ($sy-1) . " " . ($sx+3) . "," . ($sy+2) . " " . ($sx+4) . "," . ($sy+5) . " $sx," . ($sy+3) . " " . ($sx-4) . "," . ($sy+5) . " " . ($sx-3) . "," . ($sy+2) . " " . ($sx-5) . "," . ($sy-1) . " " . ($sx-2) . "," . ($sy-1) ?>"
                             fill="#f0d080"/>
                    <?php endforeach; ?>
                </svg>
            </div>

            <!-- Barangay name block -->
            <div class="brgy-name-block">
                <div class="brgy-label">Barangay Government of</div>
                <div class="brgy-name"><?= htmlspecialchars($brgy_name) ?></div>
                <div class="city-name"><?= htmlspecialchars($city_name) ?></div>
            </div>

            <!-- Barangay seal placeholder -->
            <div class="seal-circle" title="Barangay Official Seal">
                <svg viewBox="0 0 100 100" xmlns="http://www.w3.org/2000/svg">
                    <circle cx="50" cy="50" r="46" fill="none" stroke="#c8a84b" stroke-width="3"/>
                    <circle cx="50" cy="50" r="38" fill="none" stroke="#0a2a5e" stroke-width="1.5"/>
                    <text x="50" y="44" text-anchor="middle" font-size="9" fill="#0a2a5e" font-weight="bold" letter-spacing="1">BARANGAY</text>
                    <text x="50" y="56" text-anchor="middle" font-size="8" fill="#0a2a5e">OFFICIAL</text>
                    <text x="50" y="67" text-anchor="middle" font-size="8" fill="#0a2a5e">SEAL</text>
                    <circle cx="50" cy="50" r="46" fill="none" stroke="#c8a84b" stroke-width="1" stroke-dasharray="4,3"/>
                </svg>
            </div>
        </div>
    </div>

    <!-- ── DIVIDER ── -->
    <div class="cert-divider">
        <div class="cert-divider-line"></div>
        <div class="cert-divider-diamond"></div>
        <div class="cert-divider-line"></div>
    </div>

    <!-- ── CERTIFICATE TYPE BANNER ── -->
    <div class="cert-type-banner">
        <div class="cert-type-label">Office of the Punong Barangay</div>
        <div class="cert-type-title">
            <?php
            // Render type title neatly
            echo match(true) {
                str_contains($doc_type, 'Clearance')  => 'Barangay Clearance',
                str_contains($doc_type, 'Residency')  => 'Certificate of Residency',
                str_contains($doc_type, 'Indigency')  => 'Certificate of Indigency',
                default                               => htmlspecialchars($doc_type),
            };
            ?>
        </div>
    </div>

    <!-- ── DIVIDER ── -->
    <div class="cert-divider">
        <div class="cert-divider-line"></div>
        <div class="cert-divider-diamond"></div>
        <div class="cert-divider-line"></div>
    </div>

    <!-- ── BODY ── -->
    <div class="cert-body">
        <p style="text-indent:0; font-weight:600; color:#4a5a7a; font-size:10pt; letter-spacing:0.04em; text-transform:uppercase; margin-bottom: 5mm;">
            To Whom It May Concern:
        </p>

        <?= $body_html ?>

        <p>In witness whereof, I have hereunto set my hand and affixed the official seal
        of this Barangay on this <strong><?= date('jS \d\a\y \o\f F, Y') ?></strong>
        at <?= htmlspecialchars($brgy_name . ', ' . $city_name) ?>.</p>
    </div>

    <!-- ── OR / AMOUNT BOX ── -->
    <div class="cert-or-box">
        <div class="or-item">
            <div class="or-label">Document No.</div>
            <div class="or-val"><?= htmlspecialchars($doc_number) ?></div>
        </div>
        <div class="or-item">
            <div class="or-label">Date Issued</div>
            <div class="or-val"><?= $date_issued ?></div>
        </div>
        <div class="or-item">
            <div class="or-label">Fee Paid</div>
            <div class="or-val">₱<?= number_format((float)($doc['amount'] ?? 0), 2) ?></div>
        </div>
        <div class="or-item">
            <div class="or-label">O.R. No.</div>
            <div class="or-val">________________</div>
        </div>
        <div class="or-item">
            <div class="or-label">CTC No.</div>
            <div class="or-val">________________</div>
        </div>
    </div>

    <!-- ── VALIDITY ── -->
    <div class="cert-validity"><?= $validity ?></div>

    <!-- ── SIGNATURE AREA ── -->
    <div class="cert-sig-area">

        <!-- Applicant thumbmark -->
        <div class="sig-block">
            <div class="thumbmark-box">
                <svg viewBox="0 0 24 24" fill="none" stroke="#c8a84b" stroke-width="1.5" xmlns="http://www.w3.org/2000/svg">
                    <path d="M12 2C9.24 2 7 4.24 7 7c0 1.93.99 3.63 2.49 4.62C6.91 12.59 5 15.12 5 18h14c0-2.88-1.91-5.41-4.49-6.38C15.01 10.63 16 8.93 16 7c0-2.76-2.24-5-4-5z"/>
                </svg>
                <span>Right Thumbmark</span>
            </div>
            <div style="margin-top: 6px; font-size: 9pt; color: #7a8aaa;">
                <?= htmlspecialchars($resident_name) ?>
            </div>
            <div style="font-size: 8pt; color: #a8b4cc; margin-top: 2px;">Applicant's Signature over Printed Name</div>
        </div>

        <!-- Punong Barangay signature -->
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-name"><?= htmlspecialchars($captain) ?></div>
            <div class="sig-title"><?= htmlspecialchars($captain_pos) ?></div>
        </div>

    </div>

    <!-- ── FOOTER ── -->
    <div class="cert-footer">
        <span>
            <?= htmlspecialchars($brgy_name) ?> &bull; <?= htmlspecialchars($city_name) ?>
        </span>
        <span class="ctrl">Ctrl No. <?= htmlspecialchars($ctrl_no) ?></span>
        <span>Not valid without official dry seal</span>
    </div>

</div><!-- /certificate -->
</div><!-- /page-wrap -->

<?php if (!$preview): ?>
<script>
    // Auto-trigger print dialog after fonts load
    if (document.fonts) {
        document.fonts.ready.then(() => setTimeout(() => window.print(), 400));
    } else {
        setTimeout(() => window.print(), 800);
    }
</script>
<?php endif; ?>
</body>
</html>