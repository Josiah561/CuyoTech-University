<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('student');

$s = row('SELECT s.*, d.department_name, d.department_code FROM student s JOIN department d ON d.department_id=s.department_id WHERE s.user_id=?', [$u['user_id']]);
if (!$s) {
    page_start('portal', 'Student Academic Portal');
    echo '<p class="sub" style="padding:24px;text-align:center">No active student academic record is linked to this account. Please report to the Office of the University Registrar.</p>';
    page_end();
    exit;
}
$id = (int)$s['student_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'enroll') {
        if (!row("SELECT 1 FROM enrollment WHERE student_id=? AND enrollment_status IN ('Pending review', 'Enrolled')", [$id])) {
            q("INSERT INTO enrollment(student_id,school_year,semester,enrollment_date,enrollment_status) VALUES(?,?,?,CURDATE(),'Pending review')", [$id, '2026-2027', '1st']);
            audit('Submitted formal matriculation application for AY 2026-2027, 1st Semester');
            flash('Matriculation application submitted successfully. The Office of the University Registrar will evaluate your curriculum load.');
        } else {
            flash('You already have an active or pending enrollment application for this academic term.', true);
        }
    }
    redirect('student/index.php?tab=en');
}

$TABS = [
    'ov' => 'Curriculum Load & Grades',
    'en' => 'Matriculation & COR',
    'cl' => 'University Clearance Audit',
    'pf' => 'Permanent Record'
];
$tab = pick_tab($TABS);
sidebar_tabs('student/index.php', $TABS, $tab);
$last = row('SELECT enrollment_id, enrollment_status, school_year, semester, enrollment_date FROM enrollment WHERE student_id=? ORDER BY enrollment_id DESC LIMIT 1', [$id]);
$initials = first_char($s['first_name']) . first_char($s['last_name']);
$enrollStatus = $last['enrollment_status'] ?? 'Not enrolled';
$labFilename = 'lab-return-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $s['last_name'])) . '.pdf';

// Calculate Academic Standing Metrics
$g = student_load($id, $s['program'], $s['section']);   // only this student's own program + year level
$totalUnits = array_sum(array_column($g, 'units'));
$gradedUnits = 0;
$gradedSum = 0;
foreach ($g as $r) {
    if (empty($r['is_published'])) continue;   // Drafts are hidden from the student portal until published
    $cg = current_grade($r);   // Final if encoded, otherwise Midterm, otherwise Prelim
    if ($cg !== null) {
        $gradedUnits += (int)$r['units'];
        $gradedSum += $cg * (int)$r['units'];
    }
}
$gwa = ($gradedUnits > 0) ? number_format($gradedSum / $gradedUnits, 2) : '—';

page_start(
    'portal',
    'Student Academic Portal',
    'Official academic standing, enrollment matriculation, grades evaluation, and institutional clearances.',
    'STUDENT SERVICES / ACADEMIC RECORDS'
);
?>

<!-- Institutional Academic Standing Header -->
<div class="student-portal-card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 4px 20px rgba(15,23,42,0.06);margin-bottom:24px;overflow:hidden">
  <div class="student-portal-header-bar no-print" style="padding:22px 28px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:16px">
    <div style="display:flex;align-items:center;gap:16px">
      <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg, #0f2b5c 0%, #1e40af 100%);color:#ffffff;font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(15,43,92,0.22);border:2px solid #ffffff">
        <?= e($initials) ?>
      </div>
      <div>
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;flex-wrap:wrap">
          <h2 style="font-size:20px;font-weight:800;color:#0f172a;margin:0;letter-spacing:-0.02em">
            <?= e($s['first_name'] . ' ' . $s['last_name']) ?>
          </h2>
          <span style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;font-weight:700;padding:2px 10px;border-radius:9999px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">
            <span style="width:6px;height:6px;border-radius:50%;background:#10b981"></span>
            <?= e($enrollStatus) ?>
          </span>
        </div>
        <div style="font-size:12.5px;color:#64748b;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-weight:600;color:#0f172a">ID: <?= e($s['student_number']) ?></span>
          <span style="color:#cbd5e1">·</span>
          <span><?= e($s['program']) ?></span>
          <span style="color:#cbd5e1">·</span>
          <span style="background:#f1f5f9;color:#334155;padding:1px 8px;border-radius:4px;font-weight:600;font-size:11px">Section <?= e($s['section'] ?: 'TBA') ?></span>
          <span style="color:#cbd5e1">·</span>
          <span style="color:#64748b"><?= e($s['department_name']) ?></span>
        </div>
      </div>
    </div>
    
    <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
      <div style="display:inline-flex;align-items:center;gap:6px;font-size:12px;color:#475569;background:#f8fafc;padding:6px 14px;border:1px solid #e2e8f0;border-radius:8px;font-weight:600">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#2563eb" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
        AY 2026–2027, 1st Semester
      </div>
      <?php if ($enrollStatus === 'Enrolled'): ?>
        <a href="<?= url('student/index.php?tab=en') ?>" style="display:inline-flex;align-items:center;gap:6px;font-size:12.5px;font-weight:600;padding:7px 16px;border-radius:8px;background:#ffffff;border:1px solid #cbd5e1;color:#1e40af;text-decoration:none;box-shadow:0 1px 2px rgba(0,0,0,0.04);transition:all 0.15s">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Official COR
        </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Key Academic Metrics Strip - Clean Modern Executive KPI Cards -->
  <div class="academic-standing-strip no-print" style="display:grid;grid-template-columns:repeat(4,1fr);background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:16px 28px;gap:16px">
    <!-- KPI 1 -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <span style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em">ACADEMIC STANDING</span>
        <span style="width:22px;height:22px;border-radius:6px;background:#dcfce7;color:#16a34a;display:flex;align-items:center;justify-content:center">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><polyline points="20 6 9 17 4 12"/></svg>
        </span>
      </div>
      <div style="font-size:16px;font-weight:800;color:#15803d;letter-spacing:-0.01em">
        Good Standing
      </div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Regular Student · Dean's List</div>
    </div>

    <!-- KPI 2 -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <span style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em">SEMESTRAL GWA</span>
        <span style="width:22px;height:22px;border-radius:6px;background:#dbeafe;color:#2563eb;display:flex;align-items:center;justify-content:center">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
        </span>
      </div>
      <div style="font-size:18px;font-weight:800;color:#1e40af;letter-spacing:-0.02em">
        <?= $gwa ?>
      </div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Current Weighted Average</div>
    </div>

    <!-- KPI 3 -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <span style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em">TOTAL UNITS ENROLLED</span>
        <span style="width:22px;height:22px;border-radius:6px;background:#f3e8ff;color:#9333ea;display:flex;align-items:center;justify-content:center">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
        </span>
      </div>
      <div style="font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-0.02em">
        <?= $totalUnits ?>.0 Units
      </div>
      <div style="font-size:11px;color:#64748b;margin-top:2px">Full Academic Load Assessed</div>
    </div>

    <!-- KPI 4 -->
    <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 16px;box-shadow:0 1px 3px rgba(0,0,0,0.03)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px">
        <span style="font-size:10.5px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.06em">CURRICULUM LEVEL</span>
        <span style="width:22px;height:22px;border-radius:6px;background:#fef3c7;color:#d97706;display:flex;align-items:center;justify-content:center">
          <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>
        </span>
      </div>
      <div style="font-size:18px;font-weight:800;color:#0f172a;letter-spacing:-0.02em">
        <?= e(year_label($s['section'])) ?>
      </div>
      <div style="font-size:11px;color:#64748b;margin-top:2px"><?= e([1 => 'Freshman', 2 => 'Sophomore', 3 => 'Junior', 4 => 'Senior'][section_year($s['section'])] ?? 'Level TBA') ?> · AY 2026–2027</div>
    </div>
  </div>

  <!-- Tab Content Body -->
  <div style="padding:24px 28px">

  <?php if ($tab === 'ov'): ?>
    <!-- ============================================================== -->
    <!-- TAB 1: CURRICULUM LOAD & GRADES EVALUATION                    -->
    <!-- ============================================================== -->
    <!-- Screen Interactive Table -->
    <div class="no-print">
      <div style="display:flex;align-items:flex-end;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px">
        <div>
          <h3 style="font-size:17px;font-weight:700;color:#0f172a;margin:0 0 3px">Semestral Course Load &amp; Grade Evaluation</h3>
          <p style="font-size:12.5px;color:#64748b;margin:0">Official evaluation sheet for <?= e($s['program']) ?> · Section <?= e($s['section'] ?: 'TBA') ?> · AY 2026–2027, 1st Semester</p>
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <button type="button" onclick="window.print()" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Grade Evaluation Slip
          </button>
        </div>
      </div>

      <?php if (!$g): ?>
        <div class="notice-banner notice-amber" style="margin-bottom:14px">
          <strong>No course load yet:</strong> there is no curriculum encoded for <?= e($s['program']) ?><?= section_year($s['section']) ? ', ' . e(year_label($s['section'])) : '' ?>. Please contact the Office of the University Registrar.
        </div>
      <?php endif; ?>

      <!-- Modern Interactive Table -->
      <div class="table-container" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);margin-bottom:18px">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0">
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">COURSE CODE</th>
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">DESCRIPTIVE COURSE TITLE</th>
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">CLASS SCHEDULE &amp; VENUE</th>
              <th style="padding:12px 18px;text-align:center;width:60px;font-weight:700;font-size:11px;letter-spacing:0.04em">UNITS</th>
              <th style="padding:12px 18px;text-align:center;width:80px;font-weight:700;font-size:11px;letter-spacing:0.04em">PRELIM</th>
              <th style="padding:12px 18px;text-align:center;width:80px;font-weight:700;font-size:11px;letter-spacing:0.04em">MIDTERM</th>
              <th style="padding:12px 18px;text-align:center;width:80px;font-weight:700;font-size:11px;letter-spacing:0.04em">FINAL</th>
              <th style="padding:12px 18px;text-align:center;width:100px;font-weight:700;font-size:11px;letter-spacing:0.04em">STATUS</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g as $r): 
                $isPub = !empty($r['is_published']);
                $prelimVal = $isPub ? $r['prelim'] : null;
                $midtermVal = $isPub ? $r['midterm'] : null;
                $finalVal = $isPub ? $r['final_grade'] : null;

                $hasPrelim = ($prelimVal !== null);
                $hasMidterm = ($midtermVal !== null);
                $hasFinal = ($finalVal !== null);

                // On the student portal, show "In progress" until the Final grade is in
                $isPassing = ($hasFinal && (float)$finalVal <= 3.0);
            ?>
              <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.12s">
                <td style="padding:14px 18px">
                  <span style="display:inline-block;padding:2px 8px;border-radius:6px;background:#f1f5f9;color:#0f172a;font-weight:700;font-size:12px;font-family:monospace">
                    <?= e($r['code']) ?>
                  </span>
                </td>
                <td style="padding:14px 18px;font-weight:600;color:#0f172a">
                  <?= e($r['title']) ?>
                </td>
                <td style="padding:14px 18px;font-size:12px;color:#64748b">
                  <?= e($r['schedule']) ?> <span style="color:#94a3b8">(<?= e($r['room']) ?>)</span>
                </td>
                <td style="padding:14px 18px;text-align:center;font-weight:700;color:#0f172a">
                  <?= (int)$r['units'] ?>.0
                </td>
                <td style="padding:14px 18px;text-align:center">
                  <?php if ($hasPrelim): ?>
                    <span style="font-size:14px;font-weight:800;color:#1e40af"><?= number_format((float)$prelimVal, 2) ?></span>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-weight:500">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:14px 18px;text-align:center">
                  <?php if ($hasMidterm): ?>
                    <span style="font-size:14px;font-weight:800;color:#1e40af">
                      <?= number_format((float)$midtermVal, 2) ?>
                    </span>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-weight:500">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:14px 18px;text-align:center">
                  <?php if ($hasFinal): ?>
                    <span style="font-size:14px;font-weight:800;color:#1e40af"><?= number_format((float)$finalVal, 2) ?></span>
                  <?php else: ?>
                    <span style="color:#94a3b8;font-weight:500">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:14px 18px;text-align:center">
                  <?php if ($hasFinal): ?>
                    <span style="display:inline-flex;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:700;background:<?= $isPassing ? '#ecfdf5' : '#fef2f2' ?>;color:<?= $isPassing ? '#065f46' : '#991b1b' ?>">
                      <?= $isPassing ? 'PASSED' : 'DEFICIENT' ?>
                    </span>
                  <?php else: ?>
                    <span style="display:inline-flex;padding:3px 10px;border-radius:9999px;font-size:11px;font-weight:600;background:#f1f5f9;color:#64748b">
                      IN PROGRESS
                    </span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
              <td colspan="3" style="padding:12px 18px;text-align:right;font-size:12px;color:#475569">TOTAL UNITS COMPLETED / ASSESSED:</td>
              <td style="padding:12px 18px;text-align:center;font-size:13px;color:#0f172a"><?= $totalUnits ?>.0</td>
              <td colspan="4" style="padding:12px 18px;text-align:center;font-size:12px;color:#1e40af">SEMESTRAL GWA: <strong><?= $gwa ?></strong></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Clean Note Banner -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1e40af;border-radius:8px;padding:14px 18px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:20px">
        <div style="font-size:12.5px;color:#475569">
          <strong>Official Scholastic Record Notice:</strong> Prelim, midterm and final grades are officially authenticated by the Office of the University Registrar.
        </div>
        <button type="button" onclick="window.print()" style="font-size:12px;color:#1e40af;background:none;border:none;font-weight:700;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:4px">
          Generate Official Printable Slip →
        </button>
      </div>
    </div>

    <!-- PRINT-ONLY: Official Scholastic Grade Evaluation Document -->
    <div class="official-evaluation-document print-only">
      <!-- CuyoTech Custom Branded Letterhead -->
      <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR & ACADEMIC AFFAIRS', 'OFFICIAL SCHOLASTIC EVALUATION SHEET & GRADE REPORT', 'Academic Year 2026–2027 · First Semester · Official Student Copy', 'EVAL-' . date('Y') . '-' . e($s['student_number'])) ?>

      <!-- Student Credentials Matrix -->
      <div class="official-meta-grid">
        <div>
          <div class="official-meta-item-label">STUDENT NUMBER</div>
          <div class="official-meta-item-value"><?= e($s['student_number']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">STUDENT FULL NAME</div>
          <div class="official-meta-item-value"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">COLLEGE / FACULTY</div>
          <div class="official-meta-item-value"><?= e($s['department_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">DEGREE PROGRAM</div>
          <div class="official-meta-item-value"><?= e($s['program']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">YEAR LEVEL &amp; SECTION</div>
          <div class="official-meta-item-value"><?= e(year_label($s['section'])) ?> · <?= e($s['section'] ?: 'TBA') ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">EVALUATION DATE</div>
          <div class="official-meta-item-value"><?= date('F d, Y') ?></div>
        </div>
      </div>

      <!-- Scholastic Standing Summary Banner -->
      <div style="background:var(--surface-bg);border:1.5px solid #000000;padding:10px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px">
        <div>
          <span style="font-size:8pt;font-weight:700;text-transform:uppercase;color:#334155;letter-spacing:0.04em">SEMESTRAL GENERAL WEIGHTED AVERAGE (GWA):</span>
          <strong style="font-size:12pt;font-weight:800;color:var(--ink);margin-left:6px"><?= $gwa ?></strong>
        </div>
        <div>
          <span style="font-size:8pt;font-weight:700;text-transform:uppercase;color:#334155;letter-spacing:0.04em">SCHOLASTIC STANDING:</span>
          <span style="font-size:9pt;font-weight:700;color:#166534;margin-left:6px">Regular Student · Dean's List Honor Standing</span>
        </div>
        <div>
          <span style="font-size:8pt;font-weight:700;text-transform:uppercase;color:#334155;letter-spacing:0.04em">TOTAL UNITS ASSESSED:</span>
          <strong style="font-size:10pt;font-weight:800;color:var(--ink);margin-left:6px"><?= $totalUnits ?>.0 Units</strong>
        </div>
      </div>

      <!-- Official Course Evaluation Ledger -->
      <div class="table-container" style="margin:0 0 14px 0">
        <table class="official-doc-table data-table">
          <thead>
            <tr>
              <th style="width:100px">COURSE CODE</th>
              <th>DESCRIPTIVE COURSE TITLE</th>
              <th>CLASS SCHEDULE &amp; VENUE</th>
              <th style="text-align:center;width:60px">UNITS</th>
              <th style="text-align:center;width:80px">PRELIM</th>
              <th style="text-align:center;width:80px">MIDTERM</th>
              <th style="text-align:center;width:80px">FINAL</th>
              <th style="text-align:center;width:90px">REMARKS</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($g as $r): 
                $isPub = !empty($r['is_published']);
                $prelimVal = $isPub ? $r['prelim'] : null;
                $midtermVal = $isPub ? $r['midterm'] : null;
                $finalVal = $isPub ? $r['final_grade'] : null;

                $hasPrelim = ($prelimVal !== null);
                $hasMidterm = ($midtermVal !== null);
                $hasFinal = ($finalVal !== null);

                $isPassing = ($hasFinal && (float)$finalVal <= 3.0);
            ?>
              <tr>
                <td style="font-weight:700;color:var(--ink)"><?= e($r['code']) ?></td>
                <td style="font-weight:500"><?= e($r['title']) ?></td>
                <td style="font-size:8pt;color:#334155">
                  <?= e($r['schedule']) ?> (<?= e($r['room']) ?>)
                </td>
                <td style="text-align:center;font-weight:700"><?= (int)$r['units'] ?>.0</td>
                <td style="text-align:center;font-weight:800;font-size:10pt">
                  <?= $hasPrelim ? number_format((float)$prelimVal, 2) : '—' ?>
                </td>
                <td style="text-align:center;font-weight:800;font-size:10pt">
                  <?= $hasMidterm ? number_format((float)$midtermVal, 2) : '—' ?>
                </td>
                <td style="text-align:center;font-weight:800;font-size:10pt">
                  <?= $hasFinal ? number_format((float)$finalVal, 2) : '—' ?>
                </td>
                <td style="text-align:center">
                  <?php if ($hasFinal): ?>
                    <span class="badge <?= $isPassing ? 'badge-green' : 'badge-red' ?>" style="font-size:8pt;padding:2px 8px">
                      <?= $isPassing ? 'PASSED' : 'DEFICIENT' ?>
                    </span>
                  <?php else: ?>
                    <span class="badge badge-gray" style="font-size:8pt;padding:2px 8px">IN PROGRESS</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
              <td colspan="3" style="text-align:right;padding:6px 10px;font-size:8.5pt">TOTAL UNITS COMPLETED / EARNED:</td>
              <td style="text-align:center;padding:6px 10px;font-size:9.5pt"><?= $totalUnits ?>.0</td>
              <td colspan="4" style="text-align:center;font-size:8pt;color:#475569">GWA: <?= $gwa ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Grading Scale Reference Matrix (Philippine Higher Education Standard) -->
      <div style="border:1px solid #000000;padding:6px 10px;margin-bottom:14px;font-size:7.5pt;color:#334155;background:#f8fafc">
        <strong style="text-transform:uppercase;color:#000000">Philippine Higher Education Grading System Reference:</strong><br>
        1.00 (98–100% Excellent) &nbsp;·&nbsp; 1.25 (95–97% Superior) &nbsp;·&nbsp; 1.50 (92–94% Very Good) &nbsp;·&nbsp; 1.75 (89–91% Above Average) &nbsp;·&nbsp; 2.00 (86–88% Good)<br>
        2.25 (83–85% Satisfactory) &nbsp;·&nbsp; 2.50 (80–82% Fair) &nbsp;·&nbsp; 2.75 (77–79% Passing) &nbsp;·&nbsp; 3.00 (75–76% Passed) &nbsp;·&nbsp; 5.00 (Below 75% Deficient / Failed) &nbsp;·&nbsp; INC (Incomplete)
      </div>

      <!-- Tri-Column Official Certification & Signature Block -->
      <div class="official-sign-block">
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">MARIA L. SANTOS</div>
          <div class="official-sig-title">College Academic Evaluator</div>
        </div>
        <div class="official-sig-item">
          <?= cuyotech_seal_stamp() ?>
          <div style="font-size:7pt;color:#475569;margin-top:2px">Validation Code: CTU-EVL-2026-<?= str_pad((string)$id, 4, '0', STR_PAD_LEFT) ?></div>
        </div>
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">ANA REYES</div>
          <div class="official-sig-title">University Registrar</div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'en'): ?>
    <!-- ============================================================== -->
    <!-- TAB 2: MATRICULATION & CERTIFICATE OF REGISTRATION (COR)      -->
    <!-- ============================================================== -->
    <?php
    $isEnrolled = ($enrollStatus === 'Enrolled');
    $isPending = ($enrollStatus === 'Pending review');
    $isReturned = ($enrollStatus === 'Returned' || $enrollStatus === 'Needs revision');
    $subjects = $g;
    ?>

    <div class="no-print" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px">
      <div>
        <h3 style="font-size:17px;font-weight:700;color:var(--ink);margin:0 0 3px">
          <?= $isEnrolled ? 'Official Certificate of Registration (COR)' : 'Enrollment Matriculation Application' ?>
        </h3>
        <p style="font-size:12px;color:var(--ink-muted);margin:0">
          Academic Year 2026–2027, 1st Semester · Office of the University Registrar
        </p>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <?= pill($enrollStatus) ?>
        <?php if ($isEnrolled): ?>
          <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">
            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Official COR
          </button>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!$isEnrolled && !$isPending): ?>
      <!-- Matriculation Submission Callout -->
      <div class="notice-banner notice-blue no-print" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
        <div>
          <div style="font-size:14px;font-weight:700;color:#1e3a8a;margin-bottom:3px">
            Curriculum Load Prepared for Academic Year 2026–2027, 1st Semester
          </div>
          <div style="font-size:12px;color:#2563eb">
            Submitting this application submits your <?= count($subjects) ?>-course curriculum load directly to the Registrar for section assignment and validation.
          </div>
        </div>
        <form method="post" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="enroll">
          <button type="submit" class="btn btn-primary">
            <?= $isReturned ? 'Re-submit Matriculation for Review' : 'Submit Matriculation Application' ?>
          </button>
        </form>
      </div>
    <?php elseif ($isPending): ?>
      <div class="notice-banner notice-amber no-print">
        <strong>Matriculation Application Under Review:</strong> Your course load has been submitted and is currently being validated by the Office of the University Registrar. Once confirmed, your official Certificate of Registration will be generated below.
      </div>
    <?php endif; ?>

    <?php if ($isEnrolled): ?>
      <!-- Screen Interactive Enrolled Course Schedule -->
      <div class="no-print">
        <div class="table-container" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);margin-bottom:18px">
          <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px">
            <thead>
              <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0">
                <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">CODE</th>
                <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">DESCRIPTIVE COURSE TITLE</th>
                <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">SCHEDULE &amp; VENUE</th>
                <th style="padding:12px 18px;text-align:center;font-weight:700;font-size:11px;letter-spacing:0.04em">LEC</th>
                <th style="padding:12px 18px;text-align:center;font-weight:700;font-size:11px;letter-spacing:0.04em">LAB</th>
                <th style="padding:12px 18px;text-align:center;font-weight:700;font-size:11px;letter-spacing:0.04em">UNITS</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($subjects as $r): ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                  <td style="padding:12px 18px;font-family:monospace;font-weight:700;color:#0f172a"><?= e($r['code']) ?></td>
                  <td style="padding:12px 18px;font-weight:600;color:#0f172a"><?= e($r['title']) ?></td>
                  <td style="padding:12px 18px;font-size:12px;color:#64748b"><?= e($r['schedule']) ?> (<?= e($r['room']) ?>)</td>
                  <td style="padding:12px 18px;text-align:center;color:#64748b">2.0</td>
                  <td style="padding:12px 18px;text-align:center;color:#64748b">1.0</td>
                  <td style="padding:12px 18px;text-align:center;font-weight:700;color:#0f172a"><?= (int)$r['units'] ?>.0</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr style="background:#f8fafc;font-weight:700">
                <td colspan="5" style="padding:12px 18px;text-align:right;font-size:12px;color:#475569">TOTAL ACADEMIC UNITS ASSESSED:</td>
                <td style="padding:12px 18px;text-align:center;color:#1e40af;font-size:13px"><?= array_sum(array_column($subjects,'units')) ?>.0 Units</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Financial Assessment & Subsidy Banner -->
        <div style="display:grid;grid-template-columns:1.2fr 1fr;gap:20px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:18px 22px;margin-bottom:20px;align-items:center">
          <div>
            <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">ASSESSED TUITION &amp; INSTITUTIONAL CHARGES</div>
            <div style="font-size:13px;color:#334155;line-height:1.6">
              Total Assessment: <strong>₱6,150.00</strong> · CHED UniFAST RA 10931 Subsidy: <strong style="color:#16a34a">-₱6,150.00</strong>
            </div>
            <div style="font-size:14px;font-weight:800;color:#15803d;margin-top:4px">
              Net Balance Outstanding: ₱0.00 (100% Fully Subsidized)
            </div>
          </div>
          <div style="text-align:right">
            <button type="button" onclick="window.print()" class="btn btn-primary" style="display:inline-flex;align-items:center;gap:8px">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
              Print Official COR
            </button>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <!-- Official Certificate of Registration (COR) Sheet -->
    <div class="official-cor-document print-only">
      <!-- CuyoTech Custom Branded Letterhead -->
      <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR', 'CERTIFICATE OF REGISTRATION (COR)', 'Academic Year 2026–2027 · First Semester · Official Enrolled Load', 'COR-2026-' . str_pad((string)($last['enrollment_id'] ?? $id), 5, '0', STR_PAD_LEFT)) ?>

      <!-- Student & Program Info Grid -->
      <div class="official-meta-grid">
        <div>
          <div class="official-meta-item-label">STUDENT NUMBER</div>
          <div class="official-meta-item-value"><?= e($s['student_number']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">STUDENT FULL NAME</div>
          <div class="official-meta-item-value"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">COLLEGE / FACULTY</div>
          <div class="official-meta-item-value"><?= e($s['department_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">DEGREE PROGRAM</div>
          <div class="official-meta-item-value"><?= e($s['program']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">YEAR LEVEL &amp; SECTION</div>
          <div class="official-meta-item-value"><?= e(year_label($s['section'])) ?> · <?= e($s['section'] ?: 'TBA') ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">REGISTRATION DATE</div>
          <div class="official-meta-item-value"><?= e($last['enrollment_date'] ?? date('Y-m-d')) ?></div>
        </div>
      </div>

      <!-- Course Load Table -->
      <div style="margin-bottom:14px">
        <div style="font-size:8.5pt;font-weight:700;color:#000000;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">ENROLLED ACADEMIC COURSES &amp; CLASS SCHEDULE</div>
        <table class="official-doc-table data-table">
          <thead>
            <tr>
              <th style="width:100px">CODE</th>
              <th>DESCRIPTIVE COURSE TITLE</th>
              <th>DAY / SCHEDULE</th>
              <th>VENUE</th>
              <th style="text-align:center;width:50px">LEC</th>
              <th style="text-align:center;width:50px">LAB</th>
              <th style="text-align:center;width:60px">UNITS</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($subjects as $r): ?>
              <tr>
                <td style="font-weight:700;color:var(--ink)"><?= e($r['code']) ?></td>
                <td><?= e($r['title']) ?></td>
                <td><?= e($r['schedule']) ?></td>
                <td><?= e($r['room']) ?></td>
                <td style="text-align:center">2.0</td>
                <td style="text-align:center">1.0</td>
                <td style="text-align:center;font-weight:700"><?= (int)$r['units'] ?>.0</td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
              <td colspan="6" style="text-align:right;padding:6px 10px;font-size:8.5pt">TOTAL ACADEMIC UNITS ASSESSED:</td>
              <td style="text-align:center;color:var(--primary);padding:6px 10px;font-size:9.5pt"><?= array_sum(array_column($subjects,'units')) ?>.0</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Financial Assessment Breakdown Strip -->
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;border-top:1.5px solid #0f2b5c;padding-top:12px;font-size:8.5pt;margin-bottom:14px">
        <div>
          <div style="font-weight:700;color:#0f2b5c;margin-bottom:6px;text-transform:uppercase;font-size:8pt">STATEMENT OF ASSESSED FEES</div>
          <table style="width:100%;border-collapse:collapse;color:#334155;font-size:8pt">
            <tr><td style="padding:2px 0">Tuition Fee (18 Units @ ₱250.00/unit)</td><td style="text-align:right">₱4,500.00</td></tr>
            <tr><td style="padding:2px 0">Registration &amp; Matriculation Fee</td><td style="text-align:right">₱350.00</td></tr>
            <tr><td style="padding:2px 0">Library &amp; Athletics Fee</td><td style="text-align:right">₱450.00</td></tr>
            <tr><td style="padding:2px 0">Computer Laboratory &amp; Energy Fee</td><td style="text-align:right">₱850.00</td></tr>
            <tr style="border-top:1px solid #cbd5e1;font-weight:700"><td style="padding:4px 0;color:#0f172a">Total Assessed Institutional Fees</td><td style="text-align:right;color:#0f172a">₱6,150.00</td></tr>
            <tr style="color:#166534;font-weight:600"><td style="padding:2px 0">CHED UniFAST / RA 10931 Subsidy</td><td style="text-align:right">- ₱6,150.00</td></tr>
            <tr style="font-weight:800;font-size:9pt;border-top:1.5px solid #0f2b5c"><td style="padding:4px 0">NET BALANCE OUTSTANDING</td><td style="text-align:right;color:var(--primary)">₱0.00 (SUBSIDIZED)</td></tr>
          </table>
        </div>

        <div style="border-left:1px solid #cbd5e1;padding-left:18px;display:flex;flex-direction:column;justify-content:space-between">
          <div>
            <div style="font-weight:700;color:#000000;margin-bottom:4px;text-transform:uppercase;font-size:8pt">OFFICIAL CERTIFICATION &amp; AUTHENTICITY</div>
            <p style="font-size:8pt;color:#475569;line-height:1.45;margin:0">
              This Certificate of Registration serves as official evidence of matriculation in CuyoTech University for the indicated term. Any alterations or unauthorized erasure invalidates this record.
            </p>
          </div>
          <div style="padding-top:10px;border-top:1px dashed #cbd5e1;display:flex;justify-content:space-between;align-items:flex-end">
            <div>
              <div style="font-size:7pt;color:#64748b;text-transform:uppercase">VALIDATION CODE</div>
              <div style="font-family:monospace;font-size:9pt;font-weight:700;color:#000000">CTU-COR-2026-08492</div>
            </div>
            <div style="text-align:right">
              <div style="font-size:7pt;color:#64748b">CHED RA 10931 FREE HIGHER ED</div>
              <div style="font-size:8.5pt;font-weight:700;color:#166534">BENEFICIARY CONFIRMED</div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tri-Column Official Signatures Block -->
      <div class="official-sign-block">
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
          <div class="official-sig-title">Student / Matriculant Signature</div>
        </div>
        <div class="official-sig-item">
          <?= cuyotech_seal_stamp() ?>
          <div style="font-size:7pt;color:#475569;margin-top:2px">Document Authentication Barcode</div>
        </div>
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">ANA REYES</div>
          <div class="official-sig-title">University Registrar</div>
        </div>
      </div>
    </div>

  <?php elseif ($tab === 'cl'): ?>
    <!-- ============================================================== -->
    <!-- TAB 3: UNIVERSITY CLEARANCE AUDIT                             -->
    <!-- ============================================================== -->
    <?php
    ensure_student_clearance($id);
    $c = rows('SELECT office,status FROM clearance WHERE student_id=? ORDER BY clearance_id', [$id]);
    $n = count(array_filter($c, fn($r) => $r['status'] === 'Cleared'));
    $total = count($c) ?: 5;
    $pendingOffice = null;
    foreach ($c as $row) {
        if ($row['status'] !== 'Cleared') {
            $pendingOffice = $row['office'];
            break;
        }
    }
    $isDeptCleared = false;
    foreach ($c as $row) {
        if ($row['office'] === 'Department' && $row['status'] === 'Cleared') {
            $isDeptCleared = true;
            break;
        }
    }
    $requestCode = 'CLR-2026-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
    $labFilename = 'lab-return-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $s['last_name'])) . '.pdf';
    ?>

    <div class="no-print" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:12px">
      <div>
        <h3 style="font-size:17px;font-weight:700;color:var(--ink);margin:0 0 3px">Institutional Clearance Audit</h3>
        <p style="font-size:12px;color:var(--ink-muted);margin:0">Official end-of-term liability and property accountability audit across university offices.</p>
      </div>
      <div style="display:flex;align-items:center;gap:10px">
        <?= pill($pendingOffice ? 'Pending review' : 'Cleared') ?>
        <button type="button" onclick="window.print()" class="btn btn-outline btn-sm">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
          Print Clearance Certificate
        </button>
      </div>
    </div>

    <!-- Multi-Office Progress Tracker (Screen only) -->
    <div class="no-print" style="background:var(--surface-bg);border:1px solid var(--border-color);border-radius:var(--radius-md);padding:18px 22px;margin-bottom:22px">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
        <div style="font-size:18px;font-weight:700;color:var(--ink)">
          <?= $n ?> of <?= $total ?> Institutional Units Cleared
        </div>
        <span style="font-size:12px;font-weight:600;color:<?= $pendingOffice ? 'var(--amber-text)' : 'var(--green-text)' ?>">
          <?= $pendingOffice ? 'Action Required: ' . e($pendingOffice) . ' Review' : 'All Institutional Liabilities Cleared' ?>
        </span>
      </div>
      
      <div style="display:grid;grid-template-columns:repeat(<?= $total ?>,1fr);gap:6px;margin-bottom:8px">
        <?php foreach ($c as $item): ?>
          <div style="height:6px;border-radius:3px;background:<?= $item['status'] === 'Cleared' ? 'var(--primary)' : 'var(--border-color)' ?>"></div>
        <?php endforeach; ?>
      </div>
      <div style="display:grid;grid-template-columns:repeat(<?= $total ?>,1fr);gap:6px;font-size:11px;color:var(--ink-muted);text-align:center">
        <?php foreach ($c as $item): ?>
          <div><?= e($item['office']) ?></div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- Screen Interactive Layout -->
    <div class="no-print" style="display:grid;grid-template-columns:1fr 1fr;gap:24px;align-items:start">
      <!-- Left: Office Breakdown Table -->
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.04em">Office Sign-off Directory</div>
        <div class="table-container" style="margin-top:0">
          <table class="data-table">
            <thead>
              <tr>
                <th>OFFICE / UNIT</th>
                <th>DEFICIENCY / ACCOUNTABILITY</th>
                <th style="text-align:right">STATUS</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($c as $item): 
                  $isCl = ($item['status'] === 'Cleared');
              ?>
                <tr>
                  <td style="font-weight:600;color:var(--ink)"><?= e($item['office']) ?></td>
                  <td style="font-size:12px;color:var(--ink-muted)">
                    <?= $isCl ? 'No outstanding liability' : 'Pending laboratory return slip verification' ?>
                  </td>
                  <td style="text-align:right"><?= pill($item['status']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Right: Department Accountability Detail -->
      <div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-md);padding:20px">
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em">College Department Requirement</div>
        <p style="font-size:12px;color:var(--ink-muted);margin:0 0 16px">Hardware &amp; Network Laboratory Return Verification</p>

        <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:var(--surface-bg);border:1px solid var(--border-color);border-radius:var(--radius-sm);margin-bottom:16px">
          <div style="display:flex;align-items:center;gap:10px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            <div>
              <div style="font-size:12px;font-weight:700;color:var(--ink)"><?= e($labFilename) ?></div>
              <div style="font-size:11px;color:var(--ink-muted)">Signed by Engr. M. Soriano (Lab Custodian)</div>
            </div>
          </div>
          <button type="button" onclick="document.getElementById('slipModal').classList.add('show')" class="btn btn-outline btn-sm">
            View Slip
          </button>
        </div>

        <?php if ($isDeptCleared): ?>
          <div class="notice-banner notice-green" style="margin-bottom:0">
            <strong>Cleared:</strong> Department clearance verified by <?= e(program_head($s['program'])) ?>. All equipment accounted for.
          </div>
        <?php else: ?>
          <div class="notice-banner notice-amber" style="margin-bottom:0">
            <strong>Evaluation Pending:</strong> Department Head review in progress. Once signed off, this office will mark Cleared.
          </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Official Printable Certificate of Institutional Clearance -->
    <div class="official-clearance-cert print-only">
      <!-- CuyoTech Custom Branded Letterhead -->
      <?= cuyotech_doc_header('OFFICE OF STUDENT AFFAIRS & UNIVERSITY REGISTRAR', 'CERTIFICATE OF INSTITUTIONAL CLEARANCE', 'Academic Year 2026–2027 · First Semester · Official University Record', $requestCode) ?>

      <div class="official-meta-grid">
        <div>
          <div class="official-meta-item-label">STUDENT NUMBER</div>
          <div class="official-meta-item-value"><?= e($s['student_number']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">STUDENT FULL NAME</div>
          <div class="official-meta-item-value"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">COLLEGE / FACULTY</div>
          <div class="official-meta-item-value"><?= e($s['department_name']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">DEGREE PROGRAM</div>
          <div class="official-meta-item-value"><?= e($s['program']) ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">YEAR LEVEL &amp; SECTION</div>
          <div class="official-meta-item-value"><?= e(year_label($s['section'])) ?> · <?= e($s['section'] ?: 'TBA') ?></div>
        </div>
        <div>
          <div class="official-meta-item-label">AUDIT CLEARANCE REF</div>
          <div class="official-meta-item-value"><?= $requestCode ?></div>
        </div>
      </div>

      <div style="margin-bottom:14px">
        <div style="font-size:8.5pt;font-weight:700;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">MULTI-OFFICE LIABILITY &amp; ACCOUNTABILITY AUDIT SIGN-OFF</div>
        <table class="official-doc-table data-table">
          <thead>
            <tr>
              <th style="width:140px">OFFICE / DIVISION</th>
              <th>ACCOUNTABILITY &amp; AUDIT COVERAGE</th>
              <th style="width:130px">AUTHORIZED SIGNATORY</th>
              <th style="text-align:center;width:90px">AUDIT STATUS</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($c as $item): 
                $isCl = ($item['status'] === 'Cleared');
            ?>
              <tr>
                <td style="font-weight:700"><?= e($item['office']) ?></td>
                <td style="font-size:8pt;color:#334155">
                  <?= $isCl ? 'All university obligations, property liabilities, and fees fully satisfied' : 'Laboratory property return slip pending department head validation' ?>
                </td>
                <td style="font-size:8pt;font-weight:600">
                  <?= $item['office'] === 'Department' ? e(program_head($s['program'])) : ($item['office'] === 'Library' ? 'Librarian-in-Charge' : ($item['office'] === 'Cashier' ? 'Ms. April B. Galang (Cashier)' : 'Authorized Signatory')) ?>
                </td>
                <td style="text-align:center;font-weight:700;color:<?= $isCl ? '#166534' : '#b45309' ?>">
                  <?= $isCl ? 'CLEARED' : 'PENDING' ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <div class="official-cert-preamble">TO ALL WHOM THESE PRESENTS SHALL COME, GREETINGS</div>
      <div class="official-cert-statement">
        <strong>CERTIFICATION STATEMENT:</strong> This is to certify that the student named above has satisfactorily complied with all property, laboratory, library, financial, and departmental accountabilities of <strong>CuyoTech University</strong> for the indicated academic term. Having cleared all institutional obligations, the student is hereby granted official clearance in good standing.
      </div>

      <div class="official-sign-block">
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">DR. ROBERTO MENDOZA</div>
          <div class="official-sig-title">Dean of Student Affairs</div>
        </div>
        <div class="official-sig-item">
          <?= cuyotech_seal_stamp() ?>
          <div style="font-size:7pt;color:#475569;margin-top:2px">Reference: <?= $requestCode ?></div>
        </div>
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">ANA REYES</div>
          <div class="official-sig-title">University Registrar</div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <!-- ============================================================== -->
    <!-- TAB 4: STUDENT PERMANENT RECORD & PROFILE                     -->
    <!-- ============================================================== -->
    <div class="no-print" style="margin-bottom:18px">
      <h3 style="font-size:17px;font-weight:700;color:var(--ink);margin:0 0 3px">Student Permanent Academic Record (Form 1)</h3>
      <p style="font-size:12px;color:var(--ink-muted);margin:0">Official institutional identity, collegiate program standing, and contact directory.</p>
    </div>

    <div class="no-print" style="display:grid;grid-template-columns:300px 1fr;gap:24px;align-items:start">
      <!-- Left: Identity Badge Card -->
      <div style="background:var(--surface-bg);border:1px solid var(--border-color);border-radius:var(--radius-md);padding:24px;text-align:center">
        <div style="width:72px;height:72px;border-radius:var(--radius-md);background:var(--primary-light);border:1px solid var(--primary-border);color:var(--primary);font-size:22px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:0 auto 16px">
          <?= e($initials) ?>
        </div>
        <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:2px">
          <?= e($s['first_name'] . ' ' . $s['last_name']) ?>
        </div>
        <div style="font-size:12px;color:var(--ink-muted);margin-bottom:16px">
          Institutional Student ID: <?= e($s['student_number']) ?>
        </div>
        
        <div style="border-top:1px solid var(--border-color);padding-top:14px;text-align:left;font-size:12px;display:flex;flex-direction:column;gap:8px">
          <div>
            <span style="color:var(--ink-muted);font-size:10px;text-transform:uppercase;font-weight:700">ENROLLMENT STATUS</span>
            <div style="margin-top:2px"><?= pill($enrollStatus) ?></div>
          </div>
          <div>
            <span style="color:var(--ink-muted);font-size:10px;text-transform:uppercase;font-weight:700">ADMISSION YEAR</span>
            <div style="font-weight:600;color:var(--ink)">Academic Year 2023–2024</div>
          </div>
          <div>
            <span style="color:var(--ink-muted);font-size:10px;text-transform:uppercase;font-weight:700">CAMPUS / FACULTY</span>
            <div style="font-weight:600;color:var(--ink)"><?= e($s['department_name']) ?></div>
          </div>
        </div>
      </div>

      <!-- Right: Detailed Records Matrix -->
      <div>
        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.04em">Collegiate Program Matrix</div>
        <div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-md);overflow:hidden;margin-bottom:20px;font-size:13px">
          <div style="padding:12px 18px;border-bottom:1px solid var(--border-subtle);display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">Degree Program</span>
            <strong style="color:var(--ink)"><?= e($s['program']) ?></strong>
          </div>
          <div style="padding:12px 18px;border-bottom:1px solid var(--border-subtle);display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">College / Department</span>
            <span style="color:var(--ink)"><?= e($s['department_name']) ?> (<?= e($s['department_code'] ?? 'CCS') ?>)</span>
          </div>
          <div style="padding:12px 18px;border-bottom:1px solid var(--border-subtle);display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">Year Level &amp; Section</span>
            <span style="color:var(--ink)"><?= e(year_label($s['section'])) ?> · Section <?= e($s['section'] ?: 'TBA') ?></span>
          </div>
          <div style="padding:12px 18px;display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">Assigned Academic Adviser</span>
            <strong style="color:var(--ink)"><?= e(program_head($s['program'])) ?></strong>
          </div>
        </div>

        <div style="font-size:13px;font-weight:700;color:var(--ink);margin-bottom:10px;text-transform:uppercase;letter-spacing:0.04em">Contact Information &amp; Official Communications</div>
        <div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-md);overflow:hidden;margin-bottom:20px;font-size:13px">
          <div style="padding:12px 18px;border-bottom:1px solid var(--border-subtle);display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">Official Student Email</span>
            <span style="color:var(--ink)"><?= e($s['email']) ?></span>
          </div>
          <div style="padding:12px 18px;display:grid;grid-template-columns:180px 1fr">
            <span style="color:var(--ink-muted);font-size:12px">Mobile Contact Number</span>
            <span style="color:var(--ink)"><?= e($s['contact_number']) ?></span>
          </div>
        </div>

        <div class="notice-banner notice-blue" style="margin-bottom:0">
          <strong>Official Records Notice:</strong> Need a correction on your permanent academic records, civil status, or spelling? Please submit a formal Request for Record Update along with certified civil registry documents to the Office of the University Registrar.
        </div>
      </div>
    </div>
  <?php endif; ?>

  </div><!-- end tab body -->
</div><!-- end main container -->

<!-- Laboratory Return Slip Modal -->
<div class="modal-backdrop" id="slipModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="modal-card" style="padding:24px;max-width:640px;background:#ffffff">
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border-color)">
      <div style="display:flex;align-items:center;gap:10px">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        <div>
          <h3 style="font-size:15px;font-weight:700;color:var(--ink);margin:0"><?= e($labFilename) ?></h3>
          <div style="font-size:11px;color:var(--ink-muted)">Department Laboratory Equipment Accountability Return Slip</div>
        </div>
      </div>
      <button type="button" onclick="document.getElementById('slipModal').classList.remove('show')" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1">×</button>
    </div>

    <!-- Official Printable Laboratory Slip Content -->
    <div style="background:#ffffff;border:1.5px solid #1e3a8a;padding:18px 22px;color:#000000;line-height:1.4">
      <!-- CuyoTech Custom Branded Letterhead -->
      <?= cuyotech_doc_header('COLLEGE OF COMPUTER STUDIES · HARDWARE & NETWORK LAB', 'LABORATORY RETURN & ACCOUNTABILITY SLIP', 'Academic Year 2026–2027, 1st Semester', 'LAB-RET-2026-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT)) ?>

      <div class="official-meta-grid" style="grid-template-columns:1fr 1fr;gap:6px 12px;margin-bottom:12px;font-size:8pt">
        <div><strong>Student Name:</strong> <?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
        <div><strong>Student Number:</strong> <?= e($s['student_number']) ?></div>
        <div><strong>Curriculum:</strong> <?= e($s['program']) ?> (<?= e($s['section'] ?: 'TBA') ?>)</div>
        <div><strong>Date Inspected:</strong> <?= date('F d, Y') ?></div>
      </div>

      <table class="official-doc-table data-table" style="font-size:8pt;margin-bottom:10px">
        <thead>
          <tr>
            <th>Item / Equipment Description</th>
            <th style="text-align:center;width:40px">Qty</th>
            <th>Inspection &amp; Functional Condition</th>
            <th style="text-align:center;width:70px">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Arduino Microcontroller Kit v3 (Sensors, Cables)</td>
            <td style="text-align:center">1 set</td>
            <td>Complete components, verified operational</td>
            <td style="text-align:center;font-weight:700;color:#166534">RETURNED</td>
          </tr>
          <tr>
            <td>Digital Multimeter (Uni-T UT33D+ with Probes)</td>
            <td style="text-align:center">1 pc</td>
            <td>Passed calibration check, intact casing</td>
            <td style="text-align:center;font-weight:700;color:#166534">RETURNED</td>
          </tr>
          <tr>
            <td>Breadboard &amp; Jumper Wire Set (65 pcs bundle)</td>
            <td style="text-align:center">1 set</td>
            <td>Complete count verified by lab technician</td>
            <td style="text-align:center;font-weight:700;color:#166534">RETURNED</td>
          </tr>
        </tbody>
      </table>

      <div style="font-size:7.5pt;color:#334155;border:1px solid #cbd5e1;padding:6px 10px;margin-bottom:10px;background:#f8fafc">
        <strong>CUSTODIAL CERTIFICATION:</strong> All designated laboratory hardware and testing apparatus issued for the semester have been surrendered in satisfactory operating condition. Student is cleared of hardware liabilities.
      </div>

      <div class="official-sign-block" style="grid-template-columns:1fr 1fr;margin-top:10px;padding-top:10px">
        <div class="official-sig-item">
          <div class="official-sig-bar" style="margin-top:16px"></div>
          <div class="official-sig-name">ENGR. M. SORIANO</div>
          <div class="official-sig-title">Laboratory Custodian &amp; Inspector</div>
        </div>
        <div class="official-sig-item">
          <div class="official-sig-bar" style="margin-top:16px"></div>
          <div class="official-sig-name">PROF. ELENA CRUZ, MIT</div>
          <div class="official-sig-title">Department Chairperson (CCS)</div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:flex-end;gap:10px;margin-top:16px">
      <button type="button" onclick="printModalSlip('slipModal')" class="btn btn-outline btn-sm">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Slip
      </button>
      <button type="button" onclick="document.getElementById('slipModal').classList.remove('show')" class="btn btn-primary btn-sm">
        Close Window
      </button>
    </div>
  </div>
</div>

<script>
function printModalSlip(id) {
  document.body.classList.add('printing-modal');
  window.print();
  setTimeout(() => document.body.classList.remove('printing-modal'), 1000);
}
</script>

<?php page_end(); ?>