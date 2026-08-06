<?php
ob_start();
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$error = '';
$start = $_GET['start'] ?? date('Y-m-01');
$end = $_GET['end'] ?? date('Y-m-d');
$cashier_id = isset($_GET['cashier_id']) ? (int)$_GET['cashier_id'] : 0;

try {
    $cashiers = $pdo->query("SELECT id, full_name FROM users ORDER BY full_name")->fetchAll();
} catch (Throwable $e) {
    $error = app_exception_message($e, 'We could not load the cashier list right now. Please try again later.');
    $cashiers = [];
}
$selected_cashier = 'All Cashiers';
foreach ($cashiers as $cashier) {
    if ((int)$cashier['id'] === $cashier_id) {
        $selected_cashier = $cashier['full_name'];
        break;
    }
}

$where = ["DATE(s.sale_date) BETWEEN ? AND ?"];
$params = [$start, $end];
if ($cashier_id > 0) {
    $where[] = "s.user_id = ?";
    $params[] = $cashier_id;
}

try {
    $stmt = $pdo->prepare(
        "SELECT s.*, u.full_name AS cashier, c.first_name, c.last_name
         FROM sales s
         LEFT JOIN users u ON s.user_id = u.id
         LEFT JOIN customers c ON s.customer_id = c.id
         WHERE " . implode(' AND ', $where) . "
         ORDER BY s.sale_date DESC"
    );
    $stmt->execute($params);
    $sales = $stmt->fetchAll();
} catch (Throwable $e) {
    $error = app_exception_message($e, 'We could not load the sales report right now. Please try again later.');
    $sales = [];
}

$total_revenue = 0;
foreach ($sales as $s) { $total_revenue += (float)$s['grand_total']; }
$total_sales = count($sales);
$avg_sale = $total_sales ? ($total_revenue / $total_sales) : 0;

function pdf_text($x, $y, $text, $size = 10, $color = '0 0 0')
{
    $text = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], (string)$text);
    $text = preg_replace('/[^\x20-\x7E]/', '', $text);
    return sprintf("%s rg\nBT /F1 %d Tf %.2f %.2f Td (%s) Tj ET\n", $color, $size, $x, $y, $text);
}

function pdf_box($x, $y, $w, $h, $fill = null)
{
    if ($fill) {
        return sprintf("%s rg %.2f %.2f %.2f %.2f re f\n", $fill, $x, $y, $w, $h);
    }

    return sprintf("0.80 0.80 0.80 RG %.2f %.2f %.2f %.2f re S\n", $x, $y, $w, $h);
}

function pdf_line($x1, $y1, $x2, $y2)
{
    return sprintf("0.82 0.82 0.82 RG %.2f %.2f m %.2f %.2f l S\n", $x1, $y1, $x2, $y2);
}

function pdf_image($name, $x, $y, $w, $h)
{
    return sprintf("q %.2f 0 0 %.2f %.2f %.2f cm /%s Do Q\n", $w, $h, $x, $y, $name);
}

function pdf_cell_text($text, $limit)
{
    $text = trim((string)$text);
    return strlen($text) > $limit ? substr($text, 0, $limit - 3) . '...' : $text;
}

function get_pdf_logo_data()
{
    $jpegPath = __DIR__ . '/assets/DELIGOS LOGO.jpg';
    if (is_file($jpegPath)) {
        $size = @getimagesize($jpegPath);
        $data = @file_get_contents($jpegPath);
        if ($size && $data) {
            return [
                'width' => $size[0],
                'height' => $size[1],
                'data' => $data,
            ];
        }
    }

    $path = __DIR__ . '/assets/DELIGOS LOGO.png';
    if (!is_file($path) || !function_exists('imagecreatefrompng')) {
        return null;
    }
    $source = @imagecreatefrompng($path);
    if (!$source) {
        return null;
    }

    $width = imagesx($source);
    $height = imagesy($source);
    $canvas = imagecreatetruecolor($width, $height);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $width, $height, $white);
    imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

    ob_start();
    imagejpeg($canvas, null, 95);
    $jpeg = ob_get_clean();
    imagedestroy($source);
    imagedestroy($canvas);

    if (!$jpeg) {
        return null;
    }

    return [
        'width' => $width,
        'height' => $height,
        'data' => $jpeg,
    ];
}

function build_sales_report_pdf($sales, $start, $end, $cashier_name, $total_sales, $total_revenue, $avg_sale)
{
    $logo = get_pdf_logo_data();
    $pageW = 842;
    $pageH = 595;
    $margin = 36;
    $tableX = $margin;
    $tableW = $pageW - ($margin * 2);
    $cols = [92, 110, 150, 88, 82, 92, 110];
    $headers = ['Invoice', 'Cashier', 'Customer', 'Total', 'Discount', 'Grand', 'Date'];
    $pages = [];
    $content = '';
    $y = 0;

    $startPage = function () use (&$content, &$y, $pageH, $margin, $tableX, $tableW, $cols, $headers, $start, $end, $cashier_name, $total_sales, $total_revenue, $avg_sale, $logo) {
        $content = '';
        if ($logo) {
            $content .= pdf_image('Logo', $margin, $pageH - 82, 48, 48);
        }
        $content .= pdf_text($margin + 60, $pageH - 38, 'DELIGOS COMPANY', 18);
        $content .= pdf_text($margin + 60, $pageH - 58, 'Sales Report', 13);
        $content .= pdf_text($margin + 60, $pageH - 76, 'Period: ' . $start . ' to ' . $end, 10);
        $content .= pdf_text($margin + 60, $pageH - 92, 'Cashier: ' . $cashier_name, 10);
        $content .= pdf_text(650, $pageH - 64, 'Generated: ' . date('Y-m-d H:i'), 9);

        $metricY = $pageH - 138;
        $metricW = 240;
        $content .= pdf_box($margin, $metricY, $metricW, 34, '0.90 0.97 1.00');
        $content .= pdf_text($margin + 12, $metricY + 20, 'Total Sales: ' . (int)$total_sales, 11);
        $content .= pdf_box($margin + 260, $metricY, $metricW, 34, '0.90 1.00 0.94');
        $content .= pdf_text($margin + 272, $metricY + 20, 'Revenue: KSh ' . number_format($total_revenue, 2), 11);
        $content .= pdf_box($margin + 520, $metricY, $metricW, 34, '1.00 0.97 0.86');
        $content .= pdf_text($margin + 532, $metricY + 20, 'Avg Sale: KSh ' . number_format($avg_sale, 2), 11);

        $headerY = $pageH - 182;
        $content .= pdf_box($tableX, $headerY, $tableW, 22, '0.17 0.24 0.31');
        $x = $tableX + 6;
        foreach ($headers as $i => $header) {
            $content .= pdf_text($x, $headerY + 8, $header, 9, '1 1 1');
            $x += $cols[$i];
        }
        $y = $headerY - 18;
    };

    $finishPage = function () use (&$pages, &$content, $margin, $tableW) {
        $content .= pdf_line($margin, 38, $margin + $tableW, 38);
        $content .= pdf_text($margin, 24, 'POS System', 8);
        $pages[] = $content;
    };

    $startPage();

    foreach ($sales as $index => $s) {
        if ($y < 58) {
            $finishPage();
            $startPage();
        }

        $rowY = $y - 4;
        if ($index % 2 === 0) {
            $content .= pdf_box($tableX, $rowY, $tableW, 18, '0.97 0.97 0.97');
        }

        $customer = $s['first_name'] ? $s['first_name'] . ' ' . $s['last_name'] : 'Walk-in';
        $row = [
            pdf_cell_text($s['invoice_no'], 17),
            pdf_cell_text($s['cashier'], 20),
            pdf_cell_text($customer, 28),
            'KSh ' . number_format((float)$s['total_amount'], 2),
            'KSh ' . number_format((float)$s['discount'], 2),
            'KSh ' . number_format((float)$s['grand_total'], 2),
            date('d/m/Y H:i', strtotime($s['sale_date'])),
        ];

        $x = $tableX + 6;
        foreach ($row as $i => $value) {
            $content .= pdf_text($x, $y + 2, $value, 8);
            $x += $cols[$i];
        }

        $content .= pdf_line($tableX, $rowY, $tableX + $tableW, $rowY);
        $y -= 18;
    }

    if (!$sales) {
        $content .= pdf_text($tableX + 6, $y + 2, 'No sales found for this period.', 10);
    }

    $finishPage();

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[] = '';
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $logoObjectNumber = 0;
    if ($logo) {
        $logoObjectNumber = count($objects) + 1;
        $objects[] = "<< /Type /XObject /Subtype /Image /Width {$logo['width']} /Height {$logo['height']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($logo['data']) . " >>\nstream\n" . $logo['data'] . "\nendstream";
    }

    $kids = [];
    foreach ($pages as $pageContent) {
        $pageObjectNumber = count($objects) + 1;
        $contentObjectNumber = $pageObjectNumber + 1;
        $kids[] = $pageObjectNumber . ' 0 R';
        $xObjectResource = $logoObjectNumber ? " /XObject << /Logo $logoObjectNumber 0 R >>" : '';
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 $pageW $pageH] /Resources << /Font << /F1 3 0 R >>$xObjectResource >> /Contents $contentObjectNumber 0 R >>";
        $objects[] = "<< /Length " . strlen($pageContent) . " >>\nstream\n" . $pageContent . "endstream";
    }
    $objects[1] = "<< /Type /Pages /Kids [" . implode(' ', $kids) . "] /Count " . count($pages) . " >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $object) {
        $offsets[] = strlen($pdf);
        $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n$xrefOffset\n%%EOF";

    return $pdf;
}

if (($_GET['download'] ?? '') === 'pdf') {
    $cashier_slug = $cashier_id > 0 ? '-cashier-' . $cashier_id : '';
    $filename = 'sales-report-' . preg_replace('/[^0-9-]/', '', $start) . '-to-' . preg_replace('/[^0-9-]/', '', $end) . $cashier_slug . '.pdf';

    if (ob_get_length()) {
        ob_end_clean();
    }

    $pdf = build_sales_report_pdf($sales, $start, $end, $selected_cashier, $total_sales, $total_revenue, $avg_sale);
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if (ob_get_length()) {
    ob_end_clean();
}

$pageTitle = 'Reports';
include 'includes/header.php';
$download_query = http_build_query([
    'start' => $start,
    'end' => $end,
    'cashier_id' => $cashier_id,
    'download' => 'pdf',
]);
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0">Sales Reports</h2>
    <div class="d-flex gap-2 d-print-none">
        <button class="btn btn-secondary" onclick="window.print()" type="button"><i class="bi bi-printer"></i> Print</button>
        <a class="btn btn-success" data-loading-link data-loading-text="Preparing PDF…" href="?<?= htmlspecialchars($download_query) ?>"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a>
    </div>
</div>

<form method="GET" class="row g-3 mb-3 d-print-none" data-loading-text="Generating report…">
    <div class="col-auto"><label>From</label><input type="date" name="start" class="form-control" value="<?= htmlspecialchars($start) ?>"></div>
    <div class="col-auto"><label>To</label><input type="date" name="end" class="form-control" value="<?= htmlspecialchars($end) ?>"></div>
    <div class="col-auto">
        <label>Cashier</label>
        <select name="cashier_id" class="form-select">
            <option value="0">All Cashiers</option>
            <?php foreach ($cashiers as $cashier): ?>
                <option value="<?= (int)$cashier['id'] ?>" <?= (int)$cashier['id'] === $cashier_id ? 'selected' : '' ?>>
                    <?= htmlspecialchars($cashier['full_name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-auto align-self-end"><button class="btn btn-primary" type="submit">Filter</button></div>
</form>

<p class="text-muted">Report period: <?= htmlspecialchars($start) ?> to <?= htmlspecialchars($end) ?> | Cashier: <?= htmlspecialchars($selected_cashier) ?></p>

<div class="row mb-3">
    <div class="col-md-4"><div class="card bg-info text-white p-3">Total Sales: <?= (int)$total_sales ?></div></div>
    <div class="col-md-4"><div class="card bg-success text-white p-3">Revenue: KSh <?= number_format($total_revenue, 2) ?></div></div>
    <div class="col-md-4"><div class="card bg-warning text-white p-3">Avg Sale: KSh <?= number_format($avg_sale, 2) ?></div></div>
</div>

<div class="table-responsive">
    <table class="table table-striped">
        <thead><tr><th>Invoice</th><th>Cashier</th><th>Customer</th><th>Total</th><th>Discount</th><th>Grand</th><th>Date</th></tr></thead>
        <tbody>
        <?php foreach ($sales as $s): ?>
            <tr>
                <td><?= htmlspecialchars($s['invoice_no']) ?></td>
                <td><?= htmlspecialchars((string)$s['cashier']) ?></td>
                <td><?= $s['first_name'] ? htmlspecialchars($s['first_name'].' '.$s['last_name']) : 'Walk-in' ?></td>
                <td>KSh <?= number_format((float)$s['total_amount'], 2) ?></td>
                <td>KSh <?= number_format((float)$s['discount'], 2) ?></td>
                <td><strong>KSh <?= number_format((float)$s['grand_total'], 2) ?></strong></td>
                <td><?= date('d/m/Y H:i', strtotime($s['sale_date'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php include 'includes/footer.php'; ?>
