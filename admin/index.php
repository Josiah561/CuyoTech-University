<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('admin');

// --- Helpers: keep the admin's inputs when a form fails, and auto-assign IDs ---
function adm_fail(string $scope, string $msg, string $to, string $field = ''): never {
    $d = $_POST;
    unset($d['csrf'], $d['admin_confirm'], $d['confirm'], $d['new_user_password'], $d['new_password']);
    $_SESSION['adm_old'] = ['scope' => $scope, 'data' => $d, 'error' => $msg, 'field' => $field];
    redirect($to);
}
function next_student_number(): string {
    $y = date('Y');
    $r = row("SELECT MAX(CAST(SUBSTRING(student_number, 6) AS UNSIGNED)) m FROM student WHERE student_number LIKE ?", [$y . '-%']);
    return sprintf('%s-%05d', $y, (int)($r['m'] ?? 0) + 1);
}
const STAFF_PREFIX = ['registrar' => 'REG', 'cashier' => 'CSH', 'depthead' => 'DEP', 'admin' => 'ADM'];
function next_staff_id(string $role): string {
    $pre = STAFF_PREFIX[$role] ?? 'USR';
    $r = row("SELECT MAX(CAST(SUBSTRING(username, 5) AS UNSIGNED)) m FROM user_account WHERE username REGEXP ?", ['^' . $pre . '-[0-9]+$']);
    return sprintf('%s-%03d', $pre, (int)($r['m'] ?? 0) + 1);
}
function valid_college_program(int $deptId, string $program): ?array {
    $c = row('SELECT department_id, department_code FROM department WHERE department_id=?', [$deptId]);
    return ($c && in_array($program, COLLEGE_PROGRAMS[$c['department_code']] ?? [], true)) ? $c : null;
}

/** All sections grouped by degree program: [program => [['id'=>1,'name'=>'BSN 1A','year'=>1], ...]] */
function sections_by_program(): array {
    $out = [];
    foreach (rows('SELECT section_id, section_name, program, year_level FROM section ORDER BY year_level, section_name') as $r) {
        $out[$r['program']][] = ['id' => (int)$r['section_id'], 'name' => $r['section_name'], 'year' => (int)$r['year_level']];
    }
    return $out;
}
/** A section the admin picked must exist and belong to the chosen degree program. */
function valid_section(string $program, string $section): bool {
    return $section !== '' && (bool)row('SELECT 1 FROM section WHERE section_name=? AND program=?', [$section, $program]);
}
function section_json(bool $ok, string $msg, ?int $selectId = null): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $ok, 'msg' => $msg, 'selected' => $selectId, 'sections' => (object)sections_by_program()],
                     JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}

/** "3 min ago" style text from an age in seconds (computed by the database so time zones cannot skew it). */
function ago_text(int $sec): string {
    if ($sec < 60)    return 'just now';
    if ($sec < 3600)  return intdiv($sec, 60) . ' min ago';
    if ($sec < 86400) return intdiv($sec, 3600) . ' hr ago';
    if ($sec < 86400 * 30) return intdiv($sec, 86400) . ' day' . (intdiv($sec, 86400) > 1 ? 's' : '') . ' ago';
    return '';
}

/** Account activity block for the Manage account panel (also served alone for live refresh). */
function activity_panel(array $acct): string {
    $uid = (int)$acct['user_id'];
    $un  = (string)$acct['username'];
    $lk  = addcslashes($un, '%_\\');
    // Events the account itself performed (matched by id or by the ID typed at sign-in)
    $self = '(user_id = ? OR username = ?)';
    // Events someone else performed on this account, e.g. "Edited account reg01"
    $onIt = "(action LIKE CONCAT('% ', ?) OR action LIKE CONCAT('% ', ?, ' %') OR action LIKE CONCAT('% ', ?, ' (%'))";

    $recent = rows("SELECT action, username, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age, created_at
                    FROM audit_log WHERE $self OR $onIt ORDER BY log_id DESC LIMIT 6",
                   [$uid, $un, $lk, $lk, $lk]);
    $last = row("SELECT created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age FROM audit_log
                 WHERE $self AND action LIKE 'Signed in%' ORDER BY log_id DESC LIMIT 1", [$uid, $un]);
    $signIns = (int)row("SELECT COUNT(*) c FROM audit_log WHERE $self AND action LIKE 'Signed in%' AND created_at > NOW() - INTERVAL 30 DAY", [$uid, $un])['c'];
    $failed  = (int)row("SELECT COUNT(*) c FROM audit_log WHERE username = ? AND (action LIKE 'Failed authentication%' OR action LIKE 'Failed log in%') AND created_at > NOW() - INTERVAL 1 DAY", [$un])['c'];

    ob_start(); ?>
      <div style="margin-top:18px;padding-top:16px;border-top:1px solid #f1f5f9">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px">
          <div style="font-size:11px;color:#94a3b8;text-transform:uppercase;letter-spacing:0.06em;font-weight:600">Account activity</div>
          <div style="font-size:11px;color:#94a3b8"><span style="display:inline-block;width:6px;height:6px;border-radius:50%;background:#22c55e;margin-right:5px"></span>Live · updated <?= e(date('g:i:s A')) ?></div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px">
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#64748b">Last sign-in</div>
            <?php if ($last): ?>
              <div style="font-size:12px;font-weight:700;color:#0f172a;margin-top:2px"><?= e(date('d M Y', strtotime($last['created_at']))) ?><br><span style="font-weight:500;color:#64748b"><?= e(date('g:i A', strtotime($last['created_at']))) ?><?= ago_text((int)$last['age']) !== '' ? ' · ' . e(ago_text((int)$last['age'])) : '' ?></span></div>
            <?php else: ?>
              <div style="font-size:12px;font-weight:700;color:#0f172a;margin-top:2px">Never</div>
            <?php endif; ?>
          </div>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#64748b">Sign-ins · 30 days</div>
            <div style="font-size:18px;font-weight:700;color:#0f172a;margin-top:2px"><?= $signIns ?></div>
          </div>
          <div style="background:<?= $failed ? '#fef2f2' : '#f8fafc' ?>;border:1px solid <?= $failed ? '#fecaca' : '#e2e8f0' ?>;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#64748b">Failed · 24 hours</div>
            <div style="font-size:18px;font-weight:700;color:<?= $failed ? '#dc2626' : '#0f172a' ?>;margin-top:2px"><?= $failed ?></div>
          </div>
        </div>
        <?php if ($recent): ?>
          <div style="font-size:12px;font-weight:600;color:#0f172a;margin-bottom:6px">Recent events</div>
          <?php foreach ($recent as $ev):
              $bad = str_starts_with($ev['action'], 'Failed');
              $by  = (strcasecmp($ev['username'], $un) !== 0) ? ' · by ' . $ev['username'] : '';
              $rel = ago_text((int)$ev['age']); ?>
            <div style="display:flex;justify-content:space-between;gap:10px;font-size:12px;padding:6px 0;border-bottom:1px solid #f1f5f9">
              <span style="color:<?= $bad ? '#dc2626' : '#334155' ?>"><?= e($ev['action']) ?><span style="color:#94a3b8"><?= e($by) ?></span></span>
              <span style="color:#94a3b8;white-space:nowrap" title="<?= e(date('d M Y g:i:s A', strtotime($ev['created_at']))) ?>"><?= e($rel !== '' ? $rel : date('d M · g:i A', strtotime($ev['created_at']))) ?></span>
            </div>
          <?php endforeach; ?>
        <?php else: ?>
          <div style="font-size:12px;color:#94a3b8">No recorded activity for this account yet.</div>
        <?php endif; ?>
        <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:12px;font-size:12px;font-weight:600">
          <a href="<?= url('admin/index.php?tab=lg&q=' . urlencode($un)) ?>" style="color:#2563eb">View full activity log →</a>
          <a href="<?= url('admin/index.php?tab=ro&inspect=' . urlencode($acct['role'])) ?>" style="color:#2563eb">What can a <?= e(strtolower(ROLE_LABELS[$acct['role']] ?? $acct['role'])) ?> do? →</a>
        </div>
      </div>
<?php
    return (string)ob_get_clean();
}

// Sub-tabs within the single admin page (matching Figma: Accounts / Roles & permissions / Activity log)
$tab = $_GET['tab'] ?? 'ac';
if (!in_array($tab, ['ac', 'cl', 'ro', 'lg'])) $tab = 'ac';

// Live refresh of the Account activity block (polled by the Manage account panel)
if (($_GET['activity'] ?? '') === '1' && ($acctForActivity = row('SELECT user_id, username, role FROM user_account WHERE user_id=?', [(int)($_GET['edit'] ?? 0)]))) {
    header('Cache-Control: no-store');
    echo activity_panel($acctForActivity);
    exit;
}

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'add_section') {
        $prog   = trim((string)($_POST['program'] ?? ''));
        $year   = (int)($_POST['year_level'] ?? 0);
        $letter = strtoupper(trim((string)($_POST['letter'] ?? '')));
        if (!isset(PROGRAM_CODES[$prog]))                      section_json(false, 'Choose a degree program first.');
        if ($year < 1 || $year > MAX_YEAR_LEVEL)               section_json(false, 'Year level must be 1 to ' . MAX_YEAR_LEVEL . '.');
        if (!preg_match('/^[A-Z0-9]{1,3}$/', $letter))         section_json(false, 'Section letter must be 1 to 3 letters or digits, e.g. A.');
        $name = PROGRAM_CODES[$prog] . ' ' . $year . $letter;
        if (row('SELECT 1 FROM section WHERE section_name=?', [$name])) section_json(false, 'Section ' . $name . ' already exists.');
        q('INSERT INTO section(section_name, program, year_level) VALUES(?, ?, ?)', [$name, $prog, $year]);
        $newId = (int)$pdo->lastInsertId();
        audit('Added section ' . $name);
        section_json(true, 'Section ' . $name . ' added.', $newId);
    }
    if ($act === 'delete_section') {
        $sec = row('SELECT * FROM section WHERE section_id=?', [(int)($_POST['section_id'] ?? 0)]);
        if (!$sec) section_json(false, 'Section not found.');
        $n = (int)row('SELECT COUNT(*) c FROM student WHERE section=? AND program=?', [$sec['section_name'], $sec['program']])['c'];
        if ($n > 0) section_json(false, 'Cannot delete ' . $sec['section_name'] . ': ' . $n . ' student' . ($n > 1 ? 's are' : ' is') . ' still assigned to it. Move them to another section first.');
        q('DELETE FROM section WHERE section_id=?', [$sec['section_id']]);
        audit('Deleted section ' . $sec['section_name']);
        section_json(true, 'Section ' . $sec['section_name'] . ' deleted.');
    }

    if ($act === 'update_office_clearance') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $off = trim((string)($_POST['office'] ?? ''));
        $newSt = (($_POST['status'] ?? '') === 'Cleared') ? 'Cleared' : 'Pending';
        if ($sid > 0 && in_array($off, OFFICES, true)) {
            ensure_student_clearance($sid);
            q("UPDATE clearance SET status=? WHERE student_id=? AND office=?", [$newSt, $sid, $off]);
            audit("Admin updated $off clearance to $newSt for student #$sid");
            notify_student($sid, "$off Clearance Updated", "Your $off institutional clearance status has been set to: $newSt.", 'student/index.php?tab=cl');
            flash("$off clearance updated to $newSt.");
        }
        $to = $_POST['redirect_to'] ?? ('admin/index.php?tab=ac&edit=' . (int)($_POST['user_id'] ?? 0));
        redirect($to);
    }

    if ($act === 'create_account') {
        $to = 'admin/index.php?tab=ac&create=1';
        $me = row('SELECT password_hash FROM user_account WHERE user_id=?', [$u['user_id']]);
        if (!password_verify((string)($_POST['admin_confirm'] ?? ''), $me['password_hash'])) {
            audit('Failed re-authentication creating user account');
            adm_fail('create', 'Your admin password is incorrect. The account was not created — your other entries were kept.', $to, 'admin_confirm');
        }

        $newRole = trim((string)($_POST['new_role'] ?? 'student'));
        if (!isset(ROLE_LABELS[$newRole])) adm_fail('create', 'Invalid role selected.', $to, 'new_role');

        $rawPassword = (string)($_POST['new_user_password'] ?? '');
        if ($rawPassword === '') $rawPassword = ($newRole === 'student' ? 'Student@2026' : 'CuyoTech@2026');
        if (strlen($rawPassword) < 8) adm_fail('create', 'Initial password must be at least 8 characters.', $to, 'new_user_password');
        $passHash = password_hash($rawPassword, PASSWORD_DEFAULT);

        if ($newRole === 'student') {
            $fn = trim((string)($_POST['first_name'] ?? ''));
            $ln = trim((string)($_POST['last_name'] ?? ''));
            $em = trim((string)($_POST['email'] ?? ''));
            $cn = '09170000000';
            $pr = trim((string)($_POST['program'] ?? ''));
            $sc = trim((string)($_POST['section'] ?? ''));
            $collegeRow = valid_college_program((int)($_POST['department_id'] ?? 0), $pr);
            if ($fn === '' || $ln === '') adm_fail('create', 'Please enter the student\'s first and last name.', $to, $fn === '' ? 'first_name' : 'last_name');
            if (!filter_var($em, FILTER_VALIDATE_EMAIL)) adm_fail('create', 'Please enter a valid university email.', $to, 'email');
            if (!$collegeRow) adm_fail('create', 'Please choose a college and one of its degree programs.', $to, 'department_id');
            if (!valid_section($pr, $sc)) adm_fail('create', 'Please choose a section from the list for this degree program. Use "+ Add section" if it does not exist yet.', $to, 'section');
            if (row('SELECT 1 FROM student WHERE email=?', [$em])) adm_fail('create', 'A student with that email already exists.', $to, 'email');

            $pdo->beginTransaction();
            try {
                for ($try = 0; ; $try++) {              // student number is assigned automatically
                    $sn = next_student_number();
                    try {
                        q("INSERT INTO user_account(username, password_hash, role, status, must_change_password) VALUES(?, ?, 'student', 'Active', 1)", [$sn, $passHash]);
                        break;
                    } catch (PDOException $ex) {
                        if ($ex->getCode() !== '23000' || $try >= 4) throw $ex;
                    }
                }
                $newUid = (int)$pdo->lastInsertId();
                q("INSERT INTO student(user_id, department_id, student_number, first_name, last_name, email, contact_number, program, section)
                   VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)", [$newUid, (int)$collegeRow['department_id'], $sn, $fn, $ln, $em, $cn, $pr, $sc]);
                $newSid = (int)$pdo->lastInsertId();
                foreach (OFFICES as $off) {
                    q("INSERT IGNORE INTO clearance (student_id, office, status) VALUES (?, ?, ?)", [$newSid, $off, $off === 'Department' ? 'Pending' : 'Cleared']);
                }
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                adm_fail('create', 'The account could not be created. Please try again.', $to);
            }
            audit('Created student account ' . $sn);
            flash('Student account created successfully. Student number (login ID): ' . $sn . '. The student will be required to change password on first login.');
            redirect('admin/index.php?tab=ac&edit=' . $newUid);
        } else {
            $fn = trim((string)($_POST['full_name'] ?? ''));
            $deptId = null;
            if ($fn === '') adm_fail('create', 'Please enter the staff member\'s full name.', $to, 'full_name');
            if ($newRole === 'depthead') {
                $deptRow = row('SELECT department_id FROM department WHERE department_id=?', [(int)($_POST['staff_department_id'] ?? 0)]);
                if (!$deptRow) adm_fail('create', 'Please choose the department for this Department Head.', $to, 'staff_department_id');
                $deptId = (int)$deptRow['department_id'];
            }
            $pdo->beginTransaction();
            try {
                for ($try = 0; ; $try++) {              // staff ID is assigned automatically (REG-001, CSH-001, DEP-001, ADM-001 ...)
                    $un = next_staff_id($newRole);
                    try {
                        q("INSERT INTO user_account(username, password_hash, role, status, full_name, department_id, must_change_password) VALUES(?, ?, ?, 'Active', ?, ?, 1)",
                          [$un, $passHash, $newRole, $fn, $deptId]);
                        break;
                    } catch (PDOException $ex) {
                        if ($ex->getCode() !== '23000' || $try >= 4) throw $ex;
                    }
                }
                $newUid = (int)$pdo->lastInsertId();
                $pdo->commit();
            } catch (Throwable $ex) {
                $pdo->rollBack();
                adm_fail('create', 'The account could not be created. Please try again.', $to);
            }
            audit('Created staff account ' . $un . ' (' . $newRole . ')');
            flash(ROLE_LABELS[$newRole] . ' account created successfully. User ID (login): ' . $un . '. The user will be required to change password on first login.');
            redirect('admin/index.php?tab=ac&edit=' . $newUid);
        }
    }

    $acct = row('SELECT * FROM user_account WHERE user_id=?', [(int)($_POST['id'] ?? 0)]);
    $me   = row('SELECT password_hash FROM user_account WHERE user_id=?', [$u['user_id']]);
    $self = $acct && (int)$acct['user_id'] === (int)$u['user_id'];

    if (!$acct) { flash('Account not found.', true); redirect('admin/index.php'); }
    $to    = 'admin/index.php?tab=ac&edit=' . $acct['user_id'];
    $scope = 'edit:' . $acct['user_id'];

    if (!password_verify((string)($_POST['confirm'] ?? ''), $me['password_hash'])) {
        audit('Failed re-authentication editing ' . $acct['username']);
        adm_fail($scope, 'Your password is incorrect. No changes were saved — your other entries were kept.', $to, 'confirm');
    }

    $isStudentAcct = ($acct['role'] === 'student');
    $role   = ($self || $isStudentAcct) ? $acct['role']   : ($_POST['role']   ?? '');
    $status = $self ? $acct['status'] : ($_POST['status'] ?? '');

    if (!isset(ROLE_LABELS[$role]) || !in_array($status, ['Active', 'Disabled'], true)) {
        adm_fail($scope, 'Invalid role or status.', $to);
    }
    if ($role === 'student' && !$isStudentAcct) {
        adm_fail($scope, 'A staff account cannot be converted to a student (it has no student record).', $to, 'role');
    }

    $new = (string)($_POST['new_password'] ?? '');
    if ($new !== '' && strlen($new) < 8) adm_fail($scope, 'New password must be at least 8 characters.', $to, 'new_password');

    // College / department / degree changes
    $newDeptForUser = null;
    $studentUpdate = null;
    if ($isStudentAcct) {
        $pr = trim((string)($_POST['stu_program'] ?? ''));
        $c = valid_college_program((int)($_POST['stu_department_id'] ?? 0), $pr);
        if (!$c) adm_fail($scope, 'Please choose a college and one of its degree programs.', $to, 'stu_department_id');
        $sc = trim((string)($_POST['stu_section'] ?? ''));
        $cur = row('SELECT program, section FROM student WHERE user_id=?', [$acct['user_id']]);
        if ($sc === '' && $cur && $cur['program'] === $pr) {
            $sc = (string)$cur['section'];                        // untouched: keep the current section
        } elseif (!valid_section($pr, $sc)) {
            adm_fail($scope, 'Please choose a section that belongs to the selected degree program.', $to, 'stu_section');
        }
        $studentUpdate = [(int)$c['department_id'], $pr, $sc];
    } elseif ($role === 'depthead') {
        $d = row('SELECT department_id FROM department WHERE department_id=?', [(int)($_POST['edit_department_id'] ?? 0)]);
        if (!$d) adm_fail($scope, 'Please choose the department for this Department Head.', $to, 'edit_department_id');
        $newDeptForUser = (int)$d['department_id'];
    }

    q('UPDATE user_account SET role=?, status=?, department_id=? WHERE user_id=?',
      [$role, $status, $isStudentAcct ? $acct['department_id'] : $newDeptForUser, $acct['user_id']]);
    if ($studentUpdate) {
        q('UPDATE student SET department_id=?, program=?, section=? WHERE user_id=?', [$studentUpdate[0], $studentUpdate[1], $studentUpdate[2], $acct['user_id']]);
    }
    if ($new !== '') {
        q('UPDATE user_account SET password_hash=?, must_change_password=1 WHERE user_id=?', [password_hash($new, PASSWORD_DEFAULT), $acct['user_id']]);
    }
    audit('Edited account ' . $acct['username']);
    flash('Account updated successfully.');
    redirect($to);
}

// --- Saved form state from a failed submit (inputs are kept, passwords never are) ---
$old = $_SESSION['adm_old'] ?? null;
unset($_SESSION['adm_old']);
$oldCreate = ($old && $old['scope'] === 'create') ? $old : null;
$ov = fn(string $k, string $default = '') => (string)($oldCreate['data'][$k] ?? $default);   // create-modal value
$autoOpenCreate = $oldCreate !== null || isset($_GET['create']);
$cErr = fn(string $f) => ($oldCreate && $oldCreate['field'] === $f) ? ';border-color:#dc2626;background:#fef2f2' : '';

// --- Data ---
$collegeList = rows("SELECT department_id, department_name, department_code FROM department
                     ORDER BY FIELD(department_code, 'COE', 'CEAS', 'CHAS', 'CCS', 'CBAA'), department_id");
$nextIds = ['student' => next_student_number()];
foreach (array_keys(STAFF_PREFIX) as $rr) $nextIds[$rr] = next_staff_id($rr);
$programsByDept = [];
foreach ($collegeList as $c) {
    $programsByDept[(int)$c['department_id']] = COLLEGE_PROGRAMS[$c['department_code']] ?? [];
}
$sectionsByProgram = sections_by_program();
$qs         = trim($_GET['q'] ?? '');
$roleFilter = trim($_GET['role'] ?? '');
$like       = '%' . $qs . '%';

$acctWhere  = "(u.username LIKE ? OR u.role LIKE ? OR s.last_name LIKE ? OR u.full_name LIKE ?)";
$acctParams = [$like, $like, $like, $like];
if ($roleFilter !== '' && isset(ROLE_LABELS[$roleFilter])) {
    $acctWhere .= " AND u.role = ?";
    $acctParams[] = $roleFilter;
}

$accounts = rows(
    "SELECT u.user_id, u.username, u.role, u.status, u.department_id,
            COALESCE(CONCAT(s.first_name,' ',s.last_name), u.full_name) AS name,
            s.student_number,
            s.program,
            d.department_name
     FROM user_account u
     LEFT JOIN student s ON s.user_id = u.user_id
     LEFT JOIN department d ON d.department_id = u.department_id
     WHERE $acctWhere
     ORDER BY u.user_id",
    $acctParams
);

// Which account is selected in the manage-account panel
$editId   = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editAcct = null;
if ($editId > 0) {
    $editAcct = row(
        "SELECT u.*, COALESCE(CONCAT(s.first_name,' ',s.last_name), u.full_name) AS name,
                s.student_number, s.email AS student_email
         FROM user_account u
         LEFT JOIN student s ON s.user_id = u.user_id
         WHERE u.user_id = ?",
        [$editId]
    );
}
// Default: select first account
if (!$editAcct && count($accounts) > 0) {
    $first = $accounts[0];
    $editAcct = row(
        "SELECT u.*, COALESCE(CONCAT(s.first_name,' ',s.last_name), u.full_name) AS name,
                s.student_number, s.email AS student_email
         FROM user_account u
         LEFT JOIN student s ON s.user_id = u.user_id
         WHERE u.user_id = ?",
        [$first['user_id']]
    );
}

// Role-specific permissions for the manage panel
$rolePermissions = [
    'student'   => [
        'Self profile, grades, subjects & clearance' => true,
        'Request academic documents' => true,
        'Review & confirm enrollment' => false,
        'Encode & publish grades' => false,
        'Process payments & receipts' => false,
        'Departmental clearance decisions' => false,
        'Manage user accounts' => false,
    ],
    'registrar' => [
        'Review and confirm enrollment' => true,
        'Encode and publish grades' => true,
        'Process academic documents' => true,
        'Manage user accounts' => false,
    ],
    'cashier' => [
        'Process payments & issue receipts' => true,
        'View payment history' => true,
        'Issue official receipts' => true,
        'Manage user accounts' => false,
    ],
    'depthead' => [
        'Sign departmental clearance' => true,
        'View department student roster' => true,
        'Manage user accounts' => false,
    ],
    'admin' => [
        'Manage user accounts' => true,
        'Assign responsibility-based roles' => true,
        'View account & security audit logs' => true,
        'Enrollment, grades & academic documents' => false,
        'Payments & receipt processing' => false,
        'Departmental clearance decisions' => false,
    ],
];

// Role display info
$officeLabels = [
    'student'   => 'Student',
    'registrar' => 'Registrar',
    'cashier'   => 'Cashier',
    'depthead'  => 'Department',
    'admin'     => 'Administrator',
];
$officeSubtitles = [
    'student'   => 'Student account',
    'registrar' => "Registrar's Office",
    'cashier'   => "Cashier's Office",
    'depthead'  => 'Department',
    'admin'     => 'ICT Office',
];

// Build "USER ID" label like "REG-001 · Dr. George F. Barundia"
function accountLabel(array $a): string {
    $uid = ($a['role'] === 'student' && !empty($a['student_number'])) ? $a['student_number'] : ($a['username'] ?? '');
    return $uid . ' · ' . e($a['name']);
}

// Logs
$logWhere = '';
$logParams = [];
if ($tab === 'lg' && $qs !== '') {
    $logWhere = "WHERE a.username LIKE ? OR a.action LIKE ? OR u.full_name LIKE ? OR s.first_name LIKE ? OR s.last_name LIKE ?";
    $logParams = [$like, $like, $like, $like, $like];
}
$logs = rows(
    "SELECT a.*, u.full_name, u.role, s.student_number,
            COALESCE(CONCAT(s.first_name, ' ', s.last_name), u.full_name) AS full_display_name
     FROM audit_log a
     LEFT JOIN user_account u ON u.user_id = a.user_id
     LEFT JOIN student s ON s.user_id = u.user_id
     $logWhere
     ORDER BY a.log_id DESC LIMIT 100",
    $logParams
);

if ($tab === 'lg' && isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="cuyotech_audit_log_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Log ID', 'Date/Time', 'Username', 'Account Name', 'Role', 'Event Action']);
    foreach ($logs as $l) {
        fputcsv($out, [
            $l['log_id'],
            $l['created_at'],
            $l['username'],
            $l['full_display_name'] ?? '',
            $l['role'] ?? '',
            $l['action']
        ]);
    }
    fclose($out);
    exit;
}

sidebar_tabs('admin/index.php', ['ac' => 'Accounts', 'cl' => 'Clearance approvals', 'ro' => 'Roles & permissions', 'lg' => 'Activity log'], $tab);
page_start('admin', 'Identity & Access Administration', 'Enterprise directory management, role-based access control (RBAC), and university security audit trails.', 'SYSTEM ADMINISTRATION / IDENTITY & ACCESS MANAGEMENT');
?>

<!-- ══════════════════════════════════════════════════════════
     MAIN CARD  –  "University accounts"
     ══════════════════════════════════════════════════════════ -->
<div style="background:#ffffff;border-radius:16px;box-shadow:0 2px 16px rgba(0,0,0,0.06);overflow:hidden">

  <!-- Card header row -->
  <div class="no-print" style="padding:24px 32px 0;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px">
    <div>
      <?php $heads = [
          'ac' => ['University accounts', count($accounts) . ' active accounts · 5 roles · Access is assigned by responsibility'],
          'cl' => ['Institutional clearance administration', 'Manage clearance sign-offs for Library, Student Affairs, and other university offices'],
          'ro' => ['Roles & permissions', 'What each role can and cannot do in the system'],
          'lg' => ['Activity log', 'Sign-ins, account changes and security events'],
      ]; ?>
      <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px"><?= e($heads[$tab][0]) ?></h2>
      <p style="font-size:13px;color:#64748b;margin:0"><?= e($heads[$tab][1]) ?></p>
    </div>

    <?php if ($tab === 'ac'): ?>
    <button type="button" onclick="document.getElementById('createModal').classList.add('show')" style="display:inline-flex;align-items:center;gap:6px;background:#2563eb;color:#fff;border:none;border-radius:9999px;padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Create account
    </button>
    <?php elseif ($tab === 'lg'): ?>
    <div style="display:flex;align-items:center;gap:10px">
      <a href="<?= url('admin/index.php?tab=lg' . ($qs ? '&q=' . urlencode($qs) : '') . '&export=csv') ?>" style="display:inline-flex;align-items:center;gap:6px;background:#fff;color:#2563eb;border:1px solid #bfdbfe;border-radius:9999px;padding:9px 18px;font-size:13px;font-weight:600;text-decoration:none;white-space:nowrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV
      </a>
      <button type="button" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;background:#2563eb;color:#fff;border:none;border-radius:9999px;padding:10px 20px;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print log
      </button>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: ACCOUNTS  (Page 19)
       ══════════════════════════════════════════════════════════ -->
  <?php if ($tab === 'ac'):
      $perPage = 8;
      $totalAccounts = count($accounts);
      $page = max(1, min((int)($_GET['page'] ?? 1), max(1, (int)ceil($totalAccounts / $perPage))));
      $offset = ($page - 1) * $perPage;
      $pagedAccounts = array_slice($accounts, $offset, $perPage);
      $totalPages = max(1, (int)ceil($totalAccounts / $perPage));
  ?>
  <div style="display:grid;grid-template-columns:1fr 340px;gap:0;min-height:520px">

    <!-- Left: Directory -->
    <div style="padding:24px 28px;border-right:1px solid #e2e8f0">

      <!-- Search + Role filter row -->
      <form method="get" action="<?= url('admin/index.php') ?>" style="display:grid;grid-template-columns:1fr 180px;gap:12px;margin-bottom:20px">
        <input type="hidden" name="tab" value="ac">
        <div>
          <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:6px">Search Directory</div>
          <div style="position:relative;display:flex;align-items:center">
            <input type="text" name="q" placeholder="Name, university email or user ID"
                   value="<?= e($qs) ?>"
                   style="width:100%;padding:9px 70px 9px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;outline:none;box-sizing:border-box">
            <?php if ($qs): ?>
              <a href="<?= url('admin/index.php?tab=ac' . ($roleFilter ? '&role=' . urlencode($roleFilter) : '')) ?>" title="Clear search" style="position:absolute;right:34px;color:#94a3b8;font-size:18px;text-decoration:none;padding:2px 6px">×</a>
            <?php endif; ?>
            <button type="submit" style="position:absolute;right:8px;background:none;border:none;cursor:pointer;color:#2563eb;padding:4px;display:flex;align-items:center" title="Search">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            </button>
          </div>
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em;margin-bottom:6px">Role</div>
          <div style="position:relative">
            <select name="role" onchange="this.form.submit()"
                    style="width:100%;padding:9px 32px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;appearance:none;box-sizing:border-box">
              <option value="">All roles</option>
              <?php foreach (ROLE_LABELS as $rk => $rl): ?>
                <option value="<?= $rk ?>" <?= ($roleFilter === $rk) ? 'selected' : '' ?>><?= e($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </div>
      </form>

      <!-- Table header -->
      <div style="display:grid;grid-template-columns:1fr 100px 80px 60px;gap:8px;padding:8px 12px;margin-bottom:4px">
        <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em">Account</div>
        <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em">Role</div>
        <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em">Status</div>
        <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.07em">Action</div>
      </div>

      <!-- Account rows -->
      <?php if (empty($pagedAccounts)): ?>
        <div style="padding:28px 12px;text-align:center;color:#64748b;font-size:13px">No accounts found matching your query.</div>
      <?php else: ?>
        <?php foreach ($pagedAccounts as $r):
            $isSelected = ($editAcct && $editAcct['user_id'] == $r['user_id']);
            $subtitle = ($r['role'] === 'student')
                ? ($r['student_number'] . ' · ' . ($r['department_name'] ?? 'Student'))
                : ($r['username'] . ' · ' . ($r['role'] === 'depthead' ? ($r['department_name'] ?: 'No department assigned') : ($officeSubtitles[$r['role']] ?? '')));
            $roleLabel = $officeLabels[$r['role']] ?? ucfirst($r['role']);
            $statusColor = $r['status'] === 'Active' ? '#16a34a' : '#64748b';
        ?>
        <a href="<?= url('admin/index.php?tab=ac&edit=' . $r['user_id'] . ($qs ? '&q=' . urlencode($qs) : '') . ($roleFilter ? '&role=' . urlencode($roleFilter) : '') . '&page=' . $page) ?>"
           style="display:grid;grid-template-columns:1fr 100px 80px 60px;gap:8px;padding:12px;border-radius:8px;background:<?= $isSelected ? '#eff6ff' : 'transparent' ?>;text-decoration:none;margin-bottom:2px;transition:background 0.12s">
          <div>
            <div style="font-size:14px;font-weight:600;color:<?= $isSelected ? '#1d4ed8' : '#0f172a' ?>"><?= e($r['name']) ?></div>
            <div style="font-size:12px;color:<?= $isSelected ? '#3b82f6' : '#64748b' ?>;margin-top:2px"><?= e($subtitle) ?></div>
          </div>
          <div style="font-size:13px;color:<?= $isSelected ? '#2563eb' : '#334155' ?>;align-self:center;font-weight:<?= $isSelected ? '600' : '400' ?>"><?= e($roleLabel) ?></div>
          <div style="font-size:13px;color:<?= $statusColor ?>;align-self:center;font-weight:500"><?= e($r['status'] === 'Active' ? 'Active' : $r['status']) ?></div>
          <div style="align-self:center">
            <span style="font-size:13px;color:#2563eb;font-weight:500">Edit</span>
          </div>
        </a>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Pagination row -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-top:18px;padding-top:16px;border-top:1px solid #f1f5f9">
        <span style="font-size:12px;color:#64748b">Showing <?= min($offset + 1, $totalAccounts) ?>–<?= min($offset + count($pagedAccounts), $totalAccounts) ?> of <?= $totalAccounts ?> accounts</span>
        <div style="display:flex;align-items:center;gap:6px;font-size:13px">
          <?php if ($page > 1): ?>
            <a href="<?= url('admin/index.php?tab=ac&page=' . ($page - 1) . ($qs ? '&q=' . urlencode($qs) : '') . ($roleFilter ? '&role=' . urlencode($roleFilter) : '')) ?>" style="color:#2563eb;text-decoration:none;font-weight:600;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;background:#fff">← Previous</a>
          <?php else: ?>
            <span style="color:#cbd5e1;padding:4px 8px">← Previous</span>
          <?php endif; ?>

          <?php for ($p = 1; $p <= $totalPages; $p++): ?>
            <a href="<?= url('admin/index.php?tab=ac&page=' . $p . ($qs ? '&q=' . urlencode($qs) : '') . ($roleFilter ? '&role=' . urlencode($roleFilter) : '')) ?>" style="padding:4px 10px;border-radius:4px;text-decoration:none;font-weight:600;<?= ($p === $page) ? 'background:#2563eb;color:#fff' : 'background:#fff;border:1px solid #cbd5e1;color:#0f172a' ?>"><?= $p ?></a>
          <?php endfor; ?>

          <?php if ($page < $totalPages): ?>
            <a href="<?= url('admin/index.php?tab=ac&page=' . ($page + 1) . ($qs ? '&q=' . urlencode($qs) : '') . ($roleFilter ? '&role=' . urlencode($roleFilter) : '')) ?>" style="color:#2563eb;text-decoration:none;font-weight:600;padding:4px 8px;border-radius:4px;border:1px solid #cbd5e1;background:#fff">Next →</a>
          <?php else: ?>
            <span style="color:#cbd5e1;padding:4px 8px">Next →</span>
          <?php endif; ?>
        </div>
      </div>

      <!-- Info notice -->
      <div style="margin-top:16px;padding:12px 16px;background:#f8fafc;border-radius:8px;display:flex;align-items:flex-start;gap:10px">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="flex-shrink:0;margin-top:1px"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
        <span style="font-size:12px;color:#64748b">Staff roles have module-specific access. Account changes are recorded in the activity log.</span>
      </div>
    </div>

    <!-- Right: Manage account panel -->
    <?php if ($editAcct):
        $isSelf = ((int)$editAcct['user_id'] === (int)$u['user_id']);
        // Figma shows "email" field — use student email or build one from username
        $displayEmail = !empty($editAcct['student_email'])
            ? $editAcct['student_email']
            : (str_replace(['0','1','2','3','4','5','6','7','8','9','-'], '', strtolower($editAcct['name'])) . '@cuyotech.edu.ph');
        // Make it look like the figma
        $nameParts = explode(' ', strtolower($editAcct['name']));
        $displayEmail = (count($nameParts) >= 2)
            ? $nameParts[0][0] . '.' . end($nameParts) . '@cuyotech.edu.ph'
            : strtolower(str_replace(' ', '.', $editAcct['name'])) . '@cuyotech.edu.ph';
        if (!empty($editAcct['student_email'])) $displayEmail = $editAcct['student_email'];

        // UID string
        $uidStr = ($editAcct['role'] === 'student' && !empty($editAcct['student_number']))
            ? $editAcct['student_number']
            : $editAcct['username'];
        // Edit-panel state: saved inputs from a failed submit win over the database values
        $oldEdit  = ($old && $old['scope'] === 'edit:' . $editAcct['user_id']) ? $old : null;
        $ev       = fn(string $k, string $default = '') => (string)($oldEdit['data'][$k] ?? $default);
        $stuRow   = $editAcct['role'] === 'student' ? row('SELECT department_id, program, section FROM student WHERE user_id=?', [$editAcct['user_id']]) : null;
        $editRole = $ev('role', $editAcct['role']);
        $editStatus = $ev('status', $editAcct['status']);
        $editStuDept = (int)$ev('stu_department_id', (string)($stuRow['department_id'] ?? 0));
        $editStuProg = $ev('stu_program', (string)($stuRow['program'] ?? ''));
        $editStuSec  = $ev('stu_section', (string)($stuRow['section'] ?? ''));
        $editDeptId  = (int)$ev('edit_department_id', (string)($editAcct['department_id'] ?? 0));
        $inputErr = fn(string $f) => ($oldEdit && $oldEdit['field'] === $f) ? 'border-color:#dc2626;background:#fef2f2;' : '';
    ?>
    <div style="padding:24px 24px 28px">
      <!-- Panel header -->
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px">
        <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0">Manage account</h3>
        <span style="display:inline-flex;align-items:center;padding:3px 12px;border-radius:9999px;background:#dcfce7;border:1px solid #bbf7d0;font-size:12px;font-weight:600;color:#16a34a">
          <?= e($editAcct['status']) ?>
        </span>
      </div>

      <?php if ($oldEdit && $oldEdit['error']): ?>
      <div role="alert" style="margin-bottom:16px;padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px"><?= e($oldEdit['error']) ?></div>
      <?php endif; ?>

      <!-- User ID -->
      <div style="margin-bottom:16px">
        <div style="font-size:11px;color:#94a3b8;font-weight:500;margin-bottom:2px">USER ID</div>
        <div style="font-size:15px;font-weight:700;color:#0f172a"><?= e($uidStr . ' · ' . $editAcct['name']) ?></div>
      </div>

      <!-- University email -->
      <form method="post" id="editForm">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$editAcct['user_id'] ?>">

        <div style="margin-bottom:14px">
          <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">University email</label>
          <input type="email" value="<?= e($displayEmail) ?>" disabled
                 style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#f8fafc;box-sizing:border-box">
        </div>

        <!-- Assigned role -->
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Assigned role</label>
          <div style="position:relative">
            <select name="role" id="editRoleSelect" onchange="toggleEditRole(this.value)" <?= ($isSelf || $editAcct['role'] === 'student') ? 'disabled' : '' ?>
                    style="width:100%;padding:9px 32px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;appearance:none;box-sizing:border-box;<?= $inputErr('role') ?>">
              <?php foreach (ROLE_LABELS as $rk => $rl):
                  if ($rk === 'student' && $editAcct['role'] !== 'student') continue;   // staff accounts cannot become students ?>
                <option value="<?= $rk ?>" <?= ($rk === $editRole) ? 'selected' : '' ?>><?= e($rl) ?></option>
              <?php endforeach; ?>
            </select>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </div>

        <?php if ($editAcct['role'] === 'student'): ?>
        <!-- Student: college and degree program -->
        <div style="margin-bottom:14px">
          <label for="editStuCollege" style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">College / department</label>
          <select name="stu_department_id" id="editStuCollege" onchange="loadPrograms(this.value, 'editStuProgram')"
                  style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box;<?= $inputErr('stu_department_id') ?>">
            <?php foreach ($collegeList as $c): ?>
              <option value="<?= (int)$c['department_id'] ?>" <?= (int)$c['department_id'] === $editStuDept ? 'selected' : '' ?>><?= e($c['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div style="margin-bottom:18px">
          <label for="editStuProgram" style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Degree program</label>
          <select name="stu_program" id="editStuProgram" data-selected="<?= e($editStuProg) ?>" onchange="loadSections(this.value, 'editStuSection')"
                  style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box"></select>
        </div>
        <div style="margin-bottom:18px">
          <label for="editStuSection" style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Section</label>
          <select name="stu_section" id="editStuSection" data-selected="<?= e($editStuSec) ?>"
                  style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box;<?= $inputErr('stu_section') ?>"></select>
        </div>

        <?php
        $admStu = row("SELECT student_id FROM student WHERE user_id=?", [$editAcct['user_id']]);
        if ($admStu):
            $admSid = (int)$admStu['student_id'];
            ensure_student_clearance($admSid);
            $admClrs = rows("SELECT office, status FROM clearance WHERE student_id=? ORDER BY FIELD(office, 'Library','Student Affairs','Registrar','Cashier','Department')", [$admSid]);
        ?>
        <div style="margin-bottom:18px;padding:12px 14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px">
            <span style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.04em">Institutional Clearance</span>
            <span style="font-size:11px;font-weight:700;color:#2563eb"><?= count(array_filter($admClrs, fn($x)=>$x['status']==='Cleared')) ?> / 5 Cleared</span>
          </div>
          <div style="display:flex;flex-direction:column;gap:6px">
            <?php foreach ($admClrs as $cItem):
                $isC = ($cItem['status'] === 'Cleared');
            ?>
              <div style="display:flex;align-items:center;justify-content:space-between;padding:5px 8px;background:#ffffff;border:1px solid #e2e8f0;border-radius:6px;font-size:12px">
                <span style="font-weight:600;color:#334155"><?= e($cItem['office']) ?></span>
                <div style="display:flex;align-items:center;gap:6px">
                  <span class="rg-badge <?= $isC ? 'rg-badge-green' : 'rg-badge-amber' ?>" style="font-size:10px;padding:2px 8px">
                    <?= e($cItem['status']) ?>
                  </span>
                  <button type="submit" form="admClrForm_<?= e($cItem['office']) ?>" class="rg-btn <?= $isC ? 'ghost' : 'primary' ?>" style="font-size:10px;padding:2px 8px;border-radius:4px">
                    <?= $isC ? 'Pending' : 'Clear' ?>
                  </button>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
        <?php endif; ?>
        <?php else: ?>
        <!-- Department head: assigned department -->
        <div id="editDeptRow" style="margin-bottom:18px;<?= $editRole === 'depthead' ? '' : 'display:none' ?>">
          <label for="editDeptSelect" style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Assigned department</label>
          <select name="edit_department_id" id="editDeptSelect"
                  style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box;<?= $inputErr('edit_department_id') ?>">
            <option value="">Select department</option>
            <?php foreach ($collegeList as $c): ?>
              <option value="<?= (int)$c['department_id'] ?>" <?= (int)$c['department_id'] === $editDeptId ? 'selected' : '' ?>><?= e($c['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <?php endif; ?>

        <!-- Account status -->
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Account status</label>
          <div style="position:relative">
            <select name="status" <?= $isSelf ? 'disabled' : '' ?>
                    style="width:100%;padding:9px 32px 9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;appearance:none;box-sizing:border-box">
              <option value="Active" <?= $editStatus === 'Active' ? 'selected' : '' ?>>Active</option>
              <option value="Disabled" <?= $editStatus === 'Disabled' ? 'selected' : '' ?>>Disabled</option>
            </select>
            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);pointer-events:none"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
          <?php if ($isSelf): ?>
          <input type="hidden" name="status" value="<?= e($editAcct['status']) ?>">
          <?php endif; ?>
        </div>

        <!-- Reset password (optional) -->
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Reset password (optional)</label>
          <input type="password" name="new_password" placeholder="Leave blank to keep current" autocomplete="new-password"
                 style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;background:#fff;box-sizing:border-box;<?= $inputErr('new_password') ?>">
          <div style="font-size:11px;color:#94a3b8;margin-top:4px">Must be at least 8 characters if specified.</div>
        </div>

        <!-- Confirm admin password -->
        <div style="margin-bottom:18px">
          <label style="display:block;font-size:12px;color:#64748b;margin-bottom:6px">Confirm your admin password</label>
          <input name="confirm" type="password" placeholder="Your password" required autocomplete="current-password" <?= ($oldEdit && in_array($oldEdit['field'], ['confirm', 'new_password'], true)) ? 'autofocus' : '' ?>
                 style="width:100%;padding:9px 12px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;box-sizing:border-box;<?= $inputErr('confirm') ?>">
        </div>

        <button type="submit"
                style="width:100%;padding:10px;background:#2563eb;color:#fff;border:none;border-radius:9999px;font-size:13px;font-weight:600;cursor:pointer">
          Save changes
        </button>
      </form>

      <?php if (!empty($admStu)): ?>
        <?php foreach ($admClrs as $cItem):
            $isC = ($cItem['status'] === 'Cleared');
        ?>
          <form method="post" id="admClrForm_<?= e($cItem['office']) ?>" style="display:none">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update_office_clearance">
            <input type="hidden" name="user_id" value="<?= (int)$editAcct['user_id'] ?>">
            <input type="hidden" name="student_id" value="<?= $admSid ?>">
            <input type="hidden" name="office" value="<?= e($cItem['office']) ?>">
            <input type="hidden" name="status" value="<?= $isC ? 'Pending' : 'Cleared' ?>">
            <input type="hidden" name="redirect_to" value="admin/index.php?tab=ac&edit=<?= (int)$editAcct['user_id'] ?>">
          </form>
        <?php endforeach; ?>
      <?php endif; ?>

      <!-- Account activity (refreshes itself) -->
      <div id="acctActivity" data-url="<?= e(url('admin/index.php?tab=ac&activity=1&edit=' . (int)$editAcct['user_id'])) ?>"><?= activity_panel($editAcct) ?></div>
      <script>
      (function () {
        var box = document.getElementById('acctActivity');
        if (!box) return;
        function refresh() {
          if (document.hidden) return;
          fetch(box.dataset.url, {credentials: 'same-origin', cache: 'no-store'})
            .then(function (r) { return r.ok ? r.text() : null; })
            .then(function (h) { if (h) box.innerHTML = h; })
            .catch(function () {});
        }
        setInterval(refresh, 15000);
        document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
      })();
      </script>
    </div>
    <?php endif; ?>
  </div><!-- /accounts grid -->

  <!-- ══════════════════════════════════════════════════════════
       TAB: CLEARANCE APPROVALS (Library, Student Affairs, etc.)
       ══════════════════════════════════════════════════════════ -->
  <?php elseif ($tab === 'cl'): ?>
  <div style="padding:24px 32px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
      <div>
        <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px">University Institutional Clearance Matrix</h3>
        <p style="font-size:13px;color:#64748b;margin:0">Admin management for Library, Student Affairs, and institutional clearance compliance.</p>
      </div>
      <div style="font-size:12px;color:#64748b">
        5 of 5 offices required for official graduation, clearance certificates, and document releases.
      </div>
    </div>

    <?php
    $clrStudents = rows("SELECT s.student_id, s.student_number, s.first_name, s.last_name, s.program, s.section, d.department_code
                         FROM student s LEFT JOIN department d USING(department_id) ORDER BY s.student_id ASC");
    ?>
    <div class="rg-table-wrap">
      <table class="rg-table">
        <thead>
          <tr>
            <th>Student</th>
            <th>Program / Section</th>
            <th style="text-align:center">Library</th>
            <th style="text-align:center">Student Affairs</th>
            <th style="text-align:center">Registrar</th>
            <th style="text-align:center">Cashier</th>
            <th style="text-align:center">Department</th>
            <th style="text-align:center">Progress</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($clrStudents as $cst):
              $csid = (int)$cst['student_id'];
              ensure_student_clearance($csid);
              $cMap = [];
              foreach (rows("SELECT office, status FROM clearance WHERE student_id=?", [$csid]) as $crow) {
                  $cMap[$crow['office']] = $crow['status'];
              }
              $cClearedCount = count(array_filter($cMap, fn($v) => $v === 'Cleared'));
              $isAllCleared = ($cClearedCount === 5);
          ?>
            <tr>
              <td>
                <div class="rg-person">
                  <div class="rg-avatar"><?= e(first_char($cst['first_name']) . first_char($cst['last_name'])) ?></div>
                  <div>
                    <div class="rg-name"><?= e($cst['first_name'] . ' ' . $cst['last_name']) ?></div>
                    <div class="rg-sub"><?= e($cst['student_number']) ?></div>
                  </div>
                </div>
              </td>
              <td><?= e($cst['program']) ?> · <?= e($cst['section'] ?: 'TBA') ?></td>
              
              <!-- Library (Admin Managed) -->
              <td style="text-align:center">
                <form method="post" style="display:inline-block;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_office_clearance">
                  <input type="hidden" name="student_id" value="<?= $csid ?>">
                  <input type="hidden" name="office" value="Library">
                  <input type="hidden" name="status" value="<?= ($cMap['Library'] ?? '') === 'Cleared' ? 'Pending' : 'Cleared' ?>">
                  <input type="hidden" name="redirect_to" value="admin/index.php?tab=cl">
                  <button type="submit" class="rg-btn <?= ($cMap['Library'] ?? '') === 'Cleared' ? 'ghost' : 'primary' ?>" style="font-size:11px;padding:3px 10px;border-radius:4px">
                    <?= ($cMap['Library'] ?? '') === 'Cleared' ? '✓ Cleared' : 'Pending' ?>
                  </button>
                </form>
              </td>

              <!-- Student Affairs (Admin Managed) -->
              <td style="text-align:center">
                <form method="post" style="display:inline-block;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_office_clearance">
                  <input type="hidden" name="student_id" value="<?= $csid ?>">
                  <input type="hidden" name="office" value="Student Affairs">
                  <input type="hidden" name="status" value="<?= ($cMap['Student Affairs'] ?? '') === 'Cleared' ? 'Pending' : 'Cleared' ?>">
                  <input type="hidden" name="redirect_to" value="admin/index.php?tab=cl">
                  <button type="submit" class="rg-btn <?= ($cMap['Student Affairs'] ?? '') === 'Cleared' ? 'ghost' : 'primary' ?>" style="font-size:11px;padding:3px 10px;border-radius:4px">
                    <?= ($cMap['Student Affairs'] ?? '') === 'Cleared' ? '✓ Cleared' : 'Pending' ?>
                  </button>
                </form>
              </td>

              <!-- Registrar -->
              <td style="text-align:center">
                <span class="rg-badge <?= ($cMap['Registrar'] ?? '') === 'Cleared' ? 'rg-badge-green' : 'rg-badge-amber' ?>" style="font-size:11px">
                  <?= e($cMap['Registrar'] ?? 'Pending') ?>
                </span>
              </td>

              <!-- Cashier -->
              <td style="text-align:center">
                <span class="rg-badge <?= ($cMap['Cashier'] ?? '') === 'Cleared' ? 'rg-badge-green' : 'rg-badge-amber' ?>" style="font-size:11px">
                  <?= e($cMap['Cashier'] ?? 'Pending') ?>
                </span>
              </td>

              <!-- Department -->
              <td style="text-align:center">
                <span class="rg-badge <?= ($cMap['Department'] ?? '') === 'Cleared' ? 'rg-badge-green' : 'rg-badge-amber' ?>" style="font-size:11px">
                  <?= e($cMap['Department'] ?? 'Pending') ?>
                </span>
              </td>

              <!-- Progress -->
              <td style="text-align:center">
                <span class="rg-badge <?= $isAllCleared ? 'rg-badge-green' : 'rg-badge-blue' ?>" style="font-weight:700">
                  <?= $cClearedCount ?> / 5
                </span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: ROLES & PERMISSIONS  (Page 20)
       ══════════════════════════════════════════════════════════ -->
  <?php elseif ($tab === 'ro'): ?>
  <div style="padding:28px 32px">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px">
      <div>
        <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px">Role access matrix</h3>
        <p style="font-size:13px;color:#64748b;margin:0">Five responsibility-based roles. Access does not extend to other staff modules.</p>
      </div>
      <span style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:9999px;padding:4px 14px;font-size:12px;font-weight:600">5 roles</span>
    </div>

    <!-- Matrix table -->
    <?php
    $matrixRows = [
        ['Self profile, grades, subjects, enrollment & clearance tracking', 'Self', null,      null,      null,    null],
        ['Review & confirm enrollment',                                       null,  'Allowed', null,      null,    null],
        ['Encode / publish grades & student academic records',                null,  'Allowed', null,      null,    null],
        ['Academic documents',                                                'Request', 'Process', null,  null,    null],
        ['Payments & official receipts',                                      null,  null,      'Allowed', null,    null],
        ['Departmental clearance decisions',                                  null,  null,      null,      'Allowed', null],
        ['User accounts, role assignments & audit logs',                      null,  null,      null,      null,    'Allowed'],
    ];
    $colHeaders = ['CAPABILITY', 'STUDENT', 'REGISTRAR', 'CASHIER', 'DEPARTMENT', 'ADMINISTRATOR'];
    ?>
    <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden;margin-top:20px">
      <!-- Header -->
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 1fr;background:#f8fafc;border-bottom:1px solid #e2e8f0">
        <?php foreach ($colHeaders as $ch): ?>
        <div style="padding:12px 16px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.07em"><?= $ch ?></div>
        <?php endforeach; ?>
      </div>
      <!-- Rows -->
      <?php foreach ($matrixRows as $mr): ?>
      <div style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr 1fr 1fr;border-bottom:1px solid #f1f5f9">
        <div style="padding:16px;font-size:13px;color:#0f172a"><?= e($mr[0]) ?></div>
        <?php for ($i = 1; $i <= 5; $i++): $v = $mr[$i]; ?>
        <div style="padding:16px;display:flex;align-items:center;gap:6px">
          <?php if ($v): ?>
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            <span style="font-size:13px;color:#2563eb;font-weight:500"><?= e($v) ?></span>
          <?php else: ?>
            <span style="font-size:13px;color:#cbd5e1">— Not allowed</span>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Selected role callout box -->
    <?php
    $roleToInspect = $_GET['inspect'] ?? 'admin';
    if (!isset($rolePermissions[$roleToInspect])) $roleToInspect = 'admin';

    $inspectTitles = [
        'admin'     => ['Administrator', 'System administration only. No inherited staff operational access.'],
        'registrar' => ['Registrar', 'Academic operations, enrollment confirmations, grade encoding and document processing.'],
        'cashier'   => ['Cashier', 'Finance operations, recording tuition payments and issuing official receipts.'],
        'depthead'  => ['Department Head', 'Departmental clearance review, equipment return validation and student clearance decisions.'],
        'student'   => ['Student', 'Student self-service portal, view grades, track enrollment, request documents and clearances.'],
    ];
    $allowedList = [];
    $disallowedList = [];
    foreach ($rolePermissions[$roleToInspect] as $permDesc => $isAllowed) {
        if ($isAllowed) $allowedList[] = $permDesc;
        else $disallowedList[] = $permDesc;
    }
    ?>
    <div style="display:flex;align-items:center;gap:8px;margin-top:20px;flex-wrap:wrap">
      <span style="font-size:12px;font-weight:600;color:#64748b">Inspect role:</span>
      <?php foreach ($inspectTitles as $rKey => [$rName, $rDesc]): ?>
        <a href="<?= url('admin/index.php?tab=ro&inspect=' . $rKey) ?>" data-role="<?= e($rKey) ?>"
           class="role-pill<?= ($rKey === $roleToInspect) ? ' is-active' : '' ?>">
          <?= $rName ?>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="role-panels" style="margin-top:14px">
    <?php foreach ($inspectTitles as $rKey => [$rName, $rDesc]):
        $allowedList = [];
        $disallowedList = [];
        foreach (($rolePermissions[$rKey] ?? []) as $permDesc => $isAllowed) {
            if ($isAllowed) $allowedList[] = $permDesc;
            else $disallowedList[] = $permDesc;
        }
    ?>
    <div class="role-panel<?= ($rKey === $roleToInspect) ? ' is-active' : '' ?>" data-role="<?= e($rKey) ?>" <?= ($rKey === $roleToInspect) ? '' : 'hidden' ?>
         style="border:1px solid #e2e8f0;border-radius:12px;padding:22px 24px;display:grid;grid-template-columns:220px 1fr 1fr;gap:24px">
      <div>
        <div style="font-size:12px;color:#2563eb;font-weight:600;margin-bottom:6px">Selected role</div>
        <div style="font-size:20px;font-weight:700;color:#0f172a;margin-bottom:6px"><?= $rName ?></div>
        <div style="font-size:12px;color:#64748b"><?= $rDesc ?></div>
      </div>
      <div>
        <div style="font-size:12px;font-weight:600;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
          Allowed
        </div>
        <?php foreach ($allowedList as $p): ?>
        <div style="font-size:13px;color:#334155;margin-bottom:6px"><?= e($p) ?></div>
        <?php endforeach; ?>
      </div>
      <div>
        <div style="font-size:12px;font-weight:600;color:#0f172a;margin-bottom:10px;display:flex;align-items:center;gap:6px">
          <span style="color:#94a3b8">—</span> Not allowed
        </div>
        <?php if (empty($disallowedList)): ?>
          <div style="font-size:13px;color:#94a3b8">None (full operational authorization)</div>
        <?php else: ?>
          <?php foreach ($disallowedList as $p): ?>
          <div style="font-size:13px;color:#334155;margin-bottom:6px"><?= e($p) ?></div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    </div>

    <script>
    // Switch the inspected role without reloading the page
    (function () {
      var pills = document.querySelectorAll('.role-pill');
      var busy = false;
      function show(role) {
        var cur = document.querySelector('.role-panel.is-active');
        var next = document.querySelector('.role-panel[data-role="' + role + '"]');
        if (!cur || !next || next === cur || busy) return;
        busy = true;
        pills.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-role') === role); });
        cur.classList.add('is-leaving');
        setTimeout(function () {
          cur.classList.remove('is-active', 'is-leaving');
          cur.hidden = true;
          next.hidden = false;
          next.classList.add('is-entering');
          void next.offsetWidth;
          next.classList.add('is-active');
          next.classList.remove('is-entering');
          busy = false;
        }, 200);
      }
      pills.forEach(function (p) {
        p.addEventListener('click', function (e) {
          e.preventDefault();
          var role = p.getAttribute('data-role');
          show(role);
          if (window.history && history.replaceState) {
            var u = new URL(window.location.href);
            u.searchParams.set('inspect', role);
            history.replaceState(null, '', u);
          }
        });
      });
    })();
    </script>

    <!-- Footer note -->
    <div style="margin-top:16px;padding:12px 16px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b">
      Student access is limited to their own profile, grades, subjects, enrollment and clearance tracking, plus document requests. Staff navigation exposes only the module assigned to their role.
    </div>
  </div>

  <!-- ══════════════════════════════════════════════════════════
       TAB: ACTIVITY LOG  (Page 21)
       ══════════════════════════════════════════════════════════ -->
  <?php else:
      $categories = ['All', 'Authentication', 'Finance', 'Clearance', 'Enrollment', 'Academics', 'Security'];
      $selectedCat = $_GET['cat'] ?? 'All';

      // Categorize logs
      $displayLogs = [];
      foreach ($logs as $l) {
          $act = $l['action'];
          $cat = 'System';
          if (str_contains($act, 'Sign') || str_contains($act, 'log')) $cat = 'Authentication';
          elseif (str_contains($act, 'payment')) $cat = 'Finance';
          elseif (str_contains($act, 'clearance')) $cat = 'Clearance';
          elseif (str_contains($act, 'enrollment')) $cat = 'Enrollment';
          elseif (str_contains($act, 'grades')) $cat = 'Academics';
          elseif (str_contains($act, 'Requested')) $cat = 'Documents';
          elseif (str_contains($act, 'account')) $cat = 'Security';

          $l['computed_cat'] = $cat;
          if ($selectedCat === 'All' || $selectedCat === $cat) {
              $displayLogs[] = $l;
          }
      }
  ?>
  <div style="padding:28px 32px">
    <!-- PRINT-ONLY: Audit Log Header -->
    <div class="print-only" style="margin-bottom:12pt">
      <?= cuyotech_doc_header('MANAGEMENT INFORMATION SYSTEMS · OFFICE OF THE ADMINISTRATOR', 'OFFICIAL SYSTEM AUDIT & SECURITY TRAIL REPORT', 'Category: ' . e($selectedCat) . ($qs ? ' · Filter: "' . e($qs) . '"' : '') . ' · Security Compliance Archive', 'AUD-' . date('Ymd')) ?>
    </div>

    <div class="no-print" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px">
      <div>
        <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px">Account &amp; security audit</h3>
        <p style="font-size:13px;color:#64748b;margin:0">Review sign-ins, account security and system activity recorded in real time.</p>
      </div>
      <span style="background:#eff6ff;color:#2563eb;border:1px solid #bfdbfe;border-radius:9999px;padding:4px 14px;font-size:12px;font-weight:600"><?= count($displayLogs) ?> logged events</span>
    </div>

    <!-- Filters row -->
    <div class="no-print" style="margin:20px 0 16px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
      <!-- Search Form -->
      <form method="get" action="<?= url('admin/index.php') ?>" style="display:flex;align-items:center;gap:8px;max-width:420px;width:100%;margin:0">
        <input type="hidden" name="tab" value="lg">
        <?php if ($selectedCat !== 'All'): ?>
          <input type="hidden" name="cat" value="<?= e($selectedCat) ?>">
        <?php endif; ?>
        <div style="position:relative;flex:1">
          <input type="text" name="q" placeholder="Username, name or event action"
                 value="<?= e($qs) ?>"
                 style="width:100%;padding:9px 64px 9px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:13px;color:#0f172a;box-sizing:border-box">
          <?php if ($qs): ?>
            <a href="<?= url('admin/index.php?tab=lg' . ($selectedCat !== 'All' ? '&cat=' . urlencode($selectedCat) : '')) ?>" title="Clear search" style="position:absolute;right:32px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:18px;text-decoration:none">×</a>
          <?php endif; ?>
          <button type="submit" style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#2563eb;padding:4px" title="Search">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
          </button>
        </div>
      </form>

      <!-- Category Filter Pills -->
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
        <?php foreach ($categories as $cat):
            $isCatActive = ($cat === $selectedCat);
            $catUrl = url('admin/index.php?tab=lg' . ($cat !== 'All' ? '&cat=' . urlencode($cat) : '') . ($qs ? '&q=' . urlencode($qs) : ''));
        ?>
          <a href="<?= $catUrl ?>" style="padding:6px 14px;border-radius:9999px;font-size:12px;font-weight:500;text-decoration:none;<?= $isCatActive ? 'background:#2563eb;color:#fff' : 'background:#f1f5f9;color:#475569;border:1px solid #e2e8f0' ?>">
            <?= $cat ?>
          </a>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Log table -->
    <div style="border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
      <!-- Header -->
      <div style="display:grid;grid-template-columns:160px 200px 1fr 140px 120px;background:#f8fafc;border-bottom:1px solid #e2e8f0">
        <?php foreach (['DATE / TIME','ACCOUNT','EVENT ACTION','CATEGORY','OUTCOME'] as $h): ?>
        <div style="padding:11px 16px;font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.07em"><?= $h ?></div>
        <?php endforeach; ?>
      </div>

      <?php if (empty($displayLogs)): ?>
      <div style="padding:32px 16px;text-align:center;color:#64748b;font-size:13px">
        No audit log entries found<?= $qs ? ' matching "' . e($qs) . '"' : '' ?><?= ($selectedCat !== 'All') ? ' in category "' . e($selectedCat) . '"' : '' ?>.
      </div>
      <?php else: ?>
      <?php foreach ($displayLogs as $l):
          $action = $l['action'];
          $isErr = str_contains($action, 'Failed') || str_contains($action, 'Invalid');
          $outBg = $isErr ? '#fef2f2' : '#dcfce7';
          $outColor = $isErr ? '#dc2626' : '#16a34a';
          $outBorder = $isErr ? '1px solid #fecaca' : '1px solid #bbf7d0';
          $outcome = $isErr ? 'Warning' : 'Success';
          $category = $l['computed_cat'] ?? 'System';
      ?>
      <div style="display:grid;grid-template-columns:160px 200px 1fr 140px 120px;border-bottom:1px solid #f1f5f9;align-items:center">
        <div style="padding:14px 16px">
          <div style="font-size:13px;font-weight:600;color:#0f172a"><?= e(date('d M Y', strtotime($l['created_at']))) ?></div>
          <div style="font-size:12px;color:#64748b;margin-top:2px"><?= e(date('g:i A', strtotime($l['created_at']))) ?></div>
        </div>
        <div style="padding:14px 16px">
          <div style="font-size:13px;font-weight:600;color:#0f172a"><?= e($l['username']) ?></div>
          <?php if (!empty($l['full_display_name'])): ?>
          <div style="font-size:12px;color:#64748b;margin-top:2px"><?= e($l['full_display_name']) ?></div>
          <?php endif; ?>
        </div>
        <div style="padding:14px 16px;font-size:13px;color:#334155"><?= e($action) ?></div>
        <div style="padding:14px 16px;font-size:13px;color:#64748b"><?= e($category) ?></div>
        <div style="padding:14px 16px">
          <span style="display:inline-block;padding:3px 12px;border-radius:9999px;font-size:12px;font-weight:500;background:<?= $outBg ?>;color:<?= $outColor ?>;border:<?= $outBorder ?>"><?= e($outcome) ?></span>
        </div>
      </div>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="no-print" style="margin-top:16px;display:flex;align-items:center;justify-content:space-between">
      <span style="font-size:12px;color:#64748b">Showing <?= count($displayLogs) ?> logged events · Newest first</span>
    </div>

    <!-- Bottom note -->
    <div class="no-print" style="margin-top:12px;padding:12px 16px;background:#f8fafc;border-radius:8px;font-size:12px;color:#64748b">
      Audit access is read-only. This view covers account and security activity; it does not grant access to Registrar, Cashier or Department operations.
    </div>

    <!-- PRINT-ONLY: Audit Log Signature Block -->
    <div class="official-sign-block print-only" style="margin-top:20pt">
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">System Administrator</div>
        <div class="official-sig-title">IT &amp; Network Operations</div>
      </div>
      <div class="official-sig-item" style="text-align:center">
        <?= cuyotech_seal_stamp() ?>
      </div>
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">VP for Administration</div>
        <div class="official-sig-title">Office of the Vice President</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- /main card -->

<?php
?>
<!-- Create Account Modal -->
<div class="modal-backdrop" id="createModal">
  <div class="modal-card" style="max-width:540px;padding:28px 32px">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
      <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin:0">Create New University Account</h3>
      <button type="button" onclick="document.getElementById('createModal').classList.remove('show')" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1">×</button>
    </div>

    <form method="post" id="createAcctForm" style="display:flex;flex-direction:column;gap:14px;margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_account">
      <?php if ($oldCreate && $oldCreate['error']): ?>
      <div role="alert" style="padding:10px 14px;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#b91c1c;font-size:13px"><?= e($oldCreate['error']) ?></div>
      <?php endif; ?>

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Account Role</label>
        <select name="new_role" id="newRoleSelect" onchange="toggleRoleFields(this.value)" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;color:#0f172a;background:#fff" required>
          <option value="student" <?= $ov('new_role','student') === 'student' ? 'selected' : '' ?>>Student (Self-service portal)</option>
          <option value="registrar" <?= $ov('new_role','student') === 'registrar' ? 'selected' : '' ?>>Registrar Officer (Academic processing desk)</option>
          <option value="cashier" <?= $ov('new_role','student') === 'cashier' ? 'selected' : '' ?>>Cashier Officer (Finance & receipts)</option>
          <option value="depthead" <?= $ov('new_role','student') === 'depthead' ? 'selected' : '' ?>>Department Head (Clearance approvals)</option>
          <option value="admin" <?= $ov('new_role','student') === 'admin' ? 'selected' : '' ?>>System Administrator (Identity & audit)</option>
        </select>
      </div>

      <!-- Student Fields -->
      <div id="studentFields" style="display:flex;flex-direction:column;gap:12px">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">First Name</label>
            <input type="text" name="first_name" value="<?= e($ov('first_name')) ?>" placeholder="e.g. Juan" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('first_name') ?>">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Last Name</label>
            <input type="text" name="last_name" value="<?= e($ov('last_name')) ?>" placeholder="e.g. Dela Cruz" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('last_name') ?>">
          </div>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Student Number <span style="font-weight:500;color:#16a34a">· auto-assigned</span></label>
            <input type="text" value="<?= e($nextIds['student']) ?>" readonly tabindex="-1" aria-label="Student number (assigned automatically)" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #e2e8f0;font-size:13px;box-sizing:border-box;background:#f1f5f9;color:#475569;font-weight:600">
          </div>
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">University Email</label>
            <input type="email" name="email" value="<?= e($ov('email')) ?>" placeholder="e.g. juan.delacruz@cuyotech.edu.ph" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('email') ?>">
          </div>
        </div>
        <div>
          <label for="studCollege" style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">College / Department</label>
          <select name="department_id" id="studCollege" onchange="loadPrograms(this.value, 'studProgram')" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box;background:#fff;color:#0f172a<?= $cErr('department_id') ?>">
            <option value="">Select college</option>
            <?php foreach ($collegeList as $c): ?>
            <option value="<?= (int)$c['department_id'] ?>" <?= (int)$ov('department_id', '0') === (int)$c['department_id'] ? 'selected' : '' ?>><?= e($c['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label for="studProgram" style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Degree Program</label>
          <select name="program" id="studProgram" data-selected="<?= e($ov('program')) ?>" onchange="loadSections(this.value, 'studSection')" disabled style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box;background:#fff;color:#0f172a">
            <option value="">Select a college first</option>
          </select>
        </div>
        <div>
          <label for="studSection" style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Section</label>
          <select name="section" id="studSection" data-selected="<?= e($ov('section')) ?>" disabled style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box;background:#fff;color:#0f172a<?= $cErr('section') ?>">
            <option value="">Select a degree program first</option>
          </select>
          <div style="display:flex;gap:8px;margin-top:8px;flex-wrap:wrap">
            <button type="button" id="secAddBtn" onclick="secToggleAdd(true)" disabled style="padding:7px 14px;border-radius:9999px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8">+ Add section</button>
            <button type="button" id="secDelBtn" onclick="secDelete()" disabled style="padding:7px 14px;border-radius:9999px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c">Delete selected section</button>
          </div>
          <div id="secAddRow" style="display:none;margin-top:8px;padding:10px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc">
            <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
              <div>
                <label for="secYear" style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">Year level</label>
                <select id="secYear" onchange="secPreview()" style="border-radius:8px;padding:7px 10px;border:1px solid #cbd5e1;font-size:13px;background:#fff">
                  <?php for ($y = 1; $y <= MAX_YEAR_LEVEL; $y++): ?><option value="<?= $y ?>"><?= $y ?></option><?php endfor; ?>
                </select>
              </div>
              <div>
                <label for="secLetter" style="display:block;font-size:11px;font-weight:600;color:#64748b;margin-bottom:3px">Section letter</label>
                <input type="text" id="secLetter" maxlength="3" placeholder="A" oninput="this.value=this.value.toUpperCase();secPreview()" style="width:70px;border-radius:8px;padding:7px 10px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box">
              </div>
              <div style="font-size:12px;color:#475569;padding-bottom:8px">New section: <strong id="secPreview">—</strong></div>
              <button type="button" onclick="secAdd()" style="padding:7px 14px;border-radius:9999px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:none;background:#2563eb;color:#fff">Save section</button>
              <button type="button" onclick="secToggleAdd(false)" style="padding:7px 14px;border-radius:9999px;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;border:1px solid #cbd5e1;background:#fff;color:#475569">Cancel</button>
            </div>
          </div>
          <div id="secMsg" role="status" style="display:none;margin-top:8px;font-size:12px;font-weight:600"></div>
        </div>
      </div>

      <!-- Staff Fields -->
      <div id="staffFields" style="display:none;flex-direction:column;gap:12px">
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Full Name</label>
          <input type="text" name="full_name" value="<?= e($ov('full_name')) ?>" placeholder="e.g. Dr. Roberto Sanchez" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('full_name') ?>">
        </div>
        <div id="deptField" style="display:none">
          <label for="staffCollege" style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Department</label>
          <select name="staff_department_id" id="staffCollege" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box;background:#fff;color:#0f172a<?= $cErr('staff_department_id') ?>">
            <option value="">Select department</option>
            <?php foreach ($collegeList as $c): ?>
            <option value="<?= (int)$c['department_id'] ?>" <?= (int)$ov('staff_department_id', '0') === (int)$c['department_id'] ? 'selected' : '' ?>><?= e($c['department_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">User ID <span style="font-weight:500;color:#16a34a">· auto-assigned</span></label>
          <input type="text" id="staffIdPreview" value="" readonly tabindex="-1" aria-label="User ID (assigned automatically)" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #e2e8f0;font-size:13px;box-sizing:border-box;background:#f1f5f9;color:#475569;font-weight:600">
        </div>
      </div>

      <div>
        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:4px">Initial Password</label>
        <input type="password" name="new_user_password" autocomplete="new-password" placeholder="Defaults to stud123 or pass123" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('new_user_password') ?>">
      </div>

      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:12px">
        <label style="display:block;font-size:12px;font-weight:600;color:#0f172a;margin-bottom:4px">Confirm Admin Password</label>
        <input type="password" name="admin_confirm" id="adminConfirm" placeholder="Your administrator password" required autocomplete="current-password" style="width:100%;border-radius:8px;padding:9px 12px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box<?= $cErr('admin_confirm') ?>">
        <div style="font-size:11px;color:#64748b;margin-top:4px">Required for security authorization to create accounts.</div>
      </div>

      <div style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:10px">
        <button type="button" onclick="document.getElementById('createModal').classList.remove('show')" style="padding:9px 18px;border-radius:9999px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-size:13px;font-weight:600;cursor:pointer">Cancel</button>
        <button type="submit" style="padding:9px 24px;border-radius:9999px;background:#2563eb;color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer">Create Account</button>
      </div>
    </form>
  </div>
</div>

<script>
var COLLEGE_PROGRAMS_BY_ID = <?= json_encode($programsByDept, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

function loadPrograms(deptId, selectId, selected) {
    var sel = document.getElementById(selectId || 'studProgram');
    if (!sel) return;
    var list = COLLEGE_PROGRAMS_BY_ID[deptId] || [];
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = list.length ? 'Select degree program' : 'Select a college first';
    sel.appendChild(first);
    list.forEach(function (name) {
        var o = document.createElement('option');
        o.value = name;
        o.textContent = name;
        if (selected && name === selected) o.selected = true;
        sel.appendChild(o);
    });
    sel.disabled = !list.length;
    // a different college/program invalidates the section choice
    var secId = {studProgram: 'studSection', editStuProgram: 'editStuSection'}[selectId || 'studProgram'];
    if (secId && document.getElementById(secId)) loadSections(sel.value, secId, '');
}

var SECTIONS_BY_PROGRAM = <?= json_encode((object)$sectionsByProgram, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
var PROGRAM_CODES = <?= json_encode(PROGRAM_CODES, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;

// Fill a section <select> with the sections of one degree program.
function loadSections(program, selectId, selected) {
    var sel = document.getElementById(selectId || 'studSection');
    if (!sel) return;
    var list = SECTIONS_BY_PROGRAM[program] || [];
    sel.innerHTML = '';
    var first = document.createElement('option');
    first.value = '';
    first.textContent = !program ? 'Select a degree program first' : (list.length ? 'Select section' : 'No sections yet \u2013 add one');
    sel.appendChild(first);
    list.forEach(function (sec) {
        var o = document.createElement('option');
        o.value = sec.name;
        o.textContent = sec.name;
        if (selected && sec.name === selected) o.selected = true;
        sel.appendChild(o);
    });
    sel.disabled = !program;
    if (selectId === 'studSection' || !selectId) secRefreshButtons();
}
function secRefreshButtons() {
    var prog = document.getElementById('studProgram'), sel = document.getElementById('studSection');
    var add = document.getElementById('secAddBtn'), del = document.getElementById('secDelBtn');
    if (add) add.disabled = !(prog && prog.value);
    if (del) del.disabled = !(sel && sel.value);
    if (sel && !sel.dataset.bound) { sel.dataset.bound = '1'; sel.addEventListener('change', secRefreshButtons); }
    if (!prog || !prog.value) secToggleAdd(false);
}
function secMsg(text, ok) {
    var m = document.getElementById('secMsg');
    m.textContent = text; m.style.display = text ? 'block' : 'none'; m.style.color = ok ? '#16a34a' : '#dc2626';
}
function secToggleAdd(show) {
    var row = document.getElementById('secAddRow');
    if (!row) return;
    row.style.display = show ? 'block' : 'none';
    if (show) { secMsg('', true); secPreview(); document.getElementById('secLetter').focus(); }
}
function secPreview() {
    var prog = document.getElementById('studProgram').value;
    var letter = document.getElementById('secLetter').value.trim().toUpperCase();
    var year = document.getElementById('secYear').value;
    document.getElementById('secPreview').textContent = (PROGRAM_CODES[prog] && letter) ? PROGRAM_CODES[prog] + ' ' + year + letter : '\u2014';
}
function secPost(fields) {
    var fd = new FormData();
    fd.append('csrf', document.querySelector('#createModal input[name=csrf]').value);
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    return fetch(window.location.pathname, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); });
}
function secApply(res, keepSelected) {
    SECTIONS_BY_PROGRAM = res.sections || {};
    var prog = document.getElementById('studProgram').value;
    var name = '';
    (SECTIONS_BY_PROGRAM[prog] || []).forEach(function (x) { if (res.selected && x.id === res.selected) name = x.name; });
    loadSections(prog, 'studSection', name || keepSelected || '');
    secRefreshButtons();
    secMsg(res.msg, res.ok);
}
function secAdd() {
    var prog = document.getElementById('studProgram').value;
    secPost({ action: 'add_section', program: prog, year_level: document.getElementById('secYear').value, letter: document.getElementById('secLetter').value })
        .then(function (res) {
            secApply(res, '');
            if (res.ok) { document.getElementById('secLetter').value = ''; secToggleAdd(false); secMsg(res.msg, true); }
        })
        .catch(function () { secMsg('Could not reach the server. Please try again.', false); });
}
function secDelete() {
    var sel = document.getElementById('studSection');
    var name = sel.value, prog = document.getElementById('studProgram').value, id = 0;
    (SECTIONS_BY_PROGRAM[prog] || []).forEach(function (x) { if (x.name === name) id = x.id; });
    if (!id || !confirm('Delete section ' + name + '? This cannot be undone.')) return;
    secPost({ action: 'delete_section', section_id: id })
        .then(function (res) { secApply(res, res.ok ? '' : name); })
        .catch(function () { secMsg('Could not reach the server. Please try again.', false); });
}

var NEXT_IDS = <?= json_encode($nextIds) ?>;
function toggleEditRole(role) {
    var row = document.getElementById('editDeptRow');
    if (row) row.style.display = (role === 'depthead') ? 'block' : 'none';
}

function toggleRoleFields(role) {
    const isStud = (role === 'student');
    document.getElementById('studentFields').style.display = isStud ? 'flex' : 'none';
    document.getElementById('staffFields').style.display = isStud ? 'none' : 'flex';
    document.getElementById('deptField').style.display = (role === 'depthead') ? 'block' : 'none';
    document.getElementById('studCollege').required = isStud;
    document.getElementById('studProgram').required = isStud;
    document.getElementById('studSection').required = isStud;
    document.getElementById('staffCollege').required = (role === 'depthead');
    var idBox = document.getElementById('staffIdPreview');
    if (idBox) idBox.value = NEXT_IDS[role] || '';
}
toggleRoleFields(document.getElementById('newRoleSelect').value);

// restore the degree program lists (create modal + edit panel)
(function () {
    var cs = document.getElementById('studCollege'), cp = document.getElementById('studProgram');
    if (cs && cs.value) loadPrograms(cs.value, 'studProgram', cp ? cp.getAttribute('data-selected') : '');
    var csec = document.getElementById('studSection');
    if (cp && csec) loadSections(cp.value, 'studSection', csec.getAttribute('data-selected'));
    var es = document.getElementById('editStuCollege'), ep = document.getElementById('editStuProgram');
    if (es && ep) loadPrograms(es.value, 'editStuProgram', ep.getAttribute('data-selected'));
    var esec = document.getElementById('editStuSection');
    if (ep && esec) loadSections(ep.value, 'editStuSection', esec.getAttribute('data-selected'));
    <?php if ($autoOpenCreate): ?>
    document.getElementById('createModal').classList.add('show');
    var ac = document.getElementById('adminConfirm');
    <?php if ($oldCreate && $oldCreate['field'] === 'admin_confirm'): ?>if (ac) ac.focus();<?php endif; ?>
    <?php endif; ?>
})();
</script>

<?php page_end(); ?>
