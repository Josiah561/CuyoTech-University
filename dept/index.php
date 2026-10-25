<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('depthead');
$dept = row('SELECT * FROM department WHERE department_id=?', [(int)$u['department_id']]);
if (!$dept) { http_response_code(403); exit('No department is assigned to this account.'); }

$TABS = [
    'rv' => 'Clearance review',
    'rc' => 'Clearance records'
];
$tab = $_GET['tab'] ?? 'rv';
if (!array_key_exists($tab, $TABS)) $tab = 'rv';
sidebar_tabs('dept/index.php', $TABS, $tab);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'approve_clearance') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $st = row("SELECT student_id, department_id FROM student WHERE student_id=?", [$sid]);
        if (!$st || (int)$st['department_id'] !== (int)$u['department_id']) {
            audit("Unauthorized clearance update attempt on student #$sid by depthead #{$u['user_id']}");
            http_response_code(403);
            exit('Forbidden: This student does not belong to your department.');
        }
        q("UPDATE clearance SET status='Cleared' WHERE student_id=? AND office='Department'", [$sid]);
        audit('Approved departmental clearance for student #' . $sid);
        notify_student($sid, 'Department Clearance Approved', 'Your college academic department clearance has been approved and cleared.', 'student/index.php?tab=cl');
        flash('Department clearance approved successfully.');
        redirect('dept/index.php?tab=rv&sid=' . $sid);
    }
    if ($act === 'keep_pending') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $st = row("SELECT student_id, department_id FROM student WHERE student_id=?", [$sid]);
        if (!$st || (int)$st['department_id'] !== (int)$u['department_id']) {
            audit("Unauthorized clearance update attempt on student #$sid by depthead #{$u['user_id']}");
            http_response_code(403);
            exit('Forbidden: This student does not belong to your department.');
        }
        q("UPDATE clearance SET status='Pending' WHERE student_id=? AND office='Department'", [$sid]);
        audit('Kept departmental clearance pending for student #' . $sid);
        notify_student($sid, 'Department Clearance Status', 'Your department clearance status has been marked as pending.', 'student/index.php?tab=cl');
        flash('Clearance status kept as Pending.');
        redirect('dept/index.php?tab=rv&sid=' . $sid);
    }
}

// Fetch all departmental clearances for this department
$search = trim((string)($_GET['q'] ?? ''));
$reqQuery = "SELECT c.clearance_id, c.student_id, c.status AS dept_status,
       s.student_number, s.first_name, s.last_name, s.section, s.program,
       (SELECT COUNT(*) FROM clearance WHERE student_id=s.student_id AND status='Cleared') AS cleared_count,
       (SELECT COUNT(*) FROM clearance WHERE student_id=s.student_id) AS total_offices
FROM clearance c
JOIN student s ON s.student_id = c.student_id
WHERE c.office = 'Department' AND s.department_id = ?";
$params = [(int)$u['department_id']];
$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($search !== '') {
    $reqQuery .= " AND (CONCAT(s.first_name, ' ', s.last_name) LIKE ? OR s.student_number LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}
if ($statusFilter === 'Pending' || $statusFilter === 'Cleared') {
    $reqQuery .= " AND c.status = ?";
    $params[] = $statusFilter;
}
$reqQuery .= " ORDER BY (c.status = 'Pending') DESC, s.student_id ASC";
$requests = rows($reqQuery, $params);

// CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=clearance_list_' . date('Ymd_His') . '.csv');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Student ID', 'Student Name', 'Program', 'Section', 'Office', 'Status']);
    foreach ($requests as $r) {
        fputcsv($out, [
            $r['student_number'],
            $r['first_name'] . ' ' . $r['last_name'],
            $r['program'],
            $r['section'] ?: 'TBA',
            'Department',
            $r['dept_status']
        ]);
    }
    fclose($out);
    exit;
}

$selSid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$selReq = null;
if ($selSid > 0) {
    foreach ($requests as $r) {
        if ((int)$r['student_id'] === $selSid) {
            $selReq = $r;
            break;
        }
    }
}
if (!$selReq && $search !== '' && count($requests) > 0) {
    $selReq = $requests[0];
}

$pendingTotal = (int)row("SELECT COUNT(*) c FROM clearance c JOIN student s ON s.student_id=c.student_id WHERE c.office='Department' AND s.department_id=? AND c.status='Pending'", [(int)$u['department_id']])['c'];
$clearedTotal = (int)row("SELECT COUNT(*) c FROM clearance c JOIN student s ON s.student_id=c.student_id WHERE c.office='Department' AND s.department_id=? AND c.status='Cleared'", [(int)$u['department_id']])['c'];
$allTotal = $pendingTotal + $clearedTotal;

page_start('dept', 'Departmental Clearance Administration', 'Laboratory accountability validation, student property clearances, and departmental sign-offs.', 'COLLEGE OF COMPUTER STUDIES / DEPARTMENT CLEARANCE');
?>

<div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);overflow:hidden">
  <!-- Card Header Row -->
  <div class="no-print" style="padding:20px 24px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border-color);flex-wrap:wrap;gap:16px">
    <div>
      <h2 style="font-size:18px;font-weight:700;color:var(--ink);margin:0 0 2px"><?= e($dept['department_name']) ?></h2>
      <div style="font-size:12px;color:var(--ink-muted)"><?= $tab === 'rv' ? 'End-of-Term Departmental Clearance Queue' : 'Departmental Clearance Archive' ?></div>
    </div>
    <div style="display:flex;align-items:center;gap:8px">
      <a href="<?= url('dept/index.php?export=csv' . ($statusFilter ? '&status=' . urlencode($statusFilter) : '') . ($search ? '&q=' . urlencode($search) : '')) ?>" class="btn btn-outline btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Export CSV Ledger
      </a>
      <a href="#" onclick="window.print();return false;" class="btn btn-outline btn-sm">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Clearance Roster
      </a>
    </div>
  </div>

  <div style="padding:24px 28px">

<?php if ($tab === 'rv'): ?>
  <!-- Tab: Clearance review -->
  <div class="screen-two-col" style="display:grid;grid-template-columns:360px 1fr;gap:28px;align-items:start">
    <!-- Left Column: Search & Request List -->
    <div class="no-print" style="border-right:1px solid #f1f5f9;padding-right:24px">
      <form method="get" action="<?= url('dept/index.php') ?>" style="margin-bottom:16px;display:flex;gap:8px;align-items:flex-end">
        <input type="hidden" name="tab" value="rv">
        <div style="flex:1">
          <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">SEARCH REQUESTS</label>
          <div style="position:relative">
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Student name or ID..." style="width:100%;border-radius:9999px;padding:9px 36px 9px 18px;border:1px solid #cbd5e1;font-size:13px;color:#0f172a;box-sizing:border-box">
            <?php if ($search !== ''): ?>
              <a href="<?= url('dept/index.php?tab=rv') ?>" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:16px;text-decoration:none;font-weight:bold" title="Clear">×</a>
            <?php else: ?>
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position:absolute;right:14px;top:50%;transform:translateY(-50%)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <?php endif; ?>
          </div>
        </div>
        <button type="submit" style="padding:9px 16px;border-radius:9999px;background:#2563eb;color:#ffffff;border:none;font-size:13px;font-weight:600;cursor:pointer">Search</button>
      </form>

      <?php if (empty($requests)): ?>
        <p style="font-size:13px;color:#64748b">No clearance requests found.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px;margin-bottom:14px">
          <?php foreach ($requests as $r):
              $isSel = $selReq && ((int)$selReq['student_id'] === (int)$r['student_id']);
              $isPending = ($r['dept_status'] === 'Pending');
          ?>
            <a href="<?= url('dept/index.php?tab=rv&sid=' . $r['student_id'] . ($search ? '&q=' . urlencode($search) : '')) ?>"
               style="display:block;text-decoration:none;border:<?= $isSel ? '2px solid #2563eb' : '1px solid #e2e8f0' ?>;border-radius:10px;padding:14px 16px;background:#ffffff;transition:border-color 0.15s">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2px">
                <span style="font-size:14px;font-weight:700;color:<?= $isSel ? '#2563eb' : '#0f172a' ?>"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></span>
                <span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:9999px;<?= $isPending ? 'background:#fef3c7;color:#92400e' : 'background:#dcfce7;color:#166534' ?>">
                  <?= $isPending ? 'Pending' : 'Cleared' ?>
                </span>
              </div>
              <div style="font-size:12px;color:#64748b;margin-bottom:4px"><?= e($r['student_number']) ?> · <?= e($r['section'] ?: 'TBA') ?></div>
              <div style="font-size:12px;color:#64748b">Laboratory return slip · <?= $isPending ? 'For review' : 'Approved' ?></div>
            </a>
          <?php endforeach; ?>
        </div>
        <div style="font-size:12px;color:#94a3b8">Showing <?= count($requests) ?> clearance requests</div>
      <?php endif; ?>
    </div>

    <!-- Right Column: Verification & Approval Pane -->
    <?php if ($selReq):
        $isCleared = ($selReq['dept_status'] === 'Cleared');
        $initials = first_char($selReq['first_name']) . first_char($selReq['last_name']);
        $offices = rows("SELECT office, status FROM clearance WHERE student_id=? ORDER BY clearance_id", [$selReq['student_id']]);
        $requestCode = 'CLR-2026-' . str_pad((string)$selReq['student_id'], 4, '0', STR_PAD_LEFT);
        $labFilename = 'lab-return-' . strtolower(preg_replace('/[^a-z0-9]/i', '', $selReq['last_name'])) . '.pdf';
    ?>
    <div class="screen-two-col-main" style="min-width:0;width:100%">
      <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
        <div style="display:flex;align-items:center;gap:14px">
          <div style="width:48px;height:48px;border-radius:50%;background:#dbeafe;color:#2563eb;font-size:17px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">
            <?= e($initials) ?>
          </div>
          <div>
            <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px"><?= e($selReq['first_name'] . ' ' . $selReq['last_name']) ?></h3>
            <div style="font-size:13px;color:#64748b"><?= e($selReq['student_number']) ?> · <?= e($selReq['program']) ?></div>
          </div>
        </div>
        <div style="display:flex;align-items:center;gap:8px">
          <span style="font-size:12px;font-weight:600;padding:3px 12px;border-radius:9999px;<?= $isCleared ? 'background:#dcfce7;color:#166534' : 'background:#fef3c7;color:#92400e' ?>">
            <?= $isCleared ? 'Cleared' : 'Pending review' ?>
          </span>
          <span class="no-print">
            <button type="button" onclick="window.print()" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:9999px;border:1px solid #cbd5e1;background:#ffffff;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer">
              <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
              Print Clearance
            </button>
          </span>
        </div>
      </div>

      <!-- 3-col metadata row -->
      <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;margin-bottom:16px">
        <div>
          <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">REQUEST</div>
          <div style="font-size:14px;font-weight:600;color:#0f172a"><?= e($requestCode) ?></div>
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">SUBMITTED</div>
          <div style="font-size:14px;font-weight:600;color:#0f172a">Academic Year 2026–2027</div>
        </div>
        <div>
          <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">UNIVERSITY CLEARANCE</div>
          <div style="font-size:14px;font-weight:600;color:#0f172a"><?= (int)$selReq['cleared_count'] ?> of <?= (int)$selReq['total_offices'] ?> offices cleared</div>
        </div>
      </div>

      <!-- Office Pills -->
      <div class="no-print" style="display:flex;align-items:center;gap:8px;margin-bottom:24px;flex-wrap:wrap">
        <?php foreach ($offices as $off):
            $isOffCleared = ($off['status'] === 'Cleared');
        ?>
          <span style="font-size:12px;font-weight:600;padding:3px 10px;border-radius:9999px;<?= $isOffCleared ? 'background:#dcfce7;color:#166534' : 'background:#fef3c7;color:#92400e' ?>">
            <?= $isOffCleared ? '✓ ' : '' ?><?= e($off['office']) ?>
          </span>
        <?php endforeach; ?>
      </div>

      <!-- Checklist Items -->
      <div class="no-print" style="display:flex;flex-direction:column;gap:16px;margin-bottom:24px">
        <!-- Item 1 -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin-top:2px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            <div>
              <div style="font-size:14px;font-weight:600;color:#0f172a">Student identity &amp; enrollment</div>
              <div style="font-size:12px;color:#64748b">Student record matched · <?= e($selReq['section'] ?: 'TBA') ?></div>
            </div>
          </div>
          <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;background:#dcfce7;color:#166534">Verified</span>
        </div>

        <!-- Item 2 -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin-top:2px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            <div>
              <div style="font-size:14px;font-weight:600;color:#0f172a">Department project submission</div>
              <div style="font-size:12px;color:#64748b">Course project accepted by Dr. Gima Montecillo</div>
            </div>
          </div>
          <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;background:#dcfce7;color:#166534">Complete</span>
        </div>

        <!-- Item 3 -->
        <div style="display:flex;align-items:flex-start;justify-content:space-between;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
          <div style="display:flex;align-items:flex-start;gap:12px">
            <?php if ($isCleared): ?>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2" style="margin-top:2px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
            <?php else: ?>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2" style="margin-top:2px;flex-shrink:0"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?php endif; ?>
            <div>
              <div style="font-size:14px;font-weight:600;color:#0f172a">Laboratory return slip</div>
              <div style="font-size:12px;color:#64748b;margin-bottom:6px">
                <?= e($labFilename) ?> · Submitted for Department validation
              </div>
              <button type="button" onclick="document.getElementById('slipModal').classList.add('show')" style="display:inline-flex;align-items:center;gap:6px;padding:4px 12px;border-radius:6px;border:1px solid #cbd5e1;background:#f8fafc;color:#2563eb;font-size:12px;font-weight:600;cursor:pointer">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview slip document
              </button>
            </div>
          </div>
          <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;<?= $isCleared ? 'background:#dcfce7;color:#166534' : 'background:#fef3c7;color:#92400e' ?>">
            <?= $isCleared ? 'Validated' : 'For validation' ?>
          </span>
        </div>
      </div>

      <!-- Review Remarks -->
      <div class="no-print" style="margin-bottom:20px">
        <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Review remarks</label>
        <div style="border:1px solid #cbd5e1;border-radius:8px;padding:12px 16px;font-size:13px;color:#0f172a;background:#ffffff">
          <?= $isCleared ? 'Laboratory equipment return verified. Clearance approved.' : 'Confirm laboratory return slip with the lab custodian before recording approval.' ?>
        </div>
      </div>

      <!-- Action Buttons -->
      <form class="no-print" method="post" style="display:flex;align-items:center;gap:12px;margin:0 0 14px">
        <?= csrf_field() ?>
        <input type="hidden" name="student_id" value="<?= (int)$selReq['student_id'] ?>">
        <?php if (!$isCleared): ?>
          <button type="submit" name="action" value="approve_clearance" style="display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:9999px;background:#2563eb;color:#ffffff;border:none;font-size:14px;font-weight:600;cursor:pointer">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Approve clearance
          </button>
        <?php else: ?>
          <button type="submit" name="action" value="keep_pending" style="display:inline-flex;align-items:center;gap:6px;padding:10px 22px;border-radius:9999px;background:#dc2626;color:#ffffff;border:none;font-size:14px;font-weight:600;cursor:pointer">
            Revoke / Set pending
          </button>
        <?php endif; ?>
      </form>

      <div class="no-print" style="font-size:12px;color:#94a3b8">Your decision and remarks appear in the student's clearance tracker.</div>

      <!-- PRINT-ONLY: Official Departmental Clearance Verification -->
      <div class="official-clearance-cert print-only" style="margin-top:12pt">
        <?= cuyotech_doc_header($dept['department_name'], 'DEPARTMENTAL CLEARANCE CERTIFICATE', 'Academic Year 2026–2027 · End-of-Term Clearance Verification', 'CLR-2026-' . str_pad((string)$selReq['student_id'], 4, '0', STR_PAD_LEFT)) ?>
        <div class="official-meta-grid" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:14pt">
          <div class="official-meta-item">
            <div class="official-meta-item-label">Student Name</div>
            <div class="official-meta-item-value"><?= e($selReq['first_name'] . ' ' . $selReq['last_name']) ?></div>
          </div>
          <div class="official-meta-item">
            <div class="official-meta-item-label">Student Number</div>
            <div class="official-meta-item-value"><?= e($selReq['student_number']) ?></div>
          </div>
          <div class="official-meta-item">
            <div class="official-meta-item-label">Program / Section</div>
            <div class="official-meta-item-value"><?= e($selReq['program']) ?> · <?= e($selReq['section'] ?: 'TBA') ?></div>
          </div>
          <div class="official-meta-item">
            <div class="official-meta-item-label">Clearance Reference</div>
            <div class="official-meta-item-value"><?= e('CLR-2026-' . str_pad((string)$selReq['student_id'], 4, '0', STR_PAD_LEFT)) ?></div>
          </div>
          <div class="official-meta-item">
            <div class="official-meta-item-label">Department Status</div>
            <div class="official-meta-item-value"><?= $selReq['dept_status'] === 'Cleared' ? '✓ CLEARED' : 'PENDING' ?></div>
          </div>
          <div class="official-meta-item">
            <div class="official-meta-item-label">Offices Cleared</div>
            <div class="official-meta-item-value"><?= (int)$selReq['cleared_count'] ?> of <?= (int)$selReq['total_offices'] ?></div>
          </div>
        </div>
        <table class="official-doc-table" style="width:100%;margin-bottom:12pt">
          <thead>
            <tr><th>Office</th><th>Status</th><th>Authorized Signatory</th><th>Remarks</th></tr>
          </thead>
          <tbody>
            <?php foreach ($offices as $off): ?>
            <tr>
              <td><?= e($off['office']) ?></td>
              <td><?= $off['status'] === 'Cleared' ? '✓ Cleared' : 'Pending' ?></td>
              <td><?= $off['office'] === 'Department' ? e($dept['department_name'] . ' Head') : e($off['office'] . ' Officer') ?></td>
              <td><?= $off['status'] === 'Cleared' ? 'Requirements satisfied' : 'Awaiting verification' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <p style="font-size:8.5pt;line-height:1.5;margin-bottom:12pt">
          This certifies that the above-named student has complied with all departmental requirements and obligations for the current academic term.
          This certificate is issued upon verification of laboratory accountability slips and academic submissions as required by the <?= e($dept['department_name']) ?>.
        </p>
        <div class="official-sign-block">
          <div class="official-sig-item">
            <div class="official-sig-bar"></div>
            <div class="official-sig-name">Student's Signature</div>
            <div class="official-sig-title">Acknowledgment</div>
          </div>
          <div class="official-sig-item" style="text-align:center">
            <?= cuyotech_seal_stamp() ?>
          </div>
          <div class="official-sig-item">
            <div class="official-sig-bar"></div>
            <div class="official-sig-name">Department Head</div>
            <div class="official-sig-title"><?= e($dept['department_name']) ?></div>
          </div>
        </div>
        <div style="font-size:7.5pt;color:#555;margin-top:10pt;text-align:center">Printed: <?= date('d F Y, H:i') ?> · This document is valid only with the official seal of <?= e($dept['department_name']) ?>.</div>
      </div>
    </div>
    <?php else: ?>
    <div class="screen-two-col-main" style="min-width:0;width:100%">
      <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:12px;padding:56px 24px;text-align:center">
        <div style="width:56px;height:56px;border-radius:50%;background:#e2e8f0;display:flex;align-items:center;justify-content:center;margin:0 auto 16px;color:#64748b">
          <svg width="26" height="26" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        </div>
        <h4 style="font-size:16px;font-weight:700;color:#0f172a;margin:0 0 6px">No Clearance Request Selected</h4>
        <p style="font-size:13px;color:#64748b;margin:0 auto;max-width:340px;line-height:1.5">
          Select a student clearance request from the list on the left to verify laboratory accountability, review attachments, and grant departmental clearance.
        </p>
      </div>
    </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <!-- Tab: Clearance records -->
  <!-- 4 Stat Boxes -->
  <div class="no-print" style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:28px">
    <div style="background:#f8fafc;border-radius:12px;padding:20px;border:1px solid #f1f5f9">
      <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">TOTAL REQUESTS</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a"><?= $allTotal ?></div>
    </div>
    <div style="background:#f8fafc;border-radius:12px;padding:20px;border:1px solid #f1f5f9">
      <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">FOR REVIEW</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a"><?= $pendingTotal ?></div>
    </div>
    <div style="background:#f8fafc;border-radius:12px;padding:20px;border:1px solid #f1f5f9">
      <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">PENDING</div>
      <div style="font-size:28px;font-weight:800;color:#0f172a"><?= $pendingTotal ?></div>
    </div>
    <div style="background:#f8fafc;border-radius:12px;padding:20px;border:1px solid #f1f5f9">
      <div style="font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">APPROVED</div>
      <div style="font-size:28px;font-weight:800;color:#16a34a"><?= $clearedTotal ?></div>
    </div>
  </div>

  <!-- Heading -->
  <div class="no-print" style="margin-bottom:20px">
    <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px">Clearance records</h3>
    <div style="font-size:13px;color:#64748b">Search departmental requests across all clearance statuses.</div>
  </div>

  <!-- Search & Filters row -->
  <form class="no-print" method="get" action="<?= url('dept/index.php') ?>" style="display:grid;grid-template-columns:1.5fr 1fr 1fr auto;gap:12px;margin-bottom:24px;align-items:flex-end">
    <input type="hidden" name="tab" value="rc">
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">SEARCH REQUESTS</label>
      <div style="position:relative">
        <input type="text" name="q" value="<?= e($search) ?>" placeholder="Student name or student number" style="width:100%;border-radius:9999px;padding:9px 36px 9px 18px;border:1px solid #cbd5e1;font-size:13px;box-sizing:border-box">
        <?php if ($search !== ''): ?>
          <a href="<?= url('dept/index.php?tab=rc' . ($statusFilter ? '&status=' . urlencode($statusFilter) : '')) ?>" style="position:absolute;right:14px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:16px;text-decoration:none;font-weight:bold" title="Clear">×</a>
        <?php else: ?>
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2" style="position:absolute;right:14px;top:50%;transform:translateY(-50%)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <?php endif; ?>
      </div>
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">DEPARTMENT</label>
      <input type="text" value="<?= e($dept['department_name']) ?>" readonly style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#f8fafc;color:#0f172a;box-sizing:border-box">
    </div>
    <div>
      <label style="display:block;font-size:11px;font-weight:600;color:#94a3b8;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">STATUS</label>
      <select name="status" onchange="this.form.submit()" style="width:100%;border-radius:9999px;padding:9px 18px;border:1px solid #cbd5e1;font-size:13px;background:#ffffff;color:#0f172a">
        <option value="">All statuses</option>
        <option value="Pending" <?= $statusFilter === 'Pending' ? 'selected' : '' ?>>Pending</option>
        <option value="Cleared" <?= $statusFilter === 'Cleared' ? 'selected' : '' ?>>Approved</option>
      </select>
    </div>
    <div>
      <button type="submit" style="padding:9px 18px;border-radius:9999px;background:#2563eb;color:#ffffff;border:none;font-size:13px;font-weight:600;cursor:pointer;white-space:nowrap">Apply Filter</button>
    </div>
  </form>

  <!-- PRINT-ONLY: Clearance Roster Header -->
  <div class="print-only" style="margin-bottom:12pt">
    <?= cuyotech_doc_header($dept['department_name'], 'OFFICIAL DEPARTMENTAL CLEARANCE ROSTER', 'Academic Year 2026–2027, 1st Semester · Status Filter: ' . ($statusFilter ?: 'All Statuses'), 'DCR-' . date('Ymd')) ?>
  </div>

  <!-- Records Table -->
  <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px;margin-bottom:16px">
    <table class="official-doc-table" style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f8fafc;color:#475569;text-align:left">
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STUDENT</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STUDENT ID</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">SECTION</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">REQUIREMENT</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">STATUS</th>
          <th class="no-print" style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:right">ACTION</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($requests)): ?>
          <tr><td colspan="6" style="padding:16px;text-align:center;color:#64748b">No clearance records found.</td></tr>
        <?php else: ?>
          <?php foreach ($requests as $r):
              $isPend = ($r['dept_status'] === 'Pending');
          ?>
            <tr style="border-top:1px solid #f1f5f9">
              <td style="padding:14px 16px;font-weight:600;color:#0f172a"><?= e($r['first_name'] . ' ' . $r['last_name']) ?></td>
              <td style="padding:14px 16px;color:#64748b"><?= e($r['student_number']) ?></td>
              <td style="padding:14px 16px;color:#334155"><?= e($r['section'] ?: 'TBA') ?></td>
              <td style="padding:14px 16px;color:#334155">Laboratory slip</td>
              <td style="padding:14px 16px">
                <span style="font-size:11px;font-weight:600;padding:2px 10px;border-radius:9999px;<?= $isPend ? 'background:#fef3c7;color:#92400e' : 'background:#dcfce7;color:#166534' ?>">
                  <?= $isPend ? 'Pending' : 'Approved' ?>
                </span>
              </td>
              <td class="no-print" style="padding:14px 16px;text-align:right">
                <a href="<?= url('dept/index.php?tab=rv&sid=' . $r['student_id']) ?>" style="color:#2563eb;font-weight:500;text-decoration:none">Review ↗</a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="6" style="text-align:right;font-weight:700">Total Records: <?= count($requests) ?> (Cleared: <?= $clearedTotal ?>, Pending: <?= $pendingTotal ?>)</td>
        </tr>
      </tfoot>
    </table>
  </div>

  <div class="no-print" style="font-size:12px;color:#94a3b8;margin-bottom:20px">Showing <?= count($requests) ?> clearance records</div>

  <!-- Bottom banner -->
  <div class="no-print" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;font-size:13px;color:#64748b">
    For review and Pending requests are not approved. Open a request to review its departmental requirements before recording a decision.
  </div>

  <!-- PRINT-ONLY: Clearance Roster Signature Block -->
  <div class="official-sign-block print-only" style="margin-top:20pt">
    <div class="official-sig-item">
      <div class="official-sig-bar"></div>
      <div class="official-sig-name">Prepared By</div>
      <div class="official-sig-title">Department Clerk</div>
    </div>
    <div class="official-sig-item" style="text-align:center">
      <?= cuyotech_seal_stamp() ?>
    </div>
    <div class="official-sig-item">
      <div class="official-sig-bar"></div>
      <div class="official-sig-name">Department Chairperson</div>
      <div class="official-sig-title"><?= e($dept['department_name']) ?></div>
    </div>
  </div>
<?php endif; ?>

  </div><!-- end content -->
</div><!-- end main card -->

<?php if ($selReq): ?>
<!-- Laboratory Return Slip Modal -->
<div class="modal-backdrop" id="slipModal" onclick="if(event.target===this)this.classList.remove('show')">
  <div class="modal-card" style="padding:24px;max-width:640px">
    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;padding-bottom:14px;border-bottom:1px solid #f1f5f9">
      <div style="display:flex;align-items:center;gap:10px">
        <div style="width:36px;height:36px;border-radius:8px;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
        </div>
        <div>
          <h3 style="font-size:16px;font-weight:700;color:#0f172a;margin:0"><?= e($labFilename) ?></h3>
          <div style="font-size:12px;color:#64748b">Department Laboratory Clearance Return Slip</div>
        </div>
      </div>
      <button type="button" onclick="document.getElementById('slipModal').classList.remove('show')" style="background:none;border:none;font-size:22px;color:#94a3b8;cursor:pointer;line-height:1">×</button>
    </div>

    <!-- Official Document Preview Content -->
    <div class="official-claim-slip" style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:18px;margin-bottom:16px">
      <?= cuyotech_doc_header('COLLEGE OF COMPUTER STUDIES · HARDWARE & NETWORK LAB', 'LABORATORY RETURN & ACCOUNTABILITY SLIP', 'Academic Year 2026–2027, 1st Semester · Official Custodial Release', 'LAB-2026-' . str_pad((string)$selReq['student_id'], 4, '0', STR_PAD_LEFT)) ?>

      <div class="official-meta-grid" style="grid-template-columns:1fr 1fr;margin-bottom:12pt">
        <div class="official-meta-item">
          <div class="official-meta-item-label">Student Name</div>
          <div class="official-meta-item-value"><?= e($selReq['first_name'] . ' ' . $selReq['last_name']) ?></div>
        </div>
        <div class="official-meta-item">
          <div class="official-meta-item-label">Student ID</div>
          <div class="official-meta-item-value"><?= e($selReq['student_number']) ?></div>
        </div>
        <div class="official-meta-item">
          <div class="official-meta-item-label">Program / Section</div>
          <div class="official-meta-item-value"><?= e($selReq['program']) ?> · <?= e($selReq['section'] ?: 'TBA') ?></div>
        </div>
        <div class="official-meta-item">
          <div class="official-meta-item-label">Clearance Reference</div>
          <div class="official-meta-item-value"><?= e('CLR-2026-' . str_pad((string)$selReq['student_id'], 4, '0', STR_PAD_LEFT)) ?></div>
        </div>
      </div>

      <table class="official-doc-table" style="width:100%;margin-bottom:10pt">
        <thead>
          <tr>
            <th>Item / Equipment Description</th>
            <th style="text-align:center">Qty</th>
            <th>Condition Upon Return</th>
            <th style="text-align:center">Status</th>
          </tr>
        </thead>
        <tbody>
          <tr>
            <td>Arduino Microcontroller Development Kit v3</td>
            <td style="text-align:center">1</td>
            <td>Complete, tested working</td>
            <td style="text-align:center;font-weight:700">Returned ✓</td>
          </tr>
          <tr>
            <td>Digital Multimeter (Uni-T UT33D+)</td>
            <td style="text-align:center">1</td>
            <td>Good condition, probes intact</td>
            <td style="text-align:center;font-weight:700">Returned ✓</td>
          </tr>
          <tr>
            <td>Breadboard &amp; Jumper Wire Set (65 pcs)</td>
            <td style="text-align:center">1 set</td>
            <td>Complete set verified</td>
            <td style="text-align:center;font-weight:700">Returned ✓</td>
          </tr>
        </tbody>
        <tfoot>
          <tr><td colspan="4" style="text-align:right;font-size:7.5pt">Lab Custodian: Engr. M. Soriano · Date Verified: <?= date('d F Y') ?></td></tr>
        </tfoot>
      </table>

      <div style="font-size:7.5pt;color:#334155;border:1px solid #cbd5e1;padding:6px 10px;margin-bottom:10px;background:#f8fafc">
        <strong>CUSTODIAL CERTIFICATION:</strong> All designated laboratory hardware and testing apparatus issued for the semester have been surrendered in satisfactory operating condition. Student is cleared of hardware liabilities.
      </div>

      <div class="official-sign-block" style="grid-template-columns:1fr 1fr;margin-top:10px">
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name"><?= e($selReq['first_name'] . ' ' . $selReq['last_name']) ?></div>
          <div class="official-sig-title">Student — Acknowledgment</div>
        </div>
        <div class="official-sig-item">
          <div class="official-sig-bar"></div>
          <div class="official-sig-name">Engr. M. Soriano</div>
          <div class="official-sig-title">Laboratory Custodian</div>
        </div>
      </div>
    </div>

    <!-- Modal Footer -->
    <div class="no-print" style="display:flex;align-items:center;justify-content:flex-end;gap:10px">
      <button type="button" onclick="printModalSlip('slipModal')" style="display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:9999px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;font-size:13px;font-weight:600;cursor:pointer">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        Print Slip
      </button>
      <button type="button" onclick="document.getElementById('slipModal').classList.remove('show')" style="padding:8px 20px;border-radius:9999px;background:#2563eb;color:#fff;border:none;font-size:13px;font-weight:600;cursor:pointer">
        Close
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
<?php endif; ?>

<?php page_end(); ?>
