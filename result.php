<?php
require_once __DIR__ . '/config.php';

function escape_html(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

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
    $resultError = 'Keine Verifizierungs-ID angegeben.';
    $status = 'UNKNOWN';
    $walletResponse = [];
} else {
    $response = verifier_get('/management/api/verifications/' . rawurlencode($id));
    if (!$response) {
        $resultError = 'Konnte den Verifizierungsstatus nicht abrufen.';
        $status = 'UNKNOWN';
        $walletResponse = [];
    } else {
        $resultError = null;
        $status = $response['state'] ?? 'UNKNOWN';
        $walletResponse = $response['wallet_response'] ?? [];
    }
}

$credentialData = $walletResponse['credential_subject_data'] ?? [];
$errorCode = $walletResponse['error_code'] ?? null;
$errorDescription = $walletResponse['error_description'] ?? null;
?><!doctype html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifizierungsergebnis</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="page-container">
        <h1>Verifizierungsergebnis</h1>

        <?php if ($resultError): ?>
            <div class="panel panel-error">
                <strong>Fehler:</strong> <?= escape_html($resultError) ?>
            </div>
        <?php else: ?>
            <?php if ($status === 'SUCCESS'): ?>
                <div class="panel panel-success">
                    <h2>Erfolgreich</h2>
                    <p>Die Wallet hat gültige Nachweise übermittelt.</p>
                </div>
            <?php elseif ($status === 'FAILED'): ?>
                <div class="panel panel-error">
                    <h2>Fehler</h2>
                    <p>Die Verifizierung ist fehlgeschlagen.</p>
                </div>
            <?php else: ?>
                <div class="panel panel-info">
                    <h2>Status: <?= escape_html($status) ?></h2>
                    <p>Die Verifizierung ist noch nicht abgeschlossen. Bitte versuche es erneut.</p>
                </div>
            <?php endif; ?>

            <?php if ($errorCode || $errorDescription): ?>
                <div class="status-card">
                    <p><strong>Fehlercode:</strong> <?= escape_html((string)$errorCode) ?></p>
                    <p><strong>Beschreibung:</strong> <?= escape_html((string)$errorDescription) ?></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($credentialData) && is_array($credentialData)): ?>
                <div class="table-card">
                    <h3>Credential-Daten</h3>
                    <table>
                        <thead>
                            <tr>
                                <th>Feld</th>
                                <th>Wert</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($credentialData as $key => $value): ?>
                                <tr>
                                    <td><?= escape_html((string)$key) ?></td>
                                    <td><?= escape_html(is_scalar($value) ? (string)$value : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="button-row">
            <a href="index.php" class="button button-secondary">Neue Verifizierung starten</a>
        </div>
    </main>
</body>
</html>
