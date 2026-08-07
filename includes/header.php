<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>POS System</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/vendor/bootstrap-icons/font/bootstrap-icons.css">
    <script src="assets/vendor/jquery/jquery-3.6.0.min.js"></script>
    <style>
        :root { --app-navy: #203247; --app-sidebar: #182536; --app-sidebar-hover: #2d4863; --app-text: #17202a; --app-surface-muted: #f2f5f8; }
        html, body { width: 100%; min-height: 100%; }
        body { padding-top: 120px; overflow-x: hidden; padding-bottom: 3rem; color: var(--app-text); background: #f7f9fb; }
        img, svg, canvas, video { max-width: 100%; height: auto; }
        .navbar { background: var(--app-navy); min-height: 70px; padding: 0.75rem 1rem; }
        .navbar-brand, .nav-link { color: #fff !important; }
        .navbar-brand { display: flex; align-items: center; gap: 12px; font-size: clamp(0.9rem, 1.8vw, 1.05rem); font-weight: 700; line-height: 1.2; white-space: normal; flex: 1 1 auto; min-width: 0; margin-right: 0.75rem; }
        .navbar-logo { width: 56px; height: 56px; object-fit: contain; background: #fff; border-radius: 6px; padding: 5px; flex-shrink: 0; }
        .navbar-profile-photo { width: 26px; height: 26px; object-fit: cover; border-radius: 50%; border: 1px solid rgba(255,255,255,.6); }
        .navbar-toggler { border: 1px solid rgba(255,255,255,.35); padding: 0.35rem 0.5rem; flex-shrink: 0; }
        .navbar-toggler:focus { box-shadow: none; }
        .app-layout { display: flex; min-height: calc(100vh - 56px); }
        .sidebar { width: 260px; background: var(--app-sidebar); color: #fff; position: fixed; top: 56px; bottom: 0; left: 0; padding: 1rem 0; overflow-y: auto; }
        .sidebar-brand { padding: 0 1.25rem; margin-bottom: 1.25rem; font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .sidebar-gap { height: 1.25rem; }
        .sidebar .nav-link { color: rgba(255,255,255,.85); padding: 0.75rem 1.25rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: var(--app-sidebar-hover); }
        .sidebar .nav-link.active { border-left: 4px solid #f5b700; padding-left: calc(1.25rem - 4px); font-weight: 700; }
        .nav-section { padding: 1rem 1.25rem 0.4rem; color: #aebdcd; font-size: 0.7rem; font-weight: 700; letter-spacing: .09em; text-transform: uppercase; }
        .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,.08); margin-top: 1rem; }
        .main-content { margin-left: 260px; width: calc(100% - 260px); padding-top: 1rem; overflow-x: hidden; }
        .topbar { background: #fff; border-bottom: 1px solid #e9ecef; padding: 0.85rem 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; position: fixed; top: 72px; left: 260px; right: 0; z-index: 1100; flex-wrap: wrap; }
        .topbar-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; min-width: 0; }
        .topbar-profile { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; min-width: 0; }
        .profile-thumb { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 1px solid #dee2e6; }
        .profile-thumb-icon { width: 42px; height: 42px; border-radius: 50%; background: #6c757d; display: inline-flex; align-items: center; justify-content: center; color: #fff; }
        .sidebar .nav-link .bi { margin-right: 0.5rem; }
        @media (min-width: 992px) {
            body { padding-top: 152px; }
            .navbar { height: 80px; }
            .sidebar { top: 80px; }
            .topbar { top: 80px; }
        }
        @media (max-width: 991.98px) {
            body { padding-top: 84px; }
            .sidebar { display: none; }
            .main-content { margin-left: 0; width: 100%; padding-top: 0; }
            .topbar { position: static; z-index: auto; width: auto; margin-bottom: 0; padding: 0.85rem 1rem; }
            .mobile-sidebar { --bs-offcanvas-zindex: 1200; width: min(82vw, 320px); background: var(--app-sidebar); color: #fff; }
            .offcanvas-backdrop { z-index: 1190; }
            .mobile-sidebar .offcanvas-header { border-bottom: 1px solid rgba(255,255,255,.12); }
            .mobile-sidebar .offcanvas-title { font-size: 0.95rem; font-weight: 700; letter-spacing: 0.04em; }
            .mobile-sidebar .offcanvas-body { padding: 0.75rem 0 1.25rem; }
            .mobile-sidebar .nav-link { color: rgba(255,255,255,.88); padding: 0.8rem 1.25rem; }
            .mobile-sidebar .nav-link:hover, .mobile-sidebar .nav-link:focus { color: #fff; background: rgba(255,255,255,.1); }
            .mobile-sidebar .nav-link.active { border-left: 4px solid #f5b700; padding-left: calc(1.25rem - 4px); font-weight: 700; }
            .mobile-sidebar .nav-link .bi { width: 1.35rem; margin-right: 0.45rem; }
            .mobile-sidebar-footer { border-top: 1px solid rgba(255,255,255,.12); margin-top: 0.75rem; padding-top: 0.75rem; }
        }
        .app-shell {
            width: 100%;
            max-width: 100%;
            min-width: 0;
            padding-inline: clamp(0.75rem, 2vw, 1.5rem);
            padding-block: clamp(1rem, 3vw, 1.5rem);
        }
        .table-responsive { margin-top: 1.5rem; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .container-fluid, .row, .row > * { min-width: 0; }
        .card { max-width: 100%; min-width: 0; }
        .card-header { background: var(--app-surface-muted); color: var(--app-text); font-weight: 600; }
        .card-body {
            overflow-x: auto;
            padding: 1rem;
        }
        .card-header { padding: 0.9rem 1rem; }
        .table { min-width: max-content; margin-bottom: 0; }
        .table th, .table td { vertical-align: middle; white-space: nowrap; }
        .table thead th { background: #e8eef5; color: var(--app-text); }
        .dashboard-summary-card.bg-warning, .dashboard-summary-card.bg-warning .card-body { color: #1d2733 !important; }
        .is-loading { pointer-events: none; opacity: .8; }
        .btn .spinner-border { vertical-align: -0.15em; }
        @media print {
            body { padding: 0 !important; background: #fff !important; color: #000 !important; }
            .navbar, .sidebar, .topbar, .offcanvas, #site-footer, .d-print-none { display: none !important; }
            .main-content { width: 100% !important; margin: 0 !important; padding: 0 !important; }
            .app-shell { padding: 0 !important; }
            .card, .table { border-color: #555 !important; }
            .table thead th { background: #eee !important; color: #000 !important; }
        }
        .row { --bs-gutter-y: 1rem; }
        .pos-cart { background: #f8f9fa; padding: 1.25rem; border-radius: 8px; }
        .receipt { font-family: monospace; }
        .receipt-logo { width: 72px; max-width: 40%; height: auto; object-fit: contain; margin-bottom: 6px; }
        .discount-input { width: min(140px, 100%); }
        #searchResults .d-flex { gap: 0.75rem; }
        #searchResults span { min-width: 0; overflow-wrap: anywhere; white-space: normal; }
        /* Keep dialogs, their backdrop and feedback above the fixed secondary
           topbar (z-index: 1100) so the navigation cannot intercept clicks. */
        .modal-backdrop { z-index: 1290; }
        .modal { z-index: 1300; }
        .app-toast-container { z-index: 1310; }
        .app-toast { min-width: 280px; box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.18); }
        @media (max-width: 767.98px) {
            body { padding-top: 72px; padding-bottom: 4rem; }
            h1, .h1 { font-size: 1.7rem; }
            h2, .h2 { font-size: 1.4rem; }
            h3, .h3 { font-size: 1.2rem; }
            .navbar { height: 72px; min-height: 72px; padding: 0.65rem 0.8rem; }
            .navbar .container-fluid { padding-inline: 10px; align-items: center; }
            .navbar-brand { gap: 0.55rem; font-size: 0.86rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
            .navbar-logo { width: 42px; height: 42px; }
            .navbar-toggler { order: 2; margin-left: auto; }
            .app-shell { padding: 10px; }
            .topbar { padding: 0.7rem; gap: 0.6rem; }
            .topbar-info strong, .topbar-profile strong { font-size: 0.95rem; }
            .topbar-profile { width: 100%; justify-content: space-between; }
            .card { border-radius: 0.7rem; }
            .card-header { padding: 0.65rem 0.75rem; }
            .card-body { padding: 0.75rem; }
            .display-6 { font-size: 1.6rem; overflow-wrap: anywhere; }
            .input-group { flex-wrap: nowrap; }
            .table { font-size: 0.92rem; }
            .table th, .table td { white-space: normal; }
            .btn { white-space: normal; }
            .btn-sm { padding: 0.35rem 0.6rem; font-size: 0.85rem; }
            #searchResults .d-flex { align-items: stretch !important; flex-direction: column; }
            #searchResults .btn { width: 100%; }
            #completeSaleBtn, #clearCartBtn { flex: 1 1 160px; }
            .app-toast-container { left: 0; right: 0; padding: 0.75rem !important; }
            .app-toast { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
<?php
$currentPage = basename(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH));
$navLinkClass = static function (string $page) use ($currentPage): string {
    return 'nav-link' . ($currentPage === $page ? ' active' : '');
};
?>
<div class="toast-container position-fixed top-0 end-0 p-3 app-toast-container" id="appToastContainer"></div>
<nav class="navbar navbar-dark fixed-top navbar-expand-lg">
    <div class="container-fluid">
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#mobileSidebar" aria-controls="mobileSidebar" aria-label="Open navigation menu">
            <span class="navbar-toggler-icon"></span>
        </button>
        <a class="navbar-brand" href="dashboard.php"><img src="assets/DELIGOS%20LOGO.png" class="navbar-logo" alt="Deligos Company"> DELIGOS COMPANY POINT OF SALES (POS)</a>
    </div>
</nav>
<aside class="offcanvas offcanvas-start mobile-sidebar d-lg-none" tabindex="-1" id="mobileSidebar" aria-labelledby="mobileSidebarLabel">
    <div class="offcanvas-header">
        <h2 class="offcanvas-title" id="mobileSidebarLabel">Navigation</h2>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <nav class="nav flex-column">
            <div class="nav-section">Operations</div>
            <a class="<?= $navLinkClass('dashboard.php') ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="<?= $navLinkClass('sales.php') ?>" href="sales.php"><i class="bi bi-basket3"></i> Sales</a>
            <div class="nav-section">Catalog</div>
            <a class="<?= $navLinkClass('inventory.php') ?>" href="inventory.php"><i class="bi bi-box-seam"></i> Inventory</a>
            <a class="<?= $navLinkClass('customers.php') ?>" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <div class="nav-section">Insights</div>
            <a class="<?= $navLinkClass('reports.php') ?>" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a class="<?= $navLinkClass('profits.php') ?>" href="profits.php"><i class="bi bi-currency-dollar"></i> Profits</a>
            <a class="<?= $navLinkClass('commissions.php') ?>" href="commissions.php"><i class="bi bi-cash-stack"></i> Commissions</a>
            <a class="<?= $navLinkClass('expenses.php') ?>" href="expenses.php"><i class="bi bi-wallet2"></i> Expenses</a>
            <div class="nav-section">Administration</div>
            <a class="<?= $navLinkClass('users.php') ?>" href="users.php"><i class="bi bi-people-fill"></i> Users</a>
            <?php endif; ?>
        </nav>
        <div class="mobile-sidebar-footer">
            <a class="<?= $navLinkClass('profile.php') ?>" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </div>
</aside>
<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-gap" aria-hidden="true"></div>
        <nav class="nav flex-column">
            <div class="nav-section">Operations</div>
            <a class="<?= $navLinkClass('dashboard.php') ?>" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="<?= $navLinkClass('sales.php') ?>" href="sales.php"><i class="bi bi-basket3"></i> Sales</a>
            <div class="nav-section">Catalog</div>
            <a class="<?= $navLinkClass('inventory.php') ?>" href="inventory.php"><i class="bi bi-box-seam"></i> Inventory</a>
            <a class="<?= $navLinkClass('customers.php') ?>" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <div class="nav-section">Insights</div>
            <a class="<?= $navLinkClass('reports.php') ?>" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a class="<?= $navLinkClass('profits.php') ?>" href="profits.php"><i class="bi bi-currency-dollar"></i> Profits</a>
            <a class="<?= $navLinkClass('commissions.php') ?>" href="commissions.php"><i class="bi bi-cash-stack"></i> Commissions</a>
            <a class="<?= $navLinkClass('expenses.php') ?>" href="expenses.php"><i class="bi bi-wallet2"></i> Expenses</a>
            <div class="nav-section">Administration</div>
            <a class="<?= $navLinkClass('users.php') ?>" href="users.php"><i class="bi bi-people-fill"></i> Users</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a class="<?= $navLinkClass('profile.php') ?>" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
            <a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a>
        </div>
    </aside>
    <div class="main-content">
        <div class="topbar">
            <div class="topbar-info">
                <div>
                    <div class="text-muted small">Welcome back</div>
                    <strong><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></strong>
                </div>
            </div>
            <div class="topbar-profile">
                <?php
                    $lastLoginText = 'Unknown';
                    if (!empty($_SESSION['last_login'])) {
                        $lastLoginRaw = $_SESSION['last_login'];
                        $dateTime = DateTime::createFromFormat('Y-m-d H:i:s', $lastLoginRaw);
                        if (!$dateTime) {
                            $dateTime = date_create($lastLoginRaw);
                        }
                        $lastLoginText = $dateTime ? $dateTime->format('F j, Y H:i') : $lastLoginRaw;
                    }
                ?>
                <div>
                    <div class="text-muted small">Last login</div>
                    <strong><?= htmlspecialchars($lastLoginText) ?></strong>
                </div>
                <?php if (!empty($_SESSION['profile_photo'])): ?>
                    <img src="<?= htmlspecialchars($_SESSION['profile_photo']) ?>" class="profile-thumb" alt="Profile photo">
                <?php else: ?>
                    <span class="profile-thumb-icon"><i class="bi bi-person-fill"></i></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="container-fluid app-shell">
