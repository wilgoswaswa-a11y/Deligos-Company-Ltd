<?php
require_once 'includes/auth.php';
require_once 'config/db.php';
require_once 'includes/functions.php';

$customers = $pdo->query("SELECT id, first_name, last_name, phone FROM customers ORDER BY first_name")->fetchAll();
$invoiceNo = generateInvoiceNo();
$pageTitle = 'Point of Sale';
include 'includes/header.php';
?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <h2 class="mb-0">Point of Sale</h2>
    <a class="btn btn-outline-primary" href="inventory.php"><i class="bi bi-box-seam"></i> View Products</a>
</div>
<div class="row">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-body">
                <div class="input-group">
                    <input type="text" id="productSearch" class="form-control" placeholder="Search by SKU or Name...">
                    <button class="btn btn-outline-secondary" id="searchBtn" type="button"><i class="bi bi-search"></i></button>
                </div>
                <div id="searchResults" class="mt-2" style="max-height:200px; overflow-y:auto;"></div>
            </div>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between">
                <span>Current Sale</span>
                <span>Invoice: <strong id="invoiceDisplay"><?= htmlspecialchars($invoiceNo) ?></strong></span>
            </div>
            <div class="card-body">
                <table class="table table-bordered" id="cartTable">
                    <thead><tr><th>Product</th><th>Qty</th><th>Price</th><th>Total</th><th></th></tr></thead>
                    <tbody id="cartBody"></tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Subtotal</strong></td>
                            <td id="subtotal">0.00</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Discount</strong></td>
                            <td>
                                <input type="number" id="discountInput" class="form-control form-control-sm discount-input" value="0" step="0.01">
                            </td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Tax (0%)</strong></td>
                            <td id="taxDisplay">0.00</td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="3" class="text-end"><strong>Grand Total</strong></td>
                            <td id="grandTotal"><strong>0.00</strong></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>

                <div class="row mt-3 g-2">
                    <div class="col-md-4">
                        <select id="customerSelect" class="form-select">
                            <option value="">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?= (int)$c['id'] ?>">
                                    <?= htmlspecialchars($c['first_name'] . ' ' . $c['last_name'] . ' (' . ($c['phone'] ?? '') . ')') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <select id="paymentMethodSelect" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="Lipana">Lipana</option>
                        </select>
                    </div>
                    <div class="col-md-6 text-md-end d-flex flex-wrap justify-content-md-end gap-2">
                        <button class="btn btn-outline-primary" id="initiateLipanaBtn" type="button"><i class="bi bi-phone"></i> Pay with Lipana</button>
                        <button class="btn btn-success" id="completeSaleBtn" type="button"><i class="bi bi-check-circle"></i> Complete Sale</button>
                        <button class="btn btn-danger" id="clearCartBtn" type="button"><i class="bi bi-trash"></i> Clear</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="lipanaModal" tabindex="-1" aria-labelledby="lipanaModalLabel" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="lipanaModalLabel">Lipana Payment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Invoice</label>
              <div id="lipanaInvoiceDisplay" class="form-control-plaintext">&nbsp;</div>
            </div>
            <div class="mb-3">
              <label for="lipanaPhone" class="form-label">Phone Number</label>
              <input type="tel" id="lipanaPhone" class="form-control" placeholder="Phone number (e.g. 0712345678)">
            </div>
            <div class="mb-3">
              <label for="lipanaAmount" class="form-label">Amount</label>
              <input type="number" id="lipanaAmount" class="form-control" min="1" step="1" readonly>
            </div>
            <div class="mb-3">
              <label for="lipanaReference" class="form-label">Payment Reference</label>
              <input type="text" id="lipanaReference" class="form-control" readonly placeholder="Will be filled after sending the request">
            </div>
            <div id="lipanaVerification" class="alert alert-light border d-none mb-0" role="status" aria-live="polite">
              <div id="lipanaVerificationMessage">Send the request, then verify it after the customer approves the M-Pesa prompt.</div>
              <dl id="lipanaVerifiedDetails" class="row mb-0 mt-2 d-none">
                <dt class="col-sm-5">M-Pesa code</dt><dd id="lipanaMpesaCode" class="col-sm-7 mb-1"></dd>
                <dt class="col-sm-5">Customer</dt><dd id="lipanaCustomerName" class="col-sm-7 mb-1"></dd>
                <dt class="col-sm-5">Phone</dt><dd id="lipanaCustomerPhone" class="col-sm-7 mb-0"></dd>
              </dl>
            </div>
            <div class="mb-3">
              <div class="text-muted">A Lipana STK prompt will be sent to the customer. Complete payment on their phone. The receipt reference will appear automatically.</div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-primary" id="confirmLipanaBtn">Send Lipana Request</button>
            <button type="button" class="btn btn-success d-none" id="verifyLipanaBtn">Verify Payment</button>
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
        <div class="card">
            <div class="card-header">Receipt Preview</div>
            <div class="card-body receipt" id="receiptPreview" style="background:#fff; color:#000; min-height:300px;">
                <div class="text-center">
                    <img src="assets/DELIGOS%20LOGO.png" class="receipt-logo" alt="Deligos Company">
                    <h5>DELIGOS COMPANY</h5>
                    <p>
                        Invoice: <span id="receiptInvoice"><?= htmlspecialchars($invoiceNo) ?></span><br>
                        <span id="receiptDate"><?= htmlspecialchars(date('Y-m-d H:i')) ?></span><br>
                        Cashier: <span id="receiptCashier"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></span>
                    </p>
                </div>
                <hr>
                <table class="table table-sm">
                    <thead><tr><th>Item</th><th>Qty</th><th>Price</th></tr></thead>
                    <tbody id="receiptItems"></tbody>
                </table>
                <hr>
                <p>Discount: KSh <span id="receiptDiscount">0.00</span></p>
                <p><strong>Total: KSh <span id="receiptTotal">0.00</span></strong></p>
                <p id="receiptMpesaCode" style="display:none;">MPESA Code: <strong><span id="receiptMpesaCodeValue"></span></strong></p>
                <p><small>Thank you!</small></p>
            </div>
            <div class="card-footer">
                <button class="btn btn-secondary btn-sm" id="printReceiptBtn" type="button"><i class="bi bi-printer"></i> Print Receipt</button>
                <button class="btn btn-success btn-sm" id="downloadReceiptBtn" type="button"><i class="bi bi-file-earmark-pdf"></i> Download PDF</button>
                <a id="viewStoredReceiptBtn" class="btn btn-outline-primary btn-sm ms-2" href="#" style="display:none;" target="_blank"><i class="bi bi-receipt"></i> View Stored Receipt</a>
            </div>
        </div>
    </div>
</div>

<!-- Legacy inline checkout script retained temporarily for source history; the maintained module below is active. -->
<script type="text/plain">
let cart = [];
let invoiceNo = document.getElementById('invoiceDisplay').textContent;
let lastReceiptData = null;
let lipanaRequest = null;

function notify(message, type = 'info') {
    if (typeof window.showToast === 'function') {
        window.showToast(message, type);
    } else {
        alert(message);
    }
}

function generateInvoiceNo() {
    let now = new Date();
    let date = now.getFullYear().toString() + String(now.getMonth() + 1).padStart(2, '0') + String(now.getDate()).padStart(2, '0');
    return 'INV-' + date + '-' + Math.floor(Math.random() * 9000 + 1000);
}

function fmt(n){ return (Number(n) || 0).toFixed(2); }
function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function escapeHtml(value) {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function receiptHasItems() {
    return cart.length > 0 || (lastReceiptData && lastReceiptData.items.length > 0);
}

function getReceiptData() {
    if (cart.length === 0 && lastReceiptData) {
        return lastReceiptData;
    }

    let discount = parseFloat($('#receiptDiscount').text()) || 0;
    let total = parseFloat($('#receiptTotal').text()) || 0;

    return {
        invoice: $('#receiptInvoice').text().trim(),
        date: $('#receiptDate').text().trim(),
        cashier: $('#receiptCashier').text().trim(),
        discount,
        total,
        mpesa_code: lipanaRequest?.checkout_request_id || lipanaRequest?.transaction_id || null,
        items: cart.map(item => ({
            name: item.name,
            qty: item.qty,
            total: item.price * item.qty
        }))
    };
}

function renderReceiptData(data) {
    $('#receiptInvoice').text(data.invoice);
    $('#receiptDate').text(data.date);
    $('#receiptCashier').text(data.cashier);
    $('#receiptDiscount').text(fmt(data.discount));
    $('#receiptTotal').text(fmt(data.total));

    if (data.mpesa_code) {
        $('#receiptMpesaCodeValue').text(data.mpesa_code);
        $('#receiptMpesaCode').show();
    } else {
        $('#receiptMpesaCodeValue').text('');
        $('#receiptMpesaCode').hide();
    }

    let receiptItems = $('#receiptItems');
    receiptItems.empty();
    data.items.forEach(item => {
        receiptItems.append(`<tr><td>${escapeHtml(item.name)}</td><td>${item.qty}</td><td>KSh ${fmt(item.total)}</td></tr>`);
    });
}

function escapePdfText(text) {
    return String(text)
        .replace(/[^\x20-\x7E]/g, '')
        .replace(/\\/g, '\\\\')
        .replace(/\(/g, '\\(')
        .replace(/\)/g, '\\)');
}

function pdfText(x, y, text, size = 10, color = '0 0 0', font = 'F1') {
    return `${color} rg\nBT /${font} ${size} Tf ${x.toFixed(2)} ${y.toFixed(2)} Td (${escapePdfText(text)}) Tj ET\n`;
}

function pdfCenteredText(centerX, y, text, size = 10, color = '0 0 0', font = 'F1') {
    const approximateWidth = String(text).length * size * 0.6;
    return pdfText(centerX - (approximateWidth / 2), y, text, size, color, font);
}

function pdfLine(x1, y1, x2, y2) {
    return `0.75 0.75 0.75 RG ${x1.toFixed(2)} ${y1.toFixed(2)} m ${x2.toFixed(2)} ${y2.toFixed(2)} l S\n`;
}

function pdfBox(x, y, w, h, fill) {
    return `${fill} rg ${x.toFixed(2)} ${y.toFixed(2)} ${w.toFixed(2)} ${h.toFixed(2)} re f\n`;
}

function pdfImage(name, x, y, w, h) {
    return `q ${w.toFixed(2)} 0 0 ${h.toFixed(2)} ${x.toFixed(2)} ${y.toFixed(2)} cm /${name} Do Q\n`;
}

function bytesToHex(bytes) {
    let hex = '';
    bytes.forEach(byte => {
        hex += byte.toString(16).padStart(2, '0');
    });
    return hex;
}

function loadReceiptLogoForPdf() {
    return new Promise((resolve, reject) => {
        const img = new Image();
        img.onload = function() {
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            const ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.drawImage(img, 0, 0);

            const base64 = canvas.toDataURL('image/jpeg', 0.95).split(',')[1];
            const binary = atob(base64);
            const bytes = new Uint8Array(binary.length);
            for (let i = 0; i < binary.length; i++) {
                bytes[i] = binary.charCodeAt(i);
            }

            resolve({
                width: canvas.width,
                height: canvas.height,
                hex: bytesToHex(bytes)
            });
        };
        img.onerror = reject;
        img.src = 'assets/DELIGOS%20LOGO.png';
    });
}

async function makeReceiptPdf(data) {
    const logo = await loadReceiptLogoForPdf();
    const mm = 72 / 25.4;
    const pageW = 210 * mm;
    const pageH = 297 * mm;
    const receiptSize = 80 * mm;
    const receiptX = 0;
    const receiptTop = pageH;
    const receiptBottom = pageH - receiptSize;
    const pad = 10;
    const centerX = receiptX + (receiptSize / 2);
    const textLeft = receiptX + pad;
    const textRight = receiptX + receiptSize - pad;
    let y = receiptTop - 12;
    let content = '';

    content += pdfBox(receiptX, receiptBottom, receiptSize, receiptSize, '1 1 1');

    const logoW = 54;
    const logoH = logoW * (logo.height / logo.width);
    content += pdfImage('Logo', centerX - (logoW / 2), y - logoH, logoW, logoH);
    y -= logoH + 8;
    content += pdfCenteredText(centerX, y, 'DELIGOS COMPANY', 10, '0 0 0', 'F2');
    y -= 12;
    content += pdfCenteredText(centerX, y, `Invoice: ${data.invoice}`, 7);
    y -= 9;
    content += pdfCenteredText(centerX, y, data.date, 7);
    y -= 9;
    content += pdfCenteredText(centerX, y, `Cashier: ${data.cashier}`, 7);
    y -= 10;
    content += pdfLine(textLeft, y, textRight, y);
    y -= 10;

    content += pdfText(textLeft, y, 'Item', 7, '0 0 0', 'F2');
    content += pdfText(receiptX + 142, y, 'Qty', 7, '0 0 0', 'F2');
    content += pdfText(receiptX + 174, y, 'Price', 7, '0 0 0', 'F2');
    y -= 8;
    content += pdfLine(textLeft, y, textRight, y);
    y -= 9;

    const footerHeight = 42;
    const rowHeight = 9;
    const maxRows = Math.max(0, Math.floor((y - (receiptBottom + footerHeight)) / rowHeight));
    const visibleItems = data.items.slice(0, maxRows);
    visibleItems.forEach(item => {
        let name = item.name.length > 22 ? item.name.substring(0, 19) + '...' : item.name;
        content += pdfText(textLeft, y, name, 6);
        content += pdfText(receiptX + 146, y, item.qty, 6);
        content += pdfText(receiptX + 174, y, `KSh ${fmt(item.total)}`, 6);
        y -= rowHeight;
    });

    if (visibleItems.length < data.items.length) {
        content += pdfText(textLeft, y, `+ ${data.items.length - visibleItems.length} more item(s)`, 6);
    }

    y = receiptBottom + 34;
    content += pdfLine(textLeft, y, textRight, y);
    y -= 10;
    content += pdfText(textLeft, y, `Discount: KSh ${fmt(data.discount)}`, 7);
    y -= 10;
    if (data.mpesa_code) {
        content += pdfText(textLeft, y, `MPESA Code: ${data.mpesa_code}`, 7);
        y -= 10;
    }
    content += pdfText(textLeft, y, `Total: KSh ${fmt(data.total)}`, 8, '0 0 0', 'F2');
    y -= 14;
    content += pdfText(textLeft, y, 'Thank you!', 7);

    const objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [6 0 R] /Count 1 >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>',
        `<< /Type /XObject /Subtype /Image /Width ${logo.width} /Height ${logo.height} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter [/ASCIIHexDecode /DCTDecode] /Length ${logo.hex.length + 1} >>\nstream\n${logo.hex}>\nendstream`,
        `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ${pageW} ${pageH}] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> /XObject << /Logo 5 0 R >> >> /Contents 7 0 R >>`,
        `<< /Length ${content.length} >>\nstream\n${content}endstream`
    ];

    let pdf = '%PDF-1.4\n';
    const offsets = [0];
    objects.forEach((object, index) => {
        offsets.push(pdf.length);
        pdf += `${index + 1} 0 obj\n${object}\nendobj\n`;
    });

    const xrefOffset = pdf.length;
    pdf += `xref\n0 ${objects.length + 1}\n`;
    pdf += '0000000000 65535 f \n';
    for (let i = 1; i <= objects.length; i++) {
        pdf += `${String(offsets[i]).padStart(10, '0')} 00000 n \n`;
    }
    pdf += `trailer\n<< /Size ${objects.length + 1} /Root 1 0 R >>\nstartxref\n${xrefOffset}\n%%EOF`;

    return new Blob([pdf], { type: 'application/pdf' });
}

async function printReceiptOnly() {
    if (!receiptHasItems()) {
        notify('Receipt is empty.', 'warning');
        return;
    }

    const data = getReceiptData();
    const blob = await makeReceiptPdf(data);
    const printUrl = URL.createObjectURL(blob);
    const existingFrame = document.getElementById('receiptPrintFrame');
    if (existingFrame) {
        existingFrame.remove();
    }

    const frame = document.createElement('iframe');
    frame.id = 'receiptPrintFrame';
    frame.style.position = 'fixed';
    frame.style.right = '0';
    frame.style.bottom = '0';
    frame.style.width = '0';
    frame.style.height = '0';
    frame.style.border = '0';
    frame.src = printUrl;
    frame.onload = function() {
        frame.contentWindow.focus();
        frame.contentWindow.print();
        setTimeout(function() {
            URL.revokeObjectURL(printUrl);
        }, 30000);
    };
    document.body.appendChild(frame);
}

async function downloadReceiptPdf() {
    if (!receiptHasItems()) {
        notify('Receipt is empty.', 'warning');
        return;
    }

    const data = getReceiptData();
    const blob = await makeReceiptPdf(data);
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${data.invoice || 'receipt'}.pdf`;
    document.body.appendChild(link);
    link.click();
    URL.revokeObjectURL(link.href);
    link.remove();
}

function updateTotals() {
    let subtotal = 0;
    cart.forEach(i => subtotal += i.price * i.qty);

    let discount = parseFloat($('#discountInput').val()) || 0;
    let tax = 0;
    let grand = subtotal - discount + tax;

    $('#subtotal').text(fmt(subtotal));
    $('#taxDisplay').text(fmt(tax));
    $('#grandTotal').text(fmt(grand));
    $('#receiptTotal').text(fmt(grand));
    $('#receiptDiscount').text(fmt(discount));
    $('#lipanaAmount').val(fmt(grand));
}

function renderCart() {
    let tbody = $('#cartBody');
    let subtotal = 0;
    tbody.empty();

    if (cart.length > 0) {
        lastReceiptData = null;
        $('#receiptInvoice').text(invoiceNo);
        $('#receiptDate').text(new Date().toLocaleString());
    }

    cart.forEach((item, index) => {
        let total = item.price * item.qty;
        subtotal += total;
        tbody.append(`
            <tr>
                <td>${escapeHtml(item.name)}</td>
                <td>
                    <button class="btn btn-sm btn-outline-secondary qty-minus" data-index="${index}" type="button">-</button>
                    ${item.qty}
                    <button class="btn btn-sm btn-outline-secondary qty-plus" data-index="${index}" type="button">+</button>
                </td>
                <td>KSh ${item.price.toFixed(2)}</td>
                <td>KSh ${total.toFixed(2)}</td>
                <td><button class="btn btn-sm btn-danger remove-item" data-index="${index}" type="button"><i class="bi bi-x"></i></button></td>
            </tr>
        `);
    });

    // Receipt
    let receiptItems = $('#receiptItems');
    receiptItems.empty();
    cart.forEach(item => {
        receiptItems.append(`<tr><td>${escapeHtml(item.name)}</td><td>${item.qty}</td><td>KSh ${((item.price * item.qty).toFixed(2))}</td></tr>`);
    });

    $('#subtotal').text(fmt(subtotal));
    updateTotals();
}

// Search products
function searchProducts(q) {
    if (!q || q.length < 2) { $('#searchResults').html(''); return; }
    $.ajax({
        url: 'ajax/search_products.php',
        method: 'GET',
        data: { q },
        dataType: 'json',
        success: function(data) {
            let html = '';
            data.forEach(p => {
                html += `
                    <div class="d-flex justify-content-between border-bottom p-2 align-items-center">
                        <span><strong>${escapeHtml(p.name)}</strong> (${escapeHtml(p.sku)}) - KSh ${parseFloat(p.price).toFixed(2)} | Stock: ${parseInt(p.stock_qty, 10)}</span>
                        <button class="btn btn-sm btn-primary add-to-cart" type="button" data-id="${parseInt(p.id, 10)}" data-name="${escapeHtml(p.name)}" data-price="${parseFloat(p.price)}" data-stock="${parseInt(p.stock_qty, 10)}">+ Add</button>
                    </div>
                `;
            });
            $('#searchResults').html(html || '<div class="text-muted">No products found.</div>');
        }
    });
}

$('#productSearch').on('keyup', function(){ searchProducts($(this).val()); });
$('#searchBtn').on('click', function(){ searchProducts($('#productSearch').val()); });

// Add to cart
$(document).on('click', '.add-to-cart', function() {
    let id = $(this).data('id');
    let name = $(this).data('name');
    let price = parseFloat($(this).data('price'));
    let stock = parseInt($(this).data('stock'));

    let existing = cart.find(item => item.id === id);
    if (existing) {
        if (existing.qty < stock) existing.qty++;
        else notify('Not enough stock!', 'warning');
    } else {
        if (stock > 0) cart.push({ id, name, price, qty: 1, stock });
        else notify('Out of stock!', 'warning');
    }
    renderCart();
});

$(document).on('click', '.qty-plus', function() {
    let idx = $(this).data('index');
    if (cart[idx].qty < cart[idx].stock) cart[idx].qty++;
    else notify('Not enough stock!', 'warning');
    renderCart();
});

$(document).on('click', '.qty-minus', function() {
    let idx = $(this).data('index');
    if (cart[idx].qty > 1) cart[idx].qty--;
    else cart.splice(idx, 1);
    renderCart();
});

$(document).on('click', '.remove-item', function() {
    let idx = $(this).data('index');
    cart.splice(idx, 1);
    renderCart();
});

$('#clearCartBtn').on('click', function(){
    if (confirm('Clear cart?')) {
        cart = [];
        lastReceiptData = null;
        $('#discountInput').val(0);
        renderCart();
    }
});

function showLipanaModal() {
    const grand = parseFloat($('#grandTotal').text()) || 0;
    $('#lipanaAmount').val(fmt(grand));
    $('#lipanaInvoiceDisplay').text(invoiceNo);

    if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
        const lipanaModalEl = document.getElementById('lipanaModal');
        const lipanaModal = bootstrap.Modal.getOrCreateInstance(lipanaModalEl);
        lipanaModal.show();
    } else {
        $('#lipanaModal').show();
    }
}

function updateLipanaFields() {
    const isLipana = $('#paymentMethodSelect').val() === 'Lipana';
    $('#initiateLipanaBtn').toggle(isLipana);
    if (!isLipana) {
        $('#lipanaPhone').val('');
        $('#lipanaReference').val('');
        lipanaRequest = null;
    }
}

$('#paymentMethodSelect').on('change', updateLipanaFields);
$('#discountInput').on('input', updateTotals);
$('#printReceiptBtn').on('click', printReceiptOnly);
$('#downloadReceiptBtn').on('click', downloadReceiptPdf);
$('#confirmLipanaBtn').on('click', async function() {
    try {
        const data = await initiateLipanaPayment();
        notify(data.message || 'Payment request sent.', 'success');
        if (typeof bootstrap !== 'undefined' && bootstrap.Modal) {
            const lipanaModalEl = document.getElementById('lipanaModal');
            const lipanaModal = bootstrap.Modal.getOrCreateInstance(lipanaModalEl);
            lipanaModal.hide();
        } else {
            $('#lipanaModal').hide();
        }
    } catch (error) {
        notify(error.message, 'danger');
    }
});

updateLipanaFields();

async function initiateLipanaPayment() {
    const phone = $('#lipanaPhone').val();
    const amount = Number($('#lipanaAmount').val() || $('#grandTotal').text());
    if (!phone) {
        throw new Error('Enter a phone number for Lipana payment.');
    }
    if (!Number.isFinite(amount) || amount < 1) {
        throw new Error('Enter a valid Lipana payment amount.');
    }

    const response = await fetch('ajax/lipana.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': csrfToken()
        },
        body: JSON.stringify({
            action: 'initiate_payment',
            phone_number: phone,
            amount,
            account_reference: invoiceNo,
            transaction_desc: 'POS payment ' + invoiceNo
        })
    });
    const data = await response.json();
    if (!response.ok || !data.success) {
        throw new Error(data.message || 'Lipana request failed.');
    }

    lipanaRequest = {
        invoiceNo,
        amount,
        payload_token: data.payload_token || null,
        transaction_id: data.transaction_id || null,
        checkout_request_id: data.checkout_request_id || null
    };
    $('#lipanaReference').val(data.checkout_request_id || data.transaction_id || '');
    return data;
}

$('#initiateLipanaBtn').on('click', async function() {
    if (cart.length === 0) {
        notify('Cart is empty!', 'warning');
        return;
    }
    showLipanaModal();
});

// Complete Sale
$('#completeSaleBtn').on('click', async function() {
    if (cart.length === 0) { notify('Cart is empty!', 'warning'); return; }

    const completeSaleButton = $(this);
    if (completeSaleButton.data('submitting')) { return; }

    let customer_id = $('#customerSelect').val() || null;
    let discount = parseFloat($('#discountInput').val()) || 0;
    let grand_total = parseFloat($('#grandTotal').text()) || 0;
    let payment_method = $('#paymentMethodSelect').val() || 'Cash';

    if (payment_method === 'Lipana') {
        if (!lipanaRequest || lipanaRequest.invoiceNo !== invoiceNo || lipanaRequest.amount !== grand_total || !lipanaRequest.payload_token) {
            notify('Send the Lipana payment prompt for this exact sale before completing it.', 'warning');
            return;
        }
    }

    let saleData = {
        invoice_no: invoiceNo,
        customer_id: customer_id,
        discount: discount,
        grand_total: grand_total,
        payment_method: payment_method,
        payload_token: lipanaRequest?.payload_token || null,
    };

    completeSaleButton.data('submitting', true)
        .prop('disabled', true)
        .attr('aria-busy', 'true')
        .html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Completing sale…');

    const restoreCompleteSaleButton = function() {
        completeSaleButton.data('submitting', false)
            .prop('disabled', false)
            .removeAttr('aria-busy')
            .html('<i class="bi bi-check-circle"></i> Complete Sale');
    };

    $.ajax({
        url: 'ajax/complete_sale.php',
        method: 'POST',
        contentType: 'application/json',
        headers: { 'X-CSRF-Token': csrfToken() },
        data: JSON.stringify(saleData),
        dataType: 'json',
        success: function(res) {
            if (res.success) {
                notify('Sale completed! Invoice: ' + res.invoice_no, 'success');
                lastReceiptData = getReceiptData();
                cart = [];
                lipanaRequest = null;
                invoiceNo = generateInvoiceNo();
                $('#invoiceDisplay').text(invoiceNo);
                $('#receiptInvoice').text(invoiceNo);
                renderCart();
                $('#discountInput').val(0);
                $('#lipanaReference').val('');
                $('#customerSelect').val('');
                updateTotals();
                renderReceiptData(lastReceiptData);
                // Show link to stored receipt if server returned receipt_id
                if (res.receipt_id) {
                    $('#viewStoredReceiptBtn').attr('href', 'admin/receipt_view.php?id=' + encodeURIComponent(res.receipt_id)).show();
                } else {
                    $('#viewStoredReceiptBtn').hide();
                }
                restoreCompleteSaleButton();
            } else {
                notify('Error: ' + (res.message || 'Unknown error'), 'error');
                restoreCompleteSaleButton();
            }
        },
        error: function(){
            notify('Server error.', 'error');
            restoreCompleteSaleButton();
        }
    });
});

// Initial render
renderCart();
</script>
<script>document.body.dataset.maxDiscountPercent = <?= json_encode(max_discount_percent()) ?>;</script>
<script src="assets/sales.js"></script>
<?php include 'includes/footer.php'; ?>
