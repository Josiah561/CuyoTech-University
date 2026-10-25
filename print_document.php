<?php
require_once __DIR__ . '/includes/bootstrap.php';

$u = user();
if (!$u) {
    redirect('login.php');
}

$reqId = (int)($_GET['id'] ?? 0);
$doc = row("
    SELECT dr.*, s.first_name, s.last_name, s.student_number, s.program, s.section,
           d.department_name, u.full_name AS issuer_name
    FROM document_request dr
    JOIN student s ON s.student_id = dr.student_id
    LEFT JOIN department d ON d.department_id = s.department_id
    LEFT JOIN user_account u ON u.user_id = dr.issued_by
    WHERE dr.request_id = ?
", [$reqId]);

if (!$doc) {
    http_response_code(404);
    exit('Document request not found.');
}

// Ownership and role check
if ($u['role'] === 'student') {
    $stu = row("SELECT student_id FROM student WHERE user_id = ?", [$u['user_id']]);
    if (!$stu || (int)$stu['student_id'] !== (int)$doc['student_id']) {
        http_response_code(403);
        exit('Access denied: You can only view your own documents.');
    }
    if (!in_array($doc['request_status'], ['Ready', 'Released'], true)) {
        http_response_code(403);
        exit('Access denied: This document is currently ' . htmlspecialchars($doc['request_status']) . ' and cannot be viewed or printed until approved.');
    }
}

// Ensure verification code exists if Ready or Released
if (in_array($doc['request_status'], ['Ready', 'Released'], true) && empty($doc['verification_code'])) {
    $vCode = 'CTU-VER-' . strtoupper(substr(md5(uniqid((string)$reqId, true)), 0, 10)) . '-' . date('Y');
    q("UPDATE document_request SET verification_code = ?, issued_at = COALESCE(issued_at, NOW()) WHERE request_id = ?", [$vCode, $reqId]);
    $doc['verification_code'] = $vCode;
    $doc['issued_at'] = date('Y-m-d H:i:s');
}

$studentName = $doc['first_name'] . ' ' . $doc['last_name'];
$docType = $doc['document_type'];
$isTor = (stripos($docType, 'Transcript') !== false || stripos($docType, 'TOR') !== false);
$isCor = (stripos($docType, 'Registration') !== false || stripos($docType, 'Enrollment') !== false || stripos($docType, 'COR') !== false);

// Fetch enrolled subjects / grades for document body
$subjects = student_load((int)$doc['student_id'], $doc['program'], $doc['section']);
$totalUnits = array_sum(array_column($subjects, 'units'));

$gradedUnits = 0;
$gradedSum = 0;
foreach ($subjects as $sub) {
    $cg = current_grade($sub);
    if ($cg !== null) {
        $gradedUnits += (int)$sub['units'];
        $gradedSum += $cg * (int)$sub['units'];
    }
}
$docGwa = $gradedUnits > 0 ? number_format($gradedSum / $gradedUnits, 2) : '—';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($docType) ?> · <?= e($studentName) ?> · CuyoTech</title>
  <link rel="stylesheet" href="<?= url('assets/style.css') ?>">
  <style>
    @media print {
      .no-print { display: none !important; }
      body { background: #ffffff !important; padding: 0 !important; }
      .doc-sheet { box-shadow: none !important; border: none !important; width: 100% !important; max-width: 100% !important; margin: 0 !important; padding: 0 !important; }
    }
    body {
      background: #475569;
      margin: 0;
      padding: 30px 16px;
      font-family: "Times New Roman", Times, Georgia, serif;
      color: #000000;
      display: flex;
      flex-direction: column;
      align-items: center;
    }
    .doc-sheet {
      background: #ffffff;
      width: 100%;
      max-width: 820px;
      box-shadow: 0 10px 35px rgba(0,0,0,0.3);
      border-radius: 4px;
      padding: 48px 56px;
      box-sizing: border-box;
      position: relative;
    }
    .print-bar {
      width: 100%;
      max-width: 820px;
      margin-bottom: 16px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .doc-header-table {
      width: 100%;
      border-bottom: 2px solid #000000;
      padding-bottom: 12px;
      margin-bottom: 20px;
    }
    .doc-title {
      text-align: center;
      font-size: 16pt;
      font-weight: bold;
      letter-spacing: 0.08em;
      margin: 18px 0 6px;
      text-transform: uppercase;
    }
    .doc-subtitle {
      text-align: center;
      font-size: 10pt;
      font-style: italic;
      margin-bottom: 20px;
      color: #333333;
    }
    .meta-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 24px;
      font-size: 10pt;
      margin-bottom: 22px;
      border: 1px solid #000000;
      padding: 12px 16px;
      background: #fafafa;
    }
    .data-table-doc {
      width: 100%;
      border-collapse: collapse;
      font-size: 9.5pt;
      margin-bottom: 24px;
    }
    .data-table-doc th, .data-table-doc td {
      border: 1px solid #000000;
      padding: 6px 10px;
    }
    .data-table-doc th {
      background: #f1f5f9;
      font-weight: bold;
      text-align: left;
    }
    .security-box {
      border: 2px dashed #000000;
      padding: 14px 18px;
      margin-top: 24px;
      background: #fcfcfc;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .verify-link {
      font-family: monospace;
      font-weight: bold;
      color: #0f2b5c;
      font-size: 11pt;
    }
    .sig-row {
      display: flex;
      justify-content: space-between;
      margin-top: 36px;
      text-align: center;
    }
    .sig-col {
      width: 220px;
    }
    .sig-line {
      border-top: 1px solid #000000;
      margin-top: 40px;
      padding-top: 4px;
      font-weight: bold;
      font-size: 10pt;
    }
  </style>
</head>
<body>

<div class="print-bar no-print">
  <a href="javascript:history.back()" style="color:#ffffff;text-decoration:none;font-family:sans-serif;font-size:13px;display:flex;align-items:center;gap:6px">
    ← Return
  </a>
  <button onclick="window.print()" style="background:#2563eb;color:#ffffff;border:none;padding:8px 20px;border-radius:6px;font-weight:bold;cursor:pointer;font-family:sans-serif;font-size:13px;display:flex;align-items:center;gap:8px">
    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
    Print Official Document
  </button>
</div>

<div class="doc-sheet">
  <!-- Official Header -->
  <table class="doc-header-table">
    <tr>
      <td style="width:75px;vertical-align:middle;text-align:center">
        <?= cuyotech_seal_stamp() ?>
      </td>
      <td style="text-align:center;vertical-align:middle">
        <div style="font-size:10pt;letter-spacing:0.06em">REPUBLIC OF THE PHILIPPINES</div>
        <div style="font-size:15pt;font-weight:bold;letter-spacing:0.04em">CUYOTECH UNIVERSITY</div>
        <div style="font-size:9pt;color:#333333">City of Cabuyao, Laguna · Institutional Academic Registry</div>
        <div style="font-size:10pt;font-weight:bold;margin-top:4px">OFFICE OF THE UNIVERSITY REGISTRAR</div>
      </td>
      <td style="width:75px;vertical-align:middle;text-align:center">
        <div style="border:1px solid #999;width:65px;height:65px;display:inline-flex;align-items:center;justify-content:center;font-size:7pt;color:#666;text-align:center">
          OFFICIAL<br>REGISTRY<br>SEAL
        </div>
      </td>
    </tr>
  </table>

  <?php if ($isTor): ?>
    <!-- TRANSCRIPT OF RECORDS (TOR / OTR) -->
    <div class="doc-title">Official Transcript of Records</div>
    <div class="doc-subtitle">Certified Copy of Scholastic Academic Standing</div>

    <div class="meta-grid">
      <div><strong>Student Name:</strong> <?= e($studentName) ?></div>
      <div><strong>Student Number:</strong> <?= e($doc['student_number']) ?></div>
      <div><strong>Degree Program:</strong> <?= e($doc['program']) ?></div>
      <div><strong>Academic Year / Term:</strong> AY 2026–2027, 1st Sem</div>
      <div><strong>College / Division:</strong> <?= e($doc['department_name'] ?? 'College Division') ?></div>
      <div><strong>Date Issued:</strong> <?= date('F d, Y', strtotime($doc['issued_at'] ?? 'now')) ?></div>
    </div>

    <table class="data-table-doc">
      <thead>
        <tr>
          <th style="width:85px">Course Code</th>
          <th>Descriptive Title</th>
          <th style="width:50px;text-align:center">Units</th>
          <th style="width:65px;text-align:center">Prelim</th>
          <th style="width:65px;text-align:center">Midterm</th>
          <th style="width:65px;text-align:center">Final</th>
          <th style="width:80px;text-align:center">Remarks</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subjects as $s): 
            $cur = current_grade($s);
            $fin = $s['final_grade'] !== null ? number_format((float)$s['final_grade'], 2) : '—';
            $mid = $s['midterm'] !== null ? number_format((float)$s['midterm'], 2) : '—';
            $pre = $s['prelim'] !== null ? number_format((float)$s['prelim'], 2) : '—';
            $passed = ($cur !== null && $cur <= 3.0);
        ?>
          <tr>
            <td style="font-weight:bold"><?= e($s['code']) ?></td>
            <td><?= e($s['title']) ?></td>
            <td style="text-align:center"><?= (int)$s['units'] ?>.0</td>
            <td style="text-align:center"><?= $pre ?></td>
            <td style="text-align:center"><?= $mid ?></td>
            <td style="text-align:center;font-weight:bold"><?= $fin ?></td>
            <td style="text-align:center;font-weight:bold">
              <?= $s['final_grade'] !== null ? ($passed ? 'PASSED' : 'DEFICIENT') : 'IN PROGRESS' ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:bold;background:#fafafa">
          <td colspan="2" style="text-align:right">CUMULATIVE SEMESTRAL SUMMARY:</td>
          <td style="text-align:center"><?= $totalUnits ?>.0</td>
          <td colspan="4" style="text-align:center">GENERAL WEIGHTED AVERAGE (GWA): <?= $docGwa ?></td>
        </tr>
      </tfoot>
    </table>

  <?php else: ?>
    <!-- CERTIFICATE OF REGISTRATION & MATRICULATION (COR) -->
    <div class="doc-title"><?= e($docType) ?></div>
    <div class="doc-subtitle">Official Certificate of Matriculation &amp; Study Load</div>

    <div class="meta-grid">
      <div><strong>Student Name:</strong> <?= e($studentName) ?></div>
      <div><strong>Student Number:</strong> <?= e($doc['student_number']) ?></div>
      <div><strong>Degree Program:</strong> <?= e($doc['program']) ?></div>
      <div><strong>Section / Year:</strong> <?= e($doc['section'] ?: 'TBA') ?></div>
      <div><strong>Academic Term:</strong> AY 2026–2027, 1st Semester</div>
      <div><strong>Registration Status:</strong> Officially Enrolled</div>
    </div>

    <table class="data-table-doc">
      <thead>
        <tr>
          <th style="width:90px">Course Code</th>
          <th>Course Descriptive Title</th>
          <th style="width:170px">Class Schedule</th>
          <th style="width:110px">Room / Lab</th>
          <th style="width:50px;text-align:center">Units</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($subjects as $s): ?>
          <tr>
            <td style="font-weight:bold"><?= e($s['code']) ?></td>
            <td><?= e($s['title']) ?></td>
            <td style="font-size:8.5pt"><?= e($s['schedule']) ?></td>
            <td style="font-size:8.5pt"><?= e($s['room']) ?></td>
            <td style="text-align:center;font-weight:bold"><?= (int)$s['units'] ?>.0</td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr style="font-weight:bold;background:#fafafa">
          <td colspan="4" style="text-align:right">TOTAL REGISTERED ACADEMIC LOAD:</td>
          <td style="text-align:center"><?= $totalUnits ?>.0</td>
        </tr>
      </tfoot>
    </table>
  <?php endif; ?>

  <!-- Document Security & Online Verification Seal -->
  <div class="security-box">
    <div>
      <div style="font-size:8.5pt;font-weight:bold;text-transform:uppercase;letter-spacing:0.05em">
        Official University Verification Security Code
      </div>
      <div class="verify-link"><?= e($doc['verification_code']) ?></div>
      <div style="font-size:8pt;color:#444444;margin-top:2px">
        Verify online authenticity at: <strong><?= url('verify.php?code=' . urlencode($doc['verification_code'])) ?></strong>
      </div>
    </div>
    <div style="text-align:right;font-size:8pt;color:#333333">
      Issued by: <strong><?= e($doc['issuer_name'] ?: 'Office of the Registrar') ?></strong><br>
      Timestamp: <?= date('d M Y · H:i:s', strtotime($doc['issued_at'] ?? 'now')) ?>
    </div>
  </div>

  <!-- Institutional Signatures Block -->
  <div class="sig-row">
    <div class="sig-col">
      <div class="sig-line">Prepared &amp; Verified By</div>
      <div style="font-size:8.5pt;color:#444">Records Evaluator</div>
    </div>
    <div class="sig-col" style="display:flex;align-items:center;justify-content:center">
      <?= cuyotech_seal_stamp() ?>
    </div>
    <div class="sig-col">
      <div class="sig-line">Dr. Edgardo M. Santos</div>
      <div style="font-size:8.5pt;color:#444">University Registrar</div>
    </div>
  </div>

  <div style="font-size:7pt;color:#777777;text-align:center;margin-top:28px">
    Any alteration, erasure, or absence of the official university seal renders this credential null and void.
    CuyoTech University Academic Registry · Generated via SSIS
  </div>
</div>

</body>
</html>
