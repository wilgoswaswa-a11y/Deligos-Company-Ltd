<?php
// Simple mock Lipana endpoint for local testing.
// Accepts JSON POST with { action: 'initiate_payment'|'verify_payment', ... }
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true) ?: [];
$action = trim((string)($input['action'] ?? ''));

function respond(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

function normalize_mock_phone(string $phone): string
{
    return preg_replace('/[^0-9]/', '', trim($phone));
}

if ($action === 'initiate_payment') {
    $phone = normalize_mock_phone((string)($input['phone_number'] ?? ''));
    $amount = max(0, (int)($input['amount'] ?? 0));

    if ($amount < 1) {
        respond(['success' => false, 'message' => 'Mock: amount must be at least 1'], 400);
    }

    if ($phone === '') {
        respond(['success' => false, 'message' => 'Mock: phone number is required'], 400);
    }

    if (str_ends_with($phone, '111')) {
        respond([
            'success' => true,
            'payload_token' => 'mock-token-pending',
            'checkout_request_id' => 'CR-MOCK-PENDING',
            'message' => 'Mock STK sent (pending verification)',
        ]);
    }

    if (str_ends_with($phone, '222')) {
        respond([
            'success' => true,
            'payload_token' => 'mock-token-failed',
            'checkout_request_id' => 'CR-MOCK-FAILED',
            'message' => 'Mock STK sent (will fail on verification)',
        ]);
    }

    if (str_ends_with($phone, '333')) {
        respond([
            'success' => true,
            'payload_token' => 'mock-token-expired',
            'checkout_request_id' => 'CR-MOCK-EXPIRED',
            'message' => 'Mock STK sent (expired request)',
        ]);
    }

    respond([
        'success' => true,
        'payload_token' => 'mock-token',
        'checkout_request_id' => 'CR-MOCK-123',
        'message' => 'Mock STK sent',
    ]);
}

if ($action === 'verify_payment') {
    $payloadToken = trim((string)($input['payload_token'] ?? ''));

    if ($payloadToken === '') {
        respond(['success' => false, 'message' => 'Mock: payload_token is required'], 400);
    }

    if (!preg_match('/^mock-token(?:-(pending|failed|expired))?$/', $payloadToken)) {
        respond(['success' => false, 'message' => 'Mock: malformed payload_token'], 400);
    }

    if ($payloadToken === 'mock-token-pending') {
        respond([
            'success' => true,
            'verified' => false,
            'status' => 'pending',
            'message' => 'Mock payment still pending. Customer must complete STK prompt.',
        ]);
    }

    if ($payloadToken === 'mock-token-failed') {
        respond([
            'success' => true,
            'verified' => false,
            'status' => 'failed',
            'message' => 'Mock payment failed. Please try again or use a different payment method.',
        ]);
    }

    if ($payloadToken === 'mock-token-expired') {
        respond([
            'success' => true,
            'verified' => false,
            'status' => 'expired',
            'message' => 'Mock payment request expired. Restart the payment flow.',
        ]);
    }

    respond([
        'success' => true,
        'verified' => true,
        'status' => 'success',
        'mpesa_code' => 'MPESA-MOCK-001',
        'customer_phone' => '254700000000',
        'message' => 'Mock verified',
    ]);
}

respond(['success' => false, 'message' => 'Unknown action'], 400);
