<?php
require __DIR__ . '/includes/bootstrap.php';
if ($u = user()) redirect(home_for($u['role']));

const MAX_FAILS = 5;         // failed attempts allowed per username...
const MAX_IP_FAILS = 15;     // failed attempts allowed per IP...
const WINDOW_MIN = 15;       // ...within this many minutes
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

    if ($name === '')      $err = 'Institutional User ID or Student Number is required.';
    elseif ($pass === '')  $err = 'Account Password is required.';
    else {
        $userFails = (int)row('SELECT COUNT(*) c FROM login_attempt WHERE username=? AND attempted_at > NOW() - INTERVAL ' . WINDOW_MIN . ' MINUTE', [$name])['c'];
        $ipFails = (int)row('SELECT COUNT(*) c FROM login_attempt WHERE ip_address=? AND attempted_at > NOW() - INTERVAL ' . WINDOW_MIN . ' MINUTE', [$ip])['c'];

        if ($userFails >= MAX_FAILS) {
            $err = 'Account temporarily throttled due to multiple failed attempts. Please retry in 15 minutes.';
        } elseif ($ipFails >= MAX_IP_FAILS) {
            $err = 'Access temporarily throttled from this network location. Please retry in 15 minutes.';
        } else {
            $acct = row('SELECT user_id,password_hash,status,must_change_password,role FROM user_account WHERE username=?', [$name]);
            // Always run password_verify so unknown IDs take the same time as wrong passwords.
            $ok = password_verify($pass, $acct['password_hash'] ?? '$2y$10$lQ442I6.I6Ryuhmt..z.YO7d746Xq6W7dgweR49DrL1aICung2zc.');
            if ($acct && $ok && $acct['status'] === 'Active') {
                q('DELETE FROM login_attempt WHERE username=? OR ip_address=?', [$name, $ip]);
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$acct['user_id'];
                $_SESSION['last_activity'] = time();
                $loggedUser = user(true);            // reload the user now that the session is set
                audit('Signed in to academic session');

                if (!empty($acct['must_change_password'])) {
                    flash('Initial login: You must set a new personal password before continuing.');
                    redirect('change_password.php');
                }
                redirect(home_for($loggedUser['role']));
            }
            q('INSERT INTO login_attempt(username, ip_address) VALUES(?, ?)', [$name, $ip]);
            audit('Failed authentication attempt', $name);
            $err = 'Invalid credentials. Please verify your User ID and password.';
        }
    }
}
public_start('Portal Authentication');
?>
<div style="text-align:center;margin-bottom:24px">
  <img src="<?= url('assets/logo-full.png') ?>" alt="CuyoTech University" style="height:56px;width:auto;max-width:100%;margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:700;color:var(--ink);margin:8px 0 4px">Portal Authentication</h1>
  <p style="font-size:12px;color:var(--ink-muted);margin:0">Student Services Integrated System (SSIS)</p>
</div>

<form method="post" novalidate style="display:flex;flex-direction:column;gap:16px">
  <?= csrf_field() ?>
  <div>
    <label for="u" class="form-label">User ID / Student Number</label>
    <input id="u" name="username" autocomplete="username" value="<?= e($_POST['username'] ?? '') ?>" placeholder="e.g. 2023-00418 or REG-001" class="form-control" required autofocus>
  </div>

  <div>
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:5px">
      <label for="p" class="form-label" style="margin:0">Password</label>
      <a href="<?= url('forgot.php') ?>" style="font-size:11px;font-weight:600;color:var(--primary)">Forgot password?</a>
    </div>
    <div style="position:relative">
      <input id="p" name="password" type="password" autocomplete="current-password" placeholder="Enter your password" class="form-control" style="padding-right:40px" required>
      <button type="button" onclick="togglePasswordVisibility()" title="Show/Hide password" style="position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:4px;display:flex;align-items:center">
        <svg id="eyeIcon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
      </button>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="flash-alert error" role="alert" style="margin-bottom:0">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <span><?= e($err) ?></span>
    </div>
  <?php endif; ?>

  <button type="submit" class="btn btn-primary" style="width:100%;padding:10px;font-size:13px;font-weight:600;margin-top:4px">
    Authenticate &amp; Sign In
  </button>
</form>

<div style="margin-top:24px;padding-top:16px;border-top:1px solid var(--border-color);text-align:center;font-size:12px;color:var(--ink-muted)">
  Need institutional account assistance? <br>
  <a href="<?= url('contact.php') ?>" style="color:var(--primary);font-weight:600">Contact University Registrar Support Desk →</a>
</div>

<script>
function togglePasswordVisibility() {
  const p = document.getElementById('p');
  const icon = document.getElementById('eyeIcon');
  if (p.type === 'password') {
    p.type = 'text';
    icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
  } else {
    p.type = 'password';
    icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
  }
}
</script>
<?php public_end(); ?>
