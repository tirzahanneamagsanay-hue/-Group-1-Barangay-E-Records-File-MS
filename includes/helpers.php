<?php
// ============================================================
// includes/helpers.php — Shared utility functions
// require_once this ONCE per page, after auth.php and db.php
// Replaces the duplicate logActivity / timeAgo / nullIfEmpty /
// getBadgeClass functions that previously lived in every file.
// ============================================================

// ── CSRF Protection ────────────────────────────────────────

/**
 * Returns (and lazily creates) the CSRF token for this session.
 * Call csrfToken() inside the <head> to emit the meta tag:
 *   <meta name="csrf-token" content="<?= csrfToken() ?>">
 */
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Call this at the TOP of every POST handler block.
 * Accepts the token either in $_POST['csrf_token'] (forms)
 * or in the X-CSRF-Token request header (fetch / XHR).
 * Exits with a 403 JSON error if the token is missing or wrong.
 */
function verifyCsrf(): void {
    $token = trim(
        $_POST['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? ''
    );
    if (!hash_equals(csrfToken(), $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'ok'  => false,
            'msg' => 'Security token mismatch. Please refresh the page and try again.',
        ]);
        exit;
    }
}

// ── Activity Logging ───────────────────────────────────────

/**
 * Writes one row to activity_logs.
 * Safe to call anywhere you have a live $conn.
 */
function logActivity(mysqli $conn, string $action, string $details): void {
    $user_id = (int) ($_SESSION['user_id'] ?? 0);
    $ip      = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $stmt    = $conn->prepare(
        "INSERT INTO activity_logs (user_id, action, details, ip_address, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    );
    if ($stmt) {
        $stmt->bind_param('isss', $user_id, $action, $details, $ip);
        $stmt->execute();
        $stmt->close();
    }
}

// ── Login Rate Limiting ────────────────────────────────────

/**
 * Returns TRUE when the given IP has hit 5+ failed logins
 * in the last 15 minutes (uses the existing audit_log table).
 */
function isLoginBlocked(mysqli $conn, string $ip): bool {
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $limit  = 5;
    $stmt   = $conn->prepare(
        "SELECT COUNT(*) FROM audit_log
         WHERE action     = 'LOGIN_FAILED'
           AND ip_address = ?
           AND created_at >= ?"
    );
    if (!$stmt) return false;
    $stmt->bind_param('ss', $ip, $window);
    $stmt->execute();
    $stmt->bind_result($count);
    $stmt->fetch();
    $stmt->close();
    return (int) $count >= $limit;
}

/**
 * Returns how many minutes remain in the lockout window,
 * so you can show "Try again in X minutes" to the user.
 */
function lockoutMinutesRemaining(mysqli $conn, string $ip): int {
    $window = date('Y-m-d H:i:s', strtotime('-15 minutes'));
    $stmt   = $conn->prepare(
        "SELECT MIN(created_at) FROM audit_log
         WHERE action     = 'LOGIN_FAILED'
           AND ip_address = ?
           AND created_at >= ?
         LIMIT 5"            // oldest of the 5 that triggered the lock
    );
    if (!$stmt) return 15;
    $stmt->bind_param('ss', $ip, $window);
    $stmt->execute();
    $stmt->bind_result($oldest);
    $stmt->fetch();
    $stmt->close();
    if (!$oldest) return 15;
    $unlockAt = strtotime($oldest) + (15 * 60);
    return max(1, (int) ceil(($unlockAt - time()) / 60));
}

// ── Time Formatting ────────────────────────────────────────

function timeAgo(?string $dt): string {
    if (!$dt) return 'N/A';
    $diff = time() - strtotime($dt);
    if ($diff < 60)     return 'Just now';
    if ($diff < 3600)   return round($diff / 60)   . ' min ago';
    if ($diff < 86400)  return round($diff / 3600)  . ' hrs ago';
    if ($diff < 604800) return round($diff / 86400) . ' days ago';
    return date('M j, Y', strtotime($dt));
}

// ── Null Coercion ──────────────────────────────────────────

function nullIfEmpty(?string $val): ?string {
    $v = trim($val ?? '');
    return $v !== '' ? $v : null;
}

// ── Status Badge CSS Class ─────────────────────────────────

function getBadgeClass(string $status): string {
    return match (strtolower(str_replace('_', ' ', $status))) {
        'pending'             => 'badge-pending',
        'resolved'            => 'badge-resolved',
        'dismissed'           => 'badge-dismissed',
        'under investigation' => 'badge-under',
        'closed'              => 'badge-dismissed',
        'reported'            => 'badge-pending',
        'approved'            => 'badge-resolved',
        'released'            => 'badge-resolved',
        'cancelled'           => 'badge-dismissed',
        'referred to police'  => 'badge-other',
        default               => 'badge-other',
    };
}

// ── JS CSRF Snippet ────────────────────────────────────────

/**
 * Echo this once inside a <script> block, right before your
 * apiRequest() function.  The function then auto-attaches the
 * token to every FormData POST so you never forget it.
 *
 * Usage in any page:
 *   <script>
 *     <?= csrfJs() ?>
 *     async function apiRequest(action, formData) {
 *         formData.append('action', action);
 *         formData.append('csrf_token', CSRF_TOKEN);   // ← comes from csrfJs()
 *         const res = await fetch(window.location.href, { method: 'POST', body: formData });
 *         return res.json();
 *     }
 *   </script>
 */
function csrfJs(): string {
    $token = htmlspecialchars(csrfToken(), ENT_QUOTES);
    return "const CSRF_TOKEN = '{$token}';\n";
}