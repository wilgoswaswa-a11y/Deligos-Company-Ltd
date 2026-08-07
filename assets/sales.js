/* Deligos POS - Sales page logic */
(function () {
    'use strict';

    let cart = [];
    let invoiceNo = null;
    let lastReceiptData = null;
    let lipanaRequest = null;
    let lipanaVerificationTimer = null;

    const maxDiscountPercent = parseFloat(document.body.dataset.maxDiscountPercent || '0') || 0;

    function notify(message, type) {
        if (typeof window.showToast === 'function') {
            window.showToast(message, type);
        } else if (window.alert) {
            window.alert(message);
        }
    }

    function fmt(n) {
        return (Number(n) || 0).toFixed(2);
    }

    function csrfToken() {
        return document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '';
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&')
            .replace(/</g, '<')
            .replace(/>/g, '>')
            .replace(/"/g, '"')
            .replace(/'/g, '&#039;');
    }

    function receiptHasItems() {
        return cart.length > 0 || (lastReceiptData && lastReceiptData.items.length > 0);
    }

    /* ------------------------------------------------------------------ *
     * Server-side invoice minting                                        *
     * ------------------------------------------------------------------ */
    async function mintInvoice() {
        try {
            const response = await fetch('ajax/new_invoice.php', {
                method: 'GET',
                headers: { 'X-CSRF-Token': csrfToken() }
            });
            const data = await response.json();
            if (data && data.success && data.invoice_no) {
                return data.invoice_no;
            }
        } catch (error) {
            // Fall back to a client-generated number if the server is unreachable.
        }
        return 'INV-' + dateStamp() + '-' + randomHex(6);
    }

    function dateStamp() {
        const now = new Date();
        return now.getFullYear().toString()
            + String(now.getMonth() + 1).padStart(2, '0')
            + String(now.getDate()).padStart(2, '0');
    }

    function randomHex(length) {
        const bytes = new Uint8Array(Math.ceil(length / 2));
        if (window.crypto && crypto.getRandomValues) {
            crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        let hex = '';
        bytes.forEach(byte => {
            hex += byte.toString(16).padStart(2, '0');
        });
        return hex.toUpperCase().slice(0, length);
    }

    /* ------------------------------------------------------------------ *
     * Receipt data + rendering                                            *
     * ------------------------------------------------------------------ */
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
            discount: discount,
            total: total,
            mpesa_code: lipanaRequest?.mpesa_code || null,
            mpesa_customer_name: lipanaRequest?.customer_name || null,
            mpesa_customer_phone: lipanaRequest?.customer_phone || null,
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

        const receiptItems = $('#receiptItems');
        receiptItems.empty();
        data.items.forEach(function (item) {
            receiptItems.append('<tr><td>' + escapeHtml(item.name) + '</td><td>' + item.qty + '</td><td>KSh ' + fmt(item.total) + '</td></tr>');
        });
    }

    /* ------------------------------------------------------------------ *
     * Receipt PDF generation (minimal hand-rolled PDF writer)             *
     * ------------------------------------------------------------------ */
    function escapePdfText(text) {
        return String(text)
            .replace(/[^\x20-\x7E]/g, '')
            .replace(/\\/g, '\\\\')
            .replace(/\(/g, '\\(')
            .replace(/\)/g, '\\)');
    }

    function pdfText(x, y, text, size, color, font) {
        return `${color || '0 0 0'} rg\nBT /${font || 'F1'} ${size || 10} Tf ${x.toFixed(2)} ${y.toFixed(2)} Td (${escapePdfText(text)}) Tj ET\n`;
    }

    function pdfCenteredText(centerX, y, text, size, color, font) {
        const approximateWidth = String(text).length * (size || 10) * 0.6;
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
        bytes.forEach(function (byte) {
            hex += byte.toString(16).padStart(2, '0');
        });
        return hex;
    }

    function loadReceiptLogoForPdf() {
        return new Promise(function (resolve, reject) {
            const img = new Image();
            img.onload = function () {
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
        content += pdfCenteredText(centerX, y, 'Invoice: ' + data.invoice, 7);
        y -= 9;
        content += pdfCenteredText(centerX, y, data.date, 7);
        y -= 9;
        content += pdfCenteredText(centerX, y, 'Cashier: ' + data.cashier, 7);
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
        visibleItems.forEach(function (item) {
            let name = item.name.length > 22 ? item.name.substring(0, 19) + '...' : item.name;
            content += pdfText(textLeft, y, name, 6);
            content += pdfText(receiptX + 146, y, item.qty, 6);
            content += pdfText(receiptX + 174, y, 'KSh ' + fmt(item.total), 6);
            y -= rowHeight;
        });

        if (visibleItems.length < data.items.length) {
            content += pdfText(textLeft, y, '+ ' + (data.items.length - visibleItems.length) + ' more item(s)', 6);
        }

        y = receiptBottom + 34;
        content += pdfLine(textLeft, y, textRight, y);
        y -= 10;
        content += pdfText(textLeft, y, 'Discount: KSh ' + fmt(data.discount), 7);
        y -= 10;
        if (data.mpesa_code) {
            content += pdfText(textLeft, y, 'MPESA Code: ' + data.mpesa_code, 7);
            y -= 10;
        }
        content += pdfText(textLeft, y, 'Total: KSh ' + fmt(data.total), 8, '0 0 0', 'F2');
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
        objects.forEach(function (object, index) {
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
        frame.onload = function () {
            frame.contentWindow.focus();
            frame.contentWindow.print();
            setTimeout(function () {
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
        link.download = (data.invoice || 'receipt') + '.pdf';
        document.body.appendChild(link);
        link.click();
        URL.revokeObjectURL(link.href);
        link.remove();
    }

    /* ------------------------------------------------------------------ *
     * Cart                                                                *
     * ------------------------------------------------------------------ */
    function updateTotals() {
        let subtotal = 0;
        cart.forEach(function (item) {
            subtotal += item.price * item.qty;
        });

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
        const tbody = $('#cartBody');
        let subtotal = 0;
        tbody.empty();

        if (cart.length > 0) {
            lastReceiptData = null;
            $('#receiptInvoice').text(invoiceNo);
            $('#receiptDate').text(new Date().toLocaleString());
        }

        cart.forEach(function (item, index) {
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

        const receiptItems = $('#receiptItems');
        receiptItems.empty();
        cart.forEach(function (item) {
            receiptItems.append('<tr><td>' + escapeHtml(item.name) + '</td><td>' + item.qty + '</td><td>KSh ' + (item.price * item.qty).toFixed(2) + '</td></tr>');
        });

        $('#subtotal').text(fmt(subtotal));
        updateTotals();
    }

    /* ------------------------------------------------------------------ *
     * Product search                                                      *
     * ------------------------------------------------------------------ */
    function searchProducts(q) {
        if (!q || q.length < 2) {
            $('#searchResults').html('');
            return;
        }
        $.ajax({
            url: 'ajax/search_products.php',
            method: 'GET',
            data: { q: q },
            dataType: 'json',
            success: function (data) {
                let html = '';
                data.forEach(function (p) {
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

    /* ------------------------------------------------------------------ *
     * Lipana                                                              *
     * ------------------------------------------------------------------ */
    function showLipanaModal() {
        const grand = parseFloat($('#grandTotal').text()) || 0;
        $('#lipanaAmount').val(fmt(grand));
        $('#lipanaInvoiceDisplay').text(invoiceNo);
        $('#lipanaVerification').addClass('d-none');
        $('#lipanaVerifiedDetails').addClass('d-none');
        $('#verifyLipanaBtn').toggleClass('d-none', !lipanaRequest).prop('disabled', false);

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
            $('#lipanaVerification').addClass('d-none');
            $('#verifyLipanaBtn').addClass('d-none');
            lipanaRequest = null;
            if (lipanaVerificationTimer) {
                window.clearInterval(lipanaVerificationTimer);
                lipanaVerificationTimer = null;
            }
        }
    }

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
                amount: amount,
                account_reference: invoiceNo,
                transaction_desc: 'POS payment ' + invoiceNo
            })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Lipana request failed.');
        }

        lipanaRequest = {
            invoiceNo: invoiceNo,
            amount: amount,
            payload_token: data.payload_token || null,
            transaction_id: data.transaction_id || null,
            checkout_request_id: data.checkout_request_id || null,
            verified: false,
            mpesa_code: null,
            customer_name: null,
            customer_phone: null
        };
        $('#lipanaReference').val(data.checkout_request_id || data.transaction_id || '');
        $('#lipanaVerification').removeClass('d-none alert-success').addClass('alert-info');
        $('#lipanaVerificationMessage').text('STK prompt sent. Ask the customer to approve it, then click Verify Payment.');
        $('#lipanaVerifiedDetails').addClass('d-none');
        $('#verifyLipanaBtn').removeClass('d-none');
        if (lipanaVerificationTimer) {
            window.clearInterval(lipanaVerificationTimer);
        }
        lipanaVerificationTimer = window.setInterval(function () {
            verifyLipanaPayment().then(function (verification) {
                if (verification.verified && lipanaVerificationTimer) {
                    window.clearInterval(lipanaVerificationTimer);
                    lipanaVerificationTimer = null;
                    notify('Lipana payment verified. Review the M-Pesa code and customer details, then complete the sale.', 'success');
                }
            }).catch(function () {
                // A manual retry remains available; do not interrupt checkout
                // because a temporary network failure should not lose the sale.
            });
        }, 5000);
        return data;
    }

    async function verifyLipanaPayment() {
        if (!lipanaRequest || !lipanaRequest.payload_token) {
            throw new Error('Send the Lipana payment request first.');
        }

        const response = await fetch('ajax/lipana.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() },
            body: JSON.stringify({ action: 'verify_payment', invoice_no: invoiceNo, payload_token: lipanaRequest.payload_token })
        });
        const data = await response.json();
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Unable to verify the Lipana payment.');
        }

        lipanaRequest.verified = Boolean(data.verified);
        lipanaRequest.mpesa_code = data.mpesa_code || null;
        lipanaRequest.customer_name = data.customer_name || null;
        lipanaRequest.customer_phone = data.customer_phone || null;
        $('#lipanaVerification').removeClass('d-none alert-info alert-success').addClass(data.verified ? 'alert-success' : 'alert-info');
        $('#lipanaVerificationMessage').text(data.message || (data.verified ? 'Payment verified successfully.' : 'Payment is still awaiting confirmation.'));

        if (data.verified) {
            $('#lipanaMpesaCode').text(data.mpesa_code || 'Not supplied by Lipana');
            $('#lipanaCustomerName').text(data.customer_name || 'Not supplied by Lipana');
            $('#lipanaCustomerPhone').text(data.customer_phone || 'Not supplied by Lipana');
            $('#lipanaVerifiedDetails').removeClass('d-none');
            if (lipanaVerificationTimer) {
                window.clearInterval(lipanaVerificationTimer);
                lipanaVerificationTimer = null;
            }
        } else {
            $('#lipanaVerifiedDetails').addClass('d-none');
        }
        return data;
    }

    /* ------------------------------------------------------------------ *
     * Complete sale                                                       *
     * ------------------------------------------------------------------ */
    function validateDiscount(subtotal) {
        const discount = parseFloat($('#discountInput').val()) || 0;
        if (discount <= 0) {
            return true;
        }
        if (maxDiscountPercent <= 0) {
            notify('Discounts are disabled for this store.', 'warning');
            return false;
        }
        const maxAllowed = (subtotal * maxDiscountPercent) / 100;
        if (discount > maxAllowed) {
            notify('Discount exceeds the allowed limit of ' + maxDiscountPercent + '% of the subtotal.', 'warning');
            return false;
        }
        return true;
    }

    /* ------------------------------------------------------------------ *
     * Wiring                                                              *
     * ------------------------------------------------------------------ */
    $(function () {
        $('#productSearch').on('keyup', function () {
            searchProducts($(this).val());
        });
        $('#searchBtn').on('click', function () {
            searchProducts($('#productSearch').val());
        });

        $(document).on('click', '.add-to-cart', function () {
            const id = $(this).data('id');
            const name = $(this).data('name');
            const price = parseFloat($(this).data('price'));
            const stock = parseInt($(this).data('stock'), 10);

            const existing = cart.find(function (item) {
                return item.id === id;
            });
            if (existing) {
                if (existing.qty < stock) {
                    existing.qty++;
                } else {
                    notify('Not enough stock!', 'warning');
                }
            } else {
                if (stock > 0) {
                    cart.push({ id: id, name: name, price: price, qty: 1, stock: stock });
                } else {
                    notify('Out of stock!', 'warning');
                }
            }
            renderCart();
        });

        $(document).on('click', '.qty-plus', function () {
            const idx = $(this).data('index');
            if (cart[idx].qty < cart[idx].stock) {
                cart[idx].qty++;
            } else {
                notify('Not enough stock!', 'warning');
            }
            renderCart();
        });

        $(document).on('click', '.qty-minus', function () {
            const idx = $(this).data('index');
            if (cart[idx].qty > 1) {
                cart[idx].qty--;
            } else {
                cart.splice(idx, 1);
            }
            renderCart();
        });

        $(document).on('click', '.remove-item', function () {
            const idx = $(this).data('index');
            cart.splice(idx, 1);
            renderCart();
        });

        $('#clearCartBtn').on('click', function () {
            if (confirm('Clear cart?')) {
                cart = [];
                lastReceiptData = null;
                $('#discountInput').val(0);
                renderCart();
            }
        });

        $('#initiateLipanaBtn').on('click', function () {
            if (cart.length === 0) {
                notify('Cart is empty!', 'warning');
                return;
            }
            if (!validateDiscount(subtotalValue())) {
                return;
            }
            showLipanaModal();
        });

        $('#confirmLipanaBtn').on('click', async function () {
            try {
                const data = await initiateLipanaPayment();
                notify(data.message || 'Payment request sent.', 'success');
            } catch (error) {
                notify(error.message, 'danger');
            }
        });

        $('#verifyLipanaBtn').on('click', async function () {
            const verifyButton = $(this).prop('disabled', true);
            try {
                const data = await verifyLipanaPayment();
                notify(data.message, data.verified ? 'success' : 'info');
            } catch (error) {
                notify(error.message, 'danger');
            } finally {
                verifyButton.prop('disabled', false);
            }
        });

        $('#paymentMethodSelect').on('change', updateLipanaFields);
        $('#discountInput').on('input', updateTotals);
        $('#printReceiptBtn').on('click', printReceiptOnly);
        $('#downloadReceiptBtn').on('click', downloadReceiptPdf);

        $('#completeSaleBtn').on('click', async function () {
            if (cart.length === 0) {
                notify('Cart is empty!', 'warning');
                return;
            }

            const completeSaleButton = $(this);
            if (completeSaleButton.data('submitting')) {
                return;
            }

            const subtotal = subtotalValue();
            if (!validateDiscount(subtotal)) {
                return;
            }

            const customer_id = $('#customerSelect').val() || null;
            const discount = parseFloat($('#discountInput').val()) || 0;
            const grand_total = parseFloat($('#grandTotal').text()) || 0;
            const payment_method = $('#paymentMethodSelect').val() || 'Cash';

            if (payment_method === 'Lipana') {
                if (!lipanaRequest || lipanaRequest.invoiceNo !== invoiceNo || lipanaRequest.amount !== grand_total || !lipanaRequest.verified) {
                    notify('Verify the successful Lipana payment and confirm its M-Pesa code before completing this sale.', 'warning');
                    return;
                }
            }

            const saleData = {
                invoice_no: invoiceNo,
                customer_id: customer_id,
                discount: discount,
                grand_total: grand_total,
                payment_method: payment_method,
                payment_reference: lipanaRequest?.checkout_request_id || lipanaRequest?.transaction_id || null,
                items: cart.map(function (item) {
                    return { product_id: item.id, qty: item.qty, unit_price: item.price };
                })
            };
            if (payment_method === 'Lipana' && lipanaRequest.payload_token) {
                saleData.payload_token = lipanaRequest.payload_token;
            }

            completeSaleButton.data('submitting', true)
                .prop('disabled', true)
                .attr('aria-busy', 'true')
                .html('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Completing sale…');

            const restoreCompleteSaleButton = function () {
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
                success: async function (res) {
                    if (res.success) {
                        notify('Sale completed! Invoice: ' + res.invoice_no, 'success');
                        lastReceiptData = getReceiptData();
                        cart = [];
                        lipanaRequest = null;
                        if (lipanaVerificationTimer) {
                            window.clearInterval(lipanaVerificationTimer);
                            lipanaVerificationTimer = null;
                        }
                        invoiceNo = await mintInvoice();
                        $('#invoiceDisplay').text(invoiceNo);
                        $('#receiptInvoice').text(invoiceNo);
                        renderCart();
                        $('#discountInput').val(0);
                        $('#lipanaReference').val('');
                        $('#customerSelect').val('');
                        updateTotals();
                        renderReceiptData(lastReceiptData);
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
                error: function () {
                    notify('Server error.', 'error');
                    restoreCompleteSaleButton();
                }
            });
        });

        updateLipanaFields();
        renderCart();

        // Mint the invoice number from the server so it can never collide
        // with one generated by another terminal or a previous reload.
        mintInvoice().then(function (serverInvoice) {
            invoiceNo = serverInvoice;
            $('#invoiceDisplay').text(serverInvoice);
            $('#receiptInvoice').text(serverInvoice);
        });
    });

    function subtotalValue() {
        let subtotal = 0;
        cart.forEach(function (item) {
            subtotal += item.price * item.qty;
        });
        return subtotal;
    }
})();
