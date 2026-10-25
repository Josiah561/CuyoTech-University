<?php
declare(strict_types=1);
require __DIR__ . '/config.php';

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
ini_set('session.use_strict_mode', '1');
session_start();

/* 30-minute idle session timeout */
const IDLE_SESSION_TIMEOUT = 1800; // 30 minutes in seconds
if (!empty($_SESSION['uid'])) {
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > IDLE_SESSION_TIMEOUT)) {
        unset($_SESSION['uid'], $_SESSION['last_activity']);
        session_destroy();
        session_start();
        flash('Your session has timed out due to 30 minutes of inactivity. Please sign in again.', true);
        redirect('login.php');
    }
    $_SESSION['last_activity'] = time();
}

try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
} catch (PDOException $ex) {
    http_response_code(500);
    exit('Database connection failed. Check includes/config.php and that sql/database.sql is imported.');
}

/* ---- small helpers ---- */
function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function q(string $sql, array $p = []): PDOStatement { global $pdo; $st = $pdo->prepare($sql); $st->execute($p); return $st; }
function rows(string $sql, array $p = []): array { return q($sql, $p)->fetchAll(); }
function row(string $sql, array $p = []): ?array { $r = q($sql, $p)->fetch(); return $r ?: null; }
function url(string $p = ''): string { return APP_BASE . '/' . ltrim($p, '/'); }
function redirect(string $p): never { header('Location: ' . url($p)); exit; }
function money($n): string { return '₱' . number_format((float)$n, 2); }
function receipt_no(int $id): string { return 'OR-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT); }
function amount_to_words(float $num): string {
    $ones = [
        1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five',
        6 => 'Six', 7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten',
        11 => 'Eleven', 12 => 'Twelve', 13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen',
        16 => 'Sixteen', 17 => 'Seventeen', 18 => 'Eighteen', 19 => 'Nineteen'
    ];
    $tens = [
        2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
        6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety'
    ];
    $pesos = (int)floor($num);
    $cents = (int)round(($num - $pesos) * 100);
    $conv = function(int $n) use (&$conv, $ones, $tens): string {
        if ($n === 0) return '';
        if ($n < 20) return $ones[$n];
        if ($n < 100) return $tens[(int)($n / 10)] . ($n % 10 ? '-' . $ones[$n % 10] : '');
        if ($n < 1000) return $ones[(int)($n / 100)] . ' Hundred' . ($n % 100 ? ' ' . $conv($n % 100) : '');
        if ($n < 1000000) return $conv((int)($n / 1000)) . ' Thousand' . ($n % 1000 ? ' ' . $conv($n % 1000) : '');
        return (string)$n;
    };
    $out = $pesos > 0 ? $conv($pesos) . ' Pesos' : 'Zero Pesos';
    if ($cents > 0) $out .= ' and ' . $conv($cents) . ' Centavos';
    else $out .= ' Only';
    return $out;
}
function mask(string $v, string $field): string {
    return $field === 'email' ? preg_replace('/^(.).*(@.*)$/', '$1•••$2', $v) : substr($v, 0, 4) . '•••' . substr($v, -2);
}
function first_char(string $s): string { return preg_match('/^./us', $s, $m) ? strtoupper($m[0]) : ''; }
function utf8_cut(string $s, int $n): string { return preg_match('/^.{0,' . $n . '}/us', $s, $m) ? $m[0] : ''; }

/* ---- program / section / curriculum helpers ---- */
/** Year level from a section name: "BSN 1A" -> 1, "BSIT 3B" -> 3, "TBA" -> null. */
function section_year(?string $section): ?int {
    return ($section !== null && preg_match('/(\d)[A-Za-z]*$/', trim($section), $m)) ? (int)$m[1] : null;
}
function year_label(?string $section): string {
    $y = section_year($section);
    if ($y === null) return 'Year level TBA';
    $suffix = [1 => 'st', 2 => 'nd', 3 => 'rd'][$y] ?? 'th';
    return $y . $suffix . ' Year';
}
/** Subjects (with this student's prelim, midterm and final grades) loaded from enrolled_subject for the term, falling back to curriculum lookup. */
function student_load(int $studentId, string $program = '', ?string $section = null, string $schoolYear = '2026-2027', string $semester = '1st Sem'): array {
    // 1. Check real enrolled_subject records for the student
    $enrolled = rows('SELECT sub.*, g.prelim, g.midterm, g.final_grade, g.is_published
                      FROM enrolled_subject es
                      JOIN subject sub ON sub.subject_id = es.subject_id
                      LEFT JOIN grade g ON g.subject_id = es.subject_id 
                                        AND g.student_id = es.student_id 
                                        AND g.school_year = es.school_year 
                                        AND (g.semester = es.semester OR (g.semester LIKE "1st%" AND es.semester LIKE "1st%"))
                      WHERE es.student_id = ? 
                        AND es.school_year = ? 
                        AND (es.semester = ? OR (es.semester LIKE "1st%" AND ? LIKE "1st%"))
                      ORDER BY sub.year_level, sub.subject_id', [$studentId, $schoolYear, $semester, $semester]);
    if (!empty($enrolled)) {
        return $enrolled;
    }

    // 2. Fallback to curriculum lookup by program & year level if not yet enrolled
    $y = section_year($section);
    $sql = 'SELECT sub.*, g.prelim, g.midterm, g.final_grade, g.is_published FROM subject sub
            LEFT JOIN grade g ON g.subject_id = sub.subject_id 
                              AND g.student_id = ? 
                              AND g.school_year = ? 
                              AND (g.semester = ? OR (g.semester LIKE "1st%" AND ? LIKE "1st%"))
            WHERE sub.program = ?' . ($y !== null ? ' AND sub.year_level = ?' : '') . '
            ORDER BY sub.year_level, sub.subject_id';
    $params = $y !== null 
        ? [$studentId, $schoolYear, $semester, $semester, $program, $y] 
        : [$studentId, $schoolYear, $semester, $semester, $program];
    return rows($sql, $params);
}
/* ---- Grades ---- */
const GRADE_OPTIONS = ['1.00','1.25','1.50','1.75','2.00','2.25','2.50','2.75','3.00','5.00'];
/** Posted grade value -> float, or null when empty / not an allowed grade. */
function grade_value($v): ?float {
    $v = trim((string)$v);
    return in_array($v, GRADE_OPTIONS, true) ? (float)$v : null;
}
/** The grade that currently counts for a subject row: Final if encoded, otherwise Midterm, otherwise Prelim. */
function current_grade(array $r): ?float {
    $v = $r['final_grade'] ?? $r['midterm'] ?? $r['prelim'] ?? null;
    return $v === null ? null : (float)$v;
}
/** Grade dropdown used by the Registrar (on screen only; print shows the value). */
function grade_select(string $name, ?string $current): string {
    $h = '<select class="no-print" name="' . e($name) . '" style="border-radius:9999px;padding:5px 14px;border:1px solid #cbd5e1;font-size:13px;color:#2563eb;font-weight:600;background:#ffffff">';
    $h .= '<option value="">' . ($current !== null && $current !== '' ? '— Unchanged (' . e($current) . ') —' : '— Select —') . '</option>';
    $h .= '<option value="__CLEAR__">— Not encoded (Clear) —</option>';
    foreach (GRADE_OPTIONS as $o) {
        $h .= '<option value="' . $o . '"' . ($current === $o ? ' selected' : '') . '>' . $o . '</option>';
    }
    return $h . '</select>';
}

/**
 * Update a student's grade safely:
 * - Logs to grade_history if changed
 * - Blank/invalid does not clear existing grade; only '__CLEAR__' sets to null
 * - Sets is_published
 */
function record_grade_change(int $studentId, int $subjectId, string $schoolYear, string $semester, array $periods, int $changedBy, ?bool $isPublished = null): void {
    $curr = row("SELECT * FROM grade WHERE student_id=? AND subject_id=? AND school_year=? AND (semester=? OR semester LIKE '1st%')", 
                [$studentId, $subjectId, $schoolYear, $semester]);
    $gradeId = $curr ? (int)$curr['grade_id'] : null;
    $updated = [];

    foreach (['prelim', 'midterm', 'final_grade'] as $period) {
        $oldVal = $curr ? ($curr[$period] !== null ? (float)$curr[$period] : null) : null;
        if (!array_key_exists($period, $periods)) {
            $updated[$period] = $oldVal;
            continue;
        }

        $raw = trim((string)$periods[$period]);
        if ($raw === '__CLEAR__') {
            $newVal = null;
        } elseif (in_array($raw, GRADE_OPTIONS, true)) {
            $newVal = (float)$raw;
        } else {
            // Empty string or unselected -> KEEP existing grade!
            $newVal = $oldVal;
        }

        $updated[$period] = $newVal;

        if ($oldVal !== $newVal) {
            q("INSERT INTO grade_history (grade_id, student_id, subject_id, school_year, semester, period, old_value, new_value, changed_by, changed_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())",
              [$gradeId, $studentId, $subjectId, $schoolYear, $semester, $period, $oldVal, $newVal, $changedBy]);
        }
    }

    $pubVal = $isPublished !== null ? ($isPublished ? 1 : 0) : ($curr ? (int)$curr['is_published'] : 0);

    if ($curr) {
        q("UPDATE grade SET prelim=?, midterm=?, final_grade=?, is_published=? WHERE grade_id=?",
          [$updated['prelim'], $updated['midterm'], $updated['final_grade'], $pubVal, $gradeId]);
    } else {
        q("INSERT INTO grade (student_id, subject_id, school_year, semester, prelim, midterm, final_grade, is_published)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
          [$studentId, $subjectId, $schoolYear, $semester, $updated['prelim'], $updated['midterm'], $updated['final_grade'], $pubVal]);
    }
}
/** Name of the active department head for the college that offers this program. */
function program_head(string $program): string {
    foreach (COLLEGE_PROGRAMS as $code => $list) {
        if (in_array($program, $list, true)) {
            $r = row("SELECT u.full_name FROM user_account u JOIN department d ON d.department_id=u.department_id
                      WHERE u.role='depthead' AND u.status='Active' AND d.department_code=? ORDER BY u.user_id LIMIT 1", [$code]);
            if ($r && $r['full_name']) return $r['full_name'];
        }
    }
    return 'Department Head';
}

function ensure_student_clearance(int $studentId): void {
    foreach (OFFICES as $off) {
        q("INSERT IGNORE INTO clearance (student_id, office, status) VALUES (?, ?, 'Pending')", [$studentId, $off]);
    }
}
function is_student_fully_cleared(int $studentId): bool {
    ensure_student_clearance($studentId);
    $pending = (int)row("SELECT COUNT(*) c FROM clearance WHERE student_id=? AND status != 'Cleared'", [$studentId])['c'];
    return $pending === 0;
}

/* ---- CSRF + flash ---- */
function csrf_field(): string {
    $_SESSION['csrf'] ??= bin2hex(random_bytes(16));
    return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">';
}
function csrf_check(): void {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Invalid form token. Go back, refresh the page and try again.');
    }
}
function flash(string $msg, bool $bad = false): void { $_SESSION['flash'] = [$msg, $bad]; }

/* ---- auth ---- */
function user(bool $refresh = false): ?array {
    static $u = false;
    if ($refresh) $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $u = row("SELECT u.user_id,u.username,u.role,u.status,u.department_id,u.must_change_password,
                        COALESCE(CONCAT(s.first_name,' ',s.last_name),u.full_name) AS name,s.student_number,
                        s.first_name, s.last_name, s.email
                      FROM user_account u LEFT JOIN student s ON s.user_id=u.user_id WHERE u.user_id=?", [$_SESSION['uid']]);
            if ($u && $u['status'] !== 'Active') { $u = null; unset($_SESSION['uid']); }
        }
    }
    return $u;
}
function home_for(string $role): string {
    return ['student' => 'student/index.php', 'registrar' => 'registrar/index.php', 'cashier' => 'cashier/index.php',
            'depthead' => 'dept/index.php', 'admin' => 'admin/index.php'][$role];
}
function require_role(string ...$roles): array {
    $u = user();
    if (!$u) redirect('login.php');
    if (!empty($u['must_change_password'])) {
        flash('You must establish a new password before accessing your account.', true);
        redirect('change_password.php');
    }
    if (!in_array($u['role'], $roles, true)) { http_response_code(403); exit('You do not have access to this page.'); }
    return $u;
}
function audit(string $action, ?string $who = null): void {
    $u = user();
    q('INSERT INTO audit_log(user_id,username,action) VALUES(?,?,?)', [$u['user_id'] ?? null, $who ?? ($u['username'] ?? '(not signed in)'), $action]);
}
function pick_tab(array $tabs): string { $t = $_GET['tab'] ?? ''; return isset($tabs[$t]) ? $t : array_key_first($tabs); }

function notify(int $userId, string $title, string $message, ?string $link = null): void {
    if ($userId <= 0) return;
    q("INSERT INTO notification (user_id, title, message, link) VALUES (?, ?, ?, ?)", [$userId, $title, $message, $link]);
}

function notify_student(int $studentId, string $title, string $message, ?string $link = null): void {
    $stu = row("SELECT user_id FROM student WHERE student_id = ?", [$studentId]);
    if ($stu && !empty($stu['user_id'])) {
        notify((int)$stu['user_id'], $title, $message, $link);
    }
}

require __DIR__ . '/layout.php';