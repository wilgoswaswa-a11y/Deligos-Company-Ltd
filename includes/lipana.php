<?php
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/env.php';

function get_lipana_config(): array
{
    $environment = strtolower((string)(env('LIPANA_ENVIRONMENT') ?: 'sandbox'));
    if (!in_array($environment, ['sandbox', 'production'], true)) {
        $environment = 'sandbox';
    }

    $baseUrl = rtrim((string)(env('LIPANA_BASE_URL') ?: ''), '/');
    if ($baseUrl === '') {
        $baseUrl = $environment === 'production'
            ? 'https://api.lipana.dev/v1'
            : 'https://api-sandbox.lipana.dev/v1';
    } elseif ($environment === 'production' && str_starts_with($baseUrl, 'https://api-sandbox.lipana.dev')) {
        $baseUrl = 'https://api.lipana.dev' . substr($baseUrl, strlen('https://api-sandbox.lipana.dev'));
    } elseif ($environment === 'sandbox' && str_starts_with($baseUrl, 'https://api.lipana.dev')) {
        $baseUrl = 'https://api-sandbox.lipana.dev' . substr($baseUrl, strlen('https://api.lipana.dev'));
    } elseif (in_array($baseUrl, ['https://api.lipana.dev', 'https://api-sandbox.lipana.dev'], true)) {
        $baseUrl .= '/v1';
    }

    $endpoint = (string)(env('LIPANA_PAYMENT_ENDPOINT') ?: '/transactions/push-stk');
    if ($endpoint === '/v1/stkpush' || $endpoint === '/stk-push') {
        $endpoint = '/transactions/push-stk';
    }

    return [
        // Lipana manages the underlying M-Pesa connection; this app only needs
        // a server-side Lipana secret key.
        'base_url' => $baseUrl,
        'api_key' => (string)(env('LIPANA_SECRET_KEY') ?: env('LIPANA_API_KEY') ?: ''),
        'endpoint' => $endpoint,
        'environment' => $environment,
        'timeout' => (int)(env('LIPANA_TIMEOUT') ?: 60),
        'webhook_secret' => (string)(env('LIPANA_WEBHOOK_SECRET') ?: ''),
        'webhook_url' => (string)(env('LIPANA_WEBHOOK_URL') ?: ''),
    ];
}

function normalize_lipana_phone(string $phone): string
{
    $phone = preg_replace('/[^0-9+]/', '', trim($phone)) ?? '';
    if ($phone === '') {
        return '';
    }

    if (str_starts_with($phone, '+254')) {
        return substr($phone, 1);
    }

    if (str_starts_with($phone, '254')) {
        return $phone;
    }

    if (str_starts_with($phone, '0')) {
        return '254' . substr($phone, 1);
    }

    return $phone;
}

function validate_lipana_phone(string $phone): bool
{
    $normalized = normalize_lipana_phone($phone);
    return preg_match('/^254[1-9]\d{8}$/', $normalized) === 1;
}

function lipana_api_request(string $method, string $path, ?array $body = null, array $query = []): array
{
    $config = get_lipana_config();
    if ($config['api_key'] === '') {
        return ['success' => false, 'message' => 'Lipana API key is not configured.'];
    }

    $url = rtrim($config['base_url'], '/') . '/' . ltrim($path, '/');
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }

    $headers = [
        'x-api-key: ' . $config['api_key'],
        'Accept: application/json',
    ];

    $curlOptions = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];

    if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $headers[] = 'Content-Type: application/json';
        $curlOptions[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
        $curlOptions[CURLOPT_POSTFIELDS] = json_encode($body ?? [], JSON_THROW_ON_ERROR);
    }

    $curlOptions[CURLOPT_HTTPHEADER] = $headers;
    $ch = curl_init();
    curl_setopt_array($ch, $curlOptions);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        app_log('Lipana API request failed: ' . $curlError . ' url=' . $url);
        return ['success' => false, 'message' => 'Unable to reach the Lipana API. Please try again.'];
    }

    $payloadResponse = json_decode($response, true);
    $success = $httpCode >= 200 && $httpCode < 300;
    $providerMessage = '';
    if (is_array($payloadResponse)) {
        $providerMessage = (string)($payloadResponse['message'] ?? $payloadResponse['detail'] ?? '');
        if ($providerMessage === '' && is_string($payloadResponse['error'] ?? null)) {
            $providerMessage = $payloadResponse['error'];
        }
        if ($providerMessage === '' && isset($payloadResponse['error']['message'])) {
            $providerMessage = (string)$payloadResponse['error']['message'];
        }
    }

    if (!$success) {
        app_log('Lipana API response: HTTP ' . $httpCode . ' ' . substr($response, 0, 2000));
    }

    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $payloadResponse,
        'message' => $success
            ? 'Lipana API request succeeded.'
            : ($providerMessage !== '' ? 'Lipana: ' . $providerMessage : 'Lipana API request failed.'),
    ];
}

function lipana_stk_push(array $payload): array
{
    $config = get_lipana_config();
    $phone = normalize_lipana_phone((string)($payload['phone_number'] ?? ''));
    $amount = (int)($payload['amount'] ?? 0);
    $accountReference = (string)($payload['account_reference'] ?? 'POS');
    $transactionDesc = (string)($payload['transaction_desc'] ?? 'POS payment');
    $callbackUrl = trim((string)($payload['callback_url'] ?? ''));

    if ($config['api_key'] === '') {
        return ['success' => false, 'message' => 'Lipana API key is not configured.'];
    }

    if ($phone === '' || !validate_lipana_phone($phone)) {
        return ['success' => false, 'message' => 'A valid Kenyan phone number is required.'];
    }

    if ($amount < 1) {
        return ['success' => false, 'message' => 'Payment amount must be at least KES 1.'];
    }

    // These names match Lipana's STK Push API.
    $body = [
        'phone' => '+' . $phone,
        'amount' => $amount,
        'accountReference' => $accountReference,
        'transactionDesc' => $transactionDesc,
    ];

    if ($callbackUrl !== '') {
        $body['callbackUrl'] = $callbackUrl;
    } elseif ($config['webhook_url'] !== '') {
        $body['callbackUrl'] = $config['webhook_url'];
    }

    $result = lipana_api_request('POST', $config['endpoint'], $body);
    $payloadResponse = $result['response'];
    $data = is_array($payloadResponse) ? ($payloadResponse['data'] ?? []) : [];

    return [
        'success' => $result['success'],
        'http_code' => $result['http_code'],
        'response' => $payloadResponse,
        'data' => $data,
        'transaction_id' => is_string($data['transactionId'] ?? null) ? $data['transactionId'] : (string)($data['transaction_id'] ?? ''),
        'checkout_request_id' => is_string($data['checkoutRequestID'] ?? null) ? $data['checkoutRequestID'] : (string)($data['checkout_request_id'] ?? ''),
        'message' => $result['success']
            ? 'Payment request sent to Lipana.'
            : ($result['message'] !== '' ? $result['message'] : 'Lipana payment request failed.'),
    ];
}

function lipana_extract_webhook_signature(): string
{
    $headers = [];
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
    }

    foreach ($headers as $name => $value) {
        $name = strtolower((string)$name);
        if (in_array($name, ['x-lipana-signature', 'x-signature', 'signature'], true)) {
            return trim((string)$value);
        }
    }

    foreach ($_SERVER as $name => $value) {
        $name = strtolower($name);
        if (in_array($name, ['http_x_lipana_signature', 'http_x_signature', 'http_signature'], true)) {
            return trim((string)$value);
        }
    }

    return '';
}

function lipana_verify_webhook_signature(string $payload, string $signature): bool
{
    $secret = get_lipana_config()['webhook_secret'];
    if ($secret === '' || $signature === '') {
        return false;
    }
    $expectedSignature = hash_hmac('sha256', $payload, $secret);
    return hash_equals($expectedSignature, $signature);
}

function lipana_verify_webhook_request(): bool
{
    $body = file_get_contents('php://input');
    $signature = lipana_extract_webhook_signature();
    return lipana_verify_webhook_signature($body, $signature);
}

function lipana_fetch_transaction_by_id(string $transactionId): array
{
    $transactionId = trim($transactionId);
    if ($transactionId === '') {
        return ['success' => false, 'message' => 'Missing Lipana transaction ID.'];
    }

    return lipana_api_request('GET', '/transactions/' . rawurlencode($transactionId));
}

function lipana_find_transaction_for_request(array $request): array
{
    if (!empty($request['transaction_id'])) {
        $result = lipana_fetch_transaction_by_id((string)$request['transaction_id']);
        if ($result['success'] && is_array($result['response']['data'] ?? null)) {
            return $result['response']['data'];
        }
    }

    if (!empty($request['checkout_request_id'])) {
        $result = lipana_fetch_transaction_by_id((string)$request['checkout_request_id']);
        if ($result['success'] && is_array($result['response']['data'] ?? null)) {
            return $result['response']['data'];
        }
    }

    return [];
}

function lipana_transaction_is_successful(array $transaction, float $expectedAmount): bool
{
    if (empty($transaction['status'])) {
        return false;
    }

    if (strtolower((string)$transaction['status']) !== 'success') {
        return false;
    }

    if (isset($transaction['amount']) && abs((float)$transaction['amount'] - $expectedAmount) > 0.01) {
        return false;
    }

    return true;
}

function lipana_transaction_reference_code(array $transaction): ?string
{
    if (!empty($transaction['checkoutRequestID'])) {
        return (string)$transaction['checkoutRequestID'];
    }
    if (!empty($transaction['checkout_request_id'])) {
        return (string)$transaction['checkout_request_id'];
    }
    if (!empty($transaction['transactionId'])) {
        return (string)$transaction['transactionId'];
    }
    if (!empty($transaction['transaction_id'])) {
        return (string)$transaction['transaction_id'];
    }
    return null;
}

/**
 * Return the M-Pesa receipt number from the successful transaction, not the
 * Lipana checkout/request identifier used while the STK prompt is pending.
 */
function lipana_transaction_mpesa_code(array $transaction): ?string
{
    return lipana_find_transaction_value($transaction, ['mpesaReceiptNumber', 'mpesa_receipt_number', 'mpesaCode', 'mpesa_code', 'receiptNumber', 'receipt_number', 'transactionCode', 'transaction_code']);
}

/** Find a scalar callback field even when Lipana nests it in metadata. */
function lipana_find_transaction_value(array $data, array $keys): ?string
{
    $wanted = array_map(static fn($key) => strtolower((string)$key), $keys);
    foreach ($data as $key => $value) {
        if (in_array(strtolower((string)$key), $wanted, true) && is_scalar($value) && trim((string)$value) !== '') {
            return trim((string)$value);
        }
    }

    foreach ($data as $value) {
        if (is_array($value)) {
            $found = lipana_find_transaction_value($value, $keys);
            if ($found !== null) {
                return $found;
            }
        }
    }

    return null;
}

/**
 * Lipana response keys differ slightly across M-Pesa products. Keep the
 * extraction in one place so the POS displays provider-verified payer data.
 */
function lipana_transaction_customer(array $transaction): array
{
    $name = lipana_find_transaction_value($transaction, ['customerName', 'customer_name', 'payerName', 'payer_name', 'senderName', 'sender_name']);
    $phone = lipana_find_transaction_value($transaction, ['customerPhone', 'customer_phone', 'payerPhone', 'payer_phone', 'phoneNumber', 'phone_number', 'phone']);

    return ['name' => $name, 'phone' => $phone ? normalize_lipana_phone($phone) : null];
}
