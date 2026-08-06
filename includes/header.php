<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') ?>">
    <title>POS System</title>
    <link rel="icon" type="image/x-icon" href="assets/favicon.ico">
    <link rel="shortcut icon" type="image/x-icon" href="assets/favicon.ico">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <style>
        html, body { width: 100%; min-height: 100%; }
        body { padding-top: 120px; overflow-x: hidden; padding-bottom: 3rem; }
        img, svg, canvas, video { max-width: 100%; height: auto; }
        .navbar { background: #2c3e50; min-height: 70px; }
        .navbar-brand, .nav-link { color: #fff !important; }
        .navbar-brand { display: flex; align-items: center; gap: 12px; font-size: clamp(0.9rem, 1.8vw, 1.05rem); font-weight: 700; line-height: 1.2; white-space: normal; }
        .navbar-logo { width: 56px; height: 56px; object-fit: contain; background: #fff; border-radius: 6px; padding: 5px; flex-shrink: 0; }
        .navbar-profile-photo { width: 26px; height: 26px; object-fit: cover; border-radius: 50%; border: 1px solid rgba(255,255,255,.6); }
        .app-layout { display: flex; min-height: calc(100vh - 56px); }
        .sidebar { width: 260px; background: #1f2937; color: #fff; position: fixed; top: 56px; bottom: 0; left: 0; padding: 1rem 0; overflow-y: auto; }
        .sidebar-brand { padding: 0 1.25rem; margin-bottom: 1.25rem; font-size: 0.95rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; }
        .sidebar-gap { height: 1.25rem; }
        .sidebar .nav-link { color: rgba(255,255,255,.85); padding: 0.75rem 1.25rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,.08); }
        .sidebar-footer { padding: 1rem 1.25rem; border-top: 1px solid rgba(255,255,255,.08); margin-top: 1rem; }
        .main-content { margin-left: 260px; width: calc(100% - 260px); padding-top: 1rem; overflow-x: hidden; }
        .topbar { background: #fff; border-bottom: 1px solid #e9ecef; padding: 0.85rem 1rem; margin-bottom: 1rem; display: flex; justify-content: space-between; align-items: center; gap: 1rem; position: fixed; top: 72px; left: 260px; right: 0; z-index: 1100; flex-wrap: wrap; }
        .topbar-info { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; min-width: 0; }
        .topbar-profile { display: flex; align-items: center; gap: 0.75rem; margin-left: auto; min-width: 0; }
        .profile-thumb { width: 42px; height: 42px; border-radius: 50%; object-fit: cover; border: 1px solid #dee2e6; }
        .profile-thumb-icon { width: 42px; height: 42px; border-radius: 50%; background: #6c757d; display: inline-flex; align-items: center; justify-content: center; color: #fff; }
        .sidebar .nav-link .bi { margin-right: 0.5rem; }
        @media (max-width: 991.98px) {
            .sidebar { display: none; }
            .topbar { left: 0; width: 100%; top: 72px; }
            .main-content { margin-left: 0; width: 100%; padding-top: 1rem; }
            body { padding-top: 120px; }
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
        .card-body {
            overflow-x: auto;
            padding: 1rem;
        }
        .card-header {
            padding: 0.9rem 1rem;
        }
        .table { min-width: max-content; margin-bottom: 0; }
        .table th, .table td { vertical-align: middle; white-space: nowrap; }
        .row { --bs-gutter-y: 1rem; }
        .pos-cart { background: #f8f9fa; padding: 1.25rem; border-radius: 8px; }
        .receipt { font-family: monospace; }
        .receipt-logo { width: 72px; max-width: 40%; height: auto; object-fit: contain; margin-bottom: 6px; }
        .discount-input { width: min(140px, 100%); }
        #searchResults .d-flex { gap: 0.75rem; }
        #searchResults span { min-width: 0; overflow-wrap: anywhere; white-space: normal; }
        .app-toast-container { z-index: 1080; }
        .app-toast { min-width: 280px; box-shadow: 0 0.75rem 1.5rem rgba(0,0,0,.18); }
        @media (max-width: 767.98px) {
            body { padding-top: 150px; padding-bottom: 4rem; }
            h1, .h1 { font-size: 1.75rem; }
            h2, .h2 { font-size: 1.5rem; }
            h3, .h3 { font-size: 1.25rem; }
            .navbar .container-fluid { padding-inline: 12px; }
            .navbar-collapse { max-height: calc(100vh - 56px); overflow-y: auto; }
            .navbar-text { display: block; margin: 8px 0 !important; }
            .navbar .btn { width: 100%; margin: 4px 0 !important; }
            .navbar-brand { gap: 0.6rem; }
            .navbar-logo { width: 44px; height: 44px; }
            .app-shell { padding: 12px; }
            .topbar { padding: 0.75rem; }
            .topbar-profile { width: 100%; justify-content: space-between; }
            .card-header { padding: 0.65rem 0.75rem; }
            .card-body { padding: 0.75rem; }
            .display-6 { font-size: 1.75rem; overflow-wrap: anywhere; }
            .input-group { flex-wrap: nowrap; }
            .table { font-size: 0.92rem; }
            .table th, .table td { white-space: normal; }
            .btn { white-space: normal; }
            #searchResults .d-flex { align-items: stretch !important; flex-direction: column; }
            #searchResults .btn { width: 100%; }
            #completeSaleBtn, #clearCartBtn { flex: 1 1 160px; }
            .app-toast-container { left: 0; right: 0; padding: 0.75rem !important; }
            .app-toast { width: 100%; min-width: 0; }
        }
    </style>
</head>
<body>
<div class="toast-container position-fixed top-0 end-0 p-3 app-toast-container" id="appToastContainer"></div>
<nav class="navbar navbar-dark fixed-top navbar-expand-lg">
    <div class="container-fluid">
        <a class="navbar-brand" href="dashboard.php"><img src="assets/DELIGOS%20LOGO.png" class="navbar-logo" alt="Deligos Company"> DELIGOS COMPANY POINT OF SALES (POS)</a>
        <button class="navbar-toggler d-lg-none" type="button" data-bs-toggle="collapse" data-bs-target="#mobileNav" aria-controls="mobileNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse d-lg-none" id="mobileNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <li class="nav-item d-lg-none"><a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="sales.php"><i class="bi bi-basket3"></i> Sales</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="inventory.php"><i class="bi bi-box-seam"></i> Inventory</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="customers.php"><i class="bi bi-people"></i> Customers</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a></li>
                <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
                <li class="nav-item d-lg-none"><a class="nav-link" href="profits.php"><i class="bi bi-currency-dollar"></i> Profits</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="commissions.php"><i class="bi bi-cash-stack"></i> Commissions</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="expenses.php"><i class="bi bi-wallet2"></i> Expenses</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="users.php"><i class="bi bi-people-fill"></i> Users</a></li>
                <?php endif; ?>
            </ul>
            <ul class="navbar-nav ms-auto mb-2 mb-lg-0">
                <li class="nav-item d-lg-none"><a class="nav-link" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a></li>
                <li class="nav-item d-lg-none"><a class="nav-link" href="logout.php"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
            </ul>
        </div>
    </div>
</nav>
<div class="app-layout">
    <aside class="sidebar">
        <div class="sidebar-gap" aria-hidden="true"></div>
        <nav class="nav flex-column">
            <a class="nav-link" href="dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
            <a class="nav-link" href="sales.php"><i class="bi bi-basket3"></i> Sales</a>
            <a class="nav-link" href="inventory.php"><i class="bi bi-box-seam"></i> Inventory</a>
            <a class="nav-link" href="customers.php"><i class="bi bi-people"></i> Customers</a>
            <a class="nav-link" href="reports.php"><i class="bi bi-bar-chart-line"></i> Reports</a>
            <?php if (($_SESSION['role'] ?? '') === 'admin'): ?>
            <a class="nav-link" href="profits.php"><i class="bi bi-currency-dollar"></i> Profits</a>
            <a class="nav-link" href="commissions.php"><i class="bi bi-cash-stack"></i> Commissions</a>
            <a class="nav-link" href="expenses.php"><i class="bi bi-wallet2"></i> Expenses</a>
            <a class="nav-link" href="users.php"><i class="bi bi-people-fill"></i> Users</a>
            <?php endif; ?>
        </nav>
        <div class="sidebar-footer">
            <a class="nav-link" href="profile.php"><i class="bi bi-person-circle"></i> Profile</a>
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
