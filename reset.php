<?php
require __DIR__ . '/includes/bootstrap.php';

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$err = '';
$tokenValid = false;
$resetRecord = null;

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $resetRecord = row("
        SELECT pr.*, u.username, u.role
        FROM password_reset pr
        JOIN user_account u ON u.user_id = pr.user_id
        WHERE pr.token_hash = ? AND pr.used_at IS NULL AND pr.expires_at > NOW()
    ", [$tokenHash]);

    if ($resetRecord) {
        $tokenValid = true;
    } else {
        $err = 'This password reset link is invalid, has expired (60-minute limit), or has already been used. Please request a new link.';
    }
} else {
    $err = 'No recovery token provided. Please initiate credential recovery from the forgot password page.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $tokenValid) {
    csrf_check();
    $newPass = (string)($_POST['password'] ?? '');
    $confirmPass = (string)($_POST['confirm_password'] ?? '');

    if (strlen($newPass) < 8) {
        $err = 'Password must be at least 8 characters long.';
    } elseif ($newPass !== $confirmPass) {
        $err = 'Passwords do not match. Please re-enter.';
    } else {
        $newHash = password_hash($newPass, PASSWORD_DEFAULT);
        $uid = (int)$resetRecord['user_id'];

        // Update password and clear must_change_password
        q("UPDATE user_account SET password_hash = ?, must_change_password = 0 WHERE user_id = ?", [$newHash, $uid]);

        // Mark single-use token as used
        q("UPDATE password_reset SET used_at = NOW() WHERE reset_id = ?", [(int)$resetRecord['reset_id']]);

        // Clear any login attempt throttles for this user
        q("DELETE FROM login_attempt WHERE username = ?", [$resetRecord['username']]);

        audit('Password reset successfully completed via token', $resetRecord['username']);
        flash('Password successfully reset. You may now sign in with your new password.');
        redirect('login.php');
    }
}

public_start('Reset Password');
?>
<div style="text-align:center;margin-bottom:24px">
  <img src="<?= url('assets/logo-full.png') ?>" alt="CuyoTech University" style="height:56px;width:auto;max-width:100%;margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:700;color:var(--ink);margin:8px 0 4px">Set New Password</h1>
  <p style="font-size:12px;color:var(--ink-muted);margin:0">Create a secure password of 8 or more characters.</p>
</div>

<?php if ($err && !$tokenValid): ?>
  <div class="flash-alert error" role="alert" style="margin-bottom:16px">
    <span><?= e($err) ?></span>
  </div>
  <div style="text-align:center;margin-top:20px">
    <a href="<?= url('forgot.php') ?>" class="btn btn-primary" style="display:inline-block;padding:10px 20px;text-decoration:none">
      Request New Reset Link
    </a>
  </div>
<?php else: ?>
  <?php if ($err): ?>
    <div class="flash-alert error" role="alert" style="margin-bottom:16px">
      <span><?= e($err) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" novalidate style="display:flex;flex-direction:column;gap:16px">
    <?= csrf_field() ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">

    <div>
      <label class="form-label">Account User ID</label>
      <input type="text" value="<?= e($resetRecord['username']) ?>" class="form-control" disabled style="background:#f1f5f9;color:#64748b">
    </div>

    <div>
      <label for="p1" class="form-label">New Password (8+ characters)</label>
      <input id="p1" name="password" type="password" minlength="8" placeholder="Enter new password" class="form-control" required autofocus>
    </div>

    <div>
      <label for="p2" class="form-label">Confirm New Password</label>
      <input id="p2" name="confirm_password" type="password" minlength="8" placeholder="Confirm new password" class="form-control" required>
    </div>

    <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:13px;font-weight:600;margin-top:6px">
      Update Account Password
    </button>
  </form>
<?php endif; ?>

<div style="margin-top:20px;text-align:center;font-size:12px">
  <a href="<?= url('login.php') ?>" style="color:var(--primary);font-weight:600">← Return to Sign In</a>
</div>
<?php public_end(); ?>
