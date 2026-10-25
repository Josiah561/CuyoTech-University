<?php
require __DIR__ . '/includes/bootstrap.php';

$concerns = [
    'Account access'    => 'Account access',
    'Enrollment'        => 'Enrollment',
    'Grades'            => 'Grades',
    'Document requests' => 'Document requests'
];
$msg = '';
$bad = false;
$u = user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = [
        'name'    => trim($_POST['name'] ?? ''),
        'sid'     => trim($_POST['sid'] ?? ''),
        'email'   => trim($_POST['email'] ?? ''),
        'concern' => trim($_POST['concern'] ?? ''),
        'message' => trim($_POST['message'] ?? '')
    ];
    if ($f['name'] === '' || $f['message'] === '' || !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $msg = 'Please fill in your name, a valid university email, concern category and message.';
        $bad = true;
    } else {
        q('INSERT INTO inquiry(full_name,student_number,email,concern,message) VALUES(?,?,?,?,?)',
          [$f['name'], $f['sid'] ?: null, $f['email'], $f['concern'] ?: 'General inquiry', utf8_cut($f['message'], 2000)]);
        $msg = 'Your inquiry has been successfully transmitted. The Registrar\'s Office will reply to ' . $f['email'] . ' within 1 to 2 business days.';
    }
}

$defaultName = $u ? (!empty($u['first_name']) ? ($u['first_name'] . ' ' . $u['last_name']) : ($u['name'] ?? '')) : '';
$defaultSid = ($u && $u['role'] === 'student') ? ($u['student_number'] ?? '') : '';
$defaultEmail = $u ? ($u['email'] ?? '') : '';

public_start('Contact the Registrar\'s Office', true, true);
?>

<style>
.contact-grid-container {
  display: grid;
  grid-template-columns: 320px 1fr;
  background: #ffffff;
  border-radius: 16px;
  box-shadow: 0 4px 20px -2px rgba(0,0,0,0.06);
  border: 1px solid #e2e8f0;
  overflow: hidden;
}
@media (max-width: 820px) {
  .contact-grid-container {
    grid-template-columns: 1fr !important;
  }
}
</style>

<div style="max-width:1080px;margin:0 auto;padding-bottom:40px;padding-left:16px;padding-right:16px">
  <!-- Breadcrumb & Header Title -->
  <div style="font-size:11px;font-weight:700;color:#c7d6ff;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:6px">
    OFFICE OF THE UNIVERSITY REGISTRAR / STUDENT HELP DESK
  </div>
  <h1 style="font-size:26px;font-weight:800;color:#ffffff;margin:0 0 6px;letter-spacing:-0.02em;text-shadow:0 1px 8px rgba(8,14,70,0.45)">
    University Registrar Help Desk &amp; Inquiry Center
  </h1>
  <p style="font-size:13px;color:#e2e9ff;margin:0 0 24px">
    Official communications gateway for credential verification, enrollment issues, and academic records inquiries.
  </p>

  <?php if ($msg): ?>
    <div style="background:<?= $bad ? '#fef2f2' : '#f0fdf4' ?>;border:1px solid <?= $bad ? '#fecaca' : '#bbf7d0' ?>;color:<?= $bad ? '#dc2626' : '#166534' ?>;padding:14px 20px;border-radius:12px;font-size:14px;margin-bottom:24px;display:flex;align-items:center;gap:10px" role="alert">
      <?php if ($bad): ?>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      <?php else: ?>
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
      <?php endif; ?>
      <span><?= e($msg) ?></span>
    </div>
  <?php endif; ?>

  <!-- Main Card Container -->
  <div class="contact-grid-container">
    
    <!-- Left Column: How can we help? -->
    <div style="background:#f8fafc;padding:36px 32px;border-right:1px solid #e2e8f0;display:flex;flex-direction:column;justify-content:space-between">
      <div>
        <h2 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 6px">How can we help?</h2>
        <p style="font-size:13px;color:#64748b;line-height:1.5;margin:0 0 28px">
          Use the inquiry form to describe your concern to the Registrar's Office.
        </p>

        <!-- 4 Inquiry Categories -->
        <div style="display:flex;flex-direction:column;gap:10px">
          <!-- 1. Account access -->
          <div class="inquiry-cat-card" onclick="selectCategory('Account access', this)" title="Click to select this concern" style="display:flex;align-items:flex-start;gap:14px;padding:10px 12px;border-radius:10px;cursor:pointer;border:1.5px solid transparent;transition:all 0.15s">
            <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 2l-2 2m-1.5 1.5L16 7l-1.5-1.5M14 8l-1.5-1.5M11 11a5 5 0 1 0-7 7 5 5 0 0 0 7-7zm0 0l7-7"/></svg>
            </div>
            <div>
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px">Account access</div>
              <div style="font-size:12px;color:#64748b;line-height:1.4">Sign-in problems or access to your university email.</div>
            </div>
          </div>

          <!-- 2. Enrollment -->
          <div class="inquiry-cat-card" onclick="selectCategory('Enrollment', this)" title="Click to select this concern" style="display:flex;align-items:flex-start;gap:14px;padding:10px 12px;border-radius:10px;cursor:pointer;border:1.5px solid transparent;transition:all 0.15s">
            <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
            </div>
            <div>
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px">Enrollment</div>
              <div style="font-size:12px;color:#64748b;line-height:1.4">Questions about registration and enrollment records.</div>
            </div>
          </div>

          <!-- 3. Grades -->
          <div class="inquiry-cat-card" onclick="selectCategory('Grades', this)" title="Click to select this concern" style="display:flex;align-items:flex-start;gap:14px;padding:10px 12px;border-radius:10px;cursor:pointer;border:1.5px solid transparent;transition:all 0.15s">
            <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
            </div>
            <div>
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px">Grades</div>
              <div style="font-size:12px;color:#64748b;line-height:1.4">Missing grades or concerns about your academic record.</div>
            </div>
          </div>

          <!-- 4. Document requests -->
          <div class="inquiry-cat-card" onclick="selectCategory('Document requests', this)" title="Click to select this concern" style="display:flex;align-items:flex-start;gap:14px;padding:10px 12px;border-radius:10px;cursor:pointer;border:1.5px solid transparent;transition:all 0.15s">
            <div style="width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;flex-shrink:0">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            </div>
            <div>
              <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px">Document requests</div>
              <div style="font-size:12px;color:#64748b;line-height:1.4">Guidance on requesting university documents.</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Password Reset Footer Link -->
      <div style="border-top:1px solid #e2e8f0;padding-top:24px;margin-top:28px">
        <div style="font-size:13px;font-weight:700;color:#0f172a;margin-bottom:4px">Just need to reset your password?</div>
        <a href="<?= url('forgot.php') ?>" style="font-size:13px;font-weight:600;color:#2563eb;text-decoration:none;display:inline-flex;align-items:center;gap:4px">
          Go to Forgot password →
        </a>
      </div>
    </div>

    <!-- Right Column: Send an inquiry Form -->
    <div style="background:#ffffff;padding:36px 40px">
      <h2 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 4px">Send an inquiry</h2>
      <p style="font-size:13px;color:#64748b;margin:0 0 24px">
        Include your student details so your concern can be identified.
      </p>

      <form method="post" novalidate style="display:flex;flex-direction:column;gap:18px;margin:0">
        <?= csrf_field() ?>

        <!-- Full Name & Student ID Row -->
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
          <div>
            <label for="n" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px">Full name</label>
            <input id="n" name="name" value="<?= e($_POST['name'] ?? $defaultName) ?>" placeholder="Enter your full name" style="width:100%;border-radius:9999px;padding:11px 20px;border:1px solid #cbd5e1;font-size:14px;box-sizing:border-box" required>
          </div>
          <div>
            <label for="s" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px">Student ID</label>
            <input id="s" name="sid" value="<?= e($_POST['sid'] ?? $defaultSid) ?>" placeholder="Enter your student ID" style="width:100%;border-radius:9999px;padding:11px 20px;border:1px solid #cbd5e1;font-size:14px;box-sizing:border-box">
          </div>
        </div>

        <!-- University Email -->
        <div>
          <label for="m" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px">University email</label>
          <input id="m" name="email" type="email" value="<?= e($_POST['email'] ?? $defaultEmail) ?>" placeholder="Enter your university email" style="width:100%;border-radius:9999px;padding:11px 20px;border:1px solid #cbd5e1;font-size:14px;box-sizing:border-box" required>
        </div>

        <!-- Concern / Category -->
        <div>
          <label for="c" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px">Concern / category</label>
          <div style="position:relative">
            <select id="c" name="concern" style="width:100%;appearance:none;-webkit-appearance:none;border-radius:9999px;padding:11px 40px 11px 20px;border:1px solid #cbd5e1;font-size:14px;color:<?= empty($_POST['concern']) ? '#64748b' : '#0f172a' ?>;background:#ffffff;box-sizing:border-box;cursor:pointer" required onchange="this.style.color='#0f172a'">
              <option value="" disabled <?= empty($_POST['concern']) ? 'selected' : '' ?>>Select a category</option>
              <?php foreach ($concerns as $catKey => $catLabel): ?>
                <option value="<?= e($catKey) ?>" <?= (($_POST['concern'] ?? '') === $catKey) ? 'selected' : '' ?>><?= e($catLabel) ?></option>
              <?php endforeach; ?>
            </select>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute;right:18px;top:50%;transform:translateY(-50%);pointer-events:none"><polyline points="6 9 12 15 18 9"/></svg>
          </div>
        </div>

        <!-- Message -->
        <div>
          <label for="x" style="display:block;font-size:13px;font-weight:600;color:#0f172a;margin-bottom:6px">Message</label>
          <textarea id="x" name="message" placeholder="Describe your concern and any relevant details..." style="width:100%;border-radius:12px;padding:14px 20px;border:1px solid #cbd5e1;font-size:14px;box-sizing:border-box;font-family:inherit;min-height:150px;line-height:1.5;resize:vertical" required><?= e($_POST['message'] ?? '') ?></textarea>
        </div>

        <!-- Security Footnote -->
        <div style="display:flex;align-items:center;gap:8px;font-size:12px;color:#64748b;margin-top:2px">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="flex-shrink:0"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          <span>For your security, do not include passwords or sensitive payment details in your message.</span>
        </div>

        <!-- Submit Button Row (Aligned Right) -->
        <div style="display:flex;justify-content:flex-end;align-items:center;gap:12px;margin-top:8px">
          <button type="reset" onclick="resetContactForm()" style="border-radius:9999px;padding:12px 24px;font-size:14px;font-weight:600;background:#ffffff;color:#475569;border:1.5px solid #cbd5e1;cursor:pointer">
            Reset
          </button>
          <button type="submit" style="border-radius:9999px;padding:12px 36px;font-size:14px;font-weight:600;background:#2563eb;color:#ffffff;border:none;cursor:pointer;transition:background 0.15s">
            Send inquiry
          </button>
        </div>
      </form>
    </div>

  </div>
</div>

<script>
function selectCategory(cat, el) {
  const sel = document.getElementById('c');
  if (sel) {
    sel.value = cat;
    sel.style.color = '#0f172a';
  }
  document.querySelectorAll('.inquiry-cat-card').forEach(c => {
    c.style.background = 'transparent';
    c.style.borderColor = 'transparent';
    c.style.boxShadow = 'none';
  });
  if (el) {
    el.style.background = '#ffffff';
    el.style.borderColor = '#2563eb';
    el.style.boxShadow = '0 2px 6px rgba(37,99,235,0.1)';
  }
  const msgArea = document.getElementById('x');
  if (msgArea) {
    msgArea.focus();
  }
}

function resetContactForm() {
  document.querySelectorAll('.inquiry-cat-card').forEach(c => {
    c.style.background = 'transparent';
    c.style.borderColor = 'transparent';
    c.style.boxShadow = 'none';
  });
  const sel = document.getElementById('c');
  if (sel) sel.style.color = '#64748b';
}
</script>

<?php
public_end(true);
?>
