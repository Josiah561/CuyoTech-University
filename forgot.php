<?php
require __DIR__ . '/includes/bootstrap.php';
$msg = ''; 
$bad = false;
$resetLink = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ident = trim((string)($_POST['email'] ?? ''));
    if ($ident === '') { 
        $msg = 'Institutional email address or User ID is required.'; 
        $bad = true; 
    } else {
        // Look up by student email or student number or staff username
        $userAcct = row("
            SELECT u.user_id, u.username, u.role, u.status, s.email, s.first_name, s.last_name
            FROM user_account u
            LEFT JOIN student s ON s.user_id = u.user_id
            WHERE s.email = ? OR u.username = ?
            LIMIT 1
        ", [$ident, $ident]);

        if ($userAcct && $userAcct['status'] === 'Active') {
            $rawToken = bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', $rawToken);
            $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 60 minutes

            // Invalidate any older unused tokens for this user
            q("UPDATE password_reset SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL", [$userAcct['user_id']]);

            // Save new hashed single-use token
            q("INSERT INTO password_reset (user_id, token_hash, expires_at) VALUES (?, ?, ?)",
              [$userAcct['user_id'], $tokenHash, $expiresAt]);

            audit('Password recovery requested for user #' . $userAcct['user_id'], $userAcct['username']);
            
            // Build reset link
            $resetLink = url('reset.php?token=' . $rawToken);
            $msg = 'Password recovery link generated successfully. Token is valid for 60 minutes.';
        } else {
            // Neutral message to prevent account enumeration
            audit('Password recovery attempted for non-existent target: ' . $ident);
            $msg = 'If the provided account or institutional email is registered, recovery instructions have been prepared.';
        }
    }
}
public_start('Account Credential Recovery');
?>
<div style="text-align:center;margin-bottom:24px">
  <img src="<?= url('assets/logo-full.png') ?>" alt="CuyoTech University" style="height:56px;width:auto;max-width:100%;margin-bottom:14px">
  <h1 style="font-size:20px;font-weight:700;color:var(--ink);margin:8px 0 4px">Credential Recovery</h1>
  <p style="font-size:12px;color:var(--ink-muted);margin:0">Enter your registered university email or User ID to reset your password.</p>
</div>

<form method="post" novalidate style="display:flex;flex-direction:column;gap:14px">
  <?= csrf_field() ?>
  <div>
    <label for="e" class="form-label">Registered Institutional Email / User ID</label>
    <input id="e" name="email" type="text" placeholder="name@cuyotech.edu.ph or 2023-00418" class="form-control" required autofocus>
  </div>

  <?php if ($msg): ?>
    <div class="flash-alert <?= $bad ? 'error' : 'success' ?>" role="alert">
      <span><?= e($msg) ?></span>
    </div>
  <?php endif; ?>

  <?php if ($resetLink): ?>
    <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 16px;margin-top:6px">
      <div style="font-size:12px;font-weight:700;color:#1e40af;margin-bottom:4px">
        LOCAL ENVIRONMENT RESET LINK (No SMTP configured)
      </div>
      <div style="font-size:11.5px;color:#334155;margin-bottom:8px">
        Click the single-use reset link below to proceed with setting a new password (expires in 60 minutes):
      </div>
      <a href="<?= e($resetLink) ?>" style="display:inline-block;word-break:break-all;font-size:12px;font-weight:700;color:#2563eb;text-decoration:underline">
        <?= e($resetLink) ?>
      </a>
    </div>
  <?php endif; ?>

  <div class="notice-banner notice-blue" style="padding:10px 14px;font-size:11px;margin-bottom:4px">
    <strong>Security Policy:</strong> Single-use credential recovery tokens are securely hashed and automatically expire after 60 minutes.
  </div>

  <button type="submit" class="btn btn-primary" style="width:100%;padding:10px">
    Generate Password Reset Link
  </button>
</form>

<div style="margin-top:20px;text-align:center;font-size:12px">
  <a href="<?= url('login.php') ?>" style="color:var(--primary);font-weight:600">← Return to Sign In</a>
</div>

<div style="margin-top:16px;padding-top:14px;border-top:1px solid var(--border-color);text-align:center;font-size:11px;color:var(--ink-muted)">
  Need institutional account assistance? <br>
  <a href="<?= url('contact.php') ?>" style="color:var(--primary);font-weight:600">Contact University Registrar Support Desk</a>
</div>
<?php public_end(); ?>
