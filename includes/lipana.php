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
        // Compatibility with the earlier local configuration.
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

function lipana_stk_push(array $payload): array
{
    $config = get_lipana_config();
    $phone = normalize_lipana_phone((string)($payload['phone_number'] ?? ''));
    $amount = (int)($payload['amount'] ?? 0);
    $accountReference = (string)($payload['account_reference'] ?? 'POS');
    $transactionDesc = (string)($payload['transaction_desc'] ?? 'POS payment');

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

    $url = rtrim($config['base_url'], '/') . '/' . ltrim($config['endpoint'], '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
        'x-api-key: ' . $config['api_key'],
        'Content-Type: application/json',
        'Accept: application/json',
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_THROW_ON_ERROR),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $config['timeout'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        app_log('Lipana STK request failed: ' . $curlError);
        return ['success' => false, 'message' => 'Unable to reach the Lipana API. Please try again.'];
    }

    $payloadResponse = json_decode($response, true);
    $success = $httpCode < 400 && (!is_array($payloadResponse) || empty($payloadResponse['error']));
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
        app_log('Lipana STK response: HTTP ' . $httpCode . ' ' . substr($response, 0, 2000));
    }

    return [
        'success' => $success,
        'http_code' => $httpCode,
        'response' => $payloadResponse,
        'message' => $success
            ? 'Payment request sent to Lipana.'
            : ($providerMessage !== '' ? 'Lipana: ' . $providerMessage : 'Lipana payment request failed.'),
    ];
}
