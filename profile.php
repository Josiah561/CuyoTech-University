<?php
require __DIR__ . '/includes/bootstrap.php';
// Profile page for every role except Admin (admins manage accounts from the Admin module).
$u = require_role('student', 'registrar', 'cashier', 'depthead');
$isStudent = ($u['role'] === 'student');

// --- POST handler ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';

    if ($act === 'contact' && $isStudent) {
        $email   = trim((string)($_POST['email'] ?? ''));
        $contact = trim((string)($_POST['contact_number'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
            flash('Please enter a valid email address.', true);
        } elseif (!preg_match('/^[0-9+\-\s()]{7,20}$/', $contact)) {
            flash('Please enter a valid contact number (digits, spaces, + - ( ) only).', true);
        } else {
            q('UPDATE student SET email=?, contact_number=? WHERE user_id=?', [$email, $contact, $u['user_id']]);
            audit('Updated own contact details');
            flash('Your contact details were updated.');
        }
        redirect('profile.php');
    }

    if ($act === 'password') {
        $me      = row('SELECT password_hash FROM user_account WHERE user_id=?', [$u['user_id']]);
        $current = (string)($_POST['current_password'] ?? '');
        $new     = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $me['password_hash'])) {
            audit('Failed password change (wrong current password)');
            flash('Your current password is incorrect.', true);
        } elseif (strlen($new) < 8) {
            flash('New password must be at least 8 characters.', true);
        } elseif ($new !== $confirm) {
            flash('New password and confirmation do not match.', true);
        } elseif ($new === $current) {
            flash('New password must be different from your current password.', true);
        } else {
            q('UPDATE user_account SET password_hash=?, must_change_password=0 WHERE user_id=?', [password_hash($new, PASSWORD_DEFAULT), $u['user_id']]);
            user(true);
            audit('Changed own password');
            flash('Your password was changed successfully.');
        }
        redirect('profile.php');
    }

    redirect('profile.php');
}

// --- Data ---
$acct = row('SELECT username, role, status FROM user_account WHERE user_id=?', [$u['user_id']]);
$stu  = null;
$deptName = null;
if ($isStudent) {
    $stu = row('SELECT s.*, d.department_name FROM student s JOIN department d ON d.department_id = s.department_id WHERE s.user_id=?', [$u['user_id']]);
    $deptName = $stu['department_name'] ?? null;
} elseif ($u['role'] === 'depthead' && !empty($u['department_id'])) {
    $d = row('SELECT department_name FROM department WHERE department_id=?', [(int)$u['department_id']]);
    $deptName = $d['department_name'] ?? null;
}

$officeLabels = [
    'student'   => 'Student',
    'registrar' => 'Office of the University Registrar',
    'cashier'   => 'Cashiering & Treasury Services',
    'depthead'  => 'Department Head',
];

page_start('profile', 'My Profile', 'Your account details and security settings.', 'ACCOUNT / MY PROFILE');

function profile_row(string $label, ?string $value): void {
    echo '<div class="pf-row"><div class="pf-label">' . e($label) . '</div><div class="pf-value">'
       . ($value !== null && $value !== '' ? e($value) : '<span class="pf-empty">Not provided</span>') . '</div></div>';
}
$svgUser = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
$svgMail = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/></svg>';
$svgLock = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>';
?>

<!-- Identity -->
<section class="pf-card pf-hero">
  <div class="pf-hero-bar"></div>
  <div class="pf-hero-body">
    <div class="pf-avatar"><?= e(first_char($u['name'] ?? 'U')) ?></div>
    <div class="pf-hero-text">
      <h2 class="pf-name"><?= e($u['name']) ?></h2>
      <div class="pf-role"><?= e($officeLabels[$u['role']] ?? $u['role']) ?></div>
      <div class="pf-chips">
        <span class="pf-chip"><?= $isStudent ? 'Student No. ' . e($u['student_number'] ?? '') : 'User ID: ' . e($acct['username'] ?? '') ?></span>
        <?php if ($deptName !== null): ?><span class="pf-chip"><?= e($deptName) ?></span><?php endif; ?>
        <?= pill($acct['status'] ?? 'Active') ?>
      </div>
    </div>
  </div>
</section>

<div class="pf-grid">

  <!-- Account details -->
  <section class="pf-card">
    <header class="pf-head">
      <span class="pf-icon"><?= $svgUser ?></span>
      <div>
        <h3>Account details</h3>
        <p>Names, IDs, and roles are managed by the System Administrator.</p>
      </div>
    </header>
    <div class="pf-body">
      <?php
        profile_row('Full name', $u['name']);
        profile_row($isStudent ? 'Student number' : 'User ID', $isStudent ? ($u['student_number'] ?? '') : ($acct['username'] ?? ''));
        profile_row('Role', $officeLabels[$u['role']] ?? $u['role']);
        if ($deptName !== null) profile_row($isStudent ? 'College' : 'Department', $deptName);
        if ($isStudent) {
            profile_row('Degree program', $stu['program'] ?? '');
            profile_row('Section', $stu['section'] ?? '');
        }
        profile_row('Account status', $acct['status'] ?? '');
      ?>
    </div>
  </section>

  <div class="pf-stack">

    <?php if ($isStudent): ?>
    <!-- Contact details (students) -->
    <form method="post" class="pf-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="contact">
      <header class="pf-head">
        <span class="pf-icon"><?= $svgMail ?></span>
        <div>
          <h3>Contact details</h3>
          <p>Used for notices about your requests and payments.</p>
        </div>
      </header>
      <div class="pf-body pf-form">
        <label for="pfEmail">Email address</label>
        <input id="pfEmail" class="pf-input" type="email" name="email" value="<?= e($stu['email'] ?? '') ?>" required maxlength="100">
        <label for="pfContact">Contact number</label>
        <input id="pfContact" class="pf-input" type="text" name="contact_number" value="<?= e($stu['contact_number'] ?? '') ?>" required maxlength="20">
        <button type="submit" class="btn btn-primary pf-btn">Save contact details</button>
      </div>
    </form>
    <?php endif; ?>

    <!-- Change password -->
    <form method="post" autocomplete="off" class="pf-card">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="password">
      <header class="pf-head">
        <span class="pf-icon"><?= $svgLock ?></span>
        <div>
          <h3>Change password</h3>
          <p>Use at least 8 characters that you do not use elsewhere.</p>
        </div>
      </header>
      <div class="pf-body pf-form">
        <label for="pfCur">Current password</label>
        <input id="pfCur" class="pf-input pf-pw" type="password" name="current_password" required autocomplete="current-password">
        <label for="pfNew">New password</label>
        <input id="pfNew" class="pf-input pf-pw" type="password" name="new_password" required minlength="8" autocomplete="new-password">
        <label for="pfCon">Confirm new password</label>
        <input id="pfCon" class="pf-input pf-pw" type="password" name="confirm_password" required minlength="8" autocomplete="new-password">
        <label class="pf-check"><input type="checkbox" id="pfShow"> Show passwords</label>
        <button type="submit" class="btn btn-primary pf-btn">Update password</button>
      </div>
    </form>

  </div>
</div>

<script>
document.getElementById('pfShow').addEventListener('change', function () {
  document.querySelectorAll('.pf-pw').forEach(function (i) { i.type = this.checked ? 'text' : 'password'; }, this);
});
</script>

<?php page_end(); ?>
