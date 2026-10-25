<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('cashier');

$TABS = [
    'pay' => 'Record payment',
    'his' => 'Payment history',
    'rc'  => 'Receipts'
];
$tab = $_GET['tab'] ?? 'pay';
if (!array_key_exists($tab, $TABS)) $tab = 'pay';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'post_payment') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $rawAmount = str_replace([',', ' ', '₱'], '', (string)($_POST['amount'] ?? '0'));
        $amount = (float)$rawAmount;
        $method = trim((string)($_POST['method'] ?? 'Bank transfer'));
        $payType = trim((string)($_POST['payment_type'] ?? 'Tuition'));
        $ref = trim((string)($_POST['ref'] ?? ''));
        $desc = $payType . ($method ? ' · ' . $method : '') . ($ref ? ' (' . $ref . ')' : '');

        $target = trim((string)($_POST['target'] ?? ''));
        $targetEid = null;
        $targetReqId = null;
        if (str_starts_with($target, 'enrollment:')) {
            $eidVal = (int)substr($target, 11);
            $targetEid = $eidVal > 0 ? $eidVal : null;
        } elseif (str_starts_with($target, 'document:')) {
            $reqVal = (int)substr($target, 9);
            $targetReqId = $reqVal > 0 ? $reqVal : null;
        }

        // Verify target ownership
        if ($targetEid !== null && !row("SELECT enrollment_id FROM enrollment WHERE enrollment_id=? AND student_id=?", [$targetEid, $sid])) {
            $targetEid = null;
        }
        if ($targetReqId !== null && !row("SELECT request_id FROM document_request WHERE request_id=? AND student_id=?", [$targetReqId, $sid])) {
            $targetReqId = null;
        }

        $st = row("SELECT student_id FROM student WHERE student_id=?", [$sid]);
        if ($sid > 0 && $st && $amount > 0) {
            q("INSERT INTO payment(student_id, enrollment_id, request_id, processed_by, amount, payment_date, payment_type, payment_status)
               VALUES(?, ?, ?, ?, ?, CURDATE(), ?, 'Paid')",
               [$sid, $targetEid, $targetReqId, (int)$u['user_id'], $amount, $desc]);
            $newPayId = (int)$pdo->lastInsertId();
            audit('Posted payment ' . receipt_no($newPayId) . ' (' . money($amount) . ') for student #' . $sid);
            notify_student($sid, 'Payment Received', 'Official Receipt ' . receipt_no($newPayId) . ' for ' . money($amount) . ' (' . $payType . ') has been credited to your account.', 'student/index.php?tab=en');
            flash('Payment posted successfully. Official Receipt ' . receipt_no($newPayId) . ' generated.');
            redirect('cashier/index.php?tab=rc&pid=' . $newPayId . '&sid=' . $sid);
        } else {
            flash('Please enter a valid amount and student.', true);
            redirect('cashier/index.php?tab=pay&sid=' . $sid);
        }
    }
    if ($act === 'update_cashier_clearance') {
        $sid = (int)($_POST['student_id'] ?? 0);
        $st = row("SELECT student_id FROM student WHERE student_id=?", [$sid]);
        if (!$st) { flash('Student record not found.', true); redirect('cashier/index.php'); }
        $newSt = (($_POST['status'] ?? '') === 'Cleared') ? 'Cleared' : 'Pending';
        ensure_student_clearance($sid);
        q("UPDATE clearance SET status=? WHERE student_id=? AND office='Cashier'", [$newSt, $sid]);
        audit("Cashier set clearance to $newSt for student #$sid");
        notify_student($sid, 'Cashier Clearance Updated', "Your Cashier clearance status has been set to: $newSt.", 'student/index.php?tab=cl');
        flash("Cashier clearance status updated to $newSt.");
        redirect('cashier/index.php?tab=' . $tab . '&sid=' . $sid);
    }
}

// Student selection & search
$search = trim((string)($_GET['q'] ?? ''));

$studentListQuery = "SELECT student_id, student_number, first_name, last_name, program, section FROM student";
$studentListParams = [];
if ($search !== '') {
    $studentListQuery .= " WHERE student_number LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR last_name LIKE ? OR first_name LIKE ?";
    $studentListParams = ["%$search%", "%$search%", "%$search%", "%$search%"];
}
$studentListQuery .= " ORDER BY student_id ASC";
$studentList = rows($studentListQuery, $studentListParams);

$selSid = isset($_GET['sid']) ? (int)$_GET['sid'] : 0;
$s = null;
if ($selSid > 0) {
    $s = row("SELECT * FROM student WHERE student_id=?", [$selSid]);
}

if (!$s) {
    page_start('cashier', 'Cashiering & Student Accounts', 'Official fee assessment, tuition collection, payment posting, and Official Receipt (OR) issuance.', 'FINANCE DIVISION / CASHIERING & TREASURY SERVICES');
    ?>
    <div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:28px 32px">
      <!-- Terminal Header Bar -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:16px;border-bottom:1px solid #f1f5f9;padding-bottom:20px">
        <div>
          <div style="font-size:11px;font-weight:700;color:#64748b;letter-spacing:0.06em;text-transform:uppercase;margin-bottom:4px">
            CASHIERING TERMINAL WINDOW · MAIN CAMPUS
          </div>
          <h2 style="font-size:22px;font-weight:800;color:#0f172a;margin:0;letter-spacing:-0.02em">
            Student Accounts &amp; Payment Processing
          </h2>
          <p class="phone-hide" style="font-size:13px;color:#64748b;margin:4px 0 0">
            Search by student number or select an account below to post tuition payments, view balance ledgers, and issue official receipts.
          </p>
        </div>
        
        <div style="display:flex;gap:12px;align-items:center">
          <span style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:700;padding:6px 14px;border-radius:9999px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">
            <span style="width:7px;height:7px;border-radius:50%;background:#10b981"></span>
            Terminal Ready
          </span>
        </div>
      </div>

      <!-- Search & Quick Selection Console -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:12px;padding:20px 24px;margin-bottom:28px">
        <form method="get" action="<?= url('cashier/index.php') ?>" style="display:flex;gap:12px;align-items:center;margin:0;flex-wrap:wrap">
          <div style="position:relative;flex:1;min-width:260px">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#64748b" stroke-width="2" style="position:absolute;left:16px;top:50%;transform:translateY(-50%)"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="q" value="<?= e($search) ?>" placeholder="Enter Student Number (e.g. 2023-00418) or Student Full Name..." 
                   style="width:100%;height:46px;padding:0 16px 0 46px;font-size:14px;border:1.5px solid #cbd5e1;border-radius:8px;outline:none;box-sizing:border-box;background:#ffffff;color:#0f172a;transition:border-color 0.15s"
                   onfocus="this.style.borderColor='#1e40af'" onblur="this.style.borderColor='#cbd5e1'" autofocus>
          </div>
          <button type="submit" class="btn btn-primary" style="height:46px;padding:0 24px;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Search
          </button>
          <?php if ($search !== ''): ?>
            <a href="<?= url('cashier/index.php') ?>" class="btn btn-outline" style="height:46px;padding:0 18px;font-size:13px;font-weight:600;display:inline-flex;align-items:center;gap:6px">
              Clear
            </a>
          <?php endif; ?>
        </form>
        
        <?php if ($search !== '' && empty($studentList)): ?>
          <div style="margin-top:14px;font-size:13px;color:#dc2626;background:#fef2f2;padding:10px 14px;border-radius:6px;border:1px solid #fecaca;display:flex;align-items:center;gap:8px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            No student account matched "<strong><?= e($search) ?></strong>". <a href="<?= url('cashier/index.php') ?>" style="color:#2563eb;text-decoration:underline;margin-left:4px">View all accounts</a>
          </div>
        <?php endif; ?>
      </div>

      <!-- Student Accounts Roster Table -->
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="font-size:14px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em">
          <?= $search !== '' ? 'Search Results (' . count($studentList) . ' Matched)' : 'Student Accounts Roster (' . count($studentList) . ' Enrolled Students)' ?>
        </div>
        <div style="font-size:12px;color:#64748b">
          <?= $search !== '' ? 'Click "Select Account" to open student transaction' : 'Select an account to load ledger and process payments' ?>
        </div>
      </div>

      <div class="table-container" style="border:1px solid #e2e8f0;border-radius:10px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,0.03);margin-bottom:0">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:13px">
          <thead>
            <tr style="background:#f8fafc;color:#475569;border-bottom:1px solid #e2e8f0;text-align:left">
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">STUDENT NUMBER</th>
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">STUDENT NAME</th>
              <th style="padding:12px 18px;font-weight:700;font-size:11px;letter-spacing:0.04em">PROGRAM</th>
              <th style="padding:12px 18px;text-align:center;font-weight:700;font-size:11px;letter-spacing:0.04em">ASSESSED FEE</th>
              <th style="padding:12px 18px;text-align:center;font-weight:700;font-size:11px;letter-spacing:0.04em">OUTSTANDING BALANCE</th>
              <th style="padding:12px 18px;text-align:right;font-weight:700;font-size:11px;letter-spacing:0.04em">ACTION</th>
            </tr>
          </thead>
          <tbody>
            <?php if (empty($studentList)): ?>
              <tr>
                <td colspan="6" style="padding:40px 20px;text-align:center;color:#64748b;font-size:13px">
                  No enrolled student records found matching "<strong><?= e($search) ?></strong>".
                  <div style="margin-top:8px">
                    <a href="<?= url('cashier/index.php') ?>" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:6px">
                      Show All Students
                    </a>
                  </div>
                </td>
              </tr>
            <?php else: ?>
              <?php 
              foreach ($studentList as $st): 
                  $stId = (int)$st['student_id'];
                  $stPaid = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE student_id=? AND payment_status='Paid'", [$stId])['s'];
                  $stBal = max(0.00, 24000.00 - $stPaid);
                  $stInits = first_char($st['first_name']) . first_char($st['last_name']);
              ?>
                <tr style="border-bottom:1px solid #f1f5f9;transition:background 0.12s" onmouseover="this.style.background='#f8fafc'" onmouseout="this.style.background='transparent'">
                  <td style="padding:14px 18px">
                    <span style="font-family:monospace;font-weight:700;font-size:12.5px;color:#0f172a;background:#f1f5f9;padding:3px 8px;border-radius:4px">
                      <?= e($st['student_number']) ?>
                    </span>
                  </td>
                  <td style="padding:14px 18px">
                    <div style="display:flex;align-items:center;gap:10px">
                      <div style="width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg, #0f2b5c, #1e40af);color:#ffffff;font-size:12px;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0">
                        <?= e($stInits) ?>
                      </div>
                      <div style="font-weight:700;color:#0f172a">
                        <?= e($st['last_name'] . ', ' . $st['first_name']) ?>
                      </div>
                    </div>
                  </td>
                  <td style="padding:14px 18px;color:#475569">
                    <?= e($st['program']) ?> · <?= e(year_label($st['section'])) ?>
                  </td>
                  <td style="padding:14px 18px;text-align:center;font-weight:600;color:#334155">
                    ₱24,000.00
                  </td>
                  <td style="padding:14px 18px;text-align:center">
                    <?php if ($stBal > 0): ?>
                      <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:3px 10px;border-radius:9999px;background:#fef3c7;color:#92400e;border:1px solid #fde68a">
                        <?= money($stBal) ?> Due
                      </span>
                    <?php else: ?>
                      <span style="display:inline-flex;align-items:center;gap:4px;font-size:12px;font-weight:700;padding:3px 10px;border-radius:9999px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0">
                        ✓ Fully Settled
                      </span>
                    <?php endif; ?>
                  </td>
                  <td style="padding:14px 18px;text-align:right">
                    <a href="<?= url('cashier/index.php?tab=pay&sid=' . $st['student_id']) ?>" 
                       class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:600;padding:6px 14px">
                      <span>Select Account</span>
                      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="9 18 15 12 9 6"/></svg>
                    </a>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php
    page_end();
    exit;
}

$studentId = (int)$s['student_id'];
sidebar_tabs('cashier/index.php', $TABS, $tab, ['sid' => $studentId]);
$initials = first_char($s['first_name']) . first_char($s['last_name']);

// Financial computations
$totalAssessment = SEMESTER_TUITION_FEE;
$currentEnrollment = row("SELECT * FROM enrollment WHERE student_id=? ORDER BY enrollment_id DESC LIMIT 1", [$studentId]);
$tuitionPaid = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE student_id=? AND (request_id IS NULL OR request_id = 0) AND payment_status='Paid'", [$studentId])['s'];
$outstandingTuition = max(0.00, $totalAssessment - $tuitionPaid);

// Document requests for this student
$studentDocRequests = rows("SELECT dr.*, COALESCE((SELECT SUM(amount) FROM payment p WHERE p.request_id=dr.request_id AND p.payment_status='Paid'), 0) AS paid_amount
                            FROM document_request dr WHERE dr.student_id=? ORDER BY dr.request_id DESC", [$studentId]);

$paymentsReceived = (float)row("SELECT COALESCE(SUM(amount), 0) s FROM payment WHERE student_id=? AND payment_status='Paid'", [$studentId])['s'];
$outstandingBalance = $outstandingTuition;

page_start('cashier', 'Cashiering & Student Accounts', 'Official fee assessment, tuition collection, payment posting, and Official Receipt (OR) issuance.', 'FINANCE DIVISION / CASHIERING & TREASURY SERVICES');
?>

<div style="background:#ffffff;border:1px solid var(--border-color);border-radius:var(--radius-lg);box-shadow:var(--shadow-card);padding:24px 28px">
  <!-- Top-Left Return & Student Profile Bar -->
  <?php
  ensure_student_clearance($studentId);
  $cshClr = row("SELECT status FROM clearance WHERE student_id=? AND office='Cashier'", [$studentId]);
  $isCshCleared = ($cshClr && $cshClr['status'] === 'Cleared');
  ?>
  <div class="no-print" style="margin-bottom:20px">
    <div style="display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap">
      <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
        <a href="<?= url('cashier/index.php') ?>" class="btn btn-outline" style="height:38px;display:inline-flex;align-items:center;gap:6px;font-size:13px;font-weight:600;padding:0 14px;border-radius:6px">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
          <span>Return</span>
        </a>
        <div style="width:1px;height:36px;background:#e2e8f0"></div>
        <div class="cashier-student-info">
          <div class="cashier-avatar">
            <?= e($initials) ?>
          </div>
          <div>
            <div class="cashier-name-row">
              <h3 class="cashier-student-name"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></h3>
              <span class="badge <?= $outstandingBalance > 0 ? 'badge-amber' : 'badge-green' ?>">
                <?= $outstandingBalance > 0 ? 'Balance Due' : 'Fully Settled' ?>
              </span>
            </div>
            <div class="cashier-meta-row">
              Student ID: <strong style="color:#0f172a"><?= e($s['student_number']) ?></strong> &nbsp;·&nbsp; <?= e($s['program']) ?> &nbsp;·&nbsp; <?= e(year_label($s['section'])) ?>
            </div>
          </div>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
        <span class="badge <?= $isCshCleared ? 'badge-green' : 'badge-amber' ?>" style="font-size:12px;padding:5px 12px">
          <?= $isCshCleared ? '✓ Cashier Cleared' : 'Cashier Clearance Pending' ?>
        </span>
        <form method="post" style="margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="update_cashier_clearance">
          <input type="hidden" name="student_id" value="<?= $studentId ?>">
          <input type="hidden" name="status" value="<?= $isCshCleared ? 'Pending' : 'Cleared' ?>">
          <button type="submit" class="btn <?= $isCshCleared ? 'btn-outline' : 'btn-primary' ?> btn-sm" style="font-size:12px;padding:6px 14px">
            <?= $isCshCleared ? 'Set Pending' : '✓ Clear Cashier Liability' ?>
          </button>
        </form>
      </div>
    </div>
  </div>

<?php if ($tab === 'pay'): ?>
  <!-- Tab: Record Payment - Structured 2-Column Terminal -->
  <div class="cashier-pay-grid" style="display:grid;grid-template-columns:1fr 1.3fr;gap:24px;align-items:start">
    
    <!-- Left Column: Student Financial Ledger & Status -->
    <div style="display:flex;flex-direction:column;gap:18px">
      <!-- Balance Card -->
      <div style="background:#f8fafc;border:1px solid #e2e8f0;border-left:4px solid #1e40af;border-radius:8px;padding:20px">
        <div style="font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px">OUTSTANDING BALANCE DUE</div>
        <div style="font-size:30px;font-weight:800;color:#0f172a;letter-spacing:-0.02em;margin-bottom:8px">
          <?= money($outstandingBalance) ?>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;padding-top:12px;border-top:1px solid #e2e8f0;font-size:12px">
          <div>
            <span style="color:#64748b">Total Assessed:</span>
            <div style="font-weight:700;color:#0f172a"><?= money($totalAssessment) ?></div>
          </div>
          <div>
            <span style="color:#64748b">Credited Payments:</span>
            <div style="font-weight:700;color:#16a34a"><?= money($paymentsReceived) ?></div>
          </div>
        </div>
      </div>

      <!-- Itemized Matriculation Assessment -->
      <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:18px">
        <div style="font-size:12px;font-weight:700;color:#0f172a;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:12px">
          Official Fee Breakdown (AY 2026–2027)
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:12px;color:#334155">
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0">Tuition Fee (18 Units)</td><td style="text-align:right;font-weight:600">₱18,000.00</td></tr>
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0">Registration &amp; Matriculation</td><td style="text-align:right;font-weight:600">₱1,500.00</td></tr>
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0">Computer &amp; Network Lab Fee</td><td style="text-align:right;font-weight:600">₱2,500.00</td></tr>
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0">Library &amp; Institutional Athletic</td><td style="text-align:right;font-weight:600">₱1,200.00</td></tr>
          <tr style="border-bottom:1px solid #f1f5f9"><td style="padding:6px 0">Student Council &amp; Publication</td><td style="text-align:right;font-weight:600">₱800.00</td></tr>
          <tr style="font-weight:700;color:#0f172a"><td style="padding:8px 0">Total Term Liability</td><td style="text-align:right"><?= money($totalAssessment) ?></td></tr>
        </table>
      </div>
    </div>

    <!-- Right Column: Remittance Posting Terminal -->
    <div style="background:#ffffff;border:1px solid #cbd5e1;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(15,23,42,0.05)">
      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid #f1f5f9">
        <div>
          <h3 style="font-size:17px;font-weight:700;color:#0f172a;margin:0 0 2px">Post Student Remittance</h3>
          <div style="font-size:12px;color:#64748b">Record collection and immediately print Official Receipt voucher.</div>
        </div>
        <span style="font-size:11px;font-weight:700;color:#1e40af;background:#eff6ff;padding:4px 8px;border-radius:4px;border:1px solid #bfdbfe">CASHIER WINDOW 1</span>
      </div>

      <form method="post" style="margin:0" onsubmit="return confirm('Post this payment remittance and generate Official Receipt?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="post_payment">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">

        <div style="display:flex;flex-direction:column;gap:16px;margin-bottom:20px">
          <!-- Nature of Collection / Account Target -->
          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Payment For (Account Target)</label>
            <select name="target" id="paymentTargetSelect" onchange="onTargetSelect(this)" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#0f172a;background:#ffffff" required>
              <option value="enrollment:<?= $currentEnrollment ? (int)$currentEnrollment['enrollment_id'] : 0 ?>" data-balance="<?= $outstandingTuition ?>" data-type="Tuition &amp; Matriculation Fee">
                Tuition &amp; Matriculation (Assessment: <?= money($totalAssessment) ?> · Balance Due: <?= money($outstandingTuition) ?>)
              </option>
              <?php foreach ($studentDocRequests as $dr): 
                $drRem = max(0.00, (float)$dr['fee'] - (float)$dr['paid_amount']);
              ?>
                <option value="document:<?= (int)$dr['request_id'] ?>" data-balance="<?= $drRem ?>" data-type="Document Fee: <?= e($dr['document_type']) ?>">
                  Document Request #<?= (int)$dr['request_id'] ?>: <?= e($dr['document_type']) ?> (Fee: <?= money($dr['fee']) ?> · Balance Due: <?= money($drRem) ?>)
                </option>
              <?php endforeach; ?>
              <option value="" data-balance="0" data-type="Other / Auxiliary Fee">Other / Auxiliary Fee</option>
            </select>
          </div>

          <div>
            <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Collection Particulars / Description</label>
            <input type="text" name="payment_type" id="paymentTypeInput" value="Tuition &amp; Matriculation Fee" style="width:100%;padding:10px 14px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#0f172a;box-sizing:border-box" required>
          </div>

          <!-- Remittance Amount with Quick Presets -->
          <div>
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px">
              <label style="font-size:12px;font-weight:600;color:#334155;margin:0">Amount to Remit (PHP)</label>
              <span id="balancePreview" style="font-size:11px;font-weight:600;color:#1e40af"></span>
            </div>
            <div style="position:relative">
              <span style="position:absolute;left:14px;top:50%;transform:translateY(-50%);font-weight:700;color:#64748b;font-size:14px">₱</span>
              <input type="number" step="0.01" min="1" id="payAmountInput" name="amount"
                     value="<?= $outstandingTuition > 0 ? $outstandingTuition : '3000.00' ?>"
                     oninput="updateCalc(this.value)"
                     style="width:100%;padding:10px 14px 10px 32px;border:1px solid #cbd5e1;border-radius:6px;font-size:15px;font-weight:700;color:#0f172a;box-sizing:border-box" required>
            </div>

            <!-- Quick Presets -->
            <div style="display:flex;gap:6px;margin-top:8px;flex-wrap:wrap">
              <button type="button" onclick="setAmount('<?= $outstandingTuition ?>')" style="padding:4px 10px;border-radius:4px;background:#eff6ff;color:#1e40af;border:1px solid #bfdbfe;font-size:11px;font-weight:600;cursor:pointer">Full Balance</button>
              <button type="button" onclick="setAmount('<?= number_format($outstandingTuition / 2, 2, '.', '') ?>')" style="padding:4px 10px;border-radius:4px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;font-size:11px;font-weight:600;cursor:pointer">50% Half</button>
              <button type="button" onclick="setAmount('6000.00')" style="padding:4px 10px;border-radius:4px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;font-size:11px;font-weight:500;cursor:pointer">₱6,000 (Min. Down)</button>
              <button type="button" onclick="setAmount('3000.00')" style="padding:4px 10px;border-radius:4px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;font-size:11px;font-weight:500;cursor:pointer">₱3,000</button>
              <button type="button" onclick="setAmount('1000.00')" style="padding:4px 10px;border-radius:4px;background:#f8fafc;color:#475569;border:1px solid #e2e8f0;font-size:11px;font-weight:500;cursor:pointer">₱1,000</button>
            </div>
          </div>

          <!-- Method & Reference Row -->
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px">
            <div>
              <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Payment Mode</label>
              <select name="method" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#0f172a;background:#ffffff">
                <option value="Cash Remittance">Cash (Counter)</option>
                <option value="Bank Transfer / LandBank">Bank Transfer / LandBank</option>
                <option value="Manager Check">Manager's Check</option>
                <option value="Electronic / Maya / GCash">GCash / Maya Digital</option>
              </select>
            </div>
            <div>
              <label style="display:block;font-size:12px;font-weight:600;color:#334155;margin-bottom:6px">Reference / Deposit Slip #</label>
              <input type="text" name="ref" value="CTU-<?= date('ymd') ?>-<?= substr($s['student_number'], -4) ?>" style="width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;color:#0f172a;box-sizing:border-box">
            </div>
          </div>
        </div>

        <!-- Submit Row -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding-top:16px;border-top:1px solid #f1f5f9;gap:14px;flex-wrap:wrap">
          <div style="font-size:12px;color:#64748b">
            Collecting Officer: <strong><?= e($u['name']) ?></strong> (Cashier Division)
          </div>
          <button type="submit" class="btn btn-primary" style="padding:11px 26px;border-radius:6px;font-size:13.5px;font-weight:600;display:inline-flex;align-items:center;gap:8px">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polyline points="20 6 9 17 4 12"/></svg>
            Post Remittance &amp; Issue Receipt
          </button>
        </div>
      </form>
    </div>
  </div>

  <script>
  function setAmount(val) {
    document.getElementById('payAmountInput').value = val;
    updateCalc(val);
  }
  function onTargetSelect(sel) {
    var opt = sel.options[sel.selectedIndex];
    var bal = parseFloat(opt.getAttribute('data-balance')) || 0;
    var ptype = opt.getAttribute('data-type') || 'Payment';
    document.getElementById('paymentTypeInput').value = ptype;
    if (bal > 0) {
      setAmount(bal.toFixed(2));
    } else {
      updateCalc(document.getElementById('payAmountInput').value);
    }
  }
  function updateCalc(val) {
    var sel = document.getElementById('paymentTargetSelect');
    var opt = sel ? sel.options[sel.selectedIndex] : null;
    var totalBal = opt ? (parseFloat(opt.getAttribute('data-balance')) || 0) : <?= (float)$outstandingTuition ?>;
    var payAmt = parseFloat(val) || 0;
    var remaining = Math.max(0, totalBal - payAmt);
    var el = document.getElementById('balancePreview');
    if (el) {
      el.textContent = 'Remaining on selected item: ₱' + remaining.toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
  }
  updateCalc(document.getElementById('payAmountInput').value);
  </script>

<?php elseif ($tab === 'his'): ?>
  <!-- Tab: Payment history -->
  <?php
  $history = rows("
      SELECT p.*, s.student_number, CONCAT(s.first_name, ' ', s.last_name) AS student_name
      FROM payment p
      JOIN student s ON s.student_id = p.student_id
      WHERE p.student_id = ?
      ORDER BY p.payment_id DESC
  ", [$studentId]);
  ?>
  <!-- 3-col stat box -->
  <div style="background:#eff6ff;border-radius:12px;padding:24px 28px;display:grid;grid-template-columns:repeat(3, 1fr);gap:20px;margin-bottom:28px">
    <div>
      <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">TOTAL ASSESSMENT</div>
      <div style="font-size:26px;font-weight:800;color:#0f172a"><?= money($totalAssessment) ?></div>
    </div>
    <div>
      <div style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">PAYMENTS RECEIVED</div>
      <div style="font-size:26px;font-weight:800;color:#0f172a"><?= money($paymentsReceived) ?></div>
    </div>
    <div>
      <div style="font-size:11px;font-weight:700;color:#2563eb;text-transform:uppercase;letter-spacing:0.05em;margin-bottom:4px">OUTSTANDING BALANCE</div>
      <div style="font-size:26px;font-weight:800;color:#0f172a"><?= money($outstandingBalance) ?></div>
    </div>
  </div>

  <!-- Heading + View statement button -->
  <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:12px">
    <div>
      <h3 style="font-size:20px;font-weight:700;color:#0f172a;margin:0 0 4px">Payment history</h3>
      <div style="font-size:13px;color:#64748b"><?= count($history) ?> transactions · Most recent first</div>
    </div>
    <a href="#" onclick="window.print();return false;" style="display:inline-flex;align-items:center;gap:6px;padding:8px 18px;border-radius:9999px;background:#2563eb;color:#ffffff;font-size:13px;font-weight:600;text-decoration:none">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
      Print statement
    </a>
  </div>

  <!-- Transactions Table -->
  <div style="overflow-x:auto;border:1px solid #e2e8f0;border-radius:8px">
    <table style="width:100%;border-collapse:collapse;font-size:13px">
      <thead>
        <tr style="background:#f8fafc;color:#475569;text-align:left">
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">PAYMENT DATE</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">OFFICIAL RECEIPT NO.</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">PAYMENT DESCRIPTION</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em">AMOUNT RECEIVED</th>
          <th style="padding:11px 16px;font-weight:600;font-size:11px;letter-spacing:0.04em;text-align:right">RECEIPT</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($history)): ?>
          <tr><td colspan="5" style="padding:16px;text-align:center;color:#64748b">No payment transactions recorded for this student.</td></tr>
        <?php else: ?>
          <?php foreach ($history as $h): ?>
            <tr style="border-top:1px solid #f1f5f9">
              <td style="padding:14px 16px;color:#334155"><?= date('d M Y', strtotime($h['payment_date'])) ?></td>
              <td style="padding:14px 16px;font-weight:600;color:#0f172a"><?= receipt_no($h['payment_id']) ?></td>
              <td style="padding:14px 16px;color:#64748b"><?= e($h['payment_type']) ?></td>
              <td style="padding:14px 16px;font-weight:700;color:#0f172a"><?= money($h['amount']) ?></td>
              <td style="padding:14px 16px;text-align:right">
                <a href="<?= url('cashier/index.php?tab=rc&pid=' . $h['payment_id'] . '&sid=' . $studentId) ?>" style="display:inline-flex;align-items:center;gap:6px;padding:6px 14px;border-radius:9999px;border:1px solid #cbd5e1;background:#ffffff;color:#2563eb;font-size:12px;font-weight:600;text-decoration:none">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  View receipt
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
      <tfoot>
        <tr style="border-top:1px solid #e2e8f0;background:#f8fafc">
          <td colspan="3" style="padding:14px 16px;font-weight:700;color:#0f172a">Total payments received</td>
          <td colspan="2" style="padding:14px 16px;font-weight:800;color:#0f172a;text-align:right"><?= money($paymentsReceived) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>

<?php else: ?>
  <!-- Tab: Receipts -->
  <?php
  $receipts = rows("
      SELECT p.*, s.student_number, CONCAT(s.first_name, ' ', s.last_name) AS student_name,
             COALESCE(u.full_name, 'Ms. April B. Galang') AS cashier_name
      FROM payment p
      JOIN student s ON s.student_id = p.student_id
      LEFT JOIN user_account u ON u.user_id = p.processed_by
      WHERE p.student_id = ?
      ORDER BY p.payment_id DESC
  ", [$studentId]);

  $selPid = isset($_GET['pid']) ? (int)$_GET['pid'] : 0;
  $selectedReceipt = null;
  if ($selPid > 0) {
      foreach ($receipts as $rcp) {
          if ((int)$rcp['payment_id'] === $selPid) { $selectedReceipt = $rcp; break; }
      }
  }
  if (!$selectedReceipt && count($receipts) > 0) {
      $selectedReceipt = $receipts[0];
  }
  ?>
  <div class="screen-two-col" style="display:grid;grid-template-columns:320px 1fr;gap:24px;align-items:start">
    <!-- Left Column: Receipts list (Screen only) -->
    <div class="no-print">
      <div style="margin-bottom:14px">
        <h3 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 2px">Receipts</h3>
        <div style="font-size:12px;color:#64748b"><?= count($receipts) ?> official receipts · Most recent first</div>
      </div>

      <?php if (empty($receipts)): ?>
        <p style="font-size:13px;color:#64748b">No receipts issued yet for this student.</p>
      <?php else: ?>
        <div style="display:flex;flex-direction:column;gap:12px">
          <?php foreach ($receipts as $rcp):
              $isSel = $selectedReceipt && ((int)$selectedReceipt['payment_id'] === (int)$rcp['payment_id']);
          ?>
            <a href="<?= url('cashier/index.php?tab=rc&pid=' . $rcp['payment_id'] . '&sid=' . $studentId) ?>"
               style="display:block;text-decoration:none;border:<?= $isSel ? '2px solid #2563eb' : '1px solid #e2e8f0' ?>;border-radius:10px;padding:16px;background:#ffffff">
              <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
                <div style="display:flex;align-items:center;gap:6px;font-weight:700;color:<?= $isSel ? '#2563eb' : '#0f172a' ?>;font-size:13px">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                  <?= receipt_no($rcp['payment_id']) ?>
                </div>
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="<?= $isSel ? '#2563eb' : '#94a3b8' ?>" stroke-width="2"><polyline points="9 18 15 12 9 6"/></svg>
              </div>
              <div style="font-size:12px;color:#64748b;margin-bottom:6px"><?= date('d M Y', strtotime($rcp['payment_date'])) ?> · <?= e($rcp['payment_type']) ?></div>
              <div style="font-size:14px;font-weight:700;color:#0f172a"><?= money($rcp['amount']) ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- Right Column: Official Receipt Paper Slip -->
    <?php if ($selectedReceipt): ?>
    <div class="screen-two-col-main" style="min-width:0;width:100%">
      <!-- Top Action Toolbar (Screen only) -->
      <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
        <div style="display:flex;align-items:center;gap:6px;font-size:12px;font-weight:600;color:#16a34a">
          <span style="width:8px;height:8px;border-radius:50%;background:#16a34a;display:inline-block"></span>
          Issued · Valid official receipt
        </div>
        <div style="display:flex;align-items:center;gap:10px">
          <button type="button" onclick="window.print()" class="btn btn-primary btn-sm">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            Print Official Receipt
          </button>
        </div>
      </div>

      <!-- Authentic Cashier Official Receipt Voucher -->
      <div class="official-receipt-slip">
        <?= cuyotech_receipt_header(receipt_no($selectedReceipt['payment_id']), $selectedReceipt['payment_date']) ?>

        <!-- Received From / Payor Details Matrix -->
        <div class="receipt-payor-grid">
          <div>
            <div style="font-size:7pt;font-weight:700;color:#64748b;text-transform:uppercase">PAYOR (STUDENT NAME)</div>
            <div style="font-size:9.5pt;font-weight:700;color:#0f172a"><?= e($selectedReceipt['student_name']) ?></div>
          </div>
          <div>
            <div style="font-size:7pt;font-weight:700;color:#64748b;text-transform:uppercase">STUDENT NUMBER</div>
            <div style="font-family:monospace;font-size:9.5pt;font-weight:700;color:#0f172a"><?= e($selectedReceipt['student_number']) ?></div>
          </div>
          <div>
            <div style="font-size:7pt;font-weight:700;color:#64748b;text-transform:uppercase">ACADEMIC PROGRAM</div>
            <div style="font-size:8.5pt;font-weight:600;color:#1e293b"><?= e($s['program']) ?></div>
          </div>
          <div>
            <div style="font-size:7pt;font-weight:700;color:#64748b;text-transform:uppercase">ACADEMIC PERIOD</div>
            <div style="font-size:8.5pt;font-weight:600;color:#1e293b">AY 2026–2027 · 1st Semester</div>
          </div>
        </div>

        <!-- Particulars of Collection Table -->
        <table class="receipt-table">
          <thead>
            <tr>
              <th>PARTICULARS / NATURE OF COLLECTION</th>
              <th style="width:130px">FUND CODE</th>
              <th style="text-align:right;width:110px">AMOUNT (₱)</th>
            </tr>
          </thead>
          <tbody>
            <tr>
              <td>
                <div style="font-weight:700"><?= e($selectedReceipt['payment_type']) ?></div>
                <div style="font-size:7pt;color:#64748b">Matriculation &amp; Institutional Fees</div>
              </td>
              <td style="font-size:7.5pt;color:#475569">General Fiduciary (STF-101)</td>
              <td style="text-align:right;font-weight:700"><?= money($selectedReceipt['amount']) ?></td>
            </tr>
          </tbody>
          <tfoot>
            <tr>
              <td colspan="2" style="text-align:right;font-size:8pt">TOTAL AMOUNT RECEIVED:</td>
              <td style="text-align:right;font-size:9.5pt;color:#0f2b5c"><?= money($selectedReceipt['amount']) ?></td>
            </tr>
          </tfoot>
        </table>

        <!-- Amount in Words -->
        <div class="receipt-words-box">
          <strong>AMOUNT IN WORDS:</strong>
          <span style="font-style:italic"><?= e(amount_to_words((float)$selectedReceipt['amount'])) ?></span>
        </div>

        <!-- Payment Mode & Breakdown -->
        <div class="receipt-mode-bar">
          <span><strong>MODE:</strong></span>
          <span>☑ Cash</span>
          <span>☐ Check</span>
          <span>☐ Online / LandBank</span>
          <span style="margin-left:auto;font-size:7.5pt"><strong>STATUS:</strong> PAID &amp; CLEARED</span>
        </div>

        <!-- Signatures & Official Stamp -->
        <div class="receipt-sign-block">
          <div>
            <div style="border-top:1px solid #0f172a;width:130px;margin:18px auto 3px auto"></div>
            <div style="font-size:7.5pt;font-weight:700;text-transform:uppercase"><?= e($selectedReceipt['student_name']) ?></div>
            <div style="font-size:6.5pt;color:#64748b">Payor Signature</div>
          </div>
          <div>
            <div style="border-top:1px solid #0f172a;width:130px;margin:18px auto 3px auto"></div>
            <div style="font-size:7.5pt;font-weight:700;text-transform:uppercase"><?= e($selectedReceipt['cashier_name']) ?></div>
            <div style="font-size:6.5pt;color:#64748b">Collecting Officer / Cashier</div>
          </div>
        </div>

        <!-- Perforated Tear Line -->
        <div class="receipt-tear-line">
          ✂ · · · OFFICIAL CASHIER RECEIPT VOUCHER · KEEP FOR AUDIT PURPOSES · · · ✂
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

</div><!-- end main card -->

<?php page_end(); ?>
