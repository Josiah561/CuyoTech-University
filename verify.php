<?php
require_once __DIR__ . '/includes/bootstrap.php';

$code = trim((string)($_GET['code'] ?? ''));
$doc = null;
$searched = false;

if ($code !== '') {
    $searched = true;
    $doc = row("
        SELECT dr.*, s.student_number, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.program,
               d.department_name, u.full_name AS issuer_name
        FROM document_request dr
        JOIN student s ON s.student_id = dr.student_id
        LEFT JOIN department d ON d.department_id = s.department_id
        LEFT JOIN user_account u ON u.user_id = dr.issued_by
        WHERE dr.verification_code = ? AND dr.request_status IN ('Ready', 'Released')
    ", [$code]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Official Document Verification · CuyoTech University</title>
  <link rel="stylesheet" href="<?= url('assets/style.css') ?>">
  <style>
    body {
      background: #f1f5f9;
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
      color: #0f172a;
      margin: 0;
      padding: 40px 16px;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-height: 100vh;
      box-sizing: border-box;
    }
    .verify-box {
      width: 100%;
      max-width: 680px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 12px;
      box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08);
      overflow: hidden;
    }
    .verify-header {
      background: #0f2b5c;
      color: #ffffff;
      padding: 24px 28px;
      text-align: center;
    }
    .verify-header h1 {
      font-size: 18px;
      font-weight: 800;
      letter-spacing: 0.05em;
      margin: 8px 0 2px;
      text-transform: uppercase;
    }
    .verify-header p {
      font-size: 12px;
      color: #93c5fd;
      margin: 0;
    }
    .verify-content {
      padding: 32px 28px;
    }
    .verify-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 6px 14px;
      border-radius: 9999px;
      font-weight: 700;
      font-size: 13px;
    }
    .badge-valid {
      background: #dcfce7;
      color: #166534;
      border: 1px solid #86efac;
    }
    .badge-invalid {
      background: #fee2e2;
      color: #991b1b;
      border: 1px solid #fca5a5;
    }
    .meta-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 20px;
      font-size: 13.5px;
    }
    .meta-table td {
      padding: 10px 12px;
      border-bottom: 1px solid #f1f5f9;
    }
    .meta-table td:first-child {
      font-weight: 600;
      color: #64748b;
      width: 35%;
    }
    .meta-table td:last-child {
      color: #0f172a;
      font-weight: 600;
    }
  </style>
</head>
<body>

<div class="verify-box">
  <div class="verify-header">
    <div style="font-size:11px;font-weight:700;letter-spacing:0.1em;color:#93c5fd;text-transform:uppercase">
      Republic of the Philippines · Office of the University Registrar
    </div>
    <h1>CuyoTech University</h1>
    <p>Public Academic Credential &amp; Document Verification Registry</p>
  </div>

  <div class="verify-content">
    <?php if (!$searched): ?>
      <div style="text-align:center;padding:20px 0">
        <h2 style="font-size:18px;font-weight:700;margin:0 0 8px;color:#0f172a">Verify Academic Credential</h2>
        <p style="font-size:13px;color:#64748b;margin:0 0 24px;max-width:440px;margin-left:auto;margin-right:auto">
          Enter the unique Verification Code printed on the official CuyoTech University document to validate its institutional authenticity.
        </p>

        <form method="get" action="<?= url('verify.php') ?>" style="display:flex;gap:8px;max-width:440px;margin:0 auto">
          <input type="text" name="code" placeholder="e.g. CTU-VER-1A2B3C-2026" required
                 style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;text-transform:uppercase">
          <button type="submit" class="btn btn-primary" style="padding:10px 20px;font-weight:600;border-radius:6px">Verify</button>
        </form>
      </div>

    <?php elseif ($doc): ?>
      <div style="text-align:center;margin-bottom:24px">
        <div class="verify-badge badge-valid">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
          OFFICIALLY AUTHENTICATED DOCUMENT
        </div>
        <p style="font-size:13px;color:#64748b;margin:8px 0 0">
          This document is genuine, officially signed, and registered in the CuyoTech University Academic Registry.
        </p>
      </div>

      <table class="meta-table">
        <tr>
          <td>Document Type</td>
          <td><?= e($doc['document_type']) ?></td>
        </tr>
        <tr>
          <td>Verification Code</td>
          <td style="font-family:monospace;color:#1e40af;font-size:14px"><?= e($doc['verification_code']) ?></td>
        </tr>
        <tr>
          <td>Issued To Student</td>
          <td><?= e($doc['student_name']) ?></td>
        </tr>
        <tr>
          <td>Student Number</td>
          <td><?= e($doc['student_number']) ?></td>
        </tr>
        <tr>
          <td>Degree Program</td>
          <td><?= e($doc['program']) ?></td>
        </tr>
        <tr>
          <td>College / Department</td>
          <td><?= e($doc['department_name'] ?? 'College Academic Division') ?></td>
        </tr>
        <tr>
          <td>Date Issued</td>
          <td><?= date('F d, Y', strtotime($doc['issued_at'] ?? $doc['release_date'] ?? $doc['request_date'])) ?></td>
        </tr>
        <tr>
          <td>Issuing Authority</td>
          <td><?= e($doc['issuer_name'] ?: 'Office of the University Registrar') ?></td>
        </tr>
        <tr>
          <td>Issuance Status</td>
          <td><span style="color:#16a34a;font-weight:700">✓ <?= e($doc['request_status']) ?></span></td>
        </tr>
      </table>

      <div style="margin-top:24px;padding:12px 16px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;font-size:11.5px;color:#64748b;line-height:1.5">
        <strong>Privacy Notice:</strong> In accordance with Republic Act 10173 (Data Privacy Act of 2012) and university academic records policy, specific course grades and evaluative marks are not displayed publicly. For transcript requests or verification of detailed academic records, contact the Office of the University Registrar.
      </div>

      <div style="text-align:center;margin-top:24px">
        <a href="<?= url('verify.php') ?>" style="font-size:13px;color:#2563eb;text-decoration:none;font-weight:600">← Verify Another Document</a>
      </div>

    <?php else: ?>
      <div style="text-align:center;padding:16px 0">
        <div class="verify-badge badge-invalid">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
          DOCUMENT RECORD NOT FOUND
        </div>
        <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:16px 0 6px">Verification Failed</h3>
        <p style="font-size:13px;color:#64748b;margin:0 0 20px;line-height:1.5">
          No official academic document matching code <strong style="font-family:monospace;color:#dc2626"><?= e($code) ?></strong> was found in the University Registry.
          Please verify that you typed the complete code accurately.
        </p>

        <form method="get" action="<?= url('verify.php') ?>" style="display:flex;gap:8px;max-width:440px;margin:0 auto">
          <input type="text" name="code" value="<?= e($code) ?>" required
                 style="flex:1;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;text-transform:uppercase">
          <button type="submit" class="btn btn-primary" style="padding:10px 20px;font-weight:600;border-radius:6px">Re-verify</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<div style="margin-top:20px;font-size:11.5px;color:#64748b;text-align:center">
  CuyoTech University · Academic Records &amp; Information Services · <?= date('Y') ?>
</div>

</body>
</html>
