# AGENTS.MD — swiyu Verifier PHP Test Site

## Project Goal

Build a **PHP-based web application** that allows a developer to test the swiyu e-ID authentication/verification flow end-to-end. The app acts as a "business verifier" that talks to a running `swiyu-verifier` backend service (Docker), creates verification requests, displays a QR code the user scans with the swiyu Wallet app, and polls for the result.

---

## What is swiyu?

swiyu is the Swiss federal e-ID and verifiable credential trust infrastructure (Public Beta). It uses:
- **OID4VP** (OpenID for Verifiable Presentations) for the presentation/verification protocol
- **SD-JWT VC** (Selective Disclosure JWT Verifiable Credentials) as the credential format
- **DIF Presentation Exchange** (PE) or **DCQL** as the query language for credential requests
- **did:tdw** / **did:webvh** as DID method

The verification backend is a Java/Spring Boot service (Docker image: `ghcr.io/swiyu-admin-ch/swiyu-verifier:latest`) that exposes two groups of APIs:

1. **Management API** (`/management/api/...`) — internal, used by the business verifier (our PHP app) to create and poll verifications. Must NOT be publicly exposed.
2. **OID4VP API** (`/oid4vp/api/...`) — public, used by the swiyu Wallet app.

---

## Architecture

```
Browser (User)
    │
    ▼
PHP Web App (this project)
    │  calls Management API (internal)
    ▼
swiyu-verifier Service (Docker, port 8083)
    │  oid4vp endpoints (public, HTTPS required)
    ▼
swiyu Wallet App (scans QR code / deeplink)
```

The PHP app communicates with the verifier service on `http://localhost:8083` (internal). The wallet communicates with `https://<EXTERNAL_URL>/oid4vp/...` (must be public HTTPS).

---

## Prerequisites (Deployment)

Before running the PHP app, the operator must have:

1. **Registered** on the [swiyu Trust Infrastructure portal](https://www.swiyu.admin.ch)
2. **Registered** on the API self-service portal
3. **Generated a DID** (did:tdw or did:webvh) registered in the identifier registry — done via `didtoolbox.jar`
4. **Generated EC keys** (prime256v1):
   ```bash
   openssl ecparam -genkey -name prime256v1 -noout -out ec_private.pem
   openssl ec -in ec_private.pem -pubout -out ec_public.pem
   ```
5. **Running the swiyu-verifier Docker container** with correct env vars

---

## Environment Variables (for the swiyu-verifier Docker service)

| Variable | Description | Example |
|---|---|---|
| `EXTERNAL_URL` | Public HTTPS URL where wallet reaches the oid4vp endpoints | `https://verifier.example.com` |
| `VERIFIER_DID` | Your registered DID | `did:tdw:Qm...identifier-reg.trust-infra.swiyu-int.admin.ch:api:v1:did:UUID` |
| `DID_VERIFICATION_METHOD` | Full DID + fragment identifying the signing key | `did:tdw:Qm...#auth-key-01` |
| `SIGNING_KEY` | EC private key in PEM format (single-line or multi-line) | `-----BEGIN EC PRIVATE KEY-----\n...\n-----END EC PRIVATE KEY-----` |
| `POSTGRES_USER` | DB user | `verifier_user` |
| `POSTGRES_PASSWORD` | DB password | `secret` |
| `POSTGRES_DB` | DB name | `verifier_db` |
| `POSTGRES_JDBC` | JDBC connection string | `jdbc:postgresql://verifier_postgres:5432/verifier_db` |
| `OPENID_CLIENT_METADATA_FILE` | Path to verifier metadata JSON file | `file:/verifier_metadata.json` |
| `VERIFICATION_TTL_SEC` | How long a verification offer lives (seconds) | `900` |
| `WEBHOOK_CALLBACK_URI` | (Optional) URI for webhook callbacks instead of polling | |

---

## Docker Compose (sample.compose.yml)

```yaml
services:
  verifier_postgres:
    image: postgres:15-alpine
    environment:
      POSTGRES_USER: "verifier_user"
      POSTGRES_PASSWORD: "secret"
      POSTGRES_DB: "verifier_db"
    ports:
      - "5434:5432"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U verifier_user -d verifier_db"]
      interval: 5s
      timeout: 5s
      retries: 5

  verifier-service:
    image: ghcr.io/swiyu-admin-ch/swiyu-verifier:latest
    configs:
      - source: verifier_metadata
        target: /verifier_metadata.json
    ports:
      - "8083:8080"
    environment:
      EXTERNAL_URL: ${EXTERNAL_URL}
      OPENID_CLIENT_METADATA_FILE: file:/verifier_metadata.json
      VERIFIER_DID: ${VERIFIER_DID}
      DID_VERIFICATION_METHOD: ${DID_VERIFICATION_METHOD}
      SIGNING_KEY: ${SIGNING_KEY}
      POSTGRES_USER: "verifier_user"
      POSTGRES_PASSWORD: "secret"
      POSTGRES_DB: "verifier_db"
      POSTGRES_JDBC: "jdbc:postgresql://verifier_postgres:5432/verifier_db"

configs:
  verifier_metadata:
    content: |
      {
        "client_id": "${VERIFIER_DID}",
        "client_name#en": "Development Demo Verifier",
        "client_name#de": "Entwicklungs-Demo-Verifizierer",
        "client_name": "DEV Demo Verifier",
        "logo_uri": "https://www.example.com/logo.png",
        "vp_formats": {
          "jwt_vp": { "alg": ["ES256"] }
        }
      }
```

---

## Management API (used by PHP app)

Base URL: `http://localhost:8083` (internal only)

### POST /management/api/verifications

Creates a new verification request.

**Request headers:**
- `Content-Type: application/json`

**Request body example (Presentation Exchange / PE format — API version 1):**
```json
{
  "accepted_issuer_dids": [
    "did:tdw:QmPEZPhDFR4nEYSFK5bMnvECqdpf1tPTPJuWs9QrMjCumw:identifier-reg.trust-infra.swiyu-int.admin.ch:api:v1:did:9a5559f0-b81c-4368-a170-e7b4ae424527"
  ],
  "response_mode": "direct_post",
  "presentation_definition": {
    "id": "00000000-0000-0000-0000-000000000000",
    "input_descriptors": [
      {
        "id": "11111111-1111-1111-1111-111111111111",
        "format": {
          "vc+sd-jwt": {
            "sd-jwt_alg_values": ["ES256"],
            "kb-jwt_alg_values": ["ES256"]
          }
        },
        "constraints": {
          "fields": [
            {
              "path": ["$.vct"],
              "filter": {
                "type": "string",
                "const": "betaid-sdjwt"
              }
            },
            {
              "path": ["$.age_over_18"]
            }
          ]
        }
      }
    ]
  }
}
```

**Response fields:**
```json
{
  "id": "<UUID>",               // verification ID — used to poll status
  "request_nonce": "...",
  "state": "PENDING",           // PENDING | SUCCESS | FAILED
  "presentation_definition": { ... },
  "verification_url": "https://<external-url>/oid4vp/api/request-object/<request-id>",
  "verification_deeplink": "swiyu-verify://?client_id=..."
}
```

The `verification_deeplink` is the URI to encode as QR code.

### GET /management/api/verifications/{verificationId}

Polls the status of a verification.

**Response:**
```json
{
  "id": "<UUID>",
  "state": "PENDING",           // PENDING | SUCCESS | FAILED
  "wallet_response": {
    "error_code": null,         // null on success
    "error_description": null,
    "credential_subject_data": {
      "age_over_18": true,
      "dateOfBirth": "1990-01-01"
      // ... whatever fields were requested
    }
  }
}
```

---

## OID4VP API (public, used by the Wallet)

Base URL: `https://<EXTERNAL_URL>` (publicly accessible via HTTPS)

| Endpoint | Description |
|---|---|
| `GET /oid4vp/api/request-object/{request_id}` | Wallet fetches the request object |
| `POST /oid4vp/api/request-object/{request_id}/response-data` | Wallet posts its VP token response |
| `GET /oid4vp/api/openid-client-metadata.json` | Wallet fetches verifier metadata |

The PHP app does **NOT** need to implement or proxy these — they are handled entirely by the swiyu-verifier Docker service.

---

## Verification Flow (step-by-step)

```
1. PHP app POSTs to /management/api/verifications
        ↓
2. PHP receives { id, verification_deeplink, ... }
        ↓
3. PHP generates QR code from verification_deeplink
        ↓
4. User scans QR code with swiyu Wallet app
        ↓
5. Wallet fetches request object from /oid4vp/api/request-object/{id}
        ↓
6. Wallet presents credentials to /oid4vp/api/request-object/{id}/response-data
        ↓
7. PHP polls GET /management/api/verifications/{id} until state != PENDING
        ↓
8. PHP displays SUCCESS or FAILED with credential data / error
```

---

## Verification States

| State | Meaning |
|---|---|
| `PENDING` | Waiting for wallet to respond |
| `SUCCESS` | Wallet presented valid credentials |
| `FAILED` | Wallet rejected, credential invalid, etc. |

---

## Error Codes (wallet_response.error_code)

| Code | Description |
|---|---|
| `credential_invalid` | General invalid credential |
| `jwt_expired` | Expired JWT used |
| `credential_expired` | Credential itself is expired |
| `credential_revoked` | Credential has been revoked |
| `credential_suspended` | Credential is suspended |
| `credential_missing_data` | Required fields not present |
| `holder_binding_mismatch` | Invalid holder binding |
| `client_rejected` | User rejected the request in wallet |
| `issuer_not_accepted` | Issuer not in accepted list |
| `public_key_of_issuer_unresolvable` | Cannot fetch issuer's public key |
| `unresolvable_status_list` | Cannot reach credential's status list |
| `malformed_credential` | Credential format invalid |
| `missing_nonce` | Nonce missing in presentation |
| `invalid_format` | Invalid data format |

---

## Accepted Issuer DIDs (for testing with Beta Credential Service)

For quick testing using the [Beta Credential Service (BCS)](https://www.bcs.admin.ch/bcs-web/#/) on swiyu Public Beta:

```
did:tdw:QmPEZPhDFR4nEYSFK5bMnvECqdpf1tPTPJuWs9QrMjCumw:identifier-reg.trust-infra.swiyu-int.admin.ch:api:v1:did:9a5559f0-b81c-4368-a170-e7b4ae424527
```

To test: issue a credential at https://www.bcs.admin.ch/bcs-web/#/ and verify it with the verifier.

---

## PHP Application Requirements

### Config file: `config.php`

```php
<?php
define('VERIFIER_MANAGEMENT_URL', 'http://localhost:8083'); // internal URL to the verifier service
define('ACCEPTED_ISSUER_DID', 'did:tdw:QmPEZPhD...'); // BCS issuer DID for testing
define('CREDENTIAL_TYPE', 'betaid-sdjwt'); // $.vct filter value
define('REQUESTED_FIELDS', ['$.age_over_18', '$.dateOfBirth', '$.firstName', '$.lastName']); // fields to request
```

### Pages / routes

| File | Purpose |
|---|---|
| `index.php` | Start verification — POSTs to management API, shows QR code |
| `poll.php` | AJAX endpoint — GETs verification status, returns JSON |
| `result.php` | Displays SUCCESS or FAILED result |
| `config.php` | Configuration constants |

### QR Code

Use the `endroid/qr-code` library (Composer) **OR** use the Google Chart API (no dependency):
```
https://chart.googleapis.com/chart?cht=qr&chs=400x400&chl=<urlencode(deeplink)>
```
Preferred: generate QR code server-side with `endroid/qr-code` or client-side with `qrcodejs` (JS library via CDN, no install required).

### Polling mechanism

- `index.php` renders the QR code and verification_id in a hidden field
- JavaScript polls `poll.php?id=<verification_id>` every 2 seconds
- `poll.php` calls `GET /management/api/verifications/{id}` and returns JSON
- When state is `SUCCESS` or `FAILED`, JS redirects to `result.php?id=<verification_id>&state=<state>`
- `result.php` calls `GET /management/api/verifications/{id}` and displays `wallet_response`

### HTTP calls from PHP

Use `curl` (no Guzzle required):
```php
function verifier_get(string $path): array {
    $ch = curl_init(VERIFIER_MANAGEMENT_URL . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true);
}

function verifier_post(string $path, array $payload): array {
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
    curl_close($ch);
    return json_decode($body, true);
}
```

---

## File Structure

```
swiyu-test/
├── config.php          # Configuration
├── index.php           # Main page: creates verification, shows QR
├── poll.php            # AJAX polling endpoint
├── result.php          # Shows verification result
└── assets/
    └── style.css       # Optional styling
```

---

## UI / UX Requirements

- Clean, readable layout (Bootstrap 5 via CDN is fine)
- `index.php`:
  - Button "Start Verification" (POST action)
  - After POST: show QR code of `verification_deeplink`
  - Show deeplink URL as fallback text link
  - Show spinner / "Waiting for wallet..." message
  - Auto-poll every 2 seconds via JS fetch
  - On SUCCESS/FAILED: auto-redirect to result page
- `result.php`:
  - Green banner on SUCCESS, red on FAILED
  - Table with all `credential_subject_data` key-value pairs
  - Error code + description on FAILED
  - "Start new verification" button
- No external PHP libraries required (pure PHP + curl)
- QR code via JavaScript library (qrcode.js from CDN) — embed deeplink on page and render as `<canvas>`

---

## Security Notes

- The Management API (`/management/api/...`) MUST only be called server-side from PHP — never expose it directly to the browser
- In production: put the verifier service behind a firewall; only the oid4vp endpoints should be publicly reachable
- The `SIGNING_KEY` is sensitive — never log it or expose it
- In this test setup, OAuth protection on management endpoints is disabled (default)

---

## Known Limitations (Public Beta)

- swiyu Wallet only accepts `https://` EXTERNAL_URL (not http://)
- For local testing, use a tunnel like `ngrok` or `cloudflared` to expose the verifier service with HTTPS
- DCQL is supported but PE (Presentation Exchange) is the stable, recommended format for now
- The system is in Public Beta — minor bugs may exist

---

## References

- GitHub: https://github.com/swiyu-admin-ch/swiyu-verifier
- OpenAPI spec: https://github.com/swiyu-admin-ch/swiyu-verifier/blob/main/openapi.yaml
- Cookbook: https://swiyu-admin-ch.github.io/cookbooks/onboarding-generic-verifier/
- Beta Credential Service (test credentials): https://www.bcs.admin.ch/bcs-web/#/
- DIF Presentation Exchange: https://identity.foundation/presentation-exchange/
- OID4VP spec: https://openid.net/specs/openid-4-verifiable-presentations-1_0.html