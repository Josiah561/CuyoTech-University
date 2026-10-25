<?php
require __DIR__ . '/../includes/bootstrap.php';
$u = require_role('student');
$s = row('SELECT s.student_id, s.first_name, s.last_name, s.student_number, s.program, s.email, d.department_name FROM student s LEFT JOIN department d USING(department_id) WHERE s.user_id=?', [$u['user_id']]);
if (!$s) { http_response_code(404); exit('No active student record is linked to this account.'); }
$id = (int)$s['student_id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = $_POST['action'] ?? '';
    if ($act === 'cancel_request') {
        $reqId = (int)($_POST['request_id'] ?? 0);
        $req = row('SELECT * FROM document_request WHERE request_id=? AND student_id=?', [$reqId, $id]);
        if ($req && $req['request_status'] === 'Pending') {
            q('DELETE FROM document_request WHERE request_id=? AND student_id=?', [$reqId, $id]);
            audit('Cancelled document request #' . $reqId . ' (' . $req['document_type'] . ')');
            flash('Document issuance request #' . $reqId . ' has been cancelled.');
        } else {
            flash('This document request cannot be cancelled as processing has already commenced.', true);
        }
        redirect('student/documents.php?tab=my');
    }

    $doc = trim((string)($_POST['document'] ?? ''));
    $copies = max(1, min(10, (int)($_POST['copies'] ?? 1)));
    if (isset(DOC_FEES[$doc])) {
        $totalFee = DOC_FEES[$doc] * $copies;
        $docLabel = $doc . ($copies > 1 ? ' (' . $copies . ' copies)' : '');
        q("INSERT INTO document_request(student_id,document_type,fee,request_date,request_status) VALUES(?,?,?,CURDATE(),'Pending')", [$id, $docLabel, $totalFee]);
        audit('Submitted request for ' . $docLabel);
        flash($docLabel . ' requested successfully. Please settle the assessed fee of ' . money($totalFee) . ' at the Cashiering Office.');
    } else {
        flash('Please select an authorized university document from the list.', true);
    }
    redirect('student/documents.php?tab=my');
}

$tab = $_GET['tab'] ?? 'new';
$initials = first_char($s['first_name']) . first_char($s['last_name']);

// Fetch real requests
$requests = rows('SELECT * FROM document_request WHERE student_id=? ORDER BY request_id DESC', [$id]);
$reqCount = count($requests);
$TABS = [
    'new' => 'File New Document Request',
    'my'  => 'Transaction History' . ($reqCount > 0 ? ' (' . $reqCount . ')' : '')
];

sidebar_tabs('student/documents.php', $TABS, $tab);
page_start(
    'docs',
    'Academic Credential & Document Services',
    'Official application for Transcripts of Records (OTR), Certifications, and Authentication under the Office of the University Registrar.',
    'OFFICE OF THE UNIVERSITY REGISTRAR / CREDENTIAL ISSUANCE'
);
?>

<div class="student-portal-card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:14px;box-shadow:0 4px 20px rgba(15,23,42,0.06);margin-bottom:24px;overflow:hidden">
  <!-- Profile & Standing Header -->
  <div class="student-portal-header-bar no-print" style="padding:22px 28px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #f1f5f9;flex-wrap:wrap;gap:16px">
    <div style="display:flex;align-items:center;gap:16px">
      <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg, #0f2b5c 0%, #1e40af 100%);color:#ffffff;font-size:18px;font-weight:800;display:flex;align-items:center;justify-content:center;flex-shrink:0;box-shadow:0 4px 12px rgba(15,43,92,0.22);border:2px solid #ffffff">
        <?= e($initials) ?>
      </div>
      <div>
        <div style="font-size:19px;font-weight:800;color:#0f172a;margin:0 0 4px;letter-spacing:-0.02em"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
        <div style="font-size:12.5px;color:#64748b;display:flex;align-items:center;gap:8px;flex-wrap:wrap">
          <span style="font-weight:600;color:#0f172a">Student ID: <?= e($s['student_number']) ?></span>
          <span style="color:#cbd5e1">·</span>
          <span><?= e($s['program']) ?></span>
          <span style="color:#cbd5e1">·</span>
          <span style="color:#64748b"><?= e($s['department_name'] ?? 'College of Computer Studies') ?></span>
        </div>
      </div>
    </div>
    <a href="<?= url('contact.php') ?>" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:6px;font-weight:600">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
      Document Guidelines &amp; Fees
    </a>
  </div>

  <div style="padding:24px 28px">

  <?php if ($tab === 'new'): ?>
    <!-- TAB: New request -->
    <div style="margin-bottom:18px">
      <h3 style="font-size:16px;font-weight:700;color:var(--ink);margin:0 0 4px">Select Official Credential to Request</h3>
      <p style="font-size:12px;color:var(--ink-muted);margin:0">Choose from the authorized university documents below. Processing turnaround is 3 to 5 business days upon payment settlement.</p>
    </div>

    <form method="post" id="docForm" style="margin:0">
      <?= csrf_field() ?>

      <!-- Document Selection Cards -->
      <div style="display:grid;grid-template-columns:repeat(4, 1fr);gap:16px;margin-bottom:22px">
        <!-- TOR -->
        <label class="doc-card" style="display:block;cursor:pointer;border:1px solid var(--border-color);border-radius:var(--radius-md);padding:18px;position:relative;background:#ffffff;transition:all 0.15s">
          <input type="radio" name="document" value="Transcript of Records" data-fee="150" data-code="OTR" style="position:absolute;top:16px;right:16px;width:16px;height:16px;accent-color:var(--primary)">
          <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:2px">OTR</div>
          <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px">Official Transcript of Records</div>
          <div style="font-size:13px;font-weight:700;color:var(--primary)">₱150.00 <span style="font-weight:400;color:var(--ink-muted);font-size:11px">/ copy</span></div>
        </label>

        <!-- COR (Default) -->
        <label class="doc-card selected-card" style="display:block;cursor:pointer;border:2px solid var(--primary);border-radius:var(--radius-md);padding:18px;position:relative;background:var(--primary-light);transition:all 0.15s">
          <input type="radio" name="document" value="Certificate of Registration" data-fee="75" data-code="COR" checked style="position:absolute;top:16px;right:16px;width:16px;height:16px;accent-color:var(--primary)">
          <div style="font-size:16px;font-weight:700;color:var(--primary);margin-bottom:2px">COR</div>
          <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px">Certificate of Registration</div>
          <div style="font-size:13px;font-weight:700;color:var(--primary)">₱75.00 <span style="font-weight:400;color:var(--ink-muted);font-size:11px">/ copy</span></div>
        </label>

        <!-- Good Moral -->
        <label class="doc-card" style="display:block;cursor:pointer;border:1px solid var(--border-color);border-radius:var(--radius-md);padding:18px;position:relative;background:#ffffff;transition:all 0.15s">
          <input type="radio" name="document" value="Good Moral Certificate" data-fee="75" data-code="Good Moral" style="position:absolute;top:16px;right:16px;width:16px;height:16px;accent-color:var(--primary)">
          <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:2px">Good Moral</div>
          <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px">Certificate of Good Moral</div>
          <div style="font-size:13px;font-weight:700;color:var(--primary)">₱75.00 <span style="font-weight:400;color:var(--ink-muted);font-size:11px">/ copy</span></div>
        </label>

        <!-- COE -->
        <label class="doc-card" style="display:block;cursor:pointer;border:1px solid var(--border-color);border-radius:var(--radius-md);padding:18px;position:relative;background:#ffffff;transition:all 0.15s">
          <input type="radio" name="document" value="Certificate of Enrollment" data-fee="50" data-code="COE" style="position:absolute;top:16px;right:16px;width:16px;height:16px;accent-color:var(--primary)">
          <div style="font-size:16px;font-weight:700;color:var(--ink);margin-bottom:2px">COE</div>
          <div style="font-size:12px;color:var(--ink-muted);margin-bottom:12px">Certificate of Enrollment</div>
          <div style="font-size:13px;font-weight:700;color:var(--primary)">₱50.00 <span style="font-weight:400;color:var(--ink-muted);font-size:11px">/ copy</span></div>
        </label>
      </div>

      <!-- Request Specifics -->
      <div style="display:grid;grid-template-columns:180px 1fr;gap:20px;margin-bottom:18px">
        <div>
          <label class="form-label">Number of Copies</label>
          <select name="copies" id="copySelect" class="form-control">
            <option value="1">1 Official Copy</option>
            <option value="2">2 Official Copies</option>
            <option value="3">3 Official Copies</option>
            <option value="4">4 Official Copies</option>
            <option value="5">5 Official Copies</option>
          </select>
        </div>

        <div>
          <label class="form-label">Purpose of Request</label>
          <select name="purpose" class="form-control">
            <option value="Scholarship Application">Scholarship Application / Grant Verification</option>
            <option value="Employment Requirement">Employment Requirement / Background Check</option>
            <option value="PRC Board Examination">Professional Regulation Commission (PRC) Licensure Examination</option>
            <option value="Transfer / Graduate Studies">Institutional Transfer / Graduate School Admission</option>
            <option value="Visa Application">Passport / Foreign Visa Application</option>
          </select>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
        <div>
          <label class="form-label">Issuance &amp; Release Method</label>
          <select name="release_method" class="form-control">
            <option value="Registrar Window Pickup">Registrar Window Pickup (University Main Campus)</option>
            <option value="Courier Delivery">Courier Delivery (Recipient pays delivery upon receipt)</option>
          </select>
        </div>
        <div>
          <label class="form-label">Notification Email Address</label>
          <input type="email" name="email" value="<?= e($s['email'] ?: 'student@cuyotech.edu.ph') ?>" class="form-control" required>
        </div>
      </div>

      <!-- Financial Assessment Order Slip -->
      <div style="background:var(--surface-bg);border:1px solid var(--border-color);border-radius:var(--radius-md);padding:16px 20px;display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px">
        <div>
          <div class="stat-box-label">TOTAL ASSESSED PROCESSING FEE</div>
          <div id="summaryTotal" style="font-size:16px;font-weight:700;color:var(--ink);letter-spacing:-0.01em">
            ₱75.00 · 1 copy of COR
          </div>
        </div>
        <div>
          <div class="stat-box-label">ESTIMATED REGISTRAR PROCESSING</div>
          <div style="font-size:13px;font-weight:600;color:var(--ink-secondary)">
            3–5 working days upon payment verification at Cashier
          </div>
        </div>
      </div>

      <div style="margin-bottom:20px;display:flex;align-items:center;gap:10px">
        <input type="checkbox" id="confirm" checked required style="width:16px;height:16px;accent-color:var(--primary)">
        <label for="confirm" style="font-size:12px;color:var(--ink-secondary)">
          I certify that all information submitted is true and correct, and authorize the Office of the University Registrar to evaluate my academic records for document issuance.
        </label>
      </div>

      <div style="display:flex;align-items:center;gap:14px;flex-wrap:wrap">
        <button type="submit" class="btn btn-primary">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
          File Document Request
        </button>
        <span style="font-size:12px;color:var(--ink-muted)">
          Payment reference slip will be generated automatically upon submission.
        </span>
      </div>
    </form>

    <script>
    (function() {
      const radios = document.querySelectorAll('input[name="document"]');
      const copySelect = document.getElementById('copySelect');
      const summary = document.getElementById('summaryTotal');
      const cards = document.querySelectorAll('.doc-card');

      function update() {
        let chosen = document.querySelector('input[name="document"]:checked');
        if (!chosen) return;
        cards.forEach(c => {
          c.style.borderColor = 'var(--border-color)';
          c.style.background = '#ffffff';
        });
        const parentLabel = chosen.closest('label');
        if (parentLabel) {
          parentLabel.style.borderColor = 'var(--primary)';
          parentLabel.style.background = 'var(--primary-light)';
        }
        const fee = parseFloat(chosen.getAttribute('data-fee') || '0');
        const code = chosen.getAttribute('data-code') || chosen.value;
        const copies = parseInt(copySelect.value || '1', 10);
        const total = fee * copies;
        summary.textContent = '₱' + total.toFixed(2) + ' · ' + copies + ' copy of ' + code;
      }

      cards.forEach(card => {
        card.addEventListener('click', function(e) {
          const rad = card.querySelector('input[name="document"]');
          if (rad && !rad.checked) {
            rad.checked = true;
            update();
          }
        });
      });

      radios.forEach(r => r.addEventListener('change', update));
      copySelect.addEventListener('change', update);
      update();
    })();
    </script>

  <?php else: ?>
    <!-- TAB: Transaction History -->
    <div class="no-print" style="margin-bottom:18px">
      <h3 style="font-size:16px;font-weight:700;color:var(--ink);margin:0 0 4px">Active &amp; Historical Document Requests</h3>
      <p style="font-size:12px;color:var(--ink-muted);margin:0">Track your credential verification, evaluation status, and release dates.</p>
    </div>

    <?php if (empty($requests)): ?>
      <div style="text-align:center;padding:48px 24px;border:1px dashed var(--border-color);border-radius:var(--radius-md);background:var(--surface-bg)">
        <div style="font-size:15px;font-weight:700;color:var(--ink);margin-bottom:4px">No document issuance requests on file</div>
        <p style="font-size:12px;color:var(--ink-muted);margin:0 0 16px">You have not submitted any official document requests for this academic term.</p>
        <a href="<?= url('student/documents.php?tab=new') ?>" class="btn btn-primary btn-sm">
          File New Request
        </a>
      </div>
    <?php else: ?>
      <?php
      $selectedId = isset($_GET['req']) ? (int)$_GET['req'] : ($requests[0]['request_id'] ?? 0);
      $selectedReq = null;
      foreach ($requests as $r) {
          if ((int)$r['request_id'] === $selectedId) {
              $selectedReq = $r;
              break;
          }
      }
      if (!$selectedReq && count($requests) > 0) $selectedReq = $requests[0];
      $deptCleared = (bool)row("SELECT 1 FROM clearance WHERE student_id=? AND office='Department' AND status='Cleared'", [$id]);
      ?>

      <div class="screen-two-col" style="display:grid;grid-template-columns:340px 1fr;gap:24px;align-items:start">
        <!-- Left: Transactions List (Screen only) -->
        <div class="no-print" style="display:flex;flex-direction:column;gap:10px">
          <?php foreach ($requests as $r):
              $isSel = ($selectedReq && (int)$selectedReq['request_id'] === (int)$r['request_id']);
              $st = $r['request_status'] ?? 'Pending';
              $docCode = 'CTU-REQ-2026-' . str_pad((string)$r['request_id'], 4, '0', STR_PAD_LEFT);
          ?>
            <a href="<?= url('student/documents.php?tab=my&req=' . $r['request_id']) ?>" 
               style="display:block;text-decoration:none;border:<?= $isSel ? '2px solid var(--primary)' : '1px solid var(--border-color)' ?>;border-radius:var(--radius-md);padding:14px 16px;background:<?= $isSel ? 'var(--primary-light)' : '#ffffff' ?>;transition:all 0.15s">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;margin-bottom:4px">
                <div style="font-size:14px;font-weight:700;color:var(--ink)"><?= e($r['document_type']) ?></div>
                <?= pill($st) ?>
              </div>
              <div style="font-size:11px;font-weight:600;color:var(--ink-muted);margin-bottom:6px"><?= $docCode ?></div>
              <div style="font-size:11px;color:var(--ink-muted)">
                Filed on <?= date('d M Y', strtotime($r['request_date'])) ?> · <?= money($r['fee']) ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>

        <!-- Right: Transaction Detail Sheet -->
        <?php if ($selectedReq):
            $st = $selectedReq['request_status'] ?? 'Pending';
            $isReady = ($st === 'Ready');
            $isReleased = ($st === 'Released');
            $isHold = ($st === 'On hold' || ($st === 'Pending' && !$deptCleared));
            $docCode = 'CTU-REQ-2026-' . str_pad((string)$selectedReq['request_id'], 4, '0', STR_PAD_LEFT);
        ?>
          <div class="screen-two-col-main" style="min-width:0;width:100%">
            <!-- Screen Interactive Detail Card -->
            <div class="no-print" style="border:1px solid var(--border-color);border-radius:var(--radius-md);padding:22px;background:#ffffff">
              <div style="display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:4px">
                <div>
                  <h3 style="font-size:17px;font-weight:700;color:var(--ink);margin:0"><?= e($selectedReq['document_type']) ?></h3>
                  <div style="font-size:12px;color:var(--ink-muted);margin-top:2px">Transaction Reference: <strong style="color:var(--ink)"><?= $docCode ?></strong></div>
                </div>
                <?= pill($st) ?>
              </div>

              <!-- Transaction Metadata Strip -->
              <div style="display:grid;grid-template-columns:repeat(3, 1fr);gap:16px;padding:14px 0;border-bottom:1px solid var(--border-color);margin:16px 0;font-size:12px">
                <div>
                  <div class="stat-box-label">DATE FILED</div>
                  <div style="font-weight:700;color:var(--ink)"><?= date('F d, Y', strtotime($selectedReq['request_date'])) ?></div>
                </div>
                <div>
                  <div class="stat-box-label">PROCESSING FEE</div>
                  <div style="font-weight:700;color:var(--ink)"><?= money($selectedReq['fee']) ?> (Settled)</div>
                </div>
                <div>
                  <div class="stat-box-label">RELEASE SCHEDULE</div>
                  <div style="font-weight:700;color:var(--primary)">
                    <?= $isReleased ? 'Released on ' . date('M d, Y', strtotime($selectedReq['release_date'])) : ($isReady ? 'Ready for Pickup' : '3-5 Working Days') ?>
                  </div>
                </div>
              </div>

              <?php if (!$deptCleared && !$isReady && !$isReleased): ?>
                <div class="notice-banner notice-amber" style="margin-bottom:18px">
                  <strong>Administrative Hold Notice:</strong> Document release requires clearance of all department liabilities (Laboratory Return Slip). Please verify your status under the Clearance tab.
                </div>
              <?php endif; ?>

              <!-- Verification Progress Steps -->
              <div style="font-size:12px;font-weight:700;color:var(--ink);text-transform:uppercase;margin-bottom:12px">
                Issuance Verification Lifecycle
              </div>
              <div style="display:flex;flex-direction:column;gap:14px;border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:16px;background:var(--surface-bg);margin-bottom:20px;font-size:12px">
                <div style="display:flex;align-items:center;gap:10px">
                  <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
                  <div>
                    <strong>Docketed &amp; Fee Assessed:</strong> Request received and authenticated on <?= date('d M Y', strtotime($selectedReq['request_date'])) ?>.
                  </div>
                </div>

                <div style="display:flex;align-items:center;gap:10px">
                  <?php if ($st === 'Processing' || $isReady || $isReleased): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
                    <div><strong>Registrar Verification:</strong> Official records verified against University Archives.</div>
                  <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <div style="color:var(--ink-muted)"><strong>Registrar Verification:</strong> Pending curriculum archive evaluation.</div>
                  <?php endif; ?>
                </div>

                <div style="display:flex;align-items:center;gap:10px">
                  <?php if ($isReady || $isReleased): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
                    <div><strong>Official Seal Affixed:</strong> Printed on security paper and authorized by the Registrar.</div>
                  <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <div style="color:var(--ink-muted)"><strong>Official Seal Affixed:</strong> Pending document printing.</div>
                  <?php endif; ?>
                </div>

                <div style="display:flex;align-items:center;gap:10px">
                  <?php if ($isReleased): ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#16a34a" stroke-width="2.5"><circle cx="12" cy="12" r="10"/><polyline points="9 12 11 14 15 10"/></svg>
                    <div><strong>Released:</strong> Claimed on <?= date('d M Y', strtotime($selectedReq['release_date'])) ?>.</div>
                  <?php else: ?>
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#94a3b8" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    <div style="color:var(--ink-muted)"><strong>Claim &amp; Release:</strong> Present University ID at Registrar Window 2.</div>
                  <?php endif; ?>
                </div>
              </div>

              <!-- Action Buttons -->
              <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
                <?php if ($isReady || $isReleased): ?>
                  <a href="<?= url('print_document.php?id=' . (int)$selectedReq['request_id']) ?>" target="_blank" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                    View / Print Issued Document
                  </a>
                  <?php if (!empty($selectedReq['verification_code'])): ?>
                    <a href="<?= url('verify.php?code=' . urlencode($selectedReq['verification_code'])) ?>" target="_blank" class="btn btn-outline btn-sm" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none">
                      Verify Code: <?= e($selectedReq['verification_code']) ?>
                    </a>
                  <?php endif; ?>
                <?php endif; ?>
                <button type="button" onclick="window.print()" class="btn btn-outline btn-sm">
                  <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
                  Print Request Claim Slip
                </button>
                <?php if ($st === 'Pending'): ?>
                  <form method="post" style="margin:0" onsubmit="return confirm('Confirm cancellation of this document issuance request?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="cancel_request">
                    <input type="hidden" name="request_id" value="<?= (int)$selectedReq['request_id'] ?>">
                    <button type="submit" class="btn btn-danger-outline btn-sm">
                      Cancel Request
                    </button>
                  </form>
                <?php endif; ?>
              </div>
            </div>

            <!-- Official Printable Credential Claim & Routing Slip -->
            <div class="official-claim-slip">
              <!-- CuyoTech Custom Branded Letterhead -->
              <?= cuyotech_doc_header('OFFICE OF THE UNIVERSITY REGISTRAR · CREDENTIAL SERVICES', 'CREDENTIAL TRANSACTION CLAIM & ROUTING SLIP', 'Official Student Claim Order · Release Window 2', $docCode) ?>

              <!-- Student Profile -->
              <div class="official-meta-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:12px">
                <div>
                  <div class="official-meta-item-label">STUDENT NUMBER</div>
                  <div class="official-meta-item-value"><?= e($s['student_number']) ?></div>
                </div>
                <div>
                  <div class="official-meta-item-label">STUDENT NAME</div>
                  <div class="official-meta-item-value"><?= e($s['last_name'] . ', ' . $s['first_name']) ?></div>
                </div>
                <div>
                  <div class="official-meta-item-label">DEGREE PROGRAM</div>
                  <div class="official-meta-item-value"><?= e($s['program']) ?></div>
                </div>
              </div>

              <!-- Order Specifications Table -->
              <div style="margin-bottom:12px">
                <table class="official-doc-table data-table">
                  <thead>
                    <tr>
                      <th>REQUESTED CREDENTIAL / DOCUMENT</th>
                      <th style="text-align:center;width:70px">COPIES</th>
                      <th>PURPOSE OF ISSUANCE</th>
                      <th style="text-align:right;width:110px">ASSESSED FEE</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr>
                      <td style="font-weight:700"><?= e($selectedReq['document_type']) ?></td>
                      <td style="text-align:center;font-weight:700">1 Official Copy</td>
                      <td>Official Academic Credential Requirement</td>
                      <td style="text-align:right;font-weight:800"><?= money($selectedReq['fee']) ?></td>
                    </tr>
                  </tbody>
                  <tfoot>
                    <tr style="background:#f8fafc;font-weight:700">
                      <td colspan="3" style="text-align:right;padding:6px 10px;font-size:8.5pt">PAYMENT VERIFICATION STATUS:</td>
                      <td style="text-align:right;padding:6px 10px;color:#166534">SETTLED (PAID)</td>
                    </tr>
                  </tfoot>
                </table>
              </div>

              <!-- Mandatory Claiming Instructions -->
              <div style="border:1px solid #000000;padding:8px 12px;margin-bottom:14px;font-size:8pt;color:#1e293b;line-height:1.45;background:#f8fafc">
                <strong>IMPORTANT CLAIMING INSTRUCTIONS:</strong><br>
                1. Present this printed claim slip along with your valid CuyoTech Student Identification Card at <strong>Registrar Window 2</strong>.<br>
                2. If an authorized representative will claim the document on your behalf, provide a signed Authorization Letter, a photocopy of your Student ID, and the representative's valid government ID.<br>
                3. Documents not claimed within sixty (60) calendar days from the scheduled release date shall be disposed of in accordance with university records management policies.
              </div>

              <!-- Signatures Block -->
              <div class="official-sign-block">
                <div class="official-sig-item">
                  <div class="official-sig-bar"></div>
                  <div class="official-sig-name"><?= e($s['first_name'] . ' ' . $s['last_name']) ?></div>
                  <div class="official-sig-title">Requesting Student Signature</div>
                </div>
                <div class="official-sig-item">
                  <?= cuyotech_seal_stamp() ?>
                  <div style="font-size:7pt;color:#475569;margin-top:2px">Registrar Release Verification</div>
                </div>
                <div class="official-sig-item">
                  <div class="official-sig-bar"></div>
                  <div class="official-sig-name">RECORDS RELEASING OFFICER</div>
                  <div class="official-sig-title">Office of the University Registrar</div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php endif; ?>

  </div>
</div>

<?php page_end(); ?>
