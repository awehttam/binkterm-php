# Gateway Token Authentication

> **Note:** This feature is experimental and the API is subject to change in future releases.

The **Gateway Token** system allows remote components — such as door servers, external modules, or automatic login scripts — to securely verify a user's identity without requiring the user to share their primary BBS credentials with the remote system.

## Table of Contents

- [Authentication Flow](#authentication-flow)
- [Configuration](#configuration)
- [API Specification](#api-specification)
  - [Endpoint](#endpoint)
  - [Request](#request)
  - [Success Response](#success-response)
  - [Failure Response](#failure-response)
- [Remote Integration Example](#remote-integration-example)

---

## Authentication Flow

1. **Handshake Initiation** — A user visits the BBS and initiates an action that requires authentication at a remote service.
2. **Redirect** — The BBS generates a temporary, single-use token and redirects the user to the remote gateway URL (e.g., `https://remote-door.com/login?userid=123&token=abc...`).
3. **Back-Channel Validation** — The remote gateway receives the user. Before granting access, it makes a server-to-server POST request back to the BBS with its **API Key**, the **UserID**, and the **Token**.
4. **Verification** — The BBS validates the request. If successful, the gateway receives the user's profile information and can initiate a local session.

---

## Configuration

Set `BBSLINK_API_KEY` in your `.env` file. This key must be shared with the remote service and sent in the `X-API-Key` header of each verification request.

```env
BBSLINK_API_KEY=your_secret_api_key
```

---

## API Specification

### Endpoint

```
POST /auth/verify-gateway-token
```

### Request

**Headers**

| Header | Value | Description |
| :--- | :--- | :--- |
| `Content-Type` | `application/json` | Required |
| `X-API-Key` | `YOUR_BBS_API_KEY` | Must match `BBSLINK_API_KEY` in the BBS `.env` |

**Body**

Both `userid` and `user_id` are accepted as the key name.

```json
{
    "userid": 1,
    "token": "78988029a8385f9..."
}
```

### Success Response

HTTP 200

```json
{
    "valid": true,
    "userInfo": {
        "id": 1,
        "username": "Sysop",
        "email": "admin@example.com"
    }
}
```

### Failure Response

HTTP 401 or 400

```json
{
    "valid": false,
    "error": "Invalid or expired token"
}
```

---

## Remote Integration Example

The following PHP example shows how a remote service would verify a token after the user is redirected to it.

```php
<?php

/**
 * Verify a gateway token against the BBS.
 *
 * @param int    $userId  User ID received in the redirect query string.
 * @param string $token   Token received in the redirect query string.
 * @return array|false    userInfo array on success, false on failure.
 */
function verifyWithBBS(int $userId, string $token): array|false
{
    $bbsUrl = 'https://your-bbs-domain.com/auth/verify-gateway-token';
    $apiKey = 'your_configured_api_key';

    $payload = json_encode([
        'userid' => $userId,
        'token'  => $token
    ]);

    $ch = curl_init($bbsUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $data = json_decode($response, true);
        if ($data['valid']) {
            return $data['userInfo'];
        }
    }

    return false;
}

// --- Usage in a landing page ---
$userIdFromUrl = $_GET['userid'] ?? null;
$tokenFromUrl  = $_GET['token'] ?? null;

if ($userIdFromUrl && $tokenFromUrl) {
    $user = verifyWithBBS((int)$userIdFromUrl, $tokenFromUrl);

    if ($user) {
        echo "Welcome, " . htmlspecialchars($user['username']);
        // Proceed to log the user into the local system...
    } else {
        die("Authentication failed.");
    }
}
```
