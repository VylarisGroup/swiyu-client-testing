<?php
require_once __DIR__ . '/config.php';

function escape_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$lastVerifierError = null;

function verifier_post(string $path, array $payload): ?array {
    global $lastVerifierError;

    $ch = curl_init(VERIFIER_MANAGEMENT_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        $lastVerifierError = 'cURL error: ' . ($curlError ?: 'empty response');
        return null;
    }

    if ($body === null || $body === '') {
        $lastVerifierError = 'Empty response from verifier (HTTP ' . $httpCode . ').';
        return null;
    }

    $data = json_decode($body, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $lastVerifierError = 'Invalid JSON response (HTTP ' . $httpCode . '): ' . json_last_error_msg() . ' — body: ' . $body;
        return null;
    }

    if (!is_array($data)) {
        $lastVerifierError = 'Unexpected response format (HTTP ' . $httpCode . '): ' . $body;
        return null;
    }

    return $data;
}

function build_presentation_definition(): array {
    $fields = [
        [
            'path' => ['$.vct'],
            'filter' => [
                'type' => 'string',
                'const' => CREDENTIAL_TYPE,
            ],
        ],
    ];

    foreach (REQUESTED_FIELDS as $fieldPath) {
        if ($fieldPath === '$.vct') {
            continue;
        }

        $fields[] = [
            'path' => [$fieldPath],
        ];
    }

    return [
        'id' => '00000000-0000-0000-0000-000000000000',
        'input_descriptors' => [
            [
                'id' => 'presentation-input-1',
                'format' => [
                    'vc+sd-jwt' => [
                        'sd-jwt_alg_values' => ['ES256'],
                        'kb-jwt_alg_values' => ['ES256'],
                    ],
                ],
                'constraints' => [
                    'fields' => $fields,
                ],
            ],
        ],
    ];
}

$verification = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = [
        'accepted_issuer_dids' => [ACCEPTED_ISSUER_DID],
        'response_mode' => 'direct_post',
        'presentation_definition' => build_presentation_definition(),
    ];

    $verification = verifier_post('/management/api/verifications', $payload);
    if (!$verification || empty($verification['verification_deeplink']) || empty($verification['id'])) {
        $errorMessage = 'Die Erstellung der Verifizierungsanfrage ist fehlgeschlagen. Bitte prüfen Sie die Verbindung zum Verifier-Dienst.';
        if (!empty($lastVerifierError)) {
            $errorMessage .= ' (' . $lastVerifierError . ')';
        }
    }
}
?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>swiyu Verifier Test</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page-container">
        <h1>swiyu Verifier Test</h1>

        <?php if ($errorMessage): ?>
            <div class="panel panel-error">
                <strong>Fehler:</strong> <?= escape_html($errorMessage) ?>
            </div>
        <?php endif; ?>

        <?php if ($verification): ?>
            <div class="panel panel-success">
                <h2>Verifizierungsanforderung erstellt</h2>
                <p>Scanne den QR-Code mit der swiyu Wallet-App oder öffne den Link manuell.</p>
            </div>

            <div class="qr-panel">
                <div id="qrcode" class="qr-code"></div>
                <p class="qr-fallback">
                    Fallback-Link:<br>
                    <a href="<?= escape_html($verification['verification_deeplink']) ?>">
                        <?= escape_html($verification['verification_deeplink']) ?>
                    </a>
                </p>
            </div>

            <input type="hidden" id="verificationId" value="<?= escape_html($verification['id']) ?>">
            <div class="status-card">
                <p id="statusText">Warte auf Wallet-Antwort...</p>
                <div class="spinner" aria-hidden="true"></div>
            </div>

            <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js" integrity="sha512-C1hj+KbG23hsgGQ0W6CmOJOXod3zanJnlDQS53zcd3ON5q6xqLBP3kkyAYiE0fOyormnTMYTjp0Njb8wkB6+ZA==" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
            <script>
                const deeplink = <?= json_encode($verification['verification_deeplink'], JSON_UNESCAPED_SLASHES) ?>;
                const verificationId = <?= json_encode($verification['id'], JSON_UNESCAPED_SLASHES) ?>;

                new QRCode(document.getElementById('qrcode'), {
                    text: deeplink,
                    width: 320,
                    height: 320,
                    colorDark: '#000000',
                    colorLight: '#ffffff',
                });

                const statusText = document.getElementById('statusText');
                const pollInterval = 2000;

                async function pollStatus() {
                    try {
                        const response = await fetch('poll.php?id=' + encodeURIComponent(verificationId), { cache: 'no-store' });
                        if (!response.ok) {
                            throw new Error('Netzwerkfehler beim Abrufen des Status.');
                        }

                        const data = await response.json();
                        if (!data || !data.state) {
                            throw new Error('Ungültige Antwort vom Server.');
                        }

                        statusText.textContent = 'Status: ' + data.state;
                        if (data.state === 'SUCCESS' || data.state === 'FAILED') {
                            window.location.href = 'result.php?id=' + encodeURIComponent(verificationId) + '&state=' + encodeURIComponent(data.state);
                            return;
                        }
                    } catch (error) {
                        statusText.textContent = 'Fehler beim Polling: ' + error.message;
                    }
                    setTimeout(pollStatus, pollInterval);
                }

                pollStatus();
            </script>
        <?php else: ?>
            <div class="panel panel-info">
                <p>Beginne eine neue swiyu-Verifizierung. Die Anwendung erstellt serverseitig eine Anfrage beim swiyu-Verifier.</p>
            </div>

            <form method="post" class="form-card">
                <button type="submit" class="button button-primary">Verifizierung starten</button>
            </form>
        <?php endif; ?>
    </main>
</body>
</html>
