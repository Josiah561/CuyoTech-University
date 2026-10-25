<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('registrar');

$pendingCount = (int)row("SELECT COUNT(*) c FROM enrollment WHERE enrollment_status='Pending review'")['c'];
$pDocCount = (int)row("SELECT COUNT(*) c FROM document_request WHERE request_status IN ('Pending','Processing')")['c'];
$TABS = [
    'rv'  => 'Enrollment review' . ($pendingCount > 0 ? ' · ' . $pendingCount : ''),
    'gr'  => 'Grade encoding',
    'rc'  => 'Student records',
    'doc' => 'Document requests' . ($pDocCount > 0 ? ' · ' . $pDocCount : '')
];
$tab = $_GET['tab'] ?? 'rv';
if (!array_key_exists($tab, $TABS)) $tab = 'rv';

// College filter data (used by Grade encoding and Student records)
$collegeNames = [];
foreach (rows("SELECT department_code, department_name FROM department ORDER BY FIELD(department_code, 'COE', 'CEAS', 'CHAS', 'CCS', 'CBAA'), department_id") as $cr) {
    $collegeNames[$cr['department_code']] = $cr['department_name'];
}
$deptOfProg = [];
foreach (COLLEGE_PROGRAMS as $code => $list) { foreach ($list as $pn) $deptOfProg[$pn] = $code; }
sidebar_tabs('registrar/index.php', $TABS, $tab);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'confirm_enrollment') {
        $eid = (int)($_POST['eid'] ?? 0);
        $enr = row("SELECT * FROM enrollment WHERE enrollment_id=?", [$eid]);
        if (!$enr) { flash('Enrollment application not found.', true); redirect('registrar/index.php?tab=rv'); }
        $sid = (int)$enr['student_id'];

        // Enforce minimum enrollment payment rule (defined in config.php)
        $tuitionPaid = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE student_id=? AND (enrollment_id=? OR request_id IS NULL OR request_id=0) AND payment_status='Paid'", [$sid, $eid])['s'];
        if ($tuitionPaid < MINIMUM_ENROLLMENT_PAYMENT) {
            flash('Cannot confirm enrollment: Minimum downpayment of ' . money(MINIMUM_ENROLLMENT_PAYMENT) . ' required. Current payments: ' . money($tuitionPaid) . '.', true);
            redirect('registrar/index.php?tab=rv&eid=' . $eid);
        }

        q("UPDATE enrollment SET enrollment_status='Enrolled' WHERE enrollment_id=?", [$eid]);

        // Populate enrolled_subject table for this student, term, and subjects
        $stu = row("SELECT program, section FROM student WHERE student_id=?", [$sid]);
        if ($stu) {
            $y = section_year($stu['section']);
            $subs = rows("SELECT subject_id FROM subject WHERE program=?" . ($y !== null ? " AND year_level=?" : ""), $y !== null ? [$stu['program'], $y] : [$stu['program']]);
            $sy = !empty($enr['school_year']) ? $enr['school_year'] : '2026-2027';
            $sem = !empty($enr['semester']) ? $enr['semester'] : '1st Sem';
            foreach ($subs as $sub) {
                q("INSERT IGNORE INTO enrolled_subject (student_id, subject_id, school_year, semester) VALUES (?, ?, ?, ?)",
                  [$sid, (int)$sub['subject_id'], $sy, $sem]);
            }
        }

        audit('Confirmed enrollment #' . $eid . ' for student #' . $sid);
        notify_student($sid, 'Enrollment Confirmed', 'Your matriculation application for AY 2026–2027 has been confirmed. You may now view or print your Official Certificate of Registration (COR).', 'student/index.php?tab=en');
        flash('Enrollment has been confirmed.');
        redirect('registrar/index.php?tab=rv&eid=' . $eid);
    }
    if ($act === 'return_enrollment') {
        $eid = (int)($_POST['eid'] ?? 0);
        $enr = row("SELECT * FROM enrollment WHERE enrollment_id=?", [$eid]);
        if (!$enr) { flash('Enrollment application not found.', true); redirect('registrar/index.php?tab=rv'); }
        $sid = (int)$enr['student_id'];
        q("UPDATE enrollment SET enrollment_status='Returned' WHERE enrollment_id=?", [$eid]);
        audit('Returned enrollment #' . $eid);
        notify_student($sid, 'Enrollment Returned for Revision', 'Your matriculation application was returned by the Registrar. Please review requirements and resubmit.', 'student/index.php?tab=en');
        flash('Enrollment returned for revision.');
        redirect('registrar/index.php?tab=rv&eid=' . $eid);
    }
    if ($act === 'set_pending_enrollment') {
        $eid = (int)($_POST['eid'] ?? 0);
        $enr = row("SELECT * FROM enrollment WHERE enrollment_id=?", [$eid]);
        if (!$enr) { flash('Enrollment application not found.', true); redirect('registrar/index.php?tab=rv'); }
        q("UPDATE enrollment SET enrollment_status='Pending review' WHERE enrollment_id=?", [$eid]);
        audit('Re-opened enrollment #' . $eid . ' for review');
        flash('Enrollment application re-opened for evaluation.');
        redirect('registrar/index.php?tab=rv&eid=' . $eid);
    }
    if ($act === 'update_doc_status') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $req = row("SELECT * FROM document_request WHERE request_id=?", [$reqId]);
        if (!$req) { flash('Document request not found.', true); redirect('registrar/index.php?tab=doc'); }
        $newSt = trim((string)($_POST['new_status'] ?? ''));
        $validStatuses = ['Pending', 'Processing', 'Ready', 'Released', 'On hold'];
        if ($reqId > 0 && in_array($newSt, $validStatuses, true)) {
            // Document cannot be marked Ready or Released until its fee is paid
            if ($newSt === 'Ready' || $newSt === 'Released') {
                $docFee = (float)$req['fee'];
                $paidForDoc = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE request_id=? AND payment_status='Paid'", [$reqId])['s'];
                if ($paidForDoc < $docFee) {
                    flash("Cannot mark document as $newSt: Fee of " . money($docFee) . " must be fully paid first. (Current payment: " . money($paidForDoc) . ").", true);
                    redirect('registrar/index.php?tab=doc');
                }
            }
            $relDate = ($newSt === 'Released') ? date('Y-m-d') : ($req['release_date'] ?? null);
            $issuedBy = (int)$u['user_id'];
            $vCode = $req['verification_code'];
            if (($newSt === 'Ready' || $newSt === 'Released') && empty($vCode)) {
                $vCode = 'CTU-VER-' . strtoupper(substr(md5(uniqid((string)$reqId, true)), 0, 10)) . '-' . date('Y');
            }
            q("UPDATE document_request SET request_status=?, release_date=?, verification_code=?, issued_by=?, issued_at=COALESCE(issued_at, NOW()) WHERE request_id=?",
              [$newSt, $relDate, $vCode, $issuedBy, $reqId]);
            audit("Updated document request #$reqId status to $newSt (verification: $vCode) by user #$issuedBy");

            if ($newSt === 'Ready') {
                notify_student((int)$req['student_id'], 'Document Ready for Release', 'Your requested ' . $req['document_type'] . ' is now ready for release (Code: ' . $vCode . ').', 'student/documents.php?tab=my');
            } elseif ($newSt === 'Released') {
                notify_student((int)$req['student_id'], 'Document Released', 'Your requested ' . $req['document_type'] . ' has been officially released by the Registrar.', 'student/documents.php?tab=my');
            }

            flash("Document request status updated to $newSt." . ($vCode ? " Verification code: $vCode" : ""));
        }
        redirect('registrar/index.php?tab=doc');
    }
    if ($act === 'update_registrar_clearance') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $st = row("SELECT student_id FROM student WHERE student_id=?", [$sid]);
        if (!$st) { flash('Student record not found.', true); redirect('registrar/index.php?tab=rc'); }
        $newSt = (($_POST['status'] ?? '') === 'Cleared') ? 'Cleared' : 'Pending';
        ensure_student_clearance($sid);
        q("UPDATE clearance SET status=? WHERE student_id=? AND office='Registrar'", [$newSt, $sid]);
        audit("Registrar set clearance to $newSt for student #$sid");
        notify_student($sid, 'Registrar Clearance Updated', "Your Registrar clearance status was updated to: $newSt.", 'student/index.php?tab=cl');
        flash("Registrar clearance status updated to $newSt.");
        $backQ = trim((string)($_POST['q'] ?? ''));
        $backD = trim((string)($_POST['dept'] ?? ''));
        $backP = trim((string)($_POST['prog'] ?? ''));
        $backTab = trim((string)($_POST['from_tab'] ?? 'rc'));
        $backEid = (int)($_POST['eid'] ?? 0);
        if ($backTab === 'rv' && $backEid > 0) {
            redirect('registrar/index.php?tab=rv&eid=' . $backEid);
        }
        redirect('registrar/index.php?tab=rc&sid=' . $sid . ($backQ !== '' ? '&q=' . urlencode($backQ) : '')
               . ($backD !== '' ? '&dept=' . urlencode($backD) : '') . ($backP !== '' ? '&prog=' . urlencode($backP) : ''));
    }
    if ($act === 'save_student_grades') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $stu = row('SELECT student_id, program, section FROM student WHERE student_id=?', [$sid]);
        if (!$stu) { flash('Student not found.', true); redirect('registrar/index.php?tab=rc'); }
        $prels = is_array($_POST['prelim'] ?? null) ? $_POST['prelim'] : [];
        $mids = is_array($_POST['midterm'] ?? null) ? $_POST['midterm'] : [];
        $fins = is_array($_POST['final'] ?? null) ? $_POST['final'] : [];
        $sy = '2026-2027';
        $sem = '1st Sem';
        $changedBy = (int)$u['user_id'];
        $pubMode = ($_POST['publish_mode'] ?? 'publish') === 'publish';
        foreach (student_load($sid, $stu['program'], $stu['section']) as $sj) {   // only this student's own subjects
            $subId = (int)$sj['subject_id'];
            if (!array_key_exists($subId, $prels) && !array_key_exists($subId, $mids) && !array_key_exists($subId, $fins)) continue;
            $periods = [];
            if (array_key_exists($subId, $prels)) $periods['prelim'] = $prels[$subId];
            if (array_key_exists($subId, $mids)) $periods['midterm'] = $mids[$subId];
            if (array_key_exists($subId, $fins)) $periods['final_grade'] = $fins[$subId];
            record_grade_change($sid, $subId, $sy, $sem, $periods, $changedBy, $pubMode);
        }
        audit('Updated grades for student #' . $sid);
        flash($pubMode ? 'Grades saved and published for this student.' : 'Draft grades saved for this student.');
        $backQ = trim((string)($_POST['q'] ?? ''));
        $backD = trim((string)($_POST['dept'] ?? ''));
        $backP = trim((string)($_POST['prog'] ?? ''));
        redirect('registrar/index.php?tab=rc&sid=' . $sid . ($backQ !== '' ? '&q=' . urlencode($backQ) : '')
               . ($backD !== '' ? '&dept=' . urlencode($backD) : '') . ($backP !== '' ? '&prog=' . urlencode($backP) : ''));
    }
    if ($act === 'save_grades' || $act === 'publish_grades') {
        $subId = (int)($_POST['subject_id'] ?? 0);
        $subRow = row('SELECT program FROM subject WHERE subject_id=?', [$subId]);
        if (!$subRow) { flash('Subject not found.', true); redirect('registrar/index.php?tab=gr'); }
        $prels = is_array($_POST['prelim'] ?? null) ? $_POST['prelim'] : [];
        $mids = is_array($_POST['midterm'] ?? null) ? $_POST['midterm'] : [];
        $fins = is_array($_POST['final'] ?? null) ? $_POST['final'] : [];
        $sy = '2026-2027';
        $sem = '1st Sem';
        $changedBy = (int)$u['user_id'];
        $isPublish = ($act === 'publish_grades');

        foreach (array_unique(array_merge(array_keys($prels), array_keys($mids), array_keys($fins))) as $stId) {
            $stId = (int)$stId;
            if (!row('SELECT 1 FROM student WHERE student_id=? AND program=?', [$stId, $subRow['program']])) continue;   // wrong program
            $periods = [];
            if (array_key_exists($stId, $prels)) $periods['prelim'] = $prels[$stId];
            if (array_key_exists($stId, $mids)) $periods['midterm'] = $mids[$stId];
            if (array_key_exists($stId, $fins)) $periods['final_grade'] = $fins[$stId];
            record_grade_change($stId, $subId, $sy, $sem, $periods, $changedBy, $isPublish);
        }
        audit(($isPublish ? 'Published' : 'Saved draft') . ' grades for subject #' . $subId);
        flash($isPublish ? 'Grades published to Student Portal successfully.' : 'Draft grades saved (hidden from student portal until published).');
        redirect('registrar/index.php?tab=gr&prog=' . urlencode($subRow['program']) . '&sub=' . $subId . (($_POST['sec'] ?? '') !== '' ? '&sec=' . urlencode((string)$_POST['sec']) : ''));
    }
}

$tabSubtitles = [
    'rv'  => 'Matriculation Evaluation',
    'gr'  => 'Faculty Grade Sheets',
    'rc'  => 'Student Masterlist Directory',
    'doc' => 'Official Document Requests'
];

page_start('registrar', 'Office of the University Registrar', 'Central academic records, enrollment matriculation evaluation, grade sheet verification, and official document issuance.', 'OFFICE OF THE UNIVERSITY REGISTRAR / ACADEMIC CONSOLE');
?>

<div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);overflow:hidden">
  <!-- Card Header Row -->
  <div class="no-print" style="padding:20px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border-color);flex-wrap:wrap;gap:16px">
    <div>
      <h2 style="font-size:18px;font-weight:700;color:var(--ink);margin:0 0 2px">Academic Records &amp; Enrollment Division</h2>
      <div style="font-size:12px;color:var(--ink-muted)"><?= e($tabSubtitles[$tab] ?? '') ?></div>
    </div>
    <a href="#" onclick="window.print();return false;" class="btn btn-outline btn-sm">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
      Generate Enrollment Report
    </a>
  </div>

  <div style="padding:24px 28px">

<?php if ($tab === 'rv'): ?>
  <!-- Tab: Enrollment review -->
  <?php
  $apps = rows("
      SELECT e.enrollment_id AS id, e.student_id, e.school_year, e.semester, e.enrollment_date, e.enrollment_status,
             s.student_number AS sn, s.first_name, s.last_name, s.program, s.section,
             CONCAT(s.first_name, ' ', s.last_name) AS name
      FROM enrollment e
      JOIN student s ON s.student_id = e.student_id
      ORDER BY (e.enrollment_status = 'Pending review') DESC, e.enrollment_id DESC
  ");
  $rgBadge = fn($s) => $s === 'Enrolled' ? 'ok' : ($s === 'Pending review' ? 'pending' : 'warn');

  // Counts for the filter cards ("Returned" covers Returned / Needs revision)
  $cnt = ['' => count($apps), 'Pending review' => 0, 'Enrolled' => 0, 'Returned' => 0];
  foreach ($apps as $a) {
      $s = $a['enrollment_status'];
      if ($s === 'Pending review') $cnt['Pending review']++;
      elseif ($s === 'Enrolled') $cnt['Enrolled']++;
      else $cnt['Returned']++;
  }
  $stFilter = (string)($_GET['st'] ?? '');
  if (!in_array($stFilter, ['Pending review', 'Enrolled', 'Returned'], true)) $stFilter = '';
  $shownApps = array_values(array_filter($apps, function ($a) use ($stFilter) {
      if ($stFilter === '') return true;
      $s = $a['enrollment_status'];
      return $stFilter === 'Returned' ? !in_array($s, ['Enrolled', 'Pending review'], true) : $s === $stFilter;
  }));
  $stQs = $stFilter !== '' ? '&st=' . urlencode($stFilter) : '';

  $selAppId = isset($_GET['eid']) ? (int)$_GET['eid'] : 0;
  $selApp = null;
  if ($selAppId > 0) {
      foreach ($apps as $a) {
          if ((int)$a['id'] === $selAppId) { $selApp = $a; break; }
      }
  }
  $selAppUnits = $selApp ? array_sum(array_column(student_load((int)$selApp['student_id'], $selApp['program'], $selApp['section']), 'units')) : 0;
  ?>

  <div class="no-print">
  <?php if (!$selApp): ?>
    <!-- LIST VIEW: filter cards + full-width applications table -->
    <div class="rg-stats">
      <?php foreach ([
          ['', 'All applications'],
          ['Pending review', 'Awaiting review'],
          ['Enrolled', 'Enrolled'],
          ['Returned', 'Returned for revision'],
      ] as [$key, $label]): ?>
        <a class="rg-stat<?= $stFilter === $key ? ' active' : '' ?>" href="<?= url('registrar/index.php?tab=rv' . ($key !== '' ? '&st=' . urlencode($key) : '')) ?>">
          <span class="rg-stat-num"><?= (int)$cnt[$key] ?></span>
          <span class="rg-stat-label"><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if (empty($shownApps)): ?>
      <div class="rg-empty">
        <div class="rg-empty-title">No applications here</div>
        <div>There are no enrollment applications in this view.</div>
      </div>
    <?php else: ?>
      <div class="rg-table-wrap">
        <table class="rg-table">
          <thead>
            <tr>
              <th>Applicant</th>
              <th class="rg-hide-sm">Program</th>
              <th>Section</th>
              <th class="rg-hide-sm">Submitted</th>
              <th>Status</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($shownApps as $a): ?>
              <tr onclick="window.location='<?= url('registrar/index.php?tab=rv&eid=' . $a['id'] . $stQs) ?>'">
                <td>
                  <div class="rg-person">
                    <div class="rg-avatar"><?= e(first_char($a['first_name']) . first_char($a['last_name'])) ?></div>
                    <div>
                      <div class="rg-name"><?= e($a['name']) ?></div>
                      <div class="rg-sub"><?= e($a['sn']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="rg-hide-sm"><?= e($a['program']) ?></td>
                <td><?= e($a['section'] ?: 'TBA') ?></td>
                <td class="rg-hide-sm"><?= date('d M Y', strtotime($a['enrollment_date'])) ?></td>
                <td><span class="rg-badge <?= $rgBadge($a['enrollment_status']) ?>"><?= e($a['enrollment_status']) ?></span></td>
                <td style="text-align:right"><a class="rg-open" href="<?= url('registrar/index.php?tab=rv&eid=' . $a['id'] . $stQs) ?>">Review →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="rg-count">Showing <?= count($shownApps) ?> of <?= count($apps) ?> applications</div>
    <?php endif; ?>

  <?php else:
      $isEnrolled = ($selApp['enrollment_status'] === 'Enrolled');
      $isReturned = ($selApp['enrollment_status'] === 'Returned' || $selApp['enrollment_status'] === 'Needs revision');
      $appSid = (int)$selApp['student_id'];
      ensure_student_clearance($appSid);
      $isAppCleared = is_student_fully_cleared($appSid);
      $appTuitionPaid = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE student_id=? AND (enrollment_id=? OR request_id IS NULL OR request_id=0) AND payment_status='Paid'", [$appSid, (int)$selApp['id']])['s'];
      $isAppPaymentMet = ($appTuitionPaid >= MINIMUM_ENROLLMENT_PAYMENT);
      $hasAppLoad = ($selAppUnits > 0);
  ?>
    <!-- DETAIL VIEW: one application, full width -->
    <a class="rg-back" href="<?= url('registrar/index.php?tab=rv' . $stQs) ?>">← Back to applications</a>

    <div class="rg-hero">
      <div class="rg-person">
        <div class="rg-avatar lg"><?= e(first_char($selApp['first_name']) . first_char($selApp['last_name'])) ?></div>
        <div>
          <h3 class="rg-hero-name"><?= e($selApp['name']) ?></h3>
          <div class="rg-sub" style="font-size:13px"><?= e($selApp['sn']) ?> · <?= e($selApp['program']) ?></div>
        </div>
      </div>
      <span class="rg-badge lg <?= $rgBadge($selApp['enrollment_status']) ?>"><?= e($selApp['enrollment_status']) ?></span>
    </div>

    <div class="rg-facts">
      <div class="rg-fact"><div class="rg-fact-label">Application</div><div class="rg-fact-value">ENR-2026-0<?= e($selApp['id']) ?></div></div>
      <div class="rg-fact"><div class="rg-fact-label">Section</div><div class="rg-fact-value"><?= e($selApp['section'] ?: 'TBA') ?></div></div>
      <div class="rg-fact"><div class="rg-fact-label">Study load</div><div class="rg-fact-value"><?= (int)$selAppUnits ?> units</div></div>
      <div class="rg-fact"><div class="rg-fact-label">Submitted</div><div class="rg-fact-value"><?= date('d M Y', strtotime($selApp['enrollment_date'])) ?></div></div>
    </div>

    <div class="rg-panel">
      <h4 class="rg-panel-title">Evaluation checklist</h4>
      <div class="rg-checks">
        <div class="rg-check">
          <?php if ($isAppPaymentMet): ?>
            <i>✓</i> Minimum Payment Met (<?= money($appTuitionPaid) ?> / <?= money(MINIMUM_ENROLLMENT_PAYMENT) ?>)
          <?php else: ?>
            <i style="background:#fee2e2;color:#991b1b">✕</i> Payment Deficient (<?= money($appTuitionPaid) ?> of <?= money(MINIMUM_ENROLLMENT_PAYMENT) ?> min.)
          <?php endif; ?>
        </div>
        <div class="rg-check">
          <?php if ($isAppCleared): ?>
            <i>✓</i> Institutional Clearance Cleared (5/5 Offices)
          <?php else: ?>
            <i style="background:#fee2e2;color:#991b1b">✕</i> Institutional Clearance Pending
          <?php endif; ?>
        </div>
        <div class="rg-check">
          <?php if ($hasAppLoad): ?>
            <i>✓</i> Curriculum Load Found (<?= (int)$selAppUnits ?> Units)
          <?php else: ?>
            <i style="background:#fee2e2;color:#991b1b">✕</i> No Curriculum Subjects Found
          <?php endif; ?>
        </div>
      </div>
      <p class="rg-note">
        <?php if ($isAppPaymentMet && $isAppCleared && $hasAppLoad): ?>
          All institutional requirements (payment downpayment, clearance, and study load) are satisfied. Enrollment may be confirmed.
        <?php else: ?>
          <strong>Notice:</strong> One or more evaluation requirements are pending. Confirming enrollment requires at least <?= money(MINIMUM_ENROLLMENT_PAYMENT) ?> tuition downpayment.
        <?php endif; ?>
      </p>
    </div>

    <form method="post" class="rg-actions">
      <?= csrf_field() ?>
      <input type="hidden" name="eid" value="<?= e($selApp['id']) ?>">
      <?php if (!$isEnrolled): ?>
        <?php if ($isAppPaymentMet): ?>
          <button type="submit" name="action" value="confirm_enrollment" class="rg-btn primary">Confirm enrollment</button>
        <?php else: ?>
          <button type="button" class="rg-btn" disabled title="Minimum tuition downpayment of <?= money(MINIMUM_ENROLLMENT_PAYMENT) ?> is required" style="opacity:0.6;cursor:not-allowed">Confirm enrollment (Blocked: Minimum payment required)</button>
        <?php endif; ?>
      <?php endif; ?>
      <?php if (!$isReturned): ?>
        <button type="submit" name="action" value="return_enrollment" class="rg-btn">Return for revision</button>
      <?php endif; ?>
      <?php if ($isEnrolled || $isReturned): ?>
        <button type="submit" name="action" value="set_pending_enrollment" class="rg-btn ghost">Re-evaluate application</button>
      <?php endif; ?>
    </form>
  <?php endif; ?>
  </div>

  <!-- PRINT-ONLY: Official Enrollment Verification Report -->
  <div class="official-evaluation-document print-only" style="margin:0">
    <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR', 'ENROLLMENT VERIFICATION REPORT', 'Matriculation Evaluation — Academic Year 2026–2027, 1st Semester', 'EVR-' . date('Ymd')) ?>
    <table class="official-doc-table" style="width:100%;margin-bottom:18pt">
      <thead>
        <tr>
          <th>Enrollment ID</th>
          <th>Student Number</th>
          <th>Student Name</th>
          <th>Program</th>
          <th>Section</th>
          <th>Submitted</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($apps as $a): ?>
        <tr>
          <td>ENR-2026-0<?= e($a['id']) ?></td>
          <td><?= e($a['sn']) ?></td>
          <td><?= e($a['name']) ?></td>
          <td><?= e($a['program']) ?></td>
          <td><?= e($a['section'] ?: 'TBA') ?></td>
          <td><?= date('d M Y', strtotime($a['enrollment_date'])) ?></td>
          <td><?= e($a['enrollment_status']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr><td colspan="7" style="text-align:right;font-weight:700">Total Applications: <?= count($apps) ?></td></tr>
      </tfoot>
    </table>
    <div class="official-sign-block">
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">Evaluating Staff</div>
        <div class="official-sig-title">Registrar's Office</div>
      </div>
      <div class="official-sig-item" style="text-align:center">
        <?= cuyotech_seal_stamp() ?>
      </div>
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">University Registrar</div>
        <div class="official-sig-title">Office of the Registrar</div>
      </div>
    </div>
    <div style="font-size:7.5pt;color:#555;margin-top:10pt;text-align:center">This report is an official document of CuyoTech University. Printed: <?= date('d F Y, H:i') ?> · Page 1 of 1</div>
  </div>

<?php elseif ($tab === 'gr'): ?>
  <!-- Tab: Grade encoding -->
  <?php
  // Program -> subject -> section, so a sheet only ever lists its own program's subjects and students
  $allProgList = array_column(rows('SELECT DISTINCT program FROM subject ORDER BY program'), 'program');
  // College filter is tied to the program: a program always belongs to exactly one college,
  // so the college shown is the program's college, and the program list only offers that college's programs.
  $deptsWithSubjects = [];
  foreach ($allProgList as $pn) { if (isset($deptOfProg[$pn])) $deptsWithSubjects[$deptOfProg[$pn]] = true; }
  $selProg = (string)($_GET['prog'] ?? '');
  $allMode = ($selProg === 'all');                            // "All programs": every student of the college
  $selDept = (string)($_GET['dept'] ?? '');
  if (!$allMode && in_array($selProg, $allProgList, true) && isset($deptOfProg[$selProg])) {
      $selDept = $deptOfProg[$selProg];                       // the program decides its college
  } elseif (!isset($deptsWithSubjects[$selDept])) {           // no/old value (e.g. dept=all): first college that has subjects
      $selDept = '';
      foreach ($collegeNames as $dc => $dn) { if (isset($deptsWithSubjects[$dc])) { $selDept = $dc; break; } }
  }
  $progList = array_values(array_filter($allProgList, fn($pn) => $selDept === '' || ($deptOfProg[$pn] ?? '') === $selDept));
  if (!$allMode && !in_array($selProg, $progList, true)) $selProg = $progList[0] ?? '';
  $subjects = (!$allMode && $selProg !== '') ? rows('SELECT * FROM subject WHERE program=? ORDER BY year_level, subject_id', [$selProg]) : [];
  $selSubId = (int)($_GET['sub'] ?? 0);
  $selSubject = null;
  foreach ($subjects as $sb) {
      if ((int)$sb['subject_id'] === $selSubId) { $selSubject = $sb; break; }
  }
  if (!$selSubject && count($subjects) > 0) $selSubject = $subjects[0];

  $gradeRows = []; $sectionList = []; $selSec = '';
  if ($selSubject) {
      $sectionList = array_column(rows('SELECT section_name FROM section WHERE program=? AND year_level=? ORDER BY section_name',
                                       [$selSubject['program'], $selSubject['year_level']]), 'section_name');
      $selSec = (string)($_GET['sec'] ?? '');
      if (!in_array($selSec, $sectionList, true)) $selSec = '';
      foreach (rows("SELECT s.student_id, s.student_number, s.section, CONCAT(s.first_name, ' ', s.last_name) AS student_name, g.prelim, g.midterm, g.final_grade
                      FROM student s
                      LEFT JOIN grade g ON g.student_id = s.student_id AND g.subject_id = ?
                      WHERE s.program = ?
                      ORDER BY s.student_number", [$selSubject['subject_id'], $selSubject['program']]) as $r) {
          if (section_year($r['section']) !== (int)$selSubject['year_level']) continue;   // other year levels
          if ($selSec !== '' && $r['section'] !== $selSec) continue;
          $gradeRows[] = $r;
      }
  }

  // ---- "All programs" roster: every student of the selected college, across all its programs ----
  $rosterRows = []; $rosterSections = []; $selSecAll = '';
  if ($allMode && $progList) {
      $ph = implode(',', array_fill(0, count($progList), '?'));
      $rosterSections = array_column(rows("SELECT section_name FROM section WHERE program IN ($ph) ORDER BY section_name", $progList), 'section_name');
      $selSecAll = (string)($_GET['sec'] ?? '');
      if (!in_array($selSecAll, $rosterSections, true)) $selSecAll = '';
      $rq = trim((string)($_GET['q'] ?? ''));
      $subByProgYear = [];
      foreach (rows("SELECT subject_id, program, year_level, units FROM subject WHERE program IN ($ph)", $progList) as $sj) {
          $subByProgYear[$sj['program']][(int)$sj['year_level']][(int)$sj['subject_id']] = (int)$sj['units'];
      }
      $gradeBy = [];
      foreach (rows("SELECT g.student_id, g.subject_id, g.prelim, g.midterm, g.final_grade FROM grade g JOIN student s ON s.student_id = g.student_id WHERE s.program IN ($ph)", $progList) as $g0) {
          $gradeBy[(int)$g0['student_id']][(int)$g0['subject_id']] = $g0;
      }
      foreach (rows("SELECT student_id, student_number, program, section, first_name, last_name FROM student WHERE program IN ($ph) ORDER BY program, section, student_number", $progList) as $st0) {
          if ($selSecAll !== '' && $st0['section'] !== $selSecAll) continue;
          if ($rq !== '' && stripos($st0['first_name'] . ' ' . $st0['last_name'] . ' ' . $st0['student_number'], $rq) === false) continue;
          $yr = section_year($st0['section']);
          $subs = ($yr !== null) ? ($subByProgYear[$st0['program']][$yr] ?? []) : [];
          $gU = 0; $gS = 0; $gN = 0;
          foreach ($subs as $subId => $un) {
              $cg = isset($gradeBy[(int)$st0['student_id']][$subId]) ? current_grade($gradeBy[(int)$st0['student_id']][$subId]) : null;
              if ($cg !== null) { $gU += $un; $gS += $cg * $un; $gN++; }
          }
          $st0['n_subjects'] = count($subs);
          $st0['n_graded'] = $gN;
          $st0['avg'] = $gU > 0 ? number_format($gS / $gU, 2) : '—';
          $rosterRows[] = $st0;
      }
  }
  ?>
  <?php if ($allMode): ?>
  <!-- ALL PROGRAMS VIEW -->
  <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
    <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0">Grade encoding</h3>
    <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:9999px;background:#dbeafe;color:#1d4ed8">All students</span>
  </div>
  <div class="no-print" style="font-size:13px;color:#64748b;margin-bottom:20px"><?= e($collegeNames[$selDept] ?? 'All colleges') ?> · All programs · <?= e($selSecAll !== '' ? $selSecAll : 'All sections') ?></div>

  <div class="no-print gr-filters" style="grid-template-columns:1.2fr 1.4fr 1fr 1.2fr">
    <div>
      <label class="rg-label">College</label>
      <select class="rg-input" onchange="window.location='<?= url('registrar/index.php?tab=gr&prog=all&dept=') ?>'+encodeURIComponent(this.value)">
        <?php foreach ($collegeNames as $dc => $dn): if (!isset($deptsWithSubjects[$dc])) continue; ?>
          <option value="<?= e($dc) ?>" <?= $dc === $selDept ? 'selected' : '' ?>><?= e($dn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="rg-label">Program</label>
      <select class="rg-input" onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=' . urlencode($selDept) . '&prog=') ?>'+encodeURIComponent(this.value)">
        <option value="all" selected>All programs (all students)</option>
        <?php foreach ($progList as $pg): ?>
          <option value="<?= e($pg) ?>"><?= e($pg) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="rg-label">Section</label>
      <select class="rg-input" onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=' . urlencode($selDept) . '&prog=all&sec=') ?>'+encodeURIComponent(this.value)">
        <option value="">All sections</option>
        <?php foreach ($rosterSections as $sc): ?>
          <option value="<?= e($sc) ?>" <?= $sc === $selSecAll ? 'selected' : '' ?>><?= e($sc) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <form method="get" action="<?= url('registrar/index.php') ?>" style="margin:0">
      <input type="hidden" name="tab" value="gr"><input type="hidden" name="dept" value="<?= e($selDept) ?>"><input type="hidden" name="prog" value="all"><input type="hidden" name="sec" value="<?= e($selSecAll) ?>">
      <label class="rg-label">Search</label>
      <input class="rg-input" type="text" name="q" value="<?= e($rq ?? '') ?>" placeholder="Name or student number">
    </form>
  </div>

  <div class="no-print" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:20px">
    <div style="font-size:13px;font-weight:700;color:#1d4ed8;margin-bottom:2px">Every student of the college</div>
    <div style="font-size:12px;color:#1e40af">Prelim, midterm and final grades are encoded per subject. Pick a program and subject to grade a whole class, or open a student's record to encode all of their subjects at once.</div>
  </div>

  <?php if (!$rosterRows): ?>
    <div class="rg-empty"><div class="rg-empty-title">No students found</div><div>No students match this college, section or search.</div></div>
  <?php else: ?>
    <div class="rg-table-wrap">
      <table class="rg-table static">
        <thead>
          <tr>
            <th>Student</th>
            <th>Program</th>
            <th>Section</th>
            <th style="text-align:center">Subjects graded</th>
            <th style="text-align:center">Weighted avg</th>
            <th class="no-print"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rosterRows as $rr): ?>
            <tr>
              <td>
                <div class="rg-person">
                  <div class="rg-avatar"><?= e(first_char($rr['first_name']) . first_char($rr['last_name'])) ?></div>
                  <div>
                    <div class="rg-name"><?= e($rr['first_name'] . ' ' . $rr['last_name']) ?></div>
                    <div class="rg-sub"><?= e($rr['student_number']) ?></div>
                  </div>
                </div>
              </td>
              <td><?= e($rr['program']) ?></td>
              <td><?= e($rr['section'] ?: 'TBA') ?></td>
              <td style="text-align:center"><?= (int)$rr['n_graded'] ?> / <?= (int)$rr['n_subjects'] ?></td>
              <td style="text-align:center;font-weight:700;color:#2563eb"><?= $rr['avg'] ?></td>
              <td class="no-print" style="text-align:right"><a class="rg-open" href="<?= url('registrar/index.php?tab=rc&sid=' . (int)$rr['student_id']) ?>">Encode grades →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="rg-count no-print">Showing <?= count($rosterRows) ?> students</div>
  <?php endif; ?>
  <?php elseif (!$selSubject): ?>
    <div style="padding:24px;border:1px dashed #cbd5e1;border-radius:8px;color:#64748b;font-size:13px">No subjects have been encoded for any program yet.</div>
  <?php else: ?>
  <!-- PRINT-ONLY: Grade Sheet Report Header -->
  <div class="print-only" style="margin-bottom:12pt">
    <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR', 'OFFICIAL FACULTY GRADE SHEET', 'Subject: ' . e($selSubject['code']) . ' – ' . e($selSubject['title']) . ' (' . (int)$selSubject['units'] . ' Units) · ' . e($selSubject['program']) . ' · Section: ' . e($selSec !== '' ? $selSec : 'All sections') . ' · AY 2026–2027, 1st Sem', 'GS-' . e($selSubject['code'])) ?>
  </div>

  <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
    <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0">Grade encoding</h3>
    <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:9999px;background:#dbeafe;color:#1d4ed8">Official encoding</span>
  </div>
  <div class="no-print" style="font-size:13px;color:#64748b;margin-bottom:20px"><?= e($selSubject['code']) ?> · <?= e($selSubject['title']) ?> · <?= e($selSec !== '' ? $selSec : 'All sections') ?></div>

  <!-- filter controls: program > subject > section -->
  <div class="no-print gr-filters">
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">COLLEGE</label>
      <select onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=') ?>'+encodeURIComponent(this.value)" style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;color:#0f172a">
        <?php foreach ($collegeNames as $dc => $dn): if (!isset($deptsWithSubjects[$dc])) continue; ?>
          <option value="<?= e($dc) ?>" <?= $dc === $selDept ? 'selected' : '' ?>><?= e($dn) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">PROGRAM</label>
      <select onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=' . urlencode($selDept) . '&prog=') ?>'+encodeURIComponent(this.value)" style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;color:#0f172a">
        <option value="all">All programs (all students)</option>
        <?php foreach ($progList as $pg): ?>
          <option value="<?= e($pg) ?>" <?= $pg === $selSubject['program'] ? 'selected' : '' ?>><?= e($pg) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">SUBJECT</label>
      <select onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=' . urlencode($selDept) . '&prog=' . urlencode($selSubject['program']) . '&sub=') ?>'+this.value" style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;color:#0f172a">
        <?php foreach ($subjects as $sb): ?>
          <option value="<?= (int)$sb['subject_id'] ?>" <?= ((int)$sb['subject_id'] === (int)$selSubject['subject_id']) ? 'selected' : '' ?>>
            Yr <?= (int)$sb['year_level'] ?> · <?= e($sb['code']) ?> · <?= e($sb['title']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">SECTION</label>
      <select onchange="window.location='<?= url('registrar/index.php?tab=gr&dept=' . urlencode($selDept) . '&prog=' . urlencode($selSubject['program']) . '&sub=' . (int)$selSubject['subject_id'] . '&sec=') ?>'+encodeURIComponent(this.value)" style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;color:#0f172a">
        <option value="">All sections</option>
        <?php foreach ($sectionList as $sc): ?>
          <option value="<?= e($sc) ?>" <?= $sc === $selSec ? 'selected' : '' ?>><?= e($sc) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <!-- Blue callout banner -->
  <div class="no-print" style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:14px 18px;margin-bottom:24px">
    <div style="font-size:13px;font-weight:700;color:#1d4ed8;margin-bottom:2px">Official academic grade encoding</div>
    <div style="font-size:12px;color:#1e40af">Prelim, midterm and final grades are visible on the Student Portal as soon as they are saved. Enter grades below and save or publish.</div>
  </div>

  <!-- Grade Table -->
  <form method="post" style="margin:0">
    <?= csrf_field() ?>
    <input type="hidden" name="subject_id" value="<?= (int)$selSubject['subject_id'] ?>">
    <input type="hidden" name="sec" value="<?= e($selSec) ?>">
    <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:24px">
      <table class="official-doc-table" style="width:100%;border-collapse:collapse;font-size:13px">
        <thead>
          <tr style="background:#f8fafc;color:#475569;text-align:left">
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STUDENT ID</th>
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STUDENT NAME</th>
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:center">PRELIM GRADE</th>
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:center">MIDTERM GRADE</th>
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:center">FINAL GRADE</th>
            <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STATUS</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$gradeRows): ?>
            <tr><td colspan="6" style="padding:18px 16px;color:#64748b;font-size:13px">No students are enrolled in this program, year level<?= $selSec !== '' ? ' and section' : '' ?> yet.</td></tr>
          <?php endif; ?>
          <?php foreach ($gradeRows as $gr):
              $currentPre = $gr['prelim'] !== null ? number_format((float)$gr['prelim'], 2) : '';
              $currentMid = $gr['midterm'] !== null ? number_format((float)$gr['midterm'], 2) : '';
              $currentFin = $gr['final_grade'] !== null ? number_format((float)$gr['final_grade'], 2) : '';
          ?>
            <tr style="border-top:1px solid #f1f5f9">
              <td style="padding:14px 16px;font-weight:600;color:#0f172a"><?= e($gr['student_number']) ?></td>
              <td style="padding:14px 16px;color:#334155"><?= e($gr['student_name']) ?></td>
              <td style="padding:14px 16px;text-align:center">
                <span class="print-only" style="font-weight:700"><?= $currentPre !== '' ? $currentPre : '—' ?></span>
                <?= grade_select('prelim[' . (int)$gr['student_id'] . ']', $currentPre) ?>
              </td>
              <td style="padding:14px 16px;text-align:center">
                <span class="print-only" style="font-weight:700"><?= $currentMid !== '' ? $currentMid : '—' ?></span>
                <?= grade_select('midterm[' . (int)$gr['student_id'] . ']', $currentMid) ?>
              </td>
              <td style="padding:14px 16px;text-align:center">
                <span class="print-only" style="font-weight:700"><?= $currentFin !== '' ? $currentFin : '—' ?></span>
                <?= grade_select('final[' . (int)$gr['student_id'] . ']', $currentFin) ?>
              </td>
              <td style="padding:14px 16px">
                <?php $encN = ($currentPre !== '' ? 1 : 0) + ($currentMid !== '' ? 1 : 0) + ($currentFin !== '' ? 1 : 0); ?>
                <?php if ($encN === 3): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:9999px;background:#dcfce7;color:#166534">Complete</span>
                <?php elseif ($encN > 0): ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:9999px;background:#dbeafe;color:#1d4ed8"><?= $encN ?> of 3 encoded</span>
                <?php else: ?>
                  <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:9999px;background:#fef3c7;color:#92400e">Pending</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- PRINT-ONLY: Grade Sheet Signatures -->
    <div class="official-sign-block print-only" style="margin-bottom:16pt">
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-title">Instructor / Faculty</div>
      </div>
      <div class="official-sig-item" style="text-align:center">
        <?= cuyotech_seal_stamp() ?>
      </div>
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">University Registrar</div>
        <div class="official-sig-title">Office of the Registrar</div>
      </div>
    </div>
    <div class="print-only" style="font-size:7.5pt;color:#555;text-align:center;margin-bottom:16pt">
      Certified true and correct record submitted to the Office of the University Registrar. Printed: <?= date('d F Y, H:i') ?>
    </div>

    <!-- Footer row -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:16px">
      <div style="font-size:13px;color:#64748b">Changes update the official academic record in the database.</div>
      <div style="display:flex;align-items:center;gap:12px">
        <button type="submit" name="action" value="save_grades" style="padding:10px 22px;border-radius:9999px;border:1.5px solid #cbd5e1;background:#ffffff;color:#0f172a;font-size:14px;font-weight:600;cursor:pointer">
          Save draft
        </button>
        <button type="submit" name="action" value="publish_grades" style="padding:10px 22px;border-radius:9999px;background:#2563eb;color:#ffffff;border:none;font-size:14px;font-weight:600;cursor:pointer">
          Publish grades
        </button>
      </div>
    </div>
  </form>

  <?php endif; ?>

<?php elseif ($tab === 'rc'): ?>
  <!-- Tab: Student records -->
  <?php
  $q = trim((string)($_GET['q'] ?? ''));
  $stQuery = "SELECT s.*, d.department_name,
                     (SELECT enrollment_status FROM enrollment WHERE student_id=s.student_id ORDER BY enrollment_id DESC LIMIT 1) AS enrollment_status
              FROM student s
              LEFT JOIN department d ON d.department_id=s.department_id";
  $stParams = [];
  $stProgAll  = array_column(rows('SELECT DISTINCT program FROM student ORDER BY program'), 'program');
  $selStDept  = (string)($_GET['dept'] ?? '');
  if (!isset($collegeNames[$selStDept])) $selStDept = '';               // '' = all colleges
  $stProgList = array_values(array_filter($stProgAll, fn($pn) => $selStDept === '' || ($deptOfProg[$pn] ?? '') === $selStDept));
  $selStProg  = (string)($_GET['prog'] ?? '');
  if (!in_array($selStProg, $stProgList, true)) $selStProg = '';        // '' = all programs
  $stWhere = [];
  if ($q !== '') {
      $stWhere[] = "(CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.student_number LIKE ?)";
      $stParams[] = "%$q%";
      $stParams[] = "%$q%";
  }
  if ($selStDept !== '') { $stWhere[] = 'd.department_code = ?'; $stParams[] = $selStDept; }
  if ($selStProg !== '') { $stWhere[] = 's.program = ?'; $stParams[] = $selStProg; }
  if ($stWhere) $stQuery .= ' WHERE ' . implode(' AND ', $stWhere);
  $stQuery .= " ORDER BY s.student_id ASC";
  $allStudents = rows($stQuery, $stParams);
  $rgBadge = fn($s) => $s === 'Enrolled' ? 'ok' : ($s === 'Pending review' ? 'pending' : 'warn');

  // Query string that carries the current search/filters between list and record
  $rcQs = ($q !== '' ? '&q=' . urlencode($q) : '')
        . ($selStDept !== '' ? '&dept=' . urlencode($selStDept) : '')
        . ($selStProg !== '' ? '&prog=' . urlencode($selStProg) : '');

  $selSid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
  $selectedStudent = null;
  if ($selSid > 0) {
      foreach ($allStudents as $st) {
          if ((int)$st['student_id'] === $selSid) { $selectedStudent = $st; break; }
      }
  }
  // A search that matches exactly one student opens that record (unless the user just came back to the list)
  if (!$selectedStudent && $q !== '' && count($allStudents) === 1 && !isset($_GET['list'])) {
      $selectedStudent = $allStudents[0];
  }

  $studentGrades = [];
  $studentAvg = '—';
  $gUnits = 0;
  if ($selectedStudent) {
      $studentGrades = student_load((int)$selectedStudent['student_id'], $selectedStudent['program'], $selectedStudent['section']);

      $gSum = 0;
      foreach ($studentGrades as $gr) {
          $cg = current_grade($gr);
          if ($cg !== null) {
              $gUnits += (int)$gr['units'];
              $gSum += $cg * (int)$gr['units'];
          }
      }
      if ($gUnits > 0) $studentAvg = number_format($gSum / $gUnits, 2);
  }
  ?>

  <?php if (!$selectedStudent): ?>
  <!-- LIST VIEW: toolbar + full-width student table -->
  <div class="no-print">
    <form method="get" action="<?= url('registrar/index.php') ?>" class="rc-filters">
      <input type="hidden" name="tab" value="rc">
      <div>
        <label class="rg-label">Search student records</label>
        <div style="position:relative">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Student name or student number" class="rg-input" style="padding-right:38px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position:absolute;right:14px;top:50%;transform:translateY(-50%)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        </div>
      </div>
      <div>
        <label class="rg-label">College</label>
        <select name="dept" onchange="this.form.elements['prog'].value='';this.form.submit()" class="rg-input">
          <option value="">All colleges</option>
          <?php foreach ($collegeNames as $dc => $dn): ?>
            <option value="<?= e($dc) ?>" <?= $dc === $selStDept ? 'selected' : '' ?>><?= e($dn) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="rg-label">Program</label>
        <select name="prog" onchange="this.form.submit()" class="rg-input">
          <option value="">All programs</option>
          <?php foreach ($stProgList as $pg): ?>
            <option value="<?= e($pg) ?>" <?= $pg === $selStProg ? 'selected' : '' ?>><?= e($pg) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </form>

    <?php if (empty($allStudents)): ?>
      <div class="rg-empty">
        <div class="rg-empty-title">No students found</div>
        <div>Try a different name, student number, college or program.</div>
      </div>
    <?php else: ?>
      <div class="rg-table-wrap">
        <table class="rg-table">
          <thead>
            <tr>
              <th>Student</th>
              <th class="rg-hide-sm">Program</th>
              <th>Year / Section</th>
              <th>Enrollment</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($allStudents as $st):
                $enr = $st['enrollment_status'] ?? 'Not enrolled';
                $recUrl = url('registrar/index.php?tab=rc&sid=' . $st['student_id'] . $rcQs);
            ?>
              <tr onclick="window.location='<?= $recUrl ?>'">
                <td>
                  <div class="rg-person">
                    <div class="rg-avatar"><?= e(first_char($st['first_name']) . first_char($st['last_name'])) ?></div>
                    <div>
                      <div class="rg-name"><?= e($st['first_name'] . ' ' . $st['last_name']) ?></div>
                      <div class="rg-sub"><?= e($st['student_number']) ?></div>
                    </div>
                  </div>
                </td>
                <td class="rg-hide-sm"><?= e($st['program']) ?></td>
                <td><?= e(year_label($st['section'])) ?> · <?= e($st['section'] ?: 'TBA') ?></td>
                <td><span class="rg-badge <?= $rgBadge($enr) ?>"><?= e($enr) ?></span></td>
                <td style="text-align:right"><a class="rg-open" href="<?= $recUrl ?>">Open record →</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="rg-count">Showing <?= count($allStudents) ?> student records</div>
    <?php endif; ?>
  </div>

  <?php else:
      $stInitials = first_char($selectedStudent['first_name']) . first_char($selectedStudent['last_name']);
      $enrStatus = $selectedStudent['enrollment_status'] ?? 'Not enrolled';
      $totalUnits = (int)array_sum(array_column($studentGrades, 'units'));
  ?>
  <!-- RECORD VIEW: one student, full width -->
  <div class="screen-two-col-main" style="min-width:0;width:100%">
    <!-- PRINT-ONLY: Student Academic Record Header -->
    <div class="print-only" style="margin-bottom:12pt">
      <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR', 'OFFICIAL STUDENT SCHOLASTIC TRANSCRIPT SUMMARY', e($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) . ' · Student No: ' . e($selectedStudent['student_number']) . ' · ' . e($selectedStudent['program']) . ' · AY 2026–2027, 1st Sem', 'TOR-' . e($selectedStudent['student_number'])) ?>
    </div>

    <a class="rg-back no-print" href="<?= url('registrar/index.php?tab=rc&list=1' . $rcQs) ?>">← Back to student records</a>

    <div class="rg-hero no-print">
      <div class="rg-person">
        <div class="rg-avatar lg"><?= e($stInitials) ?></div>
        <div>
          <h3 class="rg-hero-name"><?= e($selectedStudent['first_name'] . ' ' . $selectedStudent['last_name']) ?></h3>
          <div class="rg-sub" style="font-size:13px"><?= e($selectedStudent['student_number']) ?> · <?= e($selectedStudent['program']) ?></div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="rg-badge lg <?= $rgBadge($enrStatus) ?>"><?= e($enrStatus) ?></span>
        <button type="button" onclick="window.print()" class="rg-btn small">
          <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
          Print record
        </button>
      </div>
    </div>

    <div class="rg-facts">
      <div class="rg-fact"><div class="rg-fact-label">College</div><div class="rg-fact-value"><?= e($selectedStudent['department_name'] ?? '—') ?></div></div>
      <div class="rg-fact"><div class="rg-fact-label">Year / Section</div><div class="rg-fact-value"><?= e(year_label($selectedStudent['section'])) ?> · <?= e($selectedStudent['section'] ?: 'TBA') ?></div></div>
      <div class="rg-fact"><div class="rg-fact-label">Study load</div><div class="rg-fact-value"><?= $totalUnits ?> units</div></div>
      <div class="rg-fact highlight"><div class="rg-fact-label">Weighted average</div><div class="rg-fact-value"><?= $studentAvg ?></div></div>
    </div>

    <!-- Institutional Clearance Status & Registrar Action -->
    <?php
    ensure_student_clearance((int)$selectedStudent['student_id']);
    $stClearances = rows("SELECT office, status FROM clearance WHERE student_id=? ORDER BY FIELD(office, 'Registrar','Cashier','Department','Library','Student Affairs')", [(int)$selectedStudent['student_id']]);
    $regClearance = row("SELECT status FROM clearance WHERE student_id=? AND office='Registrar'", [(int)$selectedStudent['student_id']]);
    $isRegCleared = ($regClearance && $regClearance['status'] === 'Cleared');
    $nClr = count(array_filter($stClearances, fn($cl) => $cl['status'] === 'Cleared'));
    ?>
    <div class="no-print rg-panel" style="margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px">
      <div>
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">INSTITUTIONAL CLEARANCE AUDIT</div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
          <span style="font-size:14px;font-weight:700;color:#0f172a"><?= $nClr ?> of 5 Offices Cleared</span>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php foreach ($stClearances as $cl): ?>
              <span class="rg-badge <?= $cl['status'] === 'Cleared' ? 'rg-badge-green' : 'rg-badge-amber' ?>" style="font-size:11px">
                <?= $cl['status'] === 'Cleared' ? '✓ ' : '' ?><?= e($cl['office']) ?>: <?= e($cl['status']) ?>
              </span>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
      <form method="post" style="margin:0">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_registrar_clearance">
        <input type="hidden" name="student_id" value="<?= (int)$selectedStudent['student_id'] ?>">
        <input type="hidden" name="status" value="<?= $isRegCleared ? 'Pending' : 'Cleared' ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <input type="hidden" name="dept" value="<?= e($selStDept) ?>">
        <input type="hidden" name="prog" value="<?= e($selStProg) ?>">
        <button type="submit" class="rg-btn <?= $isRegCleared ? 'ghost' : 'primary' ?>" style="font-size:12px">
          <?= $isRegCleared ? 'Set Registrar Clearance Pending' : '✓ Approve Registrar Clearance' ?>
        </button>
      </form>
    </div>

    <!-- Subject records & grades (editable per subject) -->
    <form method="post" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_student_grades">
      <input type="hidden" name="student_id" value="<?= (int)$selectedStudent['student_id'] ?>">
      <input type="hidden" name="q" value="<?= e($q) ?>">
      <input type="hidden" name="dept" value="<?= e($selStDept) ?>">
      <input type="hidden" name="prog" value="<?= e($selStProg) ?>">

      <div class="no-print" style="margin-bottom:12px">
        <h4 class="rg-panel-title" style="margin:0">Subject records &amp; grades</h4>
        <div class="rg-sub" style="margin-top:2px">Encode or correct this student's prelim, midterm and final grade per subject. To grade a whole class at once, use the Grade encoding tab.</div>
      </div>

      <div class="rg-table-wrap">
        <table class="rg-table official-doc-table static">
          <thead>
            <tr>
              <th>Code</th>
              <th>Subject</th>
              <th style="text-align:center">Units</th>
              <th style="text-align:center">Prelim</th>
              <th style="text-align:center">Midterm</th>
              <th style="text-align:center">Final</th>
            </tr>
          </thead>
          <tbody>
            <?php if (!$studentGrades): ?>
              <tr><td colspan="6" style="color:#64748b">No subjects are set up for this student's program and year level yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($studentGrades as $sgr):
                $pv = $sgr['prelim'] !== null ? number_format((float)$sgr['prelim'], 2) : '';
                $mv = $sgr['midterm'] !== null ? number_format((float)$sgr['midterm'], 2) : '';
                $fv = $sgr['final_grade'] !== null ? number_format((float)$sgr['final_grade'], 2) : '';
            ?>
              <tr>
                <td class="rg-name"><?= e($sgr['code']) ?></td>
                <td><?= e($sgr['title']) ?></td>
                <td style="text-align:center"><?= (int)$sgr['units'] ?></td>
                <td style="text-align:center;color:#2563eb;font-weight:600">
                  <span class="print-only"><?= $pv !== '' ? $pv : '—' ?></span>
                  <?= grade_select('prelim[' . (int)$sgr['subject_id'] . ']', $pv) ?>
                </td>
                <td style="text-align:center;color:#2563eb;font-weight:600">
                  <span class="print-only"><?= $mv !== '' ? $mv : '—' ?></span>
                  <?= grade_select('midterm[' . (int)$sgr['subject_id'] . ']', $mv) ?>
                </td>
                <td style="text-align:center;color:#2563eb;font-weight:600">
                  <span class="print-only"><?= $fv !== '' ? $fv : '—' ?></span>
                  <?= grade_select('final[' . (int)$sgr['subject_id'] . ']', $fv) ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="border-top:2px solid #000;font-weight:700">
              <td colspan="2" style="text-align:right">WEIGHTED AVERAGE (LATEST ENCODED GRADE: FINAL, ELSE MIDTERM, ELSE PRELIM):</td>
              <td style="text-align:center"><?= $gUnits ?> units</td>
              <td colspan="3" style="text-align:right;color:#2563eb"><?= $studentAvg ?></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <?php if ($studentGrades): ?>
      <div class="rg-actions no-print" style="justify-content:space-between;margin-top:16px">
        <div class="rg-sub">Academic records are authenticated by the Office of the Registrar.</div>
        <div style="display:flex;align-items:center;gap:10px">
          <button type="submit" name="publish_mode" value="draft" class="rg-btn">Save draft</button>
          <button type="submit" name="publish_mode" value="publish" class="rg-btn primary">Save &amp; Publish</button>
        </div>
      </div>
      <?php endif; ?>
    </form>

    <div class="official-sign-block print-only" style="margin-top:18pt">
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">Records Evaluator</div>
        <div class="official-sig-title">Office of the Registrar</div>
      </div>
      <div class="official-sig-item" style="text-align:center">
        <?= cuyotech_seal_stamp() ?>
      </div>
      <div class="official-sig-item">
        <div class="official-sig-bar"></div>
        <div class="official-sig-name">University Registrar</div>
        <div class="official-sig-title">Office of the Registrar</div>
      </div>
    </div>
    <div class="print-only" style="font-size:7.5pt;color:#555;text-align:center;margin-top:10pt">
      This scholastic transcript summary is an authenticated academic document of CuyoTech University. Printed: <?= date('d F Y, H:i') ?>
    </div>
  </div>
  <?php endif; ?>
<?php elseif ($tab === 'doc'): ?>
  <!-- Tab: Document requests -->
  <?php
  $docStatusFilter = trim((string)($_GET['st'] ?? ''));
  $docQuery = "
      SELECT dr.*, s.student_number, CONCAT(s.first_name, ' ', s.last_name) AS student_name, s.program,
             COALESCE((SELECT SUM(amount) FROM payment p WHERE p.request_id = dr.request_id AND p.payment_status = 'Paid'), 0) AS paid_amount
      FROM document_request dr
      JOIN student s ON s.student_id = dr.student_id
  ";
  $docParams = [];
  if ($docStatusFilter !== '') {
      $docQuery .= " WHERE dr.request_status = ?";
      $docParams[] = $docStatusFilter;
  }
  $docQuery .= " ORDER BY (dr.request_status IN ('Pending','Processing')) DESC, dr.request_id DESC";
  $allDocRequests = rows($docQuery, $docParams);

  $docStatusOptions = ['All' => '', 'Pending' => 'Pending', 'Processing' => 'Processing', 'Ready' => 'Ready', 'Released' => 'Released', 'On hold' => 'On hold'];
  ?>
  <div class="no-print" style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px">Academic document requests</h3>
      <div style="font-size:13px;color:#64748b">Process official transcripts, registration certificates and certifications.</div>
    </div>
    <!-- Status filter pills -->
    <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
      <?php foreach ($docStatusOptions as $dLabel => $dVal):
          $isDActive = ($docStatusFilter === $dVal);
      ?>
        <a href="<?= url('registrar/index.php?tab=doc' . ($dVal ? '&st=' . urlencode($dVal) : '')) ?>"
           style="padding:6px 14px;border-radius:9999px;font-size:12px;font-weight:600;text-decoration:none;<?= $isDActive ? 'background:#2563eb;color:#fff' : 'background:#f8fafc;color:#475569;border:1px solid #e2e8f0' ?>">
          <?= $dLabel ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- PRINT-ONLY: Document Request Queue Header -->
  <div class="print-only" style="margin-bottom:12pt">
    <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR', 'OFFICIAL DOCUMENT REQUEST & ISSUANCE SUMMARY', 'Academic Year 2026–2027, 1st Semester · Filter: ' . ($docStatusFilter ?: 'All Requests'), 'DRQ-' . date('Ymd')) ?>
  </div>

  <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table class="official-doc-table" style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f8fafc;color:#475569;text-align:left">
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">REQUEST ID</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STUDENT</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">DOCUMENT TYPE</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">FEE</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">DATE</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STATUS</th>
          <th class="no-print" style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:right">ACTIONS</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($allDocRequests)): ?>
          <tr><td colspan="7" style="padding:28px 16px;text-align:center;color:#64748b">No document requests found.</td></tr>
        <?php else: ?>
          <?php foreach ($allDocRequests as $dr):
              $drSt = $dr['request_status'];
              $drCode = 'DOC-2026-' . str_pad((string)$dr['request_id'], 5, '0', STR_PAD_LEFT);
              $badgeBg = ($drSt === 'Ready' || $drSt === 'Released') ? 'background:#dcfce7;color:#166534' : (($drSt === 'Processing') ? 'background:#dbeafe;color:#1d4ed8' : 'background:#fef3c7;color:#92400e');
          ?>
            <tr style="border-top:1px solid #f1f5f9">
              <td style="padding:14px 16px;font-weight:600;color:#2563eb"><?= $drCode ?></td>
              <td style="padding:14px 16px">
                <div style="font-weight:600;color:#0f172a"><?= e($dr['student_name']) ?></div>
                <div style="font-size:12px;color:#64748b"><?= e($dr['student_number']) ?> · <?= e($dr['program']) ?></div>
              </td>
              <td style="padding:14px 16px;color:#0f172a;font-weight:500"><?= e($dr['document_type']) ?></td>
              <td style="padding:14px 16px">
                <div style="color:#0f172a;font-weight:600"><?= money($dr['fee']) ?></div>
                <?php $isPaid = ((float)$dr['paid_amount'] >= (float)$dr['fee']); ?>
                <?php if ($isPaid): ?>
                  <span style="font-size:10.5px;font-weight:700;color:#16a34a">✓ Paid</span>
                <?php else: ?>
                  <span style="font-size:10.5px;font-weight:700;color:#dc2626">Unpaid (<?= money($dr['paid_amount']) ?>)</span>
                <?php endif; ?>
              </td>
              <td style="padding:14px 16px;color:#64748b"><?= date('d M Y', strtotime($dr['request_date'])) ?></td>
              <td style="padding:14px 16px">
                <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;<?= $badgeBg ?>"><?= e($drSt) ?></span>
              </td>
              <td class="no-print" style="padding:14px 16px;text-align:right">
                <form method="post" style="display:inline-flex;align-items:center;gap:6px;margin:0">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_doc_status">
                  <input type="hidden" name="request_id" value="<?= (int)$dr['request_id'] ?>">
                  <?php if ($drSt === 'Pending'): ?>
                    <button type="submit" name="new_status" value="Processing" style="padding:5px 12px;border-radius:9999px;background:#2563eb;color:#fff;border:none;font-size:12px;font-weight:600;cursor:pointer">Process</button>
                    <button type="submit" name="new_status" value="On hold" style="padding:5px 12px;border-radius:9999px;background:#fff;border:1px solid #cbd5e1;color:#64748b;font-size:12px;cursor:pointer">Hold</button>
                  <?php elseif ($drSt === 'Processing' || $drSt === 'On hold'): ?>
                    <?php if ($isPaid): ?>
                      <button type="submit" name="new_status" value="Ready" style="padding:5px 12px;border-radius:9999px;background:#16a34a;color:#fff;border:none;font-size:12px;font-weight:600;cursor:pointer">Mark Ready</button>
                      <button type="submit" name="new_status" value="Released" style="padding:5px 12px;border-radius:9999px;background:#2563eb;color:#fff;border:none;font-size:12px;cursor:pointer">Release</button>
                    <?php else: ?>
                      <button type="submit" name="new_status" value="Ready" style="padding:5px 12px;border-radius:9999px;background:#94a3b8;color:#fff;border:none;font-size:12px;font-weight:600;cursor:not-allowed" title="Document fee unpaid; will be rejected if clicked">Mark Ready</button>
                    <?php endif; ?>
                  <?php elseif ($drSt === 'Ready'): ?>
                    <a href="<?= url('print_document.php?id=' . (int)$dr['request_id']) ?>" target="_blank" style="padding:4px 10px;border-radius:9999px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11.5px;font-weight:600;text-decoration:none">Print Doc ↗</a>
                    <?php if ($isPaid): ?>
                      <button type="submit" name="new_status" value="Released" style="padding:5px 14px;border-radius:9999px;background:#16a34a;color:#fff;border:none;font-size:12px;font-weight:600;cursor:pointer">Release to Student</button>
                    <?php else: ?>
                      <button type="submit" name="new_status" value="Released" style="padding:5px 14px;border-radius:9999px;background:#94a3b8;color:#fff;border:none;font-size:12px;font-weight:600;cursor:not-allowed" title="Document fee unpaid">Release to Student</button>
                    <?php endif; ?>
                  <?php else: ?>
                    <a href="<?= url('print_document.php?id=' . (int)$dr['request_id']) ?>" target="_blank" style="padding:4px 10px;border-radius:9999px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11.5px;font-weight:600;text-decoration:none">Print Doc ↗</a>
                    <span style="font-size:12px;color:#16a34a;font-weight:600">Released ✓</span>
                  <?php endif; ?>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="7" style="text-align:right;font-weight:700">Total Requests: <?= count($allDocRequests) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
  <div class="no-print" style="font-size:12px;color:#94a3b8;margin-top:14px">Showing <?= count($allDocRequests) ?> document requests</div>

  <!-- PRINT-ONLY: Signature Block for Document Issuance -->
  <div class="official-sign-block print-only" style="margin-top:20pt">
    <div class="official-sig-item">
      <div class="official-sig-bar"></div>
      <div class="official-sig-name">Issuing Records Officer</div>
      <div class="official-sig-title">Office of the Registrar</div>
    </div>
    <div class="official-sig-item" style="text-align:center">
      <?= cuyotech_seal_stamp() ?>
    </div>
    <div class="official-sig-item">
      <div class="official-sig-bar"></div>
      <div class="official-sig-name">University Registrar</div>
      <div class="official-sig-title">Office of the Registrar</div>
    </div>
  </div>
<?php endif; ?>

  </div><!-- end content -->
</div><!-- end main card -->

<?php page_end(); ?>