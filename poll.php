<?php
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');

function verifier_get(string $path): ?array {
    $ch = curl_init(VERIFIER_MANAGEMENT_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $body = curl_exec($ch);
    curl_close($ch);

    if ($body === false || $body === null) {
        return null;
    }

    $data = json_decode($body, true);
    return json_last_error() === JSON_ERROR_NONE ? $data : null;
}

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing verification id.']);
    exit;
}

$response = verifier_get('/management/api/verifications/' . rawurlencode($id));
if (!$response) {
    http_response_code(502);
    echo json_encode(['error' => 'Unable to contact verifier management API.']);
    exit;
}

echo json_encode($response);
