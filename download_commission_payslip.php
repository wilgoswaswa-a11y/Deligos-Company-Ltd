<?php
$required_role = 'admin';
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

require_post_csrf();

$userId = validate_int($_POST['user_id'] ?? null, 1);
$start = $_POST['start'] ?? '';
$end = $_POST['end'] ?? '';
$rate = validate_decimal($_POST['rate'] ?? null, 0, 100);

if ($userId === null || $rate === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $start > $end) {
    http_response_code(422);
    exit('Invalid payslip request.');
}

try {
    $stmt = $pdo->prepare(
        "SELECT u.id, u.full_name, u.username, u.role,
                COUNT(s.id) AS sale_count,
                COALESCE(SUM(s.grand_total), 0) AS sales_total
         FROM users u
         LEFT JOIN sales s ON s.user_id = u.id AND DATE(s.sale_date) BETWEEN ? AND ?
         WHERE u.id = ?
         GROUP BY u.id, u.full_name, u.username, u.role"
    );
    $stmt->execute([$start, $end, $userId]);
    $staff = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$staff) {
        http_response_code(404);
        exit('Staff member not found.');
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Could not create the payslip.');
}

$salesTotal = (float)$staff['sales_total'];
$commission = round($salesTotal * ($rate / 100), 2);
$referenceNumber = 'PAY-' . strtoupper(substr(md5($staff['username'] . $start . $end . $rate), 0, 10));
$earnings = ['Sales commission (' . number_format($rate, 2) . '%)' => $commission];
$deductions = ['No deductions recorded' => 0.00];
$totalEarnings = array_sum($earnings);
$totalDeductions = array_sum($deductions);
$netPay = $totalEarnings - $totalDeductions;
$payPeriod = date('F j, Y', strtotime($start)) . ' – ' . date('F j, Y', strtotime($end));

function payslip_money(float $amount): string
{
    return 'KSh ' . number_format($amount, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Commission Payslip – <?= htmlspecialchars($staff['full_name']) ?></title>
<style>
    :root { --navy: #203247; --ink: #17202a; --muted: #52606d; --line: #d9e1ea; --surface: #f3f6f9; }
    * { box-sizing: border-box; }
    body { margin: 0; padding: 24px; color: var(--ink); background: #edf1f5; font-family: "Segoe UI", Arial, sans-serif; }
    .print-actions { max-width: 860px; margin: 0 auto 14px; text-align: right; }
    .print-actions button { border: 0; border-radius: 5px; background: var(--navy); color: #fff; cursor: pointer; font: inherit; padding: 9px 16px; }
    .payslip { position: relative; max-width: 860px; margin: 0 auto; overflow: hidden; background: #fff; border-radius: 8px; box-shadow: 0 3px 16px rgba(23,32,42,.12); }
    .watermark { position: absolute; top: 53%; left: 50%; z-index: 0; width: min(48%, 310px); opacity: .055; pointer-events: none; transform: translate(-50%, -50%); }
    .payslip-header, .details, .tables, .summary, .footer { position: relative; z-index: 1; }
    .payslip-header { display: flex; justify-content: space-between; align-items: center; gap: 20px; padding: 24px 30px; color: #fff; background: var(--navy); }
    .company { display: flex; align-items: center; gap: 14px; }
    .company img { width: 54px; height: 54px; padding: 4px; object-fit: contain; border-radius: 6px; background: #fff; }
    h1, h2, p { margin: 0; }
    .company h1 { font-size: 20px; }
    .company p, .payslip-title p { margin-top: 4px; color: rgba(255,255,255,.86); font-size: 13px; }
    .payslip-title { text-align: right; }
    .payslip-title h2 { font-size: 22px; letter-spacing: 1px; }
    .details { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 13px 28px; padding: 22px 30px; border-bottom: 1px solid var(--line); }
    .detail { font-size: 14px; }
    .detail strong { display: inline-block; min-width: 124px; color: var(--muted); }
    .tables { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 22px; padding: 22px 30px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th { padding: 9px 10px; color: var(--ink); text-align: left; background: var(--surface); border-bottom: 2px solid var(--line); }
    td { padding: 9px 10px; border-bottom: 1px solid #e6ebf0; }
    th:last-child, td:last-child { text-align: right; }
    .total-row td { border-top: 2px solid #bac6d2; border-bottom: 0; font-weight: 700; }
    .summary { display: flex; justify-content: flex-end; padding: 0 30px 30px; }
    .summary table { width: min(100%, 350px); }
    .net-pay td { color: #fff; font-size: 16px; font-weight: 700; background: var(--navy); border: 0; }
    .net-pay td:first-child { border-radius: 5px 0 0 5px; }
    .net-pay td:last-child { border-radius: 0 5px 5px 0; }
    .footer { padding: 16px; border-top: 1px solid var(--line); color: var(--muted); font-size: 12px; text-align: center; }
    @media (max-width: 620px) { body { padding: 12px; } .payslip-header, .details, .tables { grid-template-columns: 1fr; } .payslip-header { align-items: flex-start; flex-direction: column; } .payslip-title { text-align: left; } .details, .tables { padding: 18px; } .summary { padding: 0 18px 18px; } }
    @media print { body { padding: 0; background: #fff; } .print-actions { display: none; } .payslip { max-width: none; border-radius: 0; box-shadow: none; } }
</style>
</head>
<body>
<div class="print-actions"><button type="button" onclick="window.print()">Print / Save as PDF</button></div>
<main class="payslip">
    <img class="watermark" src="assets/DELIGOS%20LOGO.png" alt="" aria-hidden="true">
    <header class="payslip-header">
        <div class="company">
            <img src="assets/DELIGOS%20LOGO.png" alt="Deligos Company logo">
            <div><h1>DELIGOS COMPANY</h1><p>Point of Sale System</p></div>
        </div>
        <div class="payslip-title"><h2>COMMISSION PAYSLIP</h2><p><?= htmlspecialchars($payPeriod) ?></p></div>
    </header>
    <section class="details" aria-label="Staff and pay period details">
        <div class="detail"><strong>Staff member:</strong> <?= htmlspecialchars($staff['full_name']) ?></div>
        <div class="detail"><strong>Employee ID:</strong> EMP-<?= str_pad((string)$staff['id'], 5, '0', STR_PAD_LEFT) ?></div>
        <div class="detail"><strong>Username:</strong> <?= htmlspecialchars($staff['username']) ?></div>
        <div class="detail"><strong>Role:</strong> <?= htmlspecialchars(ucfirst($staff['role'])) ?></div>
        <div class="detail"><strong>Pay date:</strong> <?= htmlspecialchars(date('F j, Y')) ?></div>
        <div class="detail"><strong>Reference:</strong> <?= htmlspecialchars($referenceNumber) ?></div>
        <div class="detail"><strong>Completed sales:</strong> <?= (int)$staff['sale_count'] ?></div>
        <div class="detail"><strong>Eligible sales:</strong> <?= payslip_money($salesTotal) ?></div>
    </section>
    <section class="tables">
        <table aria-label="Earnings"><thead><tr><th>Earnings</th><th>Amount</th></tr></thead><tbody>
            <?php foreach ($earnings as $label => $amount): ?><tr><td><?= htmlspecialchars($label) ?></td><td><?= payslip_money($amount) ?></td></tr><?php endforeach; ?>
            <tr class="total-row"><td>Total earnings</td><td><?= payslip_money($totalEarnings) ?></td></tr>
        </tbody></table>
        <table aria-label="Deductions"><thead><tr><th>Deductions</th><th>Amount</th></tr></thead><tbody>
            <?php foreach ($deductions as $label => $amount): ?><tr><td><?= htmlspecialchars($label) ?></td><td><?= payslip_money($amount) ?></td></tr><?php endforeach; ?>
            <tr class="total-row"><td>Total deductions</td><td><?= payslip_money($totalDeductions) ?></td></tr>
        </tbody></table>
    </section>
    <section class="summary"><table><tbody>
        <tr><td>Gross commission</td><td><?= payslip_money($totalEarnings) ?></td></tr>
        <tr><td>Total deductions</td><td>-<?= payslip_money($totalDeductions) ?></td></tr>
        <tr class="net-pay"><td>Net payable</td><td><?= payslip_money($netPay) ?></td></tr>
    </tbody></table></section>
    <footer class="footer">This is a system-generated commission payslip and does not require a signature.</footer>
</main>
</body>
</html>
