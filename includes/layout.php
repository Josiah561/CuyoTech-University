<?php
// CuyoTech University - Student Services Integrated System (SSIS)
// Layout and Component Engine

function logo_svg(): string {
    return '<img src="' . url('assets/logo-shield.png') . '" alt="CuyoTech Seal" class="brand-shield-img" style="height:38px;width:auto;display:inline-block;vertical-align:middle">';
}

function logo(): string {
    return '<a href="' . url('index.php') . '" class="brand-logo" style="display:inline-flex;align-items:center"><img src="' . url('assets/logo-full.png') . '" alt="CuyoTech University" style="height:44px;width:auto;max-width:60vw;display:block"></a>';
}

function cuyotech_doc_header(string $office, string $docName, string $docSubtitle = '', string $refCode = ''): string {
    $logoUrl = url('assets/logo-shield.png');
    $html = '<div class="cuyotech-doc-header">'
          . '<div class="cuyotech-header-inner">'
          . '<div class="cuyotech-header-brand">'
          . '<img src="' . $logoUrl . '" alt="CuyoTech Seal" class="cuyotech-doc-logo">'
          . '<div class="cuyotech-doc-titles">'
          . '<div class="cuyotech-univ-name">CUYOTECH UNIVERSITY</div>'
          . '<div class="cuyotech-univ-tagline">Center of Excellence in Science, Computing &amp; Applied Technology</div>'
          . '<div class="cuyotech-univ-meta">Cuyo Main Campus · Palawan, Philippines · www.cuyotech.edu.ph · Tel. (048) 555-0199</div>'
          . '<div class="cuyotech-doc-office">' . e($office) . '</div>'
          . '</div>'
          . '</div>';
    if ($refCode) {
        $html .= '<div class="cuyotech-header-ref">'
              . '<div class="cuyotech-ref-label">CONTROL / AUDIT REF</div>'
              . '<div class="cuyotech-ref-badge">' . e($refCode) . '</div>'
              . '<div class="cuyotech-ref-date">ISSUED: ' . date('d M Y') . '</div>'
              . '</div>';
    }
    $html .= '</div>'
          . '<div class="cuyotech-header-stripe"></div>'
          . '<div class="cuyotech-doc-titlebar">'
          . '<div class="cuyotech-doc-title">' . e($docName) . '</div>'
          . ($docSubtitle ? '<div class="cuyotech-doc-subtitle">' . e($docSubtitle) . '</div>' : '')
          . '</div>'
          . '</div>';
    return $html;
}

function cuyotech_receipt_header(string $orNumber, string $txDate): string {
    $logoUrl = url('assets/logo-shield.png');
    return '<div class="receipt-header">'
         . '<div class="receipt-header-top">'
         . '<div class="receipt-brand">'
         . '<img src="' . $logoUrl . '" alt="Crest" class="receipt-logo">'
         . '<div class="receipt-univ-info">'
         . '<div class="receipt-univ-name">CUYOTECH UNIVERSITY</div>'
         . '<div class="receipt-office-name">OFFICE OF THE UNIVERSITY CASHIER &amp; TREASURY</div>'
         . '<div class="receipt-meta">Main Campus, Cuyo, Palawan 5318 Philippines · Tel. (048) 555-0199</div>'
         . '</div>'
         . '</div>'
         . '<div class="receipt-or-box">'
         . '<div class="receipt-or-title">OFFICIAL RECEIPT</div>'
         . '<div class="receipt-or-no">' . e($orNumber) . '</div>'
         . '<div class="receipt-or-copy">ORIGINAL · PAYOR\'S COPY</div>'
         . '</div>'
         . '</div>'
         . '<div class="receipt-rule"></div>'
         . '<div class="receipt-tx-bar">'
         . '<span><strong>DATE:</strong> ' . date('F d, Y · h:i A', strtotime($txDate)) . '</span>'
         . '<span><strong>STATION:</strong> CASHIER WINDOW 1 · MAIN CAMPUS</span>'
         . '</div>'
         . '</div>';
}

function cuyotech_seal_stamp(): string {
    $logoUrl = url('assets/logo-shield.png');
    return '<div class="cuyotech-seal-stamp">'
         . '<img src="' . $logoUrl . '" alt="Seal" class="cuyotech-seal-logo">'
         . '<div class="cuyotech-seal-text">OFFICIAL SEAL<br>EST. 1978</div>'
         . '</div>';
}

function html_head(string $title): void {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . ' | CuyoTech University SSIS</title>'
       . '<link rel="icon" type="image/png" href="' . url('assets/logo-shield.png') . '">'
       . '<link rel="preconnect" href="https://fonts.googleapis.com">'
       . '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>'
       . '<link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">'
       . '<link rel="stylesheet" href="' . url('assets/style.css') . '">'
       . '</head><body>';
}

function flash_html(): string {
    if (empty($_SESSION['flash'])) return '';
    [$m, $bad] = $_SESSION['flash'];
    unset($_SESSION['flash']);
    $cls = $bad ? 'flash-alert error' : 'flash-alert success';
    $icon = $bad
        ? '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>'
        : '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>';
    return '<div class="' . $cls . '" role="status">' . $icon . '<span>' . e($m) . '</span></div>';
}

function public_start(string $title, bool $wide = false, bool $withHeader = false): void {
    html_head($title);
    if ($withHeader) {
        $u = user();
        $retUrl = $u ? url(home_for($u['role'])) : url('login.php');
        echo '<header class="top-nav">'
           . '<div class="top-nav-left">'
           . logo()
           . '</div>'
           . '<div class="top-nav-right">'
           . '<a href="' . $retUrl . '" class="top-back-link" style="color:var(--primary);font-weight:600;font-size:13px;display:inline-flex;align-items:center;gap:6px">'
           . '<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg> Return</a>'
           . '</div>'
           . '</header>'
           . '<div class="app-main" style="max-width:1160px;margin:0 auto;padding-top:32px">';
    } else {
        echo '<div class="public-container">'
           . '<div class="public-card' . ($wide ? ' wide' : '') . '">'
           . flash_html();
    }
}

function public_end(bool $withHeader = false): void {
    if ($withHeader) {
        echo '<footer class="page-subfooter" style="margin-top:28px;padding:16px 0">'
           . '<span>CuyoTech University · Student Services Integrated System (SSIS)</span>'
           . '</footer>'
           . '</div></body></html>';
    } else {
        echo '</div></div></body></html>';
    }
}

const ROLE_DISPLAY_NAMES = [
    'student'   => 'Student Body',
    'registrar' => 'Office of the University Registrar',
    'cashier'   => 'Cashiering & Treasury Services',
    'depthead'  => 'Academic Department Clearance',
    'admin'     => 'System & Records Administration',
];

const ICONS = [
    'cap' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg>',
    'doc' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>',
    'clip' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1" ry="1"/><path d="m9 14 2 2 4-4"/></svg>',
    'card' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2" ry="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'shield' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><polyline points="9 12 11 14 15 10"/></svg>',
    'admin' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'user' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>',
    'bell' => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>',
    'chevron' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>',
    'help' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
    'signout' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>',
    'download' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>',
    'check' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'clock' => '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>',
    'search' => '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>',
];

const NAV = [
    'student'   => [
        ['student/index.php', 'Academic Portal', 'portal', 'cap'],
        ['student/documents.php', 'Document Services', 'docs', 'doc'],
        ['profile.php', 'My Profile', 'profile', 'user'],
    ],
    'registrar' => [
        ['registrar/index.php', 'Registrar Console', 'registrar', 'clip'],
        ['profile.php', 'My Profile', 'profile', 'user'],
    ],
    'cashier'   => [
        ['cashier/index.php', 'Cashiering & Assessment', 'cashier', 'card'],
        ['profile.php', 'My Profile', 'profile', 'user'],
    ],
    'depthead'  => [
        ['dept/index.php', 'Department Clearance', 'dept', 'shield'],
        ['profile.php', 'My Profile', 'profile', 'user'],
    ],
    'admin'     => [
        ['admin/index.php', 'System Administration', 'admin', 'admin'],
    ],
];

// A page registers its tabs here before page_start(); the sidebar shows them under the active module.
function sidebar_tabs(string $base, array $tabs, string $active, array $extra = []): void {
    $GLOBALS['SUBNAV'] = compact('base', 'tabs', 'active', 'extra');
}

function page_start(string $mod, string $title, string $lead = '', string $crumb = ''): void {
    $u = user();
    html_head($title);
    
    // Subtitle formatted for institutional authority
    $roleSubtitle = ROLE_DISPLAY_NAMES[$u['role']] ?? $u['role'];
    if ($u['role'] === 'student' && !empty($u['student_number'])) {
        $roleSubtitle = 'Student No. ' . $u['student_number'];
    }

    $notifs = get_user_notifications($u);
    $unreadCount = 0;
    try {
        $unreadCount = (int)row("SELECT COUNT(*) c FROM notification WHERE user_id=? AND is_read=0", [(int)$u['user_id']])['c'];
    } catch (Throwable $e) {}
    $profileUrl = ($u['role'] === 'admin') ? url(home_for('admin')) : url('profile.php');

    echo '<header class="top-nav">'
       . '<div class="top-nav-left">'
       . '<button type="button" class="mobile-nav-toggle no-print" onclick="toggleSidebar()" title="Toggle navigation">'
       . '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="18" x2="21" y2="18"/></svg>'
       . '</button>'
       . logo()
       . '</div>'
       . '<div class="top-nav-right">'
       . '<div style="position:relative">'
       . '<button type="button" class="top-nav-bell" id="notifBtn" title="System Notices" onclick="toggleDropdown(\'notifDropdown\')">'
       . ICONS['bell']
       . ($unreadCount > 0 ? '<span id="notifBadge" style="position:absolute;top:2px;right:2px;min-width:16px;height:16px;line-height:16px;background:#ef4444;color:#fff;border-radius:9999px;font-size:9.5px;font-weight:700;text-align:center;padding:0 3px;border:1.5px solid #fff">' . ($unreadCount > 9 ? '9+' : $unreadCount) . '</span>' : '')
       . '</button>'
       . '<div class="app-dropdown" id="notifDropdown" style="width:340px">'
       . '<div style="padding:12px 16px;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between">'
       . '<span style="font-size:13px;font-weight:700;color:#0f172a">Academic Notices</span>'
       . '<button type="button" onclick="fetch(\'' . url('mark_notifications_read.php') . '\',{method:\'POST\'});document.getElementById(\'notifBadge\')?.remove();document.querySelectorAll(\'.notif-item\').forEach(function(el){el.style.opacity=\'0.6\';el.style.background=\'transparent\';});" style="font-size:11px;color:var(--primary);background:none;border:none;cursor:pointer;font-weight:600">Mark all as read</button>'
       . '</div>'
       . '<div id="notifList" style="max-height:300px;overflow-y:auto">'
       . (empty($notifs) ? '<div style="padding:24px;text-align:center;color:#94a3b8;font-size:12px">No notifications on record</div>' : '');
    foreach ($notifs as $nf) {
        $unreadStyle = empty($nf['is_read']) ? 'background:#f0f9ff;border-left:3px solid #2563eb;' : 'background:transparent;';
        echo '<a href="' . e($nf['url']) . '" class="notif-item" style="display:block;padding:12px 16px;border-bottom:1px solid #f1f5f9;text-decoration:none;transition:background 0.12s;' . $unreadStyle . '">'
           . '<div style="display:flex;align-items:center;justify-content:space-between;gap:6px">'
           . '<div style="font-size:12px;font-weight:700;color:#0f172a;margin-bottom:2px">' . e($nf['title']) . '</div>'
           . (empty($nf['is_read']) ? '<span style="width:6px;height:6px;border-radius:50%;background:#2563eb;flex-shrink:0"></span>' : '')
           . '</div>'
           . '<div style="font-size:11.5px;color:#475569;line-height:1.4">' . e($nf['desc']) . '</div>'
           . '<div style="font-size:10px;color:#94a3b8;margin-top:4px">' . e($nf['time']) . '</div>'
           . '</a>';
    }
    echo '</div>'
       . '<div style="padding:9px 16px;background:#f8fafc;border-top:1px solid #f1f5f9;text-align:center">'
       . '<a href="' . url('contact.php') . '" style="font-size:11px;color:var(--primary);font-weight:600;text-decoration:none">University Help Desk &amp; Inquiries →</a>'
       . '</div>'
       . '</div>'
       . '</div>'
       . '<div style="position:relative">'
       . '<button type="button" class="top-nav-user" id="userBtn" onclick="toggleDropdown(\'userDropdown\')">'
       . '<div class="top-nav-user-avatar">' . e(strtoupper(substr($u['name'] ?? 'U', 0, 1))) . '</div>'
       . '<div class="top-nav-user-info">'
       . '<div class="top-nav-user-name">' . e($u['name']) . '</div>'
       . '<div class="top-nav-user-role">' . e($roleSubtitle) . '</div>'
       . '</div>'
       . '<div class="top-nav-chevron">' . ICONS['chevron'] . '</div>'
       . '</button>'
       . '<div class="app-dropdown" id="userDropdown" style="width:230px">'
       . '<div style="padding:12px 16px;border-bottom:1px solid #f1f5f9">'
       . '<div style="font-size:13px;font-weight:700;color:#0f172a">' . e($u['name']) . '</div>'
       . '<div style="font-size:11px;color:#64748b;margin-top:2px">' . e($roleSubtitle) . '</div>'
       . '</div>'
       . '<div style="padding:6px 0">'
       . '<a href="' . e($profileUrl) . '" style="display:flex;align-items:center;gap:10px;padding:8px 16px;font-size:12px;color:#334155;text-decoration:none" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'">'
       . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>'
       . '<span>' . ($u['role'] === 'admin' ? 'Staff Administrative Center' : 'My Profile') . '</span>'
       . '</a>'
       . '<a href="' . url('contact.php') . '" style="display:flex;align-items:center;gap:10px;padding:8px 16px;font-size:12px;color:#334155;text-decoration:none" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'transparent\'">'
       . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>'
       . '<span>Help Desk &amp; Inquiry</span>'
       . '</a>'
       . '</div>'
       . '<div style="padding:8px 16px;border-top:1px solid #f1f5f9">'
       . '<form method="post" action="' . url('logout.php') . '" style="margin:0">'
       . csrf_field()
       . '<button type="submit" style="width:100%;display:flex;align-items:center;gap:8px;padding:6px 0;background:none;border:none;font-size:12px;color:#dc2626;cursor:pointer;font-weight:600">'
       . ICONS['signout']
       . '<span>Sign Out</span>'
       . '</button>'
       . '</form>'
       . '</div>'
       . '</div>'
       . '</div>'
       . '</div>'
       . '</header>';

    echo '<div class="app-shell">'
       . '<aside class="app-sidebar">'
       . '<div class="sidebar-brand-badge">'
       . logo_svg()
       . '<div><div class="sidebar-brand-title">CUYOTECH SSIS</div><div class="sidebar-brand-subtitle">Academic Year 2026–2027</div></div>'
       . '</div>'
       . '<div class="sidebar-nav-category">Main Navigation</div>'
       . '<ul class="sidebar-nav">';

    foreach (NAV[$u['role']] as [$href, $label, $key, $iconKey]) {
        $isActive = ($key === $mod);
        $cls = 'sidebar-nav-item' . ($isActive ? ' active' : '');
        $iconSvg = ICONS[$iconKey] ?? '';
        echo '<li><a class="' . $cls . '" href="' . url($href) . '"' . ($isActive ? ' aria-current="page"' : '') . '>' . $iconSvg . '<span>' . e($label) . '</span></a>';
        if ($isActive && !empty($GLOBALS['SUBNAV'])) {
            $sn = $GLOBALS['SUBNAV'];
            echo '<ul class="sidebar-subnav" aria-label="' . e($label) . ' sections">';
            foreach ($sn['tabs'] as $k => $tl) {
                echo '<li><a href="' . url($sn['base'] . '?' . http_build_query(['tab' => $k] + $sn['extra'])) . '"'
                   . ($k === $sn['active'] ? ' class="active" aria-current="page"' : '') . '>' . e($tl) . '</a></li>';
            }
            echo '</ul>';
        }
        echo '</li>';
    }

    echo '</ul>'
       . '<div class="sidebar-footer">'
       . '<div class="sidebar-help">'
       . '<div class="sidebar-help-title">' . ICONS['help'] . '<span>Registrar Help Desk</span></div>'
       . '<div class="sidebar-help-text">Direct queries regarding records, credentials, and enrollment validation.</div>'
       . '<a class="sidebar-help-link" href="' . url('contact.php') . '">Contact Help Desk ↗</a>'
       . '</div>'
       . '<form method="post" action="' . url('logout.php') . '">'
       . csrf_field()
       . '<button class="sidebar-signout-btn" type="submit">' . ICONS['signout'] . '<span>Sign Out Session</span></button>'
       . '</form>'
       . '</div>'
       . '</aside>'
       . '<div class="sidebar-backdrop no-print" id="sidebarBackdrop" onclick="toggleSidebar(false)"></div>'
       . '<main class="app-main">'
       . '<div class="page-header-row">'
       . '<div class="page-breadcrumb">' . e($crumb ?: 'ACADEMIC SERVICES / ' . strtoupper($title)) . '</div>'
       . '<h1 class="page-title">' . e($title) . '</h1>'
       . ($lead ? '<p class="page-lead">' . e($lead) . '</p>' : '')
       . '</div>'
       . '<div class="main-card">'
       . flash_html();
}

function get_user_notifications(array $u): array {
    $notifs = [];
    try {
        $dbRows = rows("SELECT * FROM notification WHERE user_id=? ORDER BY notification_id DESC LIMIT 10", [(int)$u['user_id']]);
        if (!empty($dbRows)) {
            foreach ($dbRows as $dn) {
                $notifs[] = [
                    'id'      => (int)$dn['notification_id'],
                    'title'   => $dn['title'],
                    'desc'    => $dn['message'],
                    'url'     => $dn['link'] ? url($dn['link']) : '#',
                    'time'    => date('M d, H:i', strtotime($dn['created_at'])),
                    'is_read' => (bool)$dn['is_read']
                ];
            }
            return $notifs;
        }

        if ($u['role'] === 'student') {
            $sid = (int)($u['student_id'] ?? 0);
            if (!$sid) {
                $stRow = row("SELECT student_id FROM student WHERE user_id=?", [$u['user_id']]);
                $sid = (int)($stRow['student_id'] ?? 0);
            }
            if ($sid > 0) {
                $enr = row("SELECT enrollment_status FROM enrollment WHERE student_id=? ORDER BY enrollment_id DESC LIMIT 1", [$sid]);
                if ($enr) {
                    $notifs[] = [
                        'title' => 'Matriculation: ' . $enr['enrollment_status'],
                        'desc'  => 'Official enrollment status for Academic Year 2026–2027.',
                        'url'   => url('student/index.php?tab=en'),
                        'time'  => 'Current Term'
                    ];
                }
                $doc = row("SELECT document_type, request_status FROM document_request WHERE student_id=? ORDER BY request_id DESC LIMIT 1", [$sid]);
                if ($doc) {
                    $notifs[] = [
                        'title' => $doc['document_type'] . ': ' . $doc['request_status'],
                        'desc'  => 'Official credential processing status.',
                        'url'   => url('student/documents.php?tab=my'),
                        'time'  => 'Active Transaction'
                    ];
                }
                $clrRow = row("SELECT COUNT(*) c FROM clearance WHERE student_id=? AND status='Pending'", [$sid]);
                $clrPending = (int)($clrRow['c'] ?? 0);
                $notifs[] = [
                    'title' => $clrPending > 0 ? "$clrPending Clearance Requirement(s) Pending" : "Clearance Fully Cleared",
                    'desc'  => 'University end-of-term clearance audit ledger.',
                    'url'   => url('student/index.php?tab=cl'),
                    'time'  => 'AY 2026–2027'
                ];
            }
        } elseif ($u['role'] === 'registrar') {
            $pEnr = (int)row("SELECT COUNT(*) c FROM enrollment WHERE enrollment_status='Pending review'")['c'];
            if ($pEnr > 0) {
                $notifs[] = [
                    'title' => "$pEnr Matriculation Application(s) Awaiting Review",
                    'desc'  => 'Student curriculum loads pending Registrar validation.',
                    'url'   => url('registrar/index.php?tab=rv'),
                    'time'  => 'Action Required'
                ];
            }
            $pDoc = (int)row("SELECT COUNT(*) c FROM document_request WHERE request_status='Pending'")['c'];
            if ($pDoc > 0) {
                $notifs[] = [
                    'title' => "$pDoc Document Issuance Request(s) Queued",
                    'desc'  => 'Transcript of records and certifications ready for release.',
                    'url'   => url('registrar/index.php?tab=doc'),
                    'time'  => 'Queue Pending'
                ];
            }
        } elseif ($u['role'] === 'cashier') {
            $pPay = rows("SELECT p.amount, s.student_number FROM payment p JOIN student s ON s.student_id=p.student_id ORDER BY p.payment_id DESC LIMIT 2");
            foreach ($pPay as $pp) {
                $notifs[] = [
                    'title' => 'Payment Remittance: ' . money($pp['amount']),
                    'desc'  => 'Official receipt credited for Student No. ' . $pp['student_number'],
                    'url'   => url('cashier/index.php?tab=his'),
                    'time'  => 'Recent Posting'
                ];
            }
        } elseif ($u['role'] === 'depthead') {
            $pClr = (int)row("SELECT COUNT(*) c FROM clearance c JOIN student s ON s.student_id=c.student_id WHERE c.office='Department' AND c.status='Pending' AND s.department_id=?", [(int)($u['department_id'] ?? 1)])['c'];
            if ($pClr > 0) {
                $notifs[] = [
                    'title' => "$pClr Departmental Clearance(s) Pending Sign-off",
                    'desc'  => 'Laboratory return slips awaiting verification.',
                    'url'   => url('dept/index.php?tab=rv'),
                    'time'  => 'Action Required'
                ];
            }
        } elseif ($u['role'] === 'admin') {
            $recentLog = row("SELECT action, created_at FROM audit_log ORDER BY log_id DESC LIMIT 1");
            if ($recentLog) {
                $notifs[] = [
                    'title' => 'Security Audit Trail Logged',
                    'desc'  => $recentLog['action'],
                    'url'   => url('admin/index.php?tab=lg'),
                    'time'  => date('g:i A', strtotime($recentLog['created_at']))
                ];
            }
        }
    } catch (\Throwable $e) {}
    return $notifs;
}

function page_end(): void {
    echo '</div>'
       . '<footer class="page-subfooter">'
       . '<span>CuyoTech University · Center of Excellence in Science &amp; Technology · Student Services Integrated System (SSIS)</span>'
       . '<span>Official Academic Records · Office of the University Registrar · AY 2026–2027</span>'
       . '</footer>'
       . '</main>'
       . '</div>'
       . '<script>
       function toggleSidebar(forceState) {
           const sb = document.querySelector(".app-sidebar");
           const bd = document.getElementById("sidebarBackdrop");
           if (!sb) return;
           const shouldOpen = (forceState !== undefined) ? forceState : !sb.classList.contains("mobile-open");
           if (shouldOpen) {
               sb.classList.add("mobile-open");
               if (bd) bd.classList.add("active");
           } else {
               sb.classList.remove("mobile-open");
               if (bd) bd.classList.remove("active");
           }
       }
       function toggleDropdown(id) {
           const el = document.getElementById(id);
           const isOpen = el.classList.contains("show");
           document.querySelectorAll(".app-dropdown").forEach(d => d.classList.remove("show"));
           if (!isOpen) el.classList.add("show");
       }
       document.addEventListener("click", function(e) {
           if (!e.target.closest("#notifBtn") && !e.target.closest("#notifDropdown") &&
               !e.target.closest("#userBtn") && !e.target.closest("#userDropdown")) {
               document.querySelectorAll(".app-dropdown").forEach(d => d.classList.remove("show"));
           }
           const sb = document.querySelector(".app-sidebar");
           if (sb && sb.classList.contains("mobile-open")) {
               if (!e.target.closest(".app-sidebar") && !e.target.closest(".mobile-nav-toggle")) {
                   toggleSidebar(false);
               }
           }
       });
       document.addEventListener("DOMContentLoaded", function() {
           document.querySelectorAll("table.data-table").forEach(function(t) {
               if (t.closest("[class*=official-]") || t.querySelector("tbody td[colspan]")) return;
               var heads = Array.prototype.map.call(t.querySelectorAll("thead th"), function(h) { return h.textContent.trim(); });
               if (!heads.length) return;
               t.querySelectorAll("tbody tr").forEach(function(r) {
                   Array.prototype.forEach.call(r.children, function(c, i) { if (heads[i]) c.setAttribute("data-label", heads[i]); });
               });
               t.classList.add("stack-sm");
           });
       });
       </script>'
       . '</body></html>';
}

function pill(string $st): string {
    $map = [
        'Enrolled' => 'badge-green',
        'Confirmed · Enrolled' => 'badge-green',
        'Cleared' => 'badge-green',
        'Active' => 'badge-green',
        'Complete' => 'badge-green',
        'Verified' => 'badge-green',
        'Success' => 'badge-green',
        'Ready' => 'badge-green',
        'Released' => 'badge-green',
        'Approved' => 'badge-green',
        'Paid' => 'badge-green',
        'Passed' => 'badge-green',

        'Pending' => 'badge-amber',
        'Pending review' => 'badge-amber',
        'On hold' => 'badge-amber',
        'For validation' => 'badge-amber',
        'Needs revision' => 'badge-amber',
        'Requested' => 'badge-amber',

        'For review' => 'badge-blue',
        'Ready to review' => 'badge-blue',
        'For assessment' => 'badge-blue',
        'Partial' => 'badge-blue',

        'Disabled' => 'badge-gray',
        'Waiting' => 'badge-gray',
        'Read only' => 'badge-gray',
        'Invited' => 'badge-gray',
        'Not enrolled' => 'badge-gray',
        'Returned' => 'badge-red',
        'Deficiency' => 'badge-red',
    ];
    $cls = $map[$st] ?? 'badge-blue';
    return '<span class="badge ' . $cls . '">' . e($st) . '</span>';
}

function tabs(string $base, array $list, string $active): string {
    $out = '<div class="nav-tabs">';
    foreach ($list as $k => $label) {
        $cls = 'nav-tab-link' . ($k === $active ? ' active' : '');
        $out .= '<a class="' . $cls . '" href="' . url($base . '?tab=' . urlencode($k)) . '">' . e($label) . '</a>';
    }
    return $out . '</div>';
}

function pill_tabs(string $base, array $list, string $active): string {
    $out = '<div class="pill-tabs">';
    foreach ($list as $k => $label) {
        $cls = 'pill-tab' . ($k === $active ? ' active' : '');
        $out .= '<a class="' . $cls . '" href="' . url($base . '?tab=' . urlencode($k)) . '">' . e($label) . '</a>';
    }
    return $out . '</div>';
}

function table(array $heads, array $rows): string {
    if (!$rows) return '<p class="sub" style="padding:16px;color:var(--ink-muted);text-align:center">No official records found.</p>';
    return '<div class="table-container"><table class="data-table"><thead><tr><th>'
        . implode('</th><th>', array_map('e', $heads))
        . '</th></tr></thead><tbody>'
        . implode('', $rows)
        . '</tbody></table></div>';
}
