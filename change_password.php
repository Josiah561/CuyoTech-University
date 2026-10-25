<?php
require __DIR__ . '/includes/bootstrap.php';

$u = user();
if (!$u) {
    redirect('login.php');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $newPass = (string)($_POST['password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPass) < 8) {
        $err = 'Password must be at least 8 characters long.';
    } elseif ($newPass !== $confirmPass) {
        $err = 'Passwords do not match. Please re-enter.';
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        q("UPDATE user_account SET password_hash = ?, must_change_password = 0 WHERE user_id = ?", [$newHash, (int)$u['user_id']]);
        user(true);
        audit('Changed password (first login enforcement)');
        flash('Password successfully updated. Welcome to the portal!');
        redirect(home_for($u['role']));
    }
}

public_start('Change Password · First Login');
?>
<div style="text-align:center;margin-bottom:24px">
  <img src="<?= url('assets/logo-full.png') ?>" alt="CuyoTech University" style="height:56px;width:auto;max-width:100%;margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:700;color:var(--ink);margin:8px 0 4px">Password Change Required</h1>
  <p style="font-size:12px;color:var(--ink-muted);margin:0">For security compliance, admin-created accounts must establish a personal password on initial login.</p>
</div>

<?php if ($err): ?>
  <div class="flash-alert error" role="alert" style="margin-bottom:16px">
    <span><?= e($err) ?></span>
  </div>
<?php endif; ?>

<form method="post" novalidate style="display:flex;flex-direction:column;gap:16px">
  <?= csrf_field() ?>

  <div>
    <label class="form-label">Signed in as</label>
    <input type="text" value="<?= e($u['username']) ?> (<?= e(ROLE_LABELS[$u['role']] ?? $u['role']) ?>)" class="form-control" disabled style="background:#f1f5f9;color:#64748b">
  </div>

  <div>
    <label for="p1" class="form-label">New Password (Minimum 8 characters)</label>
    <input id="p1" name="password" type="password" minlength="8" placeholder="Create your new password" class="form-control" required autofocus>
  </div>

  <div>
    <label for="p2" class="form-label">Confirm New Password</label>
    <input id="p2" name="confirm_password" type="password" minlength="8" placeholder="Re-enter your new password" class="form-control" required>
  </div>

  <div class="notice-banner notice-blue" style="padding:10px 14px;font-size:11px">
    <strong>Password Policy:</strong> Choose a strong password containing at least 8 characters. Do not use your student ID or easily guessed phrases.
  </div>

  <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:13px;font-weight:600">
    Save Password &amp; Continue to Portal
  </button>
</form>

<div style="margin-top:20px;text-align:center;font-size:12px">
  <a href="<?= url('logout.php') ?>" style="color:#dc2626;font-weight:600">Sign Out</a>
</div>
<?php public_end(); ?>
