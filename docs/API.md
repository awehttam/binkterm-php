# BinktermPHP API Documentation

## Authentication

Most endpoints require session authentication. Log in via `POST /api/auth/login` to receive a session cookie (`binktermphp_session`). Include this cookie in subsequent requests. Some endpoints also require a CSRF token returned at login; include it as `X-CSRF-Token` on state-changing requests.

### Quickstart

**1. Log in**

```http
POST /api/auth/login
Content-Type: application/json

{"username": "youruser", "password": "yourpassword"}
```

Response:

```json
{
  "success": true,
  "csrf_token": "abc123...",
  "user": { "id": 1, "username": "youruser", "is_admin": false }
}
```

The response also sets a `binktermphp_session` cookie. Include it in all subsequent requests.

**2. Make an authenticated request**

```http
GET /api/messages/echomail?area_id=1&limit=25
Cookie: binktermphp_session=<session-cookie>
```

For state-changing requests (POST, PUT, DELETE), also include the CSRF token:

```http
POST /api/messages/echomail
Cookie: binktermphp_session=<session-cookie>
X-CSRF-Token: abc123...
Content-Type: application/json

{"area_id": 1, "subject": "Hello", "body": "Message body"}
```

**Error responses** use a structured format:

```json
{
  "error": "Invalid credentials",
  "error_code": "errors.auth.invalid_credentials"
}
```

## Contents

- [Public API](#public-api)
  - [Account](#account) (1)
  - [Address Book](#address-book) (7)
  - [Ads](#ads) (2)
  - [Auth](#auth) (7)
  - [Binkp](#binkp) (23)
  - [Bulletins](#bulletins) (3)
  - [Chat](#chat) (6)
  - [Credits](#credits) (1)
  - [Dashboard](#dashboard) (2)
  - [Debug](#debug) (1)
  - [Docs](#docs) (1)
  - [Echoareas](#echoareas) (7)
  - [Fileareas](#fileareas) (10)
  - [Files](#files) (26)
  - [Freq Log](#freq-log) (1)
  - [I18n](#i18n) (1)
  - [Interests](#interests) (7)
  - [Markdown Images](#markdown-images) (2)
  - [Media](#media) (2)
  - [MeshCore](#meshcore) (3)
  - [Messages](#messages) (47)
  - [Netmail](#netmail) (1)
  - [Nodelist](#nodelist) (2)
  - [Notify](#notify) (3)
  - [Pending Users](#pending-users) (4)
  - [Polls](#polls) (3)
  - [Qwk](#qwk) (7)
  - [Referrals](#referrals) (2)
  - [Register](#register) (1)
  - [Shoutbox](#shoutbox) (2)
  - [Stream](#stream) (2)
  - [Subscriptions](#subscriptions) (4)
  - [System](#system) (1)
  - [Taglines](#taglines) (1)
  - [Test](#test) (1)
  - [Url Preview](#url-preview) (1)
  - [User](#user) (37)
  - [Users](#users) (9)
  - [Verify](#verify) (1)
  - [Whosonline](#whosonline) (1)

---

## Public API

### Account

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/account/reminder`](#post-apiaccountreminder) | No | Send account reminder email to inactive user. |

#### `POST /api/account/reminder`

Public

Sends a reminder message to a user who has not yet logged in. Validates that the user exists and has not logged in before sending. Returns success with email_sent flag indicating whether email delivery was attempted.

**Request Body** _(JSON)_

Reminder request data

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Username to send reminder to |

**Response** _(JSON)_

Reminder send result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether reminder was sent |
| `message_code` | string | Localization key for result message |
| `email_sent` | boolean | Whether email was actually sent |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing username or reminder send failed |
| 404 | User not found or already logged in |
| 500 | Server error sending reminder |

---

### Address Book

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/address-book/`](#get-apiaddress-book) | Yes | List user's address book entries with optional search filter. |
| `GET` | [`/api/address-book/{id}`](#get-apiaddress-bookid) | Yes | Retrieve a specific address book entry by ID. |
| `POST` | [`/api/address-book/`](#post-apiaddress-book) | Yes | Create a new address book entry. |
| `PUT` | [`/api/address-book/{id}`](#put-apiaddress-bookid) | Yes | Update an existing address book entry. |
| `DELETE` | [`/api/address-book/{id}`](#delete-apiaddress-bookid) | Yes | Delete an address book entry. |
| `GET` | [`/api/address-book/search/{query}`](#get-apiaddress-booksearchquery) | Yes | Search address book entries plus matching local users for autocomplete. |
| `GET` | [`/api/address-book/stats`](#get-apiaddress-bookstats) | Yes | Get address book statistics for the user. |

#### `GET /api/address-book/`

**Requires authentication**

Retrieves all address book entries for the authenticated user. Supports optional full-text search via the 'search' query parameter to filter entries by name or address. Returns an array of matching entries.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `search` | string | No | Optional search term to filter entries by name or address |

**Response** _(JSON)_

Array of address book entries matching the search criteria

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `entries` | array | Array of address book entry objects |
| `entries[].id` | integer | Entry ID |
| `entries[].name` | string | Contact display name |
| `entries[].messaging_user_id` | integer\|null | BBS user ID if linked to a local user |
| `entries[].node_address` | string\|null | FTN node address |
| `entries[].email` | string\|null | Email address |
| `entries[].description` | string\|null | Free-text notes |
| `entries[].always_crashmail` | boolean | Always send crashmail to this address |
| `entries[].created_at` | string | ISO 8601 creation timestamp |
| `entries[].updated_at` | string | ISO 8601 last-update timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load address book entries |

---

#### `GET /api/address-book/{id}`

**Requires authentication**

Fetches a single address book entry by its ID, verifying ownership by the authenticated user. Returns the full entry details or 404 if not found or not owned by the user.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Address book entry ID |

**Response** _(JSON)_

Single address book entry object

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if entry found |
| `entry` | object | Address book entry details |
| `entry.id` | integer | Entry ID |
| `entry.name` | string | Contact display name |
| `entry.messaging_user_id` | integer\|null | BBS user ID if linked to a local user |
| `entry.node_address` | string\|null | FTN node address |
| `entry.email` | string\|null | Email address |
| `entry.description` | string\|null | Free-text notes |
| `entry.always_crashmail` | boolean | Always send crashmail to this address |
| `entry.created_at` | string | ISO 8601 creation timestamp |
| `entry.updated_at` | string | ISO 8601 last-update timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Entry not found or not owned by user |
| 500 | Failed to load address book entry |

---

#### `POST /api/address-book/`

**Requires authentication**

Creates a new address book entry for the authenticated user. Accepts entry data in JSON request body. Returns the newly created entry ID on success. Validates user authentication and required fields; throws AddressBookException for validation errors.

**Request Body** _(JSON)_

Address book entry data

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Contact name |
| `address` | string | Yes | FTN address or email |

**Response** _(JSON)_

Newly created entry confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on successful creation |
| `entry_id` | integer | ID of the newly created entry |
| `message_code` | string | Localization key for UI message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | User ID not found, validation error, or creation failed |

---

#### `PUT /api/address-book/{id}`

**Requires authentication**

Updates an address book entry by ID, verifying ownership by the authenticated user. Accepts partial or full entry data in JSON request body. Returns success confirmation or error if entry not found or update fails.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Address book entry ID to update |

**Request Body** _(JSON)_

Updated address book entry data (partial updates supported)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | Contact name |
| `address` | string | No | FTN address or email |

**Response** _(JSON)_

Update confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if update succeeded |
| `message_code` | string | Localization key for UI message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Update failed or validation error |
| 404 | Entry not found (via AddressBookException) |

---

#### `DELETE /api/address-book/{id}`

**Requires authentication**

Deletes an address book entry by ID, verifying ownership by the authenticated user. Returns success confirmation or 404 if entry not found or not owned by user.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Address book entry ID to delete |

**Response** _(JSON)_

Deletion confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if deletion succeeded |
| `message_code` | string | Localization key for UI message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Entry not found or not owned by user |
| 500 | Failed to delete address book entry |

---

#### `GET /api/address-book/search/{query}`

**Requires authentication**

Performs an autocomplete search for the authenticated user. Results include the user's address book entries plus matching local BBS users by real name or username, returned in a shared entry shape suitable for compose UI pickers. Limits results to 10 by default, maximum 20. Query string is URL-decoded before search.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `query` | string | Search query string (URL-encoded) |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `limit` | integer | No | Maximum results to return (default 10, max 20) |

**Response** _(JSON)_

Array of matching autocomplete entries

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `entries` | array | Matching address-book and local-user entries (limited) |
| `entries[].id` | integer | Entry ID |
| `entries[].name` | string | Contact display name |
| `entries[].messaging_user_id` | integer\|null | BBS user ID if linked to a local user |
| `entries[].node_address` | string\|null | FTN node address |
| `entries[].email` | string\|null | Email address |
| `entries[].description` | string\|null | Free-text notes |
| `entries[].always_crashmail` | boolean | Always send crashmail to this address |
| `entries[].created_at` | string | ISO 8601 creation timestamp |
| `entries[].updated_at` | string | ISO 8601 last-update timestamp |
| `entries[].node_system_name` | string\|null | System name from nodelist (search results only) |
| `entries[].node_domain` | string\|null | Network domain from nodelist (search results only) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to search address book entries |

---

#### `GET /api/address-book/stats`

**Requires authentication**

Retrieves aggregate statistics about the authenticated user's address book, such as total entry count or other metrics. Useful for UI display or analytics.

**Response** _(JSON)_

Address book statistics object

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `stats` | object | Statistics object |
| `stats.total_entries` | integer | Total number of entries in the address book |
| `stats.entries_with_email` | integer | Number of entries that have an email address |
| `stats.entries_with_description` | integer | Number of entries that have a description |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load address book statistics |

---

### Ads

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/ads/{id}/impression`](#post-apiadsidimpression) | Yes | Record an advertisement impression for the authenticated user. |
| `POST` | [`/api/ads/{id}/click`](#post-apiadsidclick) | Yes | Record an advertisement click and retrieve the click URL. |

#### `POST /api/ads/{id}/impression`

**Requires authentication**

Logs that the authenticated user has viewed an advertisement. Used for tracking ad impressions and analytics. Returns success confirmation or error if recording fails.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Advertisement ID |

**Response** _(JSON)_

Impression recording confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Impression was recorded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to record impression |

---

#### `POST /api/ads/{id}/click`

**Requires authentication**

Logs that the authenticated user clicked an advertisement and returns the target click URL. Used for tracking ad engagement and redirecting users to advertiser destinations.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Advertisement ID |

**Response** _(JSON)_

Click recording confirmation with redirect URL

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Click was recorded |
| `click_url` | string | URL to redirect user to (advertiser destination) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Advertisement not found |
| 500 | Failed to record click |

---

### Auth

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/auth/login`](#post-apiauthlogin) | No | Authenticate user with username and password, returning session cookie and CSRF token. |
| `POST` | [`/api/auth/logout`](#post-apiauthlogout) | No | Invalidate user session and clear authentication cookie. |
| `POST` | [`/api/auth/verify-gateway-token`](#post-apiauthverify-gateway-token) | No | Verify gateway token for external service integration (requires API key). |
| `POST` | [`/api/auth/gateway-token`](#post-apiauthgateway-token) | Yes | Generate a time-limited gateway token for authenticated user. |
| `POST` | [`/api/auth/forgot-password`](#post-apiauthforgot-password) | No | Initiate password reset by username or email address. |
| `POST` | [`/api/auth/validate-reset-token`](#post-apiauthvalidate-reset-token) | No | Validate password reset token before allowing password change. |
| `POST` | [`/api/auth/reset-password`](#post-apiauthreset-password) | No | Complete password reset with valid token and new password. |

#### `POST /api/auth/login`

Public

Validates credentials and creates an authenticated session. Sets a 30-day HTTP-only session cookie and tracks the login event. Returns a CSRF token for subsequent authenticated requests. The service parameter (default 'web') determines session behavior. Failed authentication returns 401 with invalid credentials error.

**Request Body** _(JSON)_

Login credentials

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | User login name |
| `password` | string | Yes | User password |
| `service` | string | No | Service identifier (default: 'web') |

**Response** _(JSON)_

Authentication success response

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `csrf_token` | string|null | CSRF token for authenticated requests, null if tracking fails |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing username or password |
| 401 | Invalid credentials |

---

#### `POST /api/auth/logout`

Public

Terminates the current session by removing the session cookie and invalidating the session in the database. Safe to call even if no session exists. Always returns success regardless of prior session state.

**Response** _(JSON)_

Logout confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |

---

#### `POST /api/auth/verify-gateway-token`

Public

Validates a gateway token issued for external services like bbslinkgateway. Requires X-API-KEY header matching BBSLINK_API_KEY environment variable. Returns user information if token is valid and not expired. Used by external systems to authenticate users without direct password access.

**Request Body** _(JSON)_

Token verification request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `userid` | integer | Yes | User ID (accepts userid or user_id) |
| `token` | string | Yes | Gateway token to verify |

**Response** _(JSON)_

Token validation result with user information

| Field | Type | Description |
|-------|------|-------------|
| `valid` | boolean | Token validity status |
| `userInfo` | object | User information object if valid (absent when `valid` is false) |
| `userInfo.user_id` | integer | BBS user ID |
| `userInfo.username` | string | Username |
| `userInfo.door` | string\|null | Door/service identifier the token was issued for |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Invalid or missing API key |
| 400 | Missing userid or token, or invalid/expired token |

---

#### `POST /api/auth/gateway-token`

**Requires authentication**

Creates a gateway token for the authenticated user to access external services. TTL is capped at 10 minutes maximum for security. Optional door parameter can specify which service the token grants access to. Returns the token and expiration time in seconds.

**Request Body** _(JSON)_

Token generation parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `door` | string|null | No | Target service/door identifier |
| `ttl` | integer | No | Time-to-live in seconds (default: 300, max: 600) |

**Response** _(JSON)_

Generated gateway token

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |
| `userid` | integer | User ID |
| `token` | string | Gateway token string |
| `expires_in` | integer | Token expiration time in seconds |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/auth/forgot-password`

Public

Requests a password reset for a user identified by username or email. Triggers password reset email with a time-limited token. Response indicates success or provides localized error details. Does not reveal whether username/email exists for security.

**Request Body** _(JSON)_

Password reset request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `usernameOrEmail` | string | Yes | Username or email address |

**Response** _(JSON)_

Password reset request result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Request success status |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing username or email |

---

#### `POST /api/auth/validate-reset-token`

Public

Checks if a password reset token is valid and not expired. Used to verify token before presenting password reset form. Returns validity status without revealing token details.

**Request Body** _(JSON)_

Token validation request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `token` | string | Yes | Password reset token |

**Response** _(JSON)_

Token validity status

| Field | Type | Description |
|-------|------|-------------|
| `valid` | boolean | Token validity status |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing token or invalid/expired token |

---

#### `POST /api/auth/reset-password`

Public

Resets user password using a valid reset token. Token must pass validation before calling this endpoint. Returns success status with localized error messages on failure. Sets HTTP 400 status if reset fails.

**Request Body** _(JSON)_

Password reset completion

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `token` | string | Yes | Valid password reset token |
| `newPassword` | string | Yes | New password for user |

**Response** _(JSON)_

Password reset result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Reset success status |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing token/password or invalid/expired token |

---

### Binkp

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/binkp/status`](#get-apibinkpstatus) | Yes | Get current BinkP daemon status (admin only). |
| `POST` | [`/api/binkp/poll`](#post-apibinkppoll) | Yes | Trigger BinkP poll for a specific address or all uplinks. |
| `POST` | [`/api/binkp/poll-all`](#post-apibinkppoll-all) | Yes | Trigger BinkP poll for all configured uplinks. |
| `POST` | [`/api/binkp/process-packets`](#post-apibinkpprocess-packets) | Yes | Trigger packet processing for BinkP protocol. |
| `GET` | [`/api/binkp/uplinks`](#get-apibinkpuplinks) | Yes | Retrieve list of configured BinkP uplinks. |
| `GET` | [`/api/binkp/uplink-status`](#get-apibinkpuplink-status) | Yes | Test BinkP uplink authentication and connectivity. |
| `POST` | [`/api/binkp/uplinks`](#post-apibinkpuplinks) | Yes | Add a new BinkP uplink configuration. |
| `PUT` | [`/api/binkp/uplinks/{address}`](#put-apibinkpuplinksaddress) | Yes | Update an existing BinkP uplink configuration. |
| `DELETE` | [`/api/binkp/uplinks/{address}`](#delete-apibinkpuplinksaddress) | Yes | Delete a BinkP uplink configuration. |
| `GET` | [`/api/binkp/files/inbound`](#get-apibinkpfilesinbound) | Yes | List files in BinkP inbound directory. |
| `GET` | [`/api/binkp/files/outbound`](#get-apibinkpfilesoutbound) | Yes | Retrieve list of outbound files queued for transmission. |
| `POST` | [`/api/binkp/process/inbound`](#post-apibinkpprocessinbound) | Yes | Trigger processing of inbound BinkP packets. |
| `POST` | [`/api/binkp/process/outbound`](#post-apibinkpprocessoutbound) | Yes | Trigger outbound queue processing and polling. |
| `GET` | [`/api/binkp/kept-packets/inspect`](#get-apibinkpkept-packetsinspect) | Yes | Inspect contents of a kept inbound or outbound packet. |
| `GET` | [`/api/binkp/kept-packets/download`](#get-apibinkpkept-packetsdownload) | Yes | Download a kept inbound or outbound packet file. |
| `GET` | [`/api/binkp/queue/inspect`](#get-apibinkpqueueinspect) | Yes | Inspect contents of a queued inbound or outbound packet. |
| `GET` | [`/api/binkp/queue/download`](#get-apibinkpqueuedownload) | Yes | Download a queued inbound or outbound packet file. |
| `GET` | [`/api/binkp/kept-packets/bundle/list`](#get-apibinkpkept-packetsbundlelist) | Yes | List contents of a packet bundle (archive file). |
| `GET` | [`/api/binkp/kept-packets/bundle/inspect`](#get-apibinkpkept-packetsbundleinspect) | Yes | Inspect contents of a kept BinkP packet bundle. |
| `GET` | [`/api/binkp/kept-packets/bundle/download`](#get-apibinkpkept-packetsbundledownload) | Yes | Download a kept BinkP bundle file. |
| `GET` | [`/api/binkp/kept-packets`](#get-apibinkpkept-packets) | Yes | List kept BinkP packet bundles. |
| `GET` | [`/api/binkp/logs`](#get-apibinkplogs) | Yes | Retrieve recent BinkP logs. |
| `GET` | [`/api/binkp/logs/search`](#get-apibinkplogssearch) | Yes | Search BinkP logs by query string. |

#### `GET /api/binkp/status`

**Requires authentication**

Returns operational status of the BinkP daemon including connection state, queue info, and other metrics. Requires admin privileges. Delegates to BinkpController for status retrieval.

**Response** _(JSON)_

BinkP daemon operational status

| Field | Type | Description |
|-------|------|-------------|
| `system` | object | Static system configuration |
| `system.address` | string | FidoNet address of this node |
| `system.sysop` | string | Sysop name |
| `system.location` | string | System location string |
| `system.hostname` | string | BinkP listen hostname |
| `system.port` | integer | BinkP listen port |
| `schedule` | object | Map of uplink address → schedule status entry |
| `schedule[addr].address` | string | Uplink FidoNet address |
| `schedule[addr].schedule` | string | Cron-style poll schedule expression |
| `schedule[addr].enabled` | boolean | Whether this uplink is enabled |
| `schedule[addr].last_poll` | string | ISO 8601 UTC timestamp of last poll, or `"Never"` |
| `schedule[addr].next_poll` | string | ISO 8601 UTC timestamp of next scheduled poll, or `"Unknown"` |
| `schedule[addr].due_now` | boolean | Whether a poll is currently due |
| `queues` | object | Queue statistics |
| `queues.inbound.pending_files` | integer | Packets awaiting processing in the inbound directory |
| `queues.inbound.error_files` | integer | Files in the inbound error directory |
| `queues.inbound.last_check` | string | Timestamp of last inbound queue check |
| `queues.outbound.pending_files` | integer | Packets queued for outbound transmission |
| `queues.outbound.total_size` | integer | Total byte size of outbound packets |
| `queues.outbound.total_messages` | integer | Total message count across outbound packets |
| `queues.outbound.last_check` | string | Timestamp of last outbound queue check |
| `timestamp` | string | ISO 8601 UTC timestamp of when this status was generated |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin access required |
| 500 | Failed to retrieve BinkP status |

---

#### `POST /api/binkp/poll`

**Requires authentication**

Initiates an immediate BinkP poll via the admin daemon. If no address is provided, polls all configured uplinks. Requires admin privileges. Returns success confirmation and poll result from the daemon.

**Request Body** _(JSON)_

Poll target specification

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `address` | string | No | FidoNet address to poll (e.g., '1:123/456'). If omitted, polls all uplinks. |

**Response** _(JSON)_

Poll trigger confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if poll was triggered |
| `message_code` | string | Localization key for UI message |
| `result` | object | Daemon process result |
| `result.exit_code` | integer | Daemon exit code (0 = success) |
| `result.stdout` | string | Standard output from daemon process |
| `result.stderr` | string | Standard error output from daemon process |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin access required |
| 500 | Failed to trigger BinkP poll |

---

#### `POST /api/binkp/poll-all`

**Requires authentication**

Initiates an immediate poll of all BinkP uplinks via the admin daemon. Convenience endpoint equivalent to POST /api/binkp/poll with no address parameter. Requires admin privileges.

**Response** _(JSON)_

Poll trigger confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if poll was triggered |
| `message_code` | string | Localization key for UI message |
| `result` | object | Daemon process result |
| `result.exit_code` | integer | Daemon exit code (0 = success) |
| `result.stdout` | string | Standard output from daemon process |
| `result.stderr` | string | Standard error output from daemon process |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin access required |
| 500 | Failed to poll all BinkP uplinks |

---

#### `POST /api/binkp/process-packets`

**Requires authentication**

Initiates asynchronous processing of BinkP packets via the admin daemon. Requires BinkP administrator privileges. Returns immediately with a success status and processing result. Use this to manually trigger packet queue processing outside normal scheduled intervals.

**Response** _(JSON)_

Processing initiation status with result details.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `message_code` | string | Localization key: 'ui.api.binkp.process_packets_started' |
| `result` | object | Daemon processing result details |
| `result.exit_code` | integer | Daemon exit code (0 = success) |
| `result.stdout` | string | Standard output from packet processor |
| `result.stderr` | string | Standard error output from packet processor |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Packet processing failed; daemon communication error |

---

#### `GET /api/binkp/uplinks`

**Requires authentication**

Fetches all configured BinkP uplink nodes. Requires BinkP administrator privileges. Returns uplink configuration details including addresses, authentication settings, and connection parameters.

**Response** _(JSON)_

Array of uplink configurations.

| Field | Type | Description |
|-------|------|-------------|
| `[array]` | array | Array of uplink configuration objects |
| `[].address` | string | FidoNet address of the uplink (e.g. `1:234/567`) |
| `[].me` | string | Local address to present to this uplink |
| `[].domain` | string | FTN domain name (e.g. `fidonet`) |
| `[].networks` | array of strings | Additional network names served by this uplink |
| `[].hostname` | string | Hostname or IP address |
| `[].port` | integer | TCP port (default 24554) |
| `[].password` | string | BinkP session password |
| `[].pkt_password` | string | FTS-0001 packet password |
| `[].tic_password` | string | TIC file password |
| `[].areafix_password` | string | AreaFix robot password |
| `[].filefix_password` | string | FileFix robot password |
| `[].enabled` | boolean | Whether this uplink is enabled |
| `[].default` | boolean | Whether this is the default uplink |
| `[].send_domain_in_addr` | boolean | Whether to include domain in the presented address |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to retrieve uplinks |

---

#### `GET /api/binkp/uplink-status`

**Requires authentication**

Validates authentication credentials and connection status for a specific uplink address. Requires BinkP administrator privileges. Useful for diagnosing uplink configuration issues before enabling production traffic.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `address` | string | Yes | FidoNet address of uplink to test (e.g., '1:234/567') |

**Response** _(JSON)_

Uplink authentication and status test results.

| Field | Type | Description |
|-------|------|-------------|
| `authenticated` | boolean | Whether credentials are valid |
| `connected` | boolean | Whether connection succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Address parameter missing or empty |
| 500 | Status check failed |

---

#### `POST /api/binkp/uplinks`

**Requires authentication**

Creates a new uplink node configuration. Requires BinkP administrator privileges. Accepts JSON payload with uplink details (address, credentials, connection parameters). Returns created uplink configuration with validation results.

**Request Body** _(JSON)_

Uplink configuration parameters.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `address` | string | Yes | FidoNet address (e.g., '1:234/567') |
| `password` | string | Yes | BinkP session password |
| `host` | string | No | Hostname or IP address |
| `port` | integer | No | TCP port (default 24554) |

**Response** _(JSON)_

Uplink creation confirmation.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Uplink created successfully |
| `message_code` | string | Localization key: `ui.api.binkp.uplink_added` |

---

#### `PUT /api/binkp/uplinks/{address}`

**Requires authentication**

Modifies settings for a configured uplink. Requires BinkP administrator privileges. Accepts JSON payload with updated parameters. Address in URL path identifies the uplink to modify.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `address` | string | FidoNet address of uplink to update |

**Request Body** _(JSON)_

Updated uplink configuration fields.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `password` | string | No | New BinkP session password |
| `host` | string | No | New hostname or IP |
| `port` | integer | No | New TCP port |

**Response** _(JSON)_

Update confirmation.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Update completed |
| `message_code` | string | Localization key: `ui.api.binkp.uplink_updated` |

---

#### `DELETE /api/binkp/uplinks/{address}`

**Requires authentication**

Removes an uplink node configuration. Requires BinkP administrator privileges. Address in URL path identifies the uplink to delete. Deletion is permanent.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `address` | string | FidoNet address of uplink to remove |

**Response** _(JSON)_

Deletion confirmation.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Uplink deleted successfully |

---

#### `GET /api/binkp/files/inbound`

**Requires authentication**

Retrieves inventory of files received via BinkP in the inbound directory. Requires authentication. Returns file metadata including names, sizes, and timestamps for received packets and attachments.

**Response** _(JSON)_

Array of inbound files.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `pending` | array | Array of files in the inbound queue awaiting processing |
| `pending[].filename` | string | Packet filename |
| `pending[].size` | integer | File size in bytes |
| `pending[].modified` | string | Last modified timestamp (YYYY-MM-DD HH:MM:SS) |
| `errors` | array | Array of files in the error queue |
| `errors[].filename` | string | Packet filename |
| `errors[].size` | integer | File size in bytes |
| `errors[].modified` | string | Last modified timestamp (YYYY-MM-DD HH:MM:SS) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to retrieve inbound files |

---

#### `GET /api/binkp/files/outbound`

**Requires authentication**

Fetches all files currently queued in the outbound directory awaiting BinkP transmission. Returns a JSON array of file metadata. Requires authentication.

**Response** _(JSON)_

Array of outbound file objects with metadata

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `files` | array | Array of queued outbound packet files |
| `files[].filename` | string | Packet filename |
| `files[].size` | integer | File size in bytes |
| `files[].created` | string | File creation timestamp (YYYY-MM-DD HH:MM:SS) |
| `files[].modified` | string | File last modified timestamp (YYYY-MM-DD HH:MM:SS) |
| `files[].path` | string | Full filesystem path to the packet file |
| `files[].message_count` | integer | Number of FTN messages contained in the packet |
| `files[].dest_address` | string | Destination FTN address parsed from packet header |
| `files[].orig_address` | string | Origin FTN address parsed from packet header |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to retrieve outbound files |

---

#### `POST /api/binkp/process/inbound`

**Requires authentication**

Initiates immediate processing of received inbound packets through the daemon. Requires BinkP admin privileges. Returns processing result details. This is an administrative action that may take time to complete.

**Response** _(JSON)_

Processing completion status with result details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether processing completed successfully |
| `message_code` | string | Localization key for UI message |
| `result` | object | Processing result details from daemon |
| `result.exit_code` | integer | Daemon exit code (0 = success) |
| `result.stdout` | string | Standard output from packet processor |
| `result.stderr` | string | Standard error output from packet processor |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User lacks BinkP admin privileges |
| 500 | Packet processing failed |

---

#### `POST /api/binkp/process/outbound`

**Requires authentication**

Initiates BinkP poll of all configured nodes to transmit queued outbound packets. Requires BinkP admin privileges. Polls all nodes in the system for transmission opportunities.

**Response** _(JSON)_

Polling completion status with result details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether polling completed successfully |
| `message_code` | string | Localization key for UI message |
| `result` | object | Polling result details from daemon |
| `result.exit_code` | integer | Daemon exit code (0 = spawned successfully) |
| `result.stdout` | string | Standard output (empty for async spawned poll) |
| `result.stderr` | string | Standard error output (empty for async spawned poll) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User lacks BinkP admin privileges |
| 500 | Outbound processing failed |

---

#### `GET /api/binkp/kept-packets/inspect`

**Requires authentication**

Examines the structure and contents of archived packets stored in the kept-packets directory. Requires BinkP admin privileges and valid license. Returns detailed packet metadata and message information.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Packet type: 'inbound' or 'outbound' (default: 'inbound') |
| `date` | string | No | Archive date directory (format varies by storage) |
| `filename` | string | Yes | Packet filename to inspect |

**Response** _(JSON)_

Packet inspection details including structure and contents

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `packet` | object | FTS-0001 packet header metadata |
| `packet.orig_address` | string | Origin FTN address from packet header |
| `packet.dest_address` | string | Destination FTN address from packet header |
| `packet.created` | string | Packet creation timestamp from header |
| `packet.has_password` | boolean | Whether packet has a non-empty password field |
| `packet.packet_version` | integer | FTS-0001 packet version number |
| `packet.product_code` | string | Hex product code from packet header |
| `packet.file_size` | integer | Packet file size in bytes |
| `messages` | array | Array of message headers parsed from the packet |
| `messages[].from` | string | Sender name |
| `messages[].to` | string | Recipient name |
| `messages[].subject` | string | Message subject |
| `messages[].date` | string | Message date string from packet header |
| `messages[].orig_addr` | string | Origin net:node address |
| `messages[].dest_addr` | string | Destination net:node address |
| `messages[].flags` | array of strings | FTS-0001 attribute flag labels (e.g. `Pvt`, `Crash`, `Rcvd`) |
| `messages[].cost` | integer | Message cost field |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | User lacks BinkP admin privileges or license not valid |

---

#### `GET /api/binkp/kept-packets/download`

**Requires authentication**

Retrieves and downloads an archived packet file from the kept-packets directory. Requires BinkP admin privileges and valid license. Returns binary packet data with appropriate headers for file download.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Packet type: 'inbound' or 'outbound' (default: 'inbound') |
| `date` | string | No | Archive date directory (format varies by storage) |
| `filename` | string | Yes | Packet filename to download |

**Response** _(JSON)_

Binary packet file data

| Field | Type | Description |
|-------|------|-------------|
| `file_content` | binary | Raw packet file bytes |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | User lacks BinkP admin privileges or license not valid |
| 404 | Packet file not found |

---

#### `GET /api/binkp/queue/inspect`

**Requires authentication**

Examines the structure and contents of packets currently in the active queue (not archived). Requires BinkP admin privileges and valid license. Returns detailed packet metadata and message information.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Packet type: 'inbound' or 'outbound' (default: 'inbound') |
| `filename` | string | Yes | Queue packet filename to inspect |

**Response** _(JSON)_

Queue packet inspection details including structure and contents

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `packet` | object | FTS-0001 packet header metadata |
| `packet.orig_address` | string | Origin FTN address from packet header |
| `packet.dest_address` | string | Destination FTN address from packet header |
| `packet.created` | string | Packet creation timestamp from header |
| `packet.has_password` | boolean | Whether packet has a non-empty password field |
| `packet.packet_version` | integer | FTS-0001 packet version number |
| `packet.product_code` | string | Hex product code from packet header |
| `packet.file_size` | integer | Packet file size in bytes |
| `messages` | array | Array of message headers parsed from the packet |
| `messages[].from` | string | Sender name |
| `messages[].to` | string | Recipient name |
| `messages[].subject` | string | Message subject |
| `messages[].date` | string | Message date string from packet header |
| `messages[].orig_addr` | string | Origin net:node address |
| `messages[].dest_addr` | string | Destination net:node address |
| `messages[].flags` | array of strings | FTS-0001 attribute flag labels (e.g. `Pvt`, `Crash`, `Rcvd`) |
| `messages[].cost` | integer | Message cost field |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | User lacks BinkP admin privileges or license not valid |

---

#### `GET /api/binkp/queue/download`

**Requires authentication**

Retrieves and downloads a packet file from the active queue directory. Requires BinkP admin privileges and valid license. Returns binary packet data with appropriate headers for file download.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Packet type: 'inbound' or 'outbound' (default: 'inbound') |
| `filename` | string | Yes | Queue packet filename to download |

**Response** _(JSON)_

Binary packet file data

| Field | Type | Description |
|-------|------|-------------|
| `file_content` | binary | Raw packet file bytes |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | User lacks BinkP admin privileges or license not valid |
| 404 | Queue packet file not found |

---

#### `GET /api/binkp/kept-packets/bundle/list`

**Requires authentication**

Enumerates files contained within a bundle or archive packet from the kept-packets directory. Requires BinkP admin privileges and valid license. Returns list of bundled files with metadata.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Packet type: 'inbound' or 'outbound' (default: 'inbound') |
| `date` | string | No | Archive date directory (format varies by storage) |
| `filename` | string | Yes | Bundle/archive packet filename |

**Response** _(JSON)_

List of .pkt files contained in the bundle

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `bundle` | string | Bundle filename |
| `bundle_size` | integer | Bundle file size in bytes |
| `packets` | array | Array of .pkt files found inside the bundle |
| `packets[].filename` | string | Packet filename within the bundle |
| `packets[].size` | integer | Uncompressed packet size in bytes |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | User lacks BinkP admin privileges or license not valid |

---

#### `GET /api/binkp/kept-packets/bundle/inspect`

**Requires authentication**

Retrieves detailed inspection data for a specific packet within a kept bundle (inbound or outbound). Requires BinkP admin privileges and valid license. Returns structured packet metadata and contents.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Bundle type: 'inbound' or 'outbound' (default: 'inbound') |
| `date` | string | No | Date identifier for the bundle |
| `bundle` | string | Yes | Bundle identifier |
| `pkt` | string | Yes | Packet filename to inspect |

**Response** _(JSON)_

Parsed FTS-0001 packet header and per-message header list

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `packet` | object | Packet-level header fields |
| `packet.orig_address` | string | Originating FidoNet address (zone:net/node.point) |
| `packet.dest_address` | string | Destination FidoNet address |
| `packet.created` | string | Packet creation timestamp as `YYYY-MM-DD HH:MM:SS` |
| `packet.has_password` | boolean | Whether a session password was set in the packet header |
| `packet.packet_version` | integer | FTS-0001 packet version field value |
| `packet.product_code` | string | Two-character hex product code |
| `packet.file_size` | integer | Packet file size in bytes |
| `messages` | array | Per-message header entries (up to 1000) |
| `messages[].from` | string | Sender name (CP437 decoded) |
| `messages[].to` | string | Recipient name (CP437 decoded) |
| `messages[].subject` | string | Message subject (CP437 decoded) |
| `messages[].date` | string | Message date string from packet header |
| `messages[].orig_addr` | string | Originating net/node address |
| `messages[].dest_addr` | string | Destination net/node address |
| `messages[].flags` | array | Attribute flag labels (e.g. `["Pvt"]`, `["Crash", "Local"]`) |
| `messages[].cost` | integer | Message cost field |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type, missing bundle or pkt parameter |
| 403 | License not valid or user lacks BinkP admin privileges |

---

#### `GET /api/binkp/kept-packets/bundle/download`

**Requires authentication**

Downloads a file from a kept bundle as an attachment. Requires BinkP admin privileges and valid license. Returns the file with appropriate headers for binary download.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Bundle type: 'inbound' or 'outbound' (default: 'inbound') |
| `date` | string | No | Date identifier for the bundle |
| `filename` | string | Yes | Filename to download |

**Response** _(binary)_

Raw bundle file bytes with download headers

| Header | Value |
|--------|-------|
| `Content-Type` | `application/octet-stream` |
| `Content-Length` | File size in bytes |
| `Content-Disposition` | `attachment; filename="<bundle_filename>"` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type or missing filename parameter |
| 403 | License not valid or user lacks BinkP admin privileges |
| 404 | File not found |

---

#### `GET /api/binkp/kept-packets`

**Requires authentication**

Retrieves a list of kept packet bundles (inbound or outbound). Requires BinkP admin privileges and valid license. Useful for browsing archived or retained packets.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Bundle type: 'inbound' or 'outbound' (default: 'inbound') |

**Response** _(JSON)_

Kept packets grouped by date directory, newest first

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `groups` | array | Date-grouped list of packet entries |
| `groups[].date` | string | Date directory label (e.g. `"Mar-18-2026"`), empty for loose root-level files |
| `groups[].packets` | array | Packet and bundle records within this date group |
| `groups[].packets[].file_type` | string | Either `"pkt"` (raw packet) or `"bundle"` (arcmail archive) |
| `groups[].packets[].filename` | string | Filename within the keep directory |
| `groups[].packets[].size` | integer | File size in bytes |
| `groups[].packets[].modified` | string | ISO 8601 UTC last-modified timestamp |
| `groups[].packets[].modified_ts` | integer | Unix timestamp of last modification |
| `groups[].packets[].message_count` | integer | Number of messages _(pkt only)_ |
| `groups[].packets[].dest_address` | string | Destination FidoNet address _(pkt only)_ |
| `groups[].packets[].orig_address` | string | Originating FidoNet address _(pkt only)_ |
| `groups[].latest_modified_ts` | integer | Unix timestamp of the most recently modified file in this group |
| `total` | integer | Total number of packet/bundle files across all groups |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid type parameter |
| 403 | License not valid or user lacks BinkP admin privileges |

---

#### `GET /api/binkp/logs`

**Requires authentication**

Fetches recent BinkP protocol logs. Requires BinkP admin privileges. Supports configurable line count for pagination.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `lines` | integer | No | Number of log lines to retrieve (default: 100) |

**Response** _(JSON)_

Recent log lines from all BinkP-related log files

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `logs` | array of strings | Log lines in `"<filename>: <raw log line>"` format, up to `lines` entries per file, newest last |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User lacks BinkP admin privileges |

---

#### `GET /api/binkp/logs/search`

**Requires authentication**

Searches BinkP logs for entries matching a query. Requires BinkP admin privileges. Query must be at least 2 characters. Results are JSON-encoded with UTF-8 substitution for invalid sequences.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | Yes | Search query (minimum 2 characters) |

**Response** _(JSON)_

PID-contextual log search results — all lines from sessions that contain the query term

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `lines` | array | Log line entries (all lines from matching PIDs across all BinkP log files) |
| `lines[].line` | string | Full log line prefixed with `"<filename>: "` |
| `lines[].is_match` | boolean | True if this line itself contains the query term (as opposed to being context from a matching PID) |
| `lines[].pid` | string | Process ID extracted from the log line |
| `pid_count` | integer | Number of distinct PIDs whose sessions contained the query term |
| `match_count` | integer | Number of lines that directly matched the query term |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Query string less than 2 characters |
| 403 | User lacks BinkP admin privileges |
| 500 | Failed to encode search results |

---

### Bulletins

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/bulletins`](#get-apibulletins) | Yes | Retrieve active bulletins and unread count for user. |
| `POST` | [`/api/bulletins/{id}/read`](#post-apibulletinsidread) | Yes | Mark a single bulletin as read for the authenticated user. |
| `POST` | [`/api/bulletins/read-all`](#post-apibulletinsread-all) | Yes | Mark multiple bulletins as read in a single request. |

#### `GET /api/bulletins`

**Requires authentication**

Returns list of active bulletins visible to the user, unread count, and the configured bulletin display mode. Bulletins are filtered based on user permissions and read status.

**Response** _(JSON)_

Bulletins and metadata

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `bulletins` | array | Array of active bulletin objects |
| `bulletins[].id` | integer | Bulletin ID |
| `bulletins[].title` | string | Bulletin title |
| `bulletins[].body` | string | Bulletin body text (raw source) |
| `bulletins[].format` | string | Body format (`markdown`, `html`, `plain`) |
| `bulletins[].sort_order` | integer | Display sort order |
| `bulletins[].is_active` | boolean | Whether bulletin is active |
| `bulletins[].active_from` | string\|null | ISO 8601 start date (null = always active) |
| `bulletins[].active_until` | string\|null | ISO 8601 expiry date (null = no expiry) |
| `bulletins[].created_by` | integer | User ID of bulletin creator |
| `bulletins[].is_read` | boolean | Whether the authenticated user has read this bulletin |
| `bulletins[].body_html` | string | Bulletin body rendered to HTML |
| `unread_count` | integer | Number of unread bulletins for this user |
| `bulletin_display_mode` | string | Configured display mode (e.g., `popup`, `list`, `none`) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/bulletins/{id}/read`

**Requires authentication**

Records that the authenticated user has read a specific bulletin. Uses the bulletin ID from the URL path. Returns success confirmation. No validation of bulletin existence is performed in the snippet.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The bulletin ID to mark as read |

**Response** _(JSON)_

JSON object with success status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on successful completion |

---

#### `POST /api/bulletins/read-all`

**Requires authentication**

Marks a batch of bulletins as read for the authenticated user. Accepts a JSON array of bulletin IDs in the request body. Validates that the ids field is an array before processing. Returns success confirmation or a 400 error if validation fails.

**Request Body** _(JSON)_

JSON object containing array of bulletin IDs

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `ids` | array of integers | Yes | Array of bulletin IDs to mark as read |

**Response** _(JSON)_

JSON object with success status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True when all bulletins are marked as read |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid bulletin list (ids is not an array) |

---

### Chat

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/chat/rooms`](#get-apichatrooms) | Yes | List all active chat rooms. |
| `GET` | [`/api/chat/online`](#get-apichatonline) | Yes | Get list of online users and active bots. |
| `GET` | [`/api/chat/messages`](#get-apichatmessages) | Yes | Fetch chat messages from a room or direct message thread. |
| `GET` | [`/api/chat/cursor`](#get-apichatcursor) | Yes | Return the current maximum visible chat message ID for the user. |
| `POST` | [`/api/chat/send`](#post-apichatsend) | Yes | Send a message to a chat room or direct message. |
| `POST` | [`/api/chat/moderate`](#post-apichatmoderate) | Yes | Moderate chat: kick or ban user from room. |
| `GET` | [`/api/chat/poll`](#get-apichatpoll) | Yes | Poll for new chat messages since last check. |

#### `GET /api/chat/rooms`

**Requires authentication**

Retrieves a list of all active chat rooms available on the BBS. Returns room ID, name, and description for each room. Chat feature must be enabled. Useful for populating room selection UI.

**Response** _(JSON)_

Array of active chat rooms

| Field | Type | Description |
|-------|------|-------------|
| `rooms` | array | List of room objects |
| `rooms[].id` | integer | Unique room identifier |
| `rooms[].name` | string | Room display name |
| `rooms[].description` | string | Room description |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Chat feature is disabled |

---

#### `GET /api/chat/online`

**Requires authentication**

Returns users currently online (within 15 minutes) plus all active AI bots, excluding the authenticated user. Bots are always listed regardless of session state. Useful for presence indicators and direct message targeting.

**Response** _(JSON)_

Array of online users and bots

| Field | Type | Description |
|-------|------|-------------|
| `online_users` | array | List of online user objects |
| `online_users[].user_id` | integer | User ID |
| `online_users[].username` | string | User's display name |
| `online_users[].location` | string | User's current location (may be empty) |
| `online_users[].is_bot` | boolean | True if user is an AI bot |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Chat feature is disabled |

---

#### `GET /api/chat/messages`

**Requires authentication**

Retrieves paginated messages from either a chat room or a direct message conversation. Supports cursor-based pagination via before_id. Must specify exactly one of room_id or dm_user_id. Returns up to 200 messages ordered newest first.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `room_id` | integer | No | Chat room ID (mutually exclusive with dm_user_id) |
| `dm_user_id` | integer | No | User ID for direct message thread (mutually exclusive with room_id) |
| `before_id` | integer | No | Fetch messages before this message ID (for pagination) |
| `limit` | integer | No | Max messages to return (default 50, max 200) |

**Response** _(JSON)_

Array of chat messages

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | List of message objects |
| `messages[].id` | integer | Message ID |
| `messages[].room_id` | integer|null | Room ID (null for DMs) |
| `messages[].room_name` | string|null | Room name (null for DMs) |
| `messages[].from_user_id` | integer | Sender user ID |
| `messages[].from_username` | string | Sender username |
| `messages[].to_user_id` | integer|null | Recipient user ID (null for room messages) |
| `messages[].body` | string | Message text |
| `messages[].created_at` | string | ISO 8601 timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid query: must specify exactly one of room_id or dm_user_id |
| 403 | Chat feature is disabled |

---

#### `POST /api/chat/send`

**Requires authentication**

Posts a new message to either a room or direct message thread. Supports special commands: /source (GitHub URL), /help (command list), /kick and /ban (admin only). Message body must be 1-1000 characters. Exactly one of room_id or to_user_id must be specified.

**Request Body** _(JSON)_

Message to send

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `room_id` | integer | No | Target room ID (mutually exclusive with to_user_id) |
| `to_user_id` | integer | No | Target user ID for DM (mutually exclusive with room_id) |
| `body` | string | Yes | Message text (1-1000 characters) |

**Response** _(JSON)_

Confirmation of sent message or local system message

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether message was sent |
| `local_message` | object | System message object (for /help, /source, or errors) |
| `local_message.from_username` | string | Always 'System' for local messages |
| `local_message.body` | string | Message content |
| `local_message.type` | string | Always 'local' for system messages |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid target (must specify exactly one of room_id or to_user_id) |
| 400 | Message length invalid (must be 1-1000 characters) |
| 403 | Admin required for /kick or /ban commands |
| 403 | Chat feature is disabled |

---

#### `POST /api/chat/moderate`

**Requires authentication**

Admin-only endpoint to kick (10-minute temporary ban) or permanently ban a user from a chat room. Validates room and user existence before applying action. Requires admin privileges.

**Request Body** _(JSON)_

Moderation action

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `room_id` | integer | Yes | Target chat room ID |
| `user_id` | integer | Yes | User ID to kick or ban |
| `action` | string | Yes | Either 'kick' (10 min) or 'ban' (permanent) |

**Response** _(JSON)_

Confirmation of moderation action

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether action was applied |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid moderation request (missing fields or invalid action) |
| 403 | Admin privileges required |
| 404 | Chat room not found |
| 404 | User not found or inactive |
| 403 | Chat feature is disabled |

---

#### `GET /api/chat/cursor`

**Requires authentication**

Returns the highest chat message ID currently visible to the authenticated user
across active rooms and direct messages addressed to them. This is useful for
clients that want to anchor a polling cursor at "now" without replaying older
backlog from other rooms or DM threads.

**Response** _(JSON)_

Current visible chat cursor

| Field | Type | Description |
|-------|------|-------------|
| `max_id` | integer | Highest visible chat message ID for the authenticated user |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid chat user context |
| 403 | Chat feature is disabled |

---

#### `GET /api/chat/poll`

**Requires authentication**

Long-polling endpoint that returns new messages (room and DM) since the provided since_id cursor. Excludes messages from the authenticated user. Returns up to 200 messages with HTML markup rendered. Useful for clients that don't support Server-Sent Events.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `since_id` | integer | No | Return messages with ID greater than this (default 0) |

**Response** _(JSON)_

Array of new messages since cursor

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | List of message objects |
| `messages[].id` | integer | Message ID |
| `messages[].type` | string | Either 'room' or 'dm' |
| `messages[].room_id` | integer|null | Room ID (null for DMs) |
| `messages[].room_name` | string|null | Room name (null for DMs) |
| `messages[].from_user_id` | integer | Sender user ID |
| `messages[].from_username` | string | Sender username |
| `messages[].to_user_id` | integer|null | Recipient user ID (null for room messages) |
| `messages[].body` | string | Raw message text |
| `messages[].markup_html` | string | HTML-rendered message (markdown processed) |
| `messages[].created_at` | string | ISO 8601 timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Chat feature is disabled |

---

### Credits

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/credits/send`](#post-apicreditssend) | Yes | Send credits from authenticated user to another user. |

#### `POST /api/credits/send`

**Requires authentication**

Transfers credits between users with validation of amount, recipient existence, and sender balance. Credits feature must be enabled. Amount must be between 1 and 200. Users cannot send credits to themselves. Creates transaction records for both parties.

**Request Body** _(JSON)_

Credit transfer request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `recipient_id` | integer | Yes | ID of the user receiving credits |
| `amount` | integer | Yes | Number of credits to send (1-200) |
| `message` | string | No | Optional message to include with transfer |

**Response** _(JSON)_

Transfer confirmation with updated balances

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Transfer success flag |
| `sender_balance` | integer | Sender's new credit balance |
| `recipient_balance` | integer | Recipient's new credit balance |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Credits feature disabled, invalid amount, self-transfer, or insufficient balance |
| 404 | Recipient not found or inactive |

---

### Dashboard

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/dashboard/stats`](#get-apidashboardstats) | Yes | Retrieve dashboard statistics for authenticated user. |
| `POST` | [`/api/dashboard/layout`](#post-apidashboardlayout) | Yes | Save or reset user's dashboard card layout. |

#### `GET /api/dashboard/stats`

**Requires authentication**

Returns aggregated dashboard statistics including user activity, message counts, and system metrics. Statistics are computed by DashboardStatsService and may vary based on user role and permissions.

**Response** _(JSON)_

Dashboard statistics object. All counts are for the authenticated user.

| Field | Type | Description |
|-------|------|-------------|
| `unread_netmail` | integer | Netmail badge count (non-zero only when new messages arrived since last check) |
| `total_netmail` | integer | True unread netmail count |
| `new_echomail` | integer | Echomail messages in subscribed areas since last visit |
| `online_count` | integer | Users active in the last 15 minutes |
| `unread_bulletins` | integer | Unread bulletins for the authenticated user |
| `credit_balance` | integer | User credit balance (0 if credits disabled) |
| `chat_total` | integer | New chat messages since last visit |
| `new_files` | integer | New approved files since last visit |
| `new_echoareas` | integer | Echo areas created in the last 30 days |
| `recent_echoareas` | array | Up to 8 most recently created echo area objects |
| `recent_echoareas[].id` | integer | Echo area ID |
| `recent_echoareas[].tag` | string | Echo area tag name |
| `recent_echoareas[].domain` | string\|null | Network domain |
| `recent_echoareas[].description` | string\|null | Echo area description |
| `recent_echoareas[].created_at` | string | ISO 8601 creation timestamp |
| `echomail_max_id` | integer | Current max echomail row ID (used for badge tracking) |
| `chat_max_id` | integer | Current max chat message ID |
| `files_max_id` | integer | Current max file ID |
| `total_files` | integer | Total approved files |
| `pending_file_approvals` | integer | _(admin only)_ Files pending approval |
| `pending_echomail_moderation` | integer | _(admin only)_ Echomail messages pending moderation |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/dashboard/layout`

**Requires authentication**

Persists custom dashboard layout configuration or resets to defaults. Validates layout against available cards (which may depend on user role and feature flags like referral credits). Supports reset flag to clear saved layout.

**Request Body** _(JSON)_

Dashboard layout configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `reset` | boolean | No | If true, clears saved layout and uses defaults on next load |
| `cards` | array | No | Array of card configurations (structure validated against available cards) |

**Response** _(JSON)_

Layout save confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether layout was saved or reset |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid layout data or validation failed |

---

### Debug

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/admin/debug`](#get-apiadmindebug) | Yes | Debug endpoint for authentication testing |

#### `GET /api/admin/debug`

**Requires authentication**

Returns current authenticated user info, admin status, and session cookie details. Useful for debugging auth issues and verifying session state.

**Response** _(JSON)_

Current authentication state

| Field | Type | Description |
|-------|------|-------------|
| `user` | object | Current user object (null if not authenticated) |
| `user.user_id` | integer | User ID |
| `user.username` | string | Username |
| `user.real_name` | string | Real name |
| `user.email` | string\|null | Email address |
| `user.is_admin` | boolean | Admin flag |
| `is_admin` | boolean | Whether current user has admin privileges |
| `cookie_present` | boolean | Whether session cookie exists |
| `cookie_value` | string\|null | Session cookie value (null if not present) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Auth check failed |

---

### Docs

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/docs/mcp-client-help/claude`](#get-apidocsmcp-client-helpclaude) | Yes | Retrieve MCP client help documentation as HTML. |

#### `GET /api/docs/mcp-client-help/claude`

**Requires authentication**

Returns rendered HTML version of MCPClientHelp.md markdown documentation. Requires authentication and valid license. Useful for embedding help content in client applications.

**Response** _(JSON)_

Rendered help documentation in HTML format.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `html` | string | HTML-rendered markdown content |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Help file not found |

---

### Echoareas

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/echoareas`](#get-apiechoareas) | Yes | List echo areas with filtering, subscription, and message counts. |
| `GET` | [`/api/echoareas/{id}`](#get-apiechoareasid) | Yes | Get detailed echo area configuration with LovlyNet metadata. |
| `POST` | [`/api/echoareas`](#post-apiechoareas) | Yes | Create a new echo area with configuration. |
| `PUT` | [`/api/echoareas/{id}`](#put-apiechoareasid) | Yes | Update echo area configuration. |
| `DELETE` | [`/api/echoareas/{id}`](#delete-apiechoareasid) | Yes | Delete an echo area. |
| `GET` | [`/api/echoareas/stats`](#get-apiechoareasstats) | Yes | Get echo area statistics. |
| `GET` | [`/api/echoareas/simple-list`](#get-apiechoareassimple-list) | Yes | Lightweight list of all echo areas for admin comboboxes. |

#### `GET /api/echoareas`

**Requires authentication**

Retrieves a paginated list of echo areas with support for filtering by status (active/inactive/all), subscription status, and visibility rules. Returns message counts (total and unread), subscriber counts, and last post metadata. Respects user permissions and moderation filters. Admins see all areas; regular users see only non-sysop areas they're subscribed to or have access to.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `filter` | string | No | Filter by status: 'active' (default), 'inactive', or 'all' |
| `subscribed_only` | boolean | No | If 'true', return only areas the user is subscribed to (default: false) |

**Response** _(JSON)_

Array of echo area objects with message and subscription metadata

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Echo area ID |
| `tag` | string | Echo area tag (uppercase) |
| `description` | string | Human-readable description |
| `moderator` | string|null | Moderator name or null |
| `message_count` | integer | Total visible messages (respects user permissions) |
| `unread_count` | integer | Unread messages for current user |
| `subscriber_count` | integer | Number of active subscribers |
| `last_subject` | string|null | Subject of most recent post |
| `last_author` | string|null | Author of most recent post |
| `last_date` | string|null | ISO 8601 timestamp of most recent post |
| `is_active` | boolean | Whether area is active |
| `is_sysop_only` | boolean | Whether area is restricted to sysops |
| `allow_media` | boolean|null | Media attachment policy |
| `color` | string | Hex color code for UI display |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `GET /api/echoareas/{id}`

**Requires authentication**

Retrieves full configuration for a single echo area including all settings and optional LovlyNet integration metadata. Admin-only endpoint. If the area is configured for LovlyNet domain, fetches remote metadata and validates local settings against recommended values, reporting any mismatches.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Echo area ID |

**Response** _(JSON)_

Single echo area object with extended metadata

| Field | Type | Description |
|-------|------|-------------|
| `echoarea` | object | Full echo area record including all database columns |
| `echoarea.id` | integer | Echo area ID |
| `echoarea.tag` | string | Echo area tag |
| `echoarea.description` | string | Description |
| `echoarea.domain` | string | Domain (e.g., 'lovlynet') |
| `echoarea.is_sysop_only` | boolean | Sysop-only flag |
| `echoarea.is_active` | boolean | Whether area is active |
| `echoarea.is_local` | boolean | Whether area is local-only (not forwarded) |
| `echoarea.color` | string | Hex color code for UI display |
| `echoarea.lovlynet_metadata` | object | Remote LovlyNet metadata if domain is 'lovlynet'; empty object otherwise |
| `echoarea.lovlynet_metadata.sysop_only` | boolean | LovlyNet recommended sysop-only setting |
| `echoarea.lovlynet_setting_issues` | array | Array of setting mismatches with recommended vs actual values |
| `echoarea.lovlynet_setting_issues[].setting` | string | Setting name that has a mismatch |
| `echoarea.lovlynet_setting_issues[].recommended` | boolean | LovlyNet recommended value |
| `echoarea.lovlynet_setting_issues[].actual` | boolean | Current local value |
| `echoarea.lovlynet_has_setting_issues` | boolean | Whether any setting mismatches exist |
| `echoarea.description_mismatch` | boolean | Whether local description differs from LovlyNet description |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin privileges required |
| 404 | Echo area not found |

---

#### `POST /api/echoareas`

**Requires authentication**

Creates a new echo area with full configuration including posting name policy, art format hints, and media settings. Admin-only. Validates tag format (uppercase alphanumeric with dots, underscores, hyphens, apostrophes). Supports inheritance of policies from system defaults via null values.

**Request Body** _(JSON)_

Echo area configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `tag` | string | Yes | Uppercase tag matching /^[A-Z0-9._'-]+$/ |
| `description` | string | Yes | Human-readable description |
| `moderator` | string|null | No | Moderator name |
| `uplink_address` | string|null | No | FidoNet uplink address |
| `color` | string | No | Hex color code (default: #28a745) |
| `is_active` | boolean | No | Whether area is active |
| `is_local` | boolean | No | Whether area is local-only |
| `is_sysop_only` | boolean | No | Whether area is sysop-only |
| `domain` | string | No | Domain name (e.g., 'lovlynet') |
| `posting_name_policy` | string|null | No | 'real_name', 'username', or null for inherit |
| `art_format_hint` | string|null | No | 'ansi', 'amiga_ansi', 'petscii', or null for auto |
| `allow_media` | string | No | 'allow'/'true', 'deny'/'false', or 'inherit' (default) |
| `gemini_public` | boolean | No | Whether area is public on Gemini protocol |

**Response** _(JSON)_

Created echo area with ID

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | true on success |
| `id` | integer | New echo area ID |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Validation error (invalid tag format, missing required fields, duplicate tag) |
| 403 | Admin privileges required |

---

#### `PUT /api/echoareas/{id}`

**Requires authentication**

Updates an existing echo area's configuration. Admin-only. Validates all fields same as POST. Supports partial updates; omitted fields retain current values. Tag must be unique unless unchanged.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Echo area ID |

**Request Body** _(JSON)_

Echo area configuration (same as POST, all fields optional)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `tag` | string | No | Uppercase tag |
| `description` | string | No | Description |
| `moderator` | string|null | No | Moderator name |
| `uplink_address` | string|null | No | FidoNet uplink address |
| `color` | string | No | Hex color code |
| `is_active` | boolean | No | Active status |
| `is_local` | boolean | No | Local-only flag |
| `is_sysop_only` | boolean | No | Sysop-only flag |
| `domain` | string | No | Domain name |
| `posting_name_policy` | string|null | No | Posting name policy |
| `art_format_hint` | string|null | No | Art format hint |
| `allow_media` | string | No | Media policy |
| `gemini_public` | boolean | No | Gemini public flag |

**Response** _(JSON)_

Updated echo area

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | true on success |
| `message_code` | string | Localization key |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Validation error or echo area not found |
| 403 | Admin privileges required |

---

#### `DELETE /api/echoareas/{id}`

**Requires authentication**

Deletes an echo area only if it contains no messages. Admin-only. Returns error if area has messages; deactivation is recommended instead. Cascades delete to subscriptions and related data.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Echo area ID |

**Response** _(JSON)_

Deletion confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | true on success |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Cannot delete area with messages, or area not found |
| 403 | Admin privileges required |

---

#### `GET /api/echoareas/stats`

**Requires authentication**

Returns aggregate statistics for all echo areas: count of active areas, total messages across all areas, and messages posted today. Useful for dashboard/monitoring.

**Response** _(JSON)_

Echo area statistics

| Field | Type | Description |
|-------|------|-------------|
| `active_count` | integer | Number of active echo areas |
| `total_messages` | integer | Total messages across all areas |
| `today_messages` | integer | Messages posted today (UTC) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `GET /api/echoareas/simple-list`

**Requires authentication**

Returns a minimal echo area listing suitable for populating admin UI dropdowns. Includes only essential fields: id, tag, description, and domain. Sorted alphabetically by tag.

**Response** _(JSON)_

Array of echo areas

| Field | Type | Description |
|-------|------|-------------|
| `echoareas` | array | Array of minimal echo area objects |
| `echoareas[].id` | integer | Echo area ID |
| `echoareas[].tag` | string | Echo area tag |
| `echoareas[].description` | string | Human-readable description |
| `echoareas[].domain` | string | Domain name (e.g., `fidonet`, `lovlynet`) |

---

### Fileareas

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/fileareas`](#get-apifileareas) | Yes | List file areas with LovlyNet metadata. |
| `GET` | [`/api/fileareas/{id}`](#get-apifileareasid) | Yes | Get detailed file area configuration. |
| `POST` | [`/api/fileareas`](#post-apifileareas) | Yes | Create a new file area. |
| `PUT` | [`/api/fileareas/{id}`](#put-apifileareasid) | Yes | Update an existing file area. |
| `DELETE` | [`/api/fileareas/{id}`](#delete-apifileareasid) | Yes | Delete a file area. |
| `GET` | [`/api/fileareas/stats`](#get-apifileareasstats) | Yes | Get file area statistics. |
| `GET` | [`/api/fileareas/{id}/preview-iso`](#get-apifileareasidpreview-iso) | Yes | Preview ISO file import without committing changes. |
| `POST` | [`/api/fileareas/{id}/reindex-iso`](#post-apifileareasidreindex-iso) | Yes | Re-index an ISO file area with optional overrides. |
| `DELETE` | [`/api/fileareas/{id}/subfolder`](#delete-apifileareasidsubfolder) | Yes | Delete all files in a subfolder. |
| `POST` | [`/api/fileareas/{id}/comment-area`](#post-apifileareasidcomment-area) | Yes | Admin: link, create, or unlink a comment echo area for a file area. |

#### `GET /api/fileareas`

**Requires authentication**

Retrieves file areas with filtering by status and user access level. Returns ISO mount point accessibility status. Fetches LovlyNet metadata for areas in the 'lovlynet' domain. Respects admin/user/public visibility rules.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `filter` | string | No | Filter by status: 'active' (default), 'inactive', or 'all' |

**Response** _(JSON)_

Array of file area objects with metadata

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | File area ID |
| `tag` | string | File area tag |
| `description` | string | Description |
| `area_type` | string | Type: 'iso', 'local', etc. |
| `iso_accessible` | boolean | Whether ISO mount point is readable (if area_type='iso') |
| `domain` | string | Domain name |
| `is_active` | boolean | Whether area is active |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `GET /api/fileareas/{id}`

**Requires authentication**

Retrieves full configuration for a single file area including ISO mount point accessibility. Admin-only endpoint.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID |

**Response** _(JSON)_

Single file area object with full configuration

| Field | Type | Description |
|-------|------|-------------|
| `filearea` | object | Full file area configuration (all database columns) |
| `filearea.id` | integer | File area ID |
| `filearea.tag` | string | File area tag |
| `filearea.description` | string | Description |
| `filearea.domain` | string | Domain name (e.g. `fidonet`) |
| `filearea.is_active` | boolean | Whether area is active |
| `filearea.is_local` | boolean | Whether area is local-only |
| `filearea.is_private` | boolean | Whether area is private |
| `filearea.is_public` | boolean | Whether area is publicly accessible without login |
| `filearea.area_type` | string | Area type: `normal` or `iso` |
| `filearea.iso_mount_point` | string|null | Path to ISO mount point (if area_type is `iso`) |
| `filearea.iso_accessible` | boolean | Whether ISO mount point is currently readable |
| `filearea.comment_echoarea_id` | integer|null | ID of linked comment echo area |
| `filearea.upload_permission` | integer | Upload permission level (1 = users, 2 = admin only) |
| `filearea.file_count` | integer | Number of approved files in area |
| `filearea.total_size` | integer | Total size of all files in bytes |
| `filearea.created_at` | string | ISO 8601 creation timestamp |
| `filearea.updated_at` | string | ISO 8601 last update timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin privileges required |
| 404 | File area not found |

---

#### `POST /api/fileareas`

**Requires authentication**

Creates a new file area with the provided configuration. Requires admin authentication. Returns the newly created file area ID on success. The request body should contain file area configuration details passed to FileAreaManager::createFileArea().

**Request Body** _(JSON)_

File area configuration object

**Response** _(JSON)_

Success response with created file area ID

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `id` | integer | ID of the newly created file area |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Failed to create file area (invalid data or database error) |

---

#### `PUT /api/fileareas/{id}`

**Requires authentication**

Updates a file area identified by ID with new configuration data. Requires admin authentication. Modifies the file area in-place and returns success status without the updated object.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID to update |

**Request Body** _(JSON)_

File area configuration updates

**Response** _(JSON)_

Success response

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Failed to update file area (invalid data or database error) |

---

#### `DELETE /api/fileareas/{id}`

**Requires authentication**

Permanently deletes a file area and all associated data. Requires admin authentication. This operation cannot be undone.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID to delete |

**Response** _(JSON)_

Success response

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Failed to delete file area (database error) |

---

#### `GET /api/fileareas/stats`

**Requires authentication**

Retrieves aggregated statistics for all file areas. Requires authentication. Returns different data based on user privilege level (guest vs. authenticated users).

**Response** _(JSON)_

File area statistics

| Field | Type | Description |
|-------|------|-------------|
| `active_count` | integer | Number of active file areas (public-only when request is unauthenticated) |
| `total_files` | integer | Total approved file count across matching areas |
| `total_size` | integer | Total byte size of all files across matching areas |

---

#### `GET /api/fileareas/{id}/preview-iso`

**Requires authentication**

Performs a dry-run scan of an ISO file area, returning directory entries with descriptions and import status. Requires admin authentication. Supports flat listing and catalogue-only modes via query parameters. Does not modify the database.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID to preview |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `flat` | boolean | No | If set, return flat file list instead of hierarchical structure |
| `catalogue_only` | boolean | No | If set, only include catalogued entries |

**Response** _(JSON)_

Preview data with success flag and directory entries

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | ISO preview failed (file read or parsing error) |

---

#### `POST /api/fileareas/{id}/reindex-iso`

**Requires authentication**

Triggers a re-index of an ISO file area, importing or updating file entries. Requires admin authentication. Supports per-file overrides for descriptions and skip flags. Returns import counters (added, updated, skipped, etc.).

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID to re-index |

**Request Body** _(JSON)_

ISO import configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `flat` | boolean | No | Use flat import structure |
| `catalogue_only` | boolean | No | Only import catalogued files |
| `overrides` | array | No | Array of per-file overrides with rel_path, description, and skip flag |

**Response** _(JSON)_

Import result with counters

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `counters` | object | Import statistics |
| `counters.imported` | integer | Number of new files added |
| `counters.updated` | integer | Number of existing files updated |
| `counters.skipped` | integer | Number of files skipped (unchanged or already present) |
| `counters.no_description` | integer | Number of files with no description available |
| `counters.errors` | integer | Number of files that failed to import |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | ISO re-index failed (file processing or database error) |

---

#### `DELETE /api/fileareas/{id}/subfolder`

**Requires authentication**

Removes all files and iso_subdir records belonging to a specified subfolder path, including nested subfolders. Requires admin authentication. Returns count of deleted files.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID |

**Request Body** _(JSON)_

Subfolder deletion request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `subfolder` | string | Yes | Subfolder path to delete (cannot be empty) |

**Response** _(JSON)_

Deletion result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `deleted` | integer | Number of files deleted |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Subfolder parameter is required or empty |
| 500 | Failed to delete subfolder (database error) |

---

#### `POST /api/fileareas/{id}/comment-area`

**Requires authentication**

Manages the comment echo area association for a file area. Supports three actions: 'link' to attach an existing echo area, 'create' to generate a new echo area, or 'unlink' to remove the association. Tag validation enforces FidoNet naming conventions. Admin-only endpoint.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File area ID |

**Request Body** _(JSON)_

Comment area action configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | One of: 'link', 'create', 'unlink' |
| `echoarea_id` | integer | No | Echo area ID (required for 'link' action) |
| `tag` | string | No | Echo area tag in uppercase (required for 'create' action, must match /^[A-Z0-9._'-]+$/) |
| `description` | string | No | Echo area description (optional for 'create' action) |

**Response** _(JSON)_

Updated file area with comment area configuration

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success status |
| `comment_echoarea_id` | integer|null | ID of linked echo area, or null if unlinked |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing required field (echoarea_id for link, tag for create) or invalid tag format |
| 404 | File area or echo area not found |

---

### Files

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/files`](#get-apifiles) | Yes | List files in a file area with optional subfolder filtering. |
| `GET` | [`/api/files/recent`](#get-apifilesrecent) | Yes | Retrieve recently uploaded files across all accessible areas. |
| `GET` | [`/api/files/my-uploads`](#get-apifilesmy-uploads) | Yes | List all files uploaded by the authenticated user. |
| `GET` | [`/api/files/search`](#get-apifilessearch) | Yes | Search files by name and description across accessible areas. |
| `GET` | [`/api/files/{id}`](#get-apifilesid) | Yes | Retrieve detailed metadata for a specific file. |
| `POST` | [`/api/files/{id}/rehatch`](#post-apifilesidrehatch) | Yes | Re-hatch a file via the admin daemon (admin only). |
| `GET` | [`/api/files/{id}/download`](#get-apifilesiddownload) | Yes | Download a file with access control and credit deduction. |
| `GET` | [`/api/files/{id}/preview`](#get-apifilesidpreview) | Yes | Preview a file inline (images, video, audio, text). |
| `GET` | [`/api/files/{id}/prgs`](#get-apifilesidprgs) | Yes | Extract and return PRG files from archives as base64-encoded JSON. |
| `GET` | [`/api/files/{id}/zip-contents`](#get-apifilesidzip-contents) | Yes | List non-directory entries inside a .zip file. |
| `GET` | [`/api/files/{id}/zip-entry`](#get-apifilesidzip-entry) | Yes | Serve a single entry from a .zip file for inline preview. |
| `GET` | [`/api/files/{id}/archive-contents`](#get-apifilesidarchive-contents) | Yes | List entries in any supported archive format. |
| `GET` | [`/api/files/{id}/archive-entry`](#get-apifilesidarchive-entry) | Yes | Serve a single entry from any supported archive. |
| `POST` | [`/api/files/{id}/share`](#post-apifilesidshare) | Yes | Create a share link for a file. |
| `GET` | [`/api/files/shared/check/{fileId}`](#get-apifilessharedcheckfileid) | Yes | Check if user has an active share for a file. |
| `GET` | [`/api/files/shared/{area}/{filename}`](#get-apifilessharedareafilename) | Yes | Get shared file info by area tag and filename. |
| `DELETE` | [`/api/files/shares/{shareId}`](#delete-apifilessharesshareid) | Yes | Revoke a file share link. |
| `POST` | [`/api/files/upload`](#post-apifilesupload) | Yes | Upload a file to a file area with descriptions and optional cost deduction. |
| `POST` | [`/api/files/add-link`](#post-apifilesadd-link) | Yes | Add an external URL link to a file area as a file entry. |
| `POST` | [`/api/files/fetch-url-meta`](#post-apifilesfetch-url-meta) | Yes | Fetch page title and metadata from a URL for link preview. |
| `DELETE` | [`/api/files/{id}/delete`](#delete-apifilesiddelete) | Yes | Delete a file from a file area (owner or admin). |
| `PUT` | [`/api/files/{id}/rename`](#put-apifilesidrename) | Yes | Edit file name and/or descriptions (owner or admin). |
| `POST` | [`/api/files/{id}/scan`](#post-apifilesidscan) | Yes | Trigger on-demand ClamAV virus scan for a file (admin only). |
| `PUT` | [`/api/files/{id}/scan-status`](#put-apifilesidscan-status) | Yes | Manually override virus scan status for a file (admin only). |
| `GET` | [`/api/files/{id}/comments`](#get-apifilesidcomments) | Yes | Fetch threaded echomail comments linked to a file. |
| `POST` | [`/api/files/{id}/comments`](#post-apifilesidcomments) | Yes | Post a comment on a file, creating a thread root if needed. |

#### `GET /api/files`

**Requires authentication**

Retrieves files and subfolders from a specified file area. Supports public areas (guest access) and private areas (authenticated users only). Requires area_id query parameter. Optional subfolder parameter filters results; empty string or missing parameter returns root level. Returns subfolders, files, and breadcrumb navigation.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `area_id` | integer | Yes | File area ID to list |
| `subfolder` | string | No | Subfolder path to list (omit or empty for root) |

**Response** _(JSON)_

Files and subfolders in the area

| Field | Type | Description |
|-------|------|-------------|
| `subfolders` | array | List of subdirectory objects |
| `subfolders[].subfolder` | string | Subfolder path |
| `subfolders[].description` | string\|null | Display label from ISO metadata (if present) |
| `subfolders[].long_description` | string\|null | Extended ISO subfolder description |
| `subfolders[].subdir_id` | integer\|null | ID of the iso_subdir record if applicable |
| `files` | array | List of file objects in current folder |
| `files[].id` | integer | File ID |
| `files[].filename` | string | File name |
| `files[].filesize` | integer | File size in bytes |
| `files[].short_description` | string | Brief description |
| `files[].long_description` | string | Extended description |
| `files[].status` | string | Approval status (approved, pending, rejected) |
| `files[].source_type` | string | Origin type (fidonet, user_upload, iso_import, url, etc.) |
| `files[].created_at` | string | Upload timestamp (ISO 8601) |
| `files[].subfolder` | string\|null | Subfolder path if in a subdirectory |
| `files[].owner_id` | integer\|null | User ID of uploader |
| `files[].area_tag` | string | Tag of the file area |
| `files[].is_shared` | boolean | Whether an active share link exists for this file |
| `subfolder` | string\|null | Current subfolder path (null at root level) |
| `subfolder_label` | string\|null | Display label for current subfolder (null at root level) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature is disabled |
| 400 | File area ID is required |
| 403 | User does not have access to this file area |

---

#### `GET /api/files/recent`

**Requires authentication**

Returns a paginated list of the most recently uploaded files visible to the authenticated user. Guests see only public files. The limit parameter is capped at 50 to prevent abuse. File areas must be enabled.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `limit` | integer | No | Maximum number of files to return (default 25, max 50) |

**Response** _(JSON)_

Array of recent file objects

| Field | Type | Description |
|-------|------|-------------|
| `files` | array | List of file objects |
| `files[].id` | integer | File ID |
| `files[].filename` | string | File name |
| `files[].filesize` | integer | File size in bytes |
| `files[].short_description` | string | Brief description |
| `files[].created_at` | string | Upload timestamp (ISO 8601) |
| `files[].subfolder` | string\|null | Subfolder path if applicable |
| `files[].subfolder_label` | string\|null | Display label for subfolder from ISO metadata |
| `files[].source_type` | string | Origin type (fidonet, user_upload, iso_import, url, etc.) |
| `files[].area_tag` | string | Tag of the file area |
| `files[].domain` | string | Domain of the file area |
| `files[].is_local` | boolean | Whether the file area is local-only |
| `files[].is_shared` | boolean | Whether an active share link exists for this file |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature is disabled |

---

#### `GET /api/files/my-uploads`

**Requires authentication**

Returns the authenticated user's uploaded files along with a summary of their upload statistics. Requires authentication. File areas must be enabled.

**Response** _(JSON)_

User's uploads and summary statistics

| Field | Type | Description |
|-------|------|-------------|
| `files` | array | List of file objects uploaded by the user |
| `files[].id` | integer | File ID |
| `files[].filename` | string | File name |
| `files[].filesize` | integer | File size in bytes |
| `files[].short_description` | string | Brief description |
| `files[].status` | string | Approval status (approved, pending, rejected) |
| `files[].created_at` | string | Upload timestamp (ISO 8601) |
| `files[].area_tag` | string | Tag of the file area |
| `files[].domain` | string | Domain of the file area |
| `files[].area_description` | string | Description of the file area |
| `summary` | object | Upload statistics |
| `summary.total_count` | integer | Total number of uploads |
| `summary.total_size` | integer | Total size in bytes of all uploads |
| `summary.pending_count` | integer | Number of uploads awaiting approval |
| `summary.approved_count` | integer | Number of approved uploads |
| `summary.rejected_count` | integer | Number of rejected uploads |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature is disabled |

---

#### `GET /api/files/search`

**Requires authentication**

Full-text search across filenames and short descriptions in approved files. Query must be at least 2 characters. Returns up to 100 results ordered by area tag and filename. Respects area access controls: guests see only public areas, users see public + their private areas, admins see all active non-private areas.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | Yes | Search query (minimum 2 characters) |

**Response** _(JSON)_

Search results with file metadata

| Field | Type | Description |
|-------|------|-------------|
| `results` | array | Array of matching file objects |
| `results[].id` | integer | File ID |
| `results[].filename` | string | File name |
| `results[].short_description` | string | Brief description |
| `results[].filesize` | integer | File size in bytes |
| `results[].created_at` | string | Upload timestamp (ISO 8601) |
| `results[].area_id` | integer | File area ID |
| `results[].area_tag` | string | File area tag |
| `results[].subfolder` | string\|null | Subfolder path if applicable |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature is disabled |

---

#### `GET /api/files/{id}`

**Requires authentication**

Returns full file details including metadata, area info, and access status. Guests can access files in public areas only. Authenticated users see approved files and their own pending/rejected uploads. Admins see all files. File must be approved or belong to the requesting user.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID |

**Response** _(JSON)_

Complete file metadata object

| Field | Type | Description |
|-------|------|-------------|
| `file` | object | File details object |
| `file.id` | integer | File ID |
| `file.filename` | string | File name |
| `file.filesize` | integer | File size in bytes |
| `file.short_description` | string | Brief description |
| `file.long_description` | string | Extended description |
| `file.status` | string | Approval status (approved, pending, rejected) |
| `file.source_type` | string | Origin type (fidonet, user_upload, iso_import, url, etc.) |
| `file.created_at` | string | Upload timestamp (ISO 8601) |
| `file.updated_at` | string | Last update timestamp (ISO 8601) |
| `file.subfolder` | string\|null | Subfolder path if in a subdirectory |
| `file.url` | string\|null | External URL (for url-type files) |
| `file.owner_id` | integer\|null | User ID of uploader |
| `file.file_area_id` | integer | Associated file area ID |
| `file.virus_scanned` | boolean | Whether virus scan was performed |
| `file.virus_scan_result` | string\|null | Scan result (clean, infected, error, skipped) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File not found or not accessible |
| 404 | File areas feature is disabled |

---

#### `POST /api/files/{id}/rehatch`

**Requires authentication**

Triggers file_hatch.php to re-process a file's metadata and hatch information. Admin-only operation. Cannot rehatch files in local-only or private areas. Communicates with the admin daemon to perform the operation.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to rehatch |

**Response** _(JSON)_

Rehatch operation result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether rehatch completed successfully |
| `result` | object | Command execution result from admin daemon |
| `result.exit_code` | integer | Exit code from file_hatch.php (0 on success) |
| `result.stdout` | string | Standard output from file_hatch.php |
| `result.stderr` | string | Standard error output from file_hatch.php |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Not an admin |
| 404 | File not found |
| 400 | Cannot rehatch file in local-only area |
| 400 | Cannot rehatch file in private area |
| 500 | Rehatch operation failed |

---

#### `GET /api/files/{id}/download`

**Requires authentication**

Serves a file for download with proper access control. Guests can download from public areas. Authenticated users can download approved files and their own unapprovedUploads. Admins bypass most restrictions. Senders of netmail attachments can always download their attachments. Download credits are deducted if configured.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to download |

**Response** _(JSON)_

Binary file content with appropriate headers

| Field | Type | Description |
|-------|------|-------------|
| `Content-Type` | string | MIME type of the file |
| `Content-Disposition` | string | Attachment header with filename |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File not found or not accessible |
| 404 | File areas feature is disabled |
| 403 | Insufficient download credits |

---

#### `GET /api/files/{id}/preview`

**Requires authentication**

Serves a file for in-browser preview without charging download credits. Supports images, video, audio, and text files. Unknown types are served as attachments. Allows unauthenticated access via valid file shares or public areas. No credit deduction occurs.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to preview |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `share_area` | string | No | File area tag for shared file access |
| `share_filename` | string | No | Filename for shared file access |

**Response** _(JSON)_

File content with inline Content-Disposition header

| Field | Type | Description |
|-------|------|-------------|
| `Content-Type` | string | MIME type (image/*, video/*, audio/*, text/*, etc.) |
| `Content-Disposition` | string | Inline header for browser preview |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File not found or not approved |
| 404 | File areas feature is disabled |
| 403 | Access denied to file area |

---

#### `GET /api/files/{id}/prgs`

**Requires authentication**

Extracts all PRG files from .prg, .zip, or .d64 archives and returns them as base64-encoded data with load addresses. Used by the file preview modal to render PETSCII art. The 2-byte PRG load address header is stripped before encoding. Allows unauthenticated access via valid file shares.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID (must be .prg, .zip, or .d64) |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `share_area` | string | No | File area tag for shared file access |
| `share_filename` | string | No | Filename for shared file access |

**Response** _(JSON)_

Extracted PRG files with metadata

| Field | Type | Description |
|-------|------|-------------|
| `prgs` | array | Array of PRG file objects |
| `prgs[].name` | string | PRG file name |
| `prgs[].load_address` | integer | C64 load address (decimal) |
| `prgs[].data_b64` | string | Base64-encoded PRG content (load address header stripped) |
| `disk_name` | string | Disk name (only present for .d64 files) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File not found or not approved |
| 404 | File areas feature is disabled |
| 403 | Access denied to file area |

---

#### `GET /api/files/{id}/zip-contents`

**Requires authentication**

Retrieves a list of all non-directory entries contained within a ZIP archive. Accessible to authenticated users, file owners via share links, or guests accessing public file areas. Returns entry metadata including path, name, and size. Requires the file to be approved and the file areas feature to be enabled.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID of the ZIP archive |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `share_area` | string | No | File area tag for share link access |
| `share_filename` | string | No | Filename for share link access |

**Response** _(JSON)_

JSON object containing array of ZIP entries

| Field | Type | Description |
|-------|------|-------------|
| `entries` | array | Array of ZIP entry objects |
| `entries[].path` | string | Full path within the archive |
| `entries[].name` | string | File name (basename) |
| `entries[].size` | integer | Uncompressed file size in bytes |
| `entries[].comp_method` | string | Compression method (e.g., deflate, store) |
| `total` | integer | Total number of entries in the archive |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required and no valid auth/share/public access provided |
| 404 | File not found, not approved, or feature disabled |

---

#### `GET /api/files/{id}/zip-entry`

**Requires authentication**

Extracts and serves a single file entry from within a ZIP archive. Applies content-type detection and encoding logic for known file types to enable inline preview; unknown types are served as attachments for download. Accessible via authentication, share links, or public file areas. The file must be approved.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID of the ZIP archive |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `path` | string | Yes | Path to the entry within the ZIP (e.g., 'subdir/file.txt') |
| `share_area` | string | No | File area tag for share link access |
| `share_filename` | string | No | Filename for share link access |

**Response** _(JSON)_

Raw file content with appropriate Content-Type header

| Field | Type | Description |
|-------|------|-------------|
| `body` | binary | File entry content |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required and no valid auth/share/public access provided |
| 404 | File not found, not approved, feature disabled, or entry not found in archive |

---

#### `GET /api/files/{id}/archive-contents`

**Requires authentication**

Retrieves a list of all entries from an archive file (ZIP, TAR, RAR, 7Z, etc.), auto-detected by magic bytes. Returns archive type, human-readable label, entry list, and total count. Accessible to authenticated users, share link holders, or guests on public file areas. File must be approved.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID of the archive |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `share_area` | string | No | File area tag for share link access |
| `share_filename` | string | No | Filename for share link access |

**Response** _(JSON)_

JSON object with archive metadata and entry list

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Archive format code (e.g., 'zip', 'tar', 'rar') |
| `label` | string | Human-readable archive type label |
| `entries` | array | Array of archive entry objects |
| `entries[].path` | string | Full path within the archive |
| `entries[].name` | string | File name (basename) |
| `entries[].size` | integer | Uncompressed file size in bytes |
| `entries[].comp_method` | string | Compression method (present for ZIP entries only) |
| `total` | integer | Total number of entries in the archive |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required and no valid auth/share/public access provided |
| 404 | File not found, not approved, feature disabled, or unsupported archive format |

---

#### `GET /api/files/{id}/archive-entry`

**Requires authentication**

Extracts and serves a single file entry from any supported archive format (auto-detected by magic bytes). Applies content-type detection for inline preview or download based on file type. Accessible via authentication, share links, or public file areas.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID of the archive |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `path` | string | Yes | Path to the entry within the archive |
| `share_area` | string | No | File area tag for share link access |
| `share_filename` | string | No | Filename for share link access |

**Response** _(JSON)_

Raw file content with appropriate Content-Type header

| Field | Type | Description |
|-------|------|-------------|
| `body` | binary | File entry content |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required and no valid auth/share/public access provided |
| 404 | File not found, not approved, feature disabled, or entry not found in archive |

---

#### `POST /api/files/{id}/share`

**Requires authentication**

Generates a shareable link for a file, allowing unauthenticated access. If a share already exists, returns the existing share. Supports optional expiration in hours and frequency-accessible flag. Returns share metadata including ID and access tracking info.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to share |

**Request Body** _(JSON)_

Share creation parameters

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `expires_hours` | integer | No | Hours until share expires; null for no expiration |
| `freq_accessible` | boolean | No | Whether share is frequently accessible (default: true) |

**Response** _(JSON)_

Share creation result with share details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success status |
| `share_id` | integer | ID of the created or existing share |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid file ID, file not found, or access denied |
| 404 | Feature disabled |

---

#### `GET /api/files/shared/check/{fileId}`

**Requires authentication**

Verifies whether the authenticated user has an active share link for a specific file. Returns the share URL in area/filename format, access count, last access timestamp, and revocation permission. Useful for UI to show existing shares.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `fileId` | integer | File ID to check for shares |

**Response** _(JSON)_

Share existence and details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Query success status |
| `exists` | boolean | Whether a share exists (if false, other fields omitted) |
| `share_id` | integer | Share ID (if exists) |
| `share_url` | string | Full share URL (if exists) |
| `access_count` | integer | Number of times share has been accessed |
| `last_accessed_at` | string | ISO timestamp of last access |
| `can_revoke` | boolean | Whether user can revoke this share |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Feature disabled |

---

#### `GET /api/files/shared/{area}/{filename}`

**Requires authentication**

Retrieves metadata for a shared file using its file area tag and filename. No authentication required for accessing shared files. Returns file details if the share is active and valid. Used by share link endpoints to serve files.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `area` | string | File area tag (URL-encoded) |
| `filename` | string | Filename (URL-encoded) |

**Response** _(JSON)_

Shared file metadata

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Query success status |
| `file` | object | File details |
| `file.id` | integer | File ID |
| `file.filename` | string | File name |
| `file.filesize` | integer | File size in bytes |
| `file.short_description` | string | Brief description |
| `file.long_description` | string | Extended description |
| `file.created_at` | string | Upload timestamp (ISO 8601) |
| `file.virus_scanned` | boolean | Whether virus scan was performed |
| `file.virus_scan_result` | string\|null | Scan result if scanned |
| `file.file_area_id` | integer | Associated file area ID |
| `file.area_tag` | string | Tag of the file area |
| `file.area_description` | string | Description of the file area |
| `file.domain` | string | Domain of the file area |
| `share_info` | object | Share metadata |
| `share_info.share_id` | integer | Share record ID |
| `share_info.shared_by` | string | Username of the user who created the share |
| `share_info.created_at` | string | Share creation timestamp (ISO 8601) |
| `share_info.expires_at` | string\|null | Share expiry timestamp; null for no expiry |
| `share_info.access_count` | integer | Number of times the share has been accessed |
| `share_info.share_url` | string | Full URL of the share link |
| `share_info.is_logged_in` | boolean | Whether the requesting user is authenticated |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Share not found, expired, or feature disabled |

---

#### `DELETE /api/files/shares/{shareId}`

**Requires authentication**

Deletes a share link, preventing further access via that share. Only the share creator or admins can revoke. Returns success confirmation with localized message code.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `shareId` | integer | Share ID to revoke |

**Response** _(JSON)_

Revocation result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Revocation success status |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Share not found or user lacks permission to revoke |

---

#### `POST /api/files/upload`

**Requires authentication**

Accepts multipart form data to upload a file to a specified file area. Requires file_area_id, short_description, and optionally long_description. Validates file area access permissions, upload permissions (read-only areas rejected), and user quotas. May deduct upload costs from user account if configured. Returns file metadata including ID, hash, and size on success.

**Request Body** _(JSON)_

Multipart form data with file and metadata

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes | Binary file to upload |
| `file_area_id` | integer | Yes | Target file area ID |
| `short_description` | string | Yes | Brief file description (max 255 chars) |
| `long_description` | string | No | Extended description |

**Response** _(JSON)_

Uploaded file metadata and transaction details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `file_id` | integer | ID of uploaded file |
| `filename` | string | Stored filename |
| `file_hash` | string | SHA-256 hash of file |
| `file_size` | integer | File size in bytes |
| `upload_cost_charged` | boolean | Whether cost was deducted |
| `upload_cost` | integer | Cost deducted (if any) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled |
| 400 | Missing required fields or invalid file area ID |
| 403 | Access denied, read-only area, or quota exceeded |
| 413 | File too large |

---

#### `POST /api/files/add-link`

**Requires authentication**

Creates a file entry pointing to an external URL instead of uploading binary data. Requires file_area_id, valid URL, and short_description. Validates file area access and upload permissions. Optionally deducts link-creation costs. URL must pass FILTER_VALIDATE_URL validation.

**Request Body** _(JSON)_

JSON object with link metadata

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file_area_id` | integer | Yes | Target file area ID |
| `url` | string | Yes | External URL (must be valid) |
| `file_name` | string | No | Display name for link |
| `short_description` | string | Yes | Brief description (max 255 chars) |
| `long_description` | string | No | Extended description |

**Response** _(JSON)_

Created link entry metadata

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `file_id` | integer | ID of created link entry |
| `url` | string | Stored URL |
| `upload_cost_charged` | boolean | Whether cost was deducted |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled or file area not found |
| 400 | Invalid URL or missing required fields |
| 403 | Access denied or read-only area |

---

#### `POST /api/files/fetch-url-meta`

**Requires authentication**

Server-side metadata scraper to avoid CORS issues. Extracts page title (short_description) and og:description (long_description) from HTML. Special handling for YouTube via oEmbed API. Returns empty strings if metadata unavailable. Timeout: 8 seconds per request.

**Request Body** _(JSON)_

JSON object with URL

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `url` | string | Yes | URL to fetch metadata from |

**Response** _(JSON)_

Extracted metadata from URL

| Field | Type | Description |
|-------|------|-------------|
| `short_description` | string | Page title (max 255 chars) |
| `long_description` | string | og:description or meta description |
| `og_image_url` | string | og:image URL if available |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled |
| 400 | Invalid or missing URL |

---

#### `DELETE /api/files/{id}/delete`

**Requires authentication**

Removes a file entry and associated data. Owner or admin required. ISO-backed files (source_type='iso_import') cannot be deleted by non-admins. Returns success message on deletion.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to delete |

**Response** _(JSON)_

Deletion confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Deletion successful |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled |
| 403 | Access denied or ISO-backed file cannot be deleted |

---

#### `PUT /api/files/{id}/rename`

**Requires authentication**

Updates file metadata. filename, short_description, long_description, url, and file_area_id are all optional; omit to skip update. If provided, filename and short_description must be non-empty. Only admins may move files (file_area_id). ISO-backed files cannot be renamed or moved, but descriptions may be edited.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to edit |

**Request Body** _(JSON)_

JSON object with optional fields to update

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `filename` | string | No | New filename (non-empty if provided) |
| `short_description` | string | No | New short description (non-empty if provided) |
| `long_description` | string | No | New long description (empty string clears it) |
| `url` | string | No | New URL for link entries |
| `file_area_id` | integer | No | Move to different area (admin only) |

**Response** _(JSON)_

Update confirmation with the fields that were changed

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Update successful |
| `filename` | string | New filename (only present if filename was updated) |
| `short_description` | string | New short description (only present if description was updated) |
| `long_description` | string\|null | New long description (only present if description was updated) |
| `file_area_id` | integer | New file area ID (only present if file was moved; admin only) |
| `url` | string | New URL (only present if URL was updated; admin only) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled or file not found |
| 400 | Filename or short_description empty when provided |
| 403 | Access denied, non-admin move attempt, or ISO-backed file rename/move |

---

#### `POST /api/files/{id}/scan`

**Requires authentication**

Initiates asynchronous virus scan via admin daemon. Admin-only endpoint. Returns scan result (clean/infected), signature if infected, and scanned flag. Requires VIRUS_SCAN_DISABLED != 'true' in config.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to scan |

**Response** _(JSON)_

Scan result from ClamAV

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Scan initiated successfully |
| `result` | string | Scan result: 'clean', 'infected', or null |
| `signature` | string | Malware signature if infected |
| `scanned` | boolean | Whether scan completed |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled |
| 403 | Admin access required or virus scanning disabled |
| 500 | Scan daemon communication failed |

---

#### `PUT /api/files/{id}/scan-status`

**Requires authentication**

Sets scan status to not_scanned, clean, or infected without running ClamAV. Admin-only. Optionally stores signature for infected status. Updates virus_scanned, virus_scan_result, virus_signature, and virus_scanned_at fields.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to update |

**Request Body** _(JSON)_

JSON object with scan status override

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `status` | string | Yes | One of: 'not_scanned', 'clean', 'infected' |
| `signature` | string | No | Malware signature (used if status='infected') |

**Response** _(JSON)_

Status update confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Status updated successfully |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File areas feature disabled or file not found |
| 400 | Invalid scan status value |
| 403 | Admin access required |

---

#### `GET /api/files/{id}/comments`

**Requires authentication**

Retrieves all comments for a file via its file area's linked comment echoarea. Uses FILEREF kludge (new and legacy formats) and subject matching to build thread tree. Returns empty array if no comment echoarea linked. Includes from_name, subject, message_text, and date_written for each comment.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID to fetch comments for |

**Response** _(JSON)_

Threaded comment messages

| Field | Type | Description |
|-------|------|-------------|
| `enabled` | boolean | Whether comments are enabled for this file |
| `comments` | array | Threaded tree of top-level comment objects |
| `comments[].id` | integer | Echomail message ID |
| `comments[].from_name` | string | Name of the commenter |
| `comments[].date_written` | string | Message timestamp (ISO 8601) |
| `comments[].body` | string | Comment text (tearline stripped) |
| `comments[].level` | integer | Nesting depth (0 = top-level, max 2) |
| `comments[].children` | array | Nested reply objects (same structure, empty at level 2) |
| `total` | integer | Total comment count (flat, across all levels) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | File not found or file areas feature disabled |

---

#### `POST /api/files/{id}/comments`

**Requires authentication**

Creates a comment on a file in its linked comment echo area. If no comment thread exists, one is created automatically. The file area must have a comment echo area configured. Respects sysop-only restrictions on the comment area. Supports optional reply threading via reply_to_id.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | File ID |

**Request Body** _(JSON)_

Comment data

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `body` | string | Yes | Comment text (non-empty) |
| `reply_to_id` | integer | No | ID of message to reply to within the comment thread |

**Response** _(JSON)_

Comment creation result with message details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success status |
| `message_id` | integer | ID of created comment message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Comment body is required or empty |
| 403 | Comments not enabled for file area, or user lacks permission (sysop-only area) |
| 404 | File not found |

---

### Freq Log

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/admin/api/freq-log`](#get-adminapifreq-log) | Yes | Query file request frequency log with filtering. |

#### `GET /admin/api/freq-log`

**Requires authentication**

Paginated admin endpoint for viewing file request logs. Supports filtering by requesting node, filename, served status, and source. Returns paginated results with total count. Requires admin authentication.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number (default 1) |
| `node` | string | No | Filter by requesting node (partial match) |
| `filename` | string | No | Filter by filename (partial match) |
| `served` | string | No | Filter by served status ('0' or '1') |
| `source` | string | No | Filter by request source |

**Response** _(JSON)_

Paginated frequency log entries

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success indicator |
| `entries` | array | Log entries |
| `entries[].id` | integer | Log entry ID |
| `entries[].requested_at` | string | ISO 8601 timestamp of the request |
| `entries[].requesting_node` | string | FTN address of the requesting node |
| `entries[].filename` | string | Filename that was requested |
| `entries[].served` | boolean | Whether the file was served |
| `entries[].deny_reason` | string\|null | Reason for denial (if not served) |
| `entries[].file_size` | integer\|null | Size of the served file in bytes |
| `entries[].source` | string | Source of the file request |
| `total` | integer | Total matching entries across all pages |
| `page` | integer | Current page number |
| `per_page` | integer | Entries per page (50) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin privileges required |

---

### I18n

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/i18n/catalog`](#get-apii18ncatalog) | Yes | Fetch i18n translation catalogs for specified namespaces. |

#### `GET /api/i18n/catalog`

**Requires authentication**

Returns localized translation catalogs for one or more namespaces. Supports lazy loading of specific namespaces and locale resolution based on query parameter, user preferences, or system default. Persists resolved locale for the session.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `locale` | string | No | Requested locale code (e.g., 'en', 'de'). Falls back to user preference or system default if not provided |
| `ns` | string | No | Comma-separated list of namespace names to load (default: 'common'). Example: 'common,errors,admin' |

**Response** _(JSON)_

Localized translation catalogs

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether catalogs were successfully loaded |
| `locale` | string | The resolved locale code used for translations |
| `default_locale` | string | The system default locale |
| `catalogs` | object | Object keyed by namespace name (e.g. `common`, `errors`); each value is an object mapping translation keys to their localized string values |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load translation catalogs |

---

### Interests

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/interests/`](#get-apiinterests) | No | Retrieve all active interests with subscription status. |
| `POST` | [`/api/interests/{id}/subscribe`](#post-apiinterestsidsubscribe) | Yes | Subscribe authenticated user to an interest with optional echo area selection. |
| `POST` | [`/api/interests/{id}/unsubscribe`](#post-apiinterestsidunsubscribe) | Yes | Unsubscribe authenticated user from an interest or specific echo areas. |
| `POST` | [`/api/interests/{id}/manage-areas`](#post-apiinterestsidmanage-areas) | Yes | Replace user's subscribed echo areas within an interest. |
| `GET` | [`/api/interests/{id}/echoareas`](#get-apiinterestsidechoareas) | No | List echo areas belonging to an interest with optional subscription status. |
| `GET` | [`/api/interests/{id}/stats`](#get-apiinterestsidstats) | Yes | Get message statistics for an interest's echo areas. |
| `GET` | [`/api/interests/{id}/messages`](#get-apiinterestsidmessages) | Yes | Get paginated echomail messages from an interest's echo areas. |

#### `GET /api/interests/`

Public

Returns a list of all active interests. When the request is authenticated, each interest includes a `subscribed` boolean indicating whether the current user is subscribed. When unauthenticated, all interests have `subscribed: false`. Feature can be disabled via ENABLE_INTERESTS environment variable.

**Response** _(JSON)_

List of active interests

| Field | Type | Description |
|-------|------|-------------|
| `interests` | array | Array of interest objects with subscription status |
| `interests[].id` | integer | Interest ID |
| `interests[].name` | string | Interest name |
| `interests[].slug` | string | URL-safe slug |
| `interests[].description` | string\|null | Interest description |
| `interests[].sort_order` | integer | Display sort order |
| `interests[].is_active` | boolean | Whether the interest is active |
| `interests[].echoarea_count` | integer | Number of echo areas in this interest |
| `interests[].filearea_count` | integer | Number of file areas in this interest |
| `interests[].subscriber_count` | integer | Number of subscribers |
| `interests[].subscribed` | boolean | True if the authenticated user is subscribed |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interests feature is disabled (ENABLE_INTERESTS != 'true') |

---

#### `POST /api/interests/{id}/subscribe`

**Requires authentication**

Subscribes the authenticated user to an interest. If `echoarea_ids` array is provided in the request body, subscribes only to those specific echo areas within the interest; otherwise subscribes to all echo areas. Requires the interests feature to be enabled. Returns success status and subscription confirmation.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Request Body** _(JSON)_

Optional echo area selection

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `echoarea_ids` | integer[] | No | Array of echo area IDs to subscribe to within this interest. If omitted, subscribes to all areas. |

**Response** _(JSON)_

Subscription confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `subscribed` | boolean | User is now subscribed to the interest |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found or interests feature disabled |

---

#### `POST /api/interests/{id}/unsubscribe`

**Requires authentication**

Unsubscribes the authenticated user from an interest. If `echoarea_ids` array is provided, removes subscription only from those specific echo areas; otherwise removes all subscriptions to the interest. Returns success status and whether user remains subscribed to any areas in the interest.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Request Body** _(JSON)_

Optional selective unsubscription

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `echoarea_ids` | integer[] | No | Array of echo area IDs to unsubscribe from. If omitted, unsubscribes from all areas. |

**Response** _(JSON)_

Unsubscription confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `subscribed` | boolean | User still has active subscriptions in this interest |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found or interests feature disabled |

---

#### `POST /api/interests/{id}/manage-areas`

**Requires authentication**

Replaces the user's entire set of subscribed echo areas for an interest with the provided list. Passing an empty array fully unsubscribes the user from the interest. This is an atomic replace operation, not additive.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Request Body** _(JSON)_

New set of echo area subscriptions

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `wanted_echoarea_ids` | integer[] | Yes | Array of echo area IDs to subscribe to. Empty array unsubscribes from the interest entirely. |

**Response** _(JSON)_

Management confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `subscribed` | boolean | User still has active subscriptions in this interest |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found or interests feature disabled |

---

#### `GET /api/interests/{id}/echoareas`

Public

Returns all echo areas associated with an interest, including tag, domain, description, and message count. If authenticated, includes a `subscribed` boolean for each area indicating the user's subscription status. Public endpoint respecting the interests feature flag.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Response** _(JSON)_

List of echo areas in the interest

| Field | Type | Description |
|-------|------|-------------|
| `echoareas` | object[] | Array of echo area objects |
| `echoareas[].echoarea_id` | integer | Echo area ID |
| `echoareas[].tag` | string | Echo area tag |
| `echoareas[].domain` | string | Network domain |
| `echoareas[].description` | string\|null | Echo area description |
| `echoareas[].message_count` | integer | Total message count |
| `echoareas[].subscribed` | boolean | _(authenticated only)_ Whether the user is subscribed to this area |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found or interests feature disabled |

---

#### `GET /api/interests/{id}/stats`

**Requires authentication**

Returns aggregated message counts across all echo areas in an interest that the user is subscribed to. Includes total, recent (last 24h), unread, area count, and filter-specific counts (all, unread, read, to_me, saved, drafts). Respects sysop-only area restrictions for non-admin users.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Response** _(JSON)_

Aggregated message statistics

| Field | Type | Description |
|-------|------|-------------|
| `total` | integer | Total messages in subscribed areas |
| `recent` | integer | Messages from last 24 hours |
| `unread` | integer | Unread messages for user |
| `areas` | integer | Number of subscribed echo areas |
| `filter_counts` | object | Counts by filter |
| `filter_counts.all` | integer | Total messages |
| `filter_counts.unread` | integer | Unread messages |
| `filter_counts.read` | integer | Read messages |
| `filter_counts.tome` | integer | Messages addressed to me |
| `filter_counts.saved` | integer | Saved messages |
| `filter_counts.drafts` | integer | Draft messages |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found, inactive, or user has no subscriptions |

---

#### `GET /api/interests/{id}/messages`

**Requires authentication**

Returns paginated echomail messages from all echo areas belonging to the interest that the user is subscribed to. Supports sorting (date_desc, date_asc, subject, author) and filtering (all, unread, read, tome, saved, drafts). Pagination defaults to page 1.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Interest ID |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number (default: 1) |
| `sort` | string | No | Sort order: date_desc, date_asc, subject, author (default: date_desc) |
| `filter` | string | No | Message filter: all, unread, read, tome, saved, drafts (default: all) |

**Response** _(JSON)_

Paginated message results

| Field | Type | Description |
|-------|------|-------------|
| `messages` | object[] | Array of echomail message objects (same shape as `GET /api/messages/echomail`) |
| `pagination` | object | Pagination metadata |
| `pagination.page` | integer | Current page number |
| `pagination.limit` | integer | Messages per page |
| `pagination.total` | integer | Total matching messages |
| `pagination.pages` | integer | Total number of pages |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Interest not found, inactive, or interests feature disabled |

---

### Markdown Images

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/markdown-images`](#get-apimarkdown-images) | Yes | List markdown images uploaded by the authenticated user. |
| `POST` | [`/api/markdown-images`](#post-apimarkdown-images) | Yes | Upload a markdown image for the authenticated user. |

#### `GET /api/markdown-images`

**Requires authentication**

Retrieves all markdown images associated with the authenticated user. Returns image metadata including filename, accessible URL, and creation timestamp. URLs are constructed using either a user-specific slug or file hash. Requires authentication.

**Response** _(JSON)_

JSON object containing success flag and array of image objects.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `images` | array | Array of image objects |
| `images[].filename` | string | Original filename |
| `images[].url` | string | Public URL for embedding in markdown |
| `images[].created_at` | string | ISO 8601 upload timestamp |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load images from storage |

---

#### `POST /api/markdown-images`

**Requires authentication**

Accepts multipart image upload (JPEG, PNG, GIF, WebP) up to 5MB (configurable). Stores image and generates a user-specific URL slug. Returns the public URL for embedding in markdown. Validates MIME type and file size before storage. Requires authentication.

**Request Body** _(JSON)_

Multipart form data with image file.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `image` | file | Yes | Image file (JPEG, PNG, GIF, or WebP) |

**Response** _(JSON)_

JSON object with upload success, public URL, and original filename.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if upload succeeded |
| `url` | string | Public URL for accessing the uploaded image |
| `filename` | string | Original filename as provided by client |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Upload failed, unsupported MIME type, or file exceeds size limit |
| 500 | Failed to store image to filesystem |

---

### Media

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/media/raw`](#get-apimediaraw) | No | Proxy and stream raw media files from external URLs. |
| `GET` | [`/api/media/embed`](#get-apimediaembed) | No | Resolve media URLs to embeddable HTML for supported providers. |

#### `GET /api/media/raw`

Public

Fetches and streams audio/music files (XM, IT, S3M, MOD, SID, MIDI, etc.) from external public URLs via CURL. Validates URL scheme (HTTP/HTTPS), file extension, and host to prevent SSRF attacks. Enforces 8MB size limit. Returns 404 if URL is invalid or media unavailable.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | Yes | Public HTTP(S) URL to media file with allowed extension |

**Response** _(binary)_

Raw proxied media file bytes

| Header | Value |
|--------|-------|
| `Content-Type` | MIME type reported by the upstream server (e.g. `audio/x-mod`, `application/octet-stream`) |
| `Content-Length` | File size in bytes |
| `Content-Disposition` | `inline; filename="<basename>"` |
| `Cache-Control` | `public, max-age=86400` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Invalid URL, disallowed extension, private host, or media not found |

---

#### `GET /api/media/embed`

Public

Detects media provider (YouTube, Vimeo, etc.) from URL and returns embed HTML if provider is enabled. Respects global media player configuration and per-provider settings. Returns unknown type with empty embed_html if URL is invalid, provider disabled, or resolution fails. No authentication required.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | Yes | HTTP(S) URL to resolve for media embedding |

**Response** _(JSON)_

JSON object with media type, provider name, and embed HTML.

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | Media type (e.g., 'video', 'audio') or 'unknown' |
| `provider` | string|null | Provider name (e.g., 'youtube') or null if unrecognized |
| `embed_html` | string | HTML embed code or empty string if unavailable |

---

### MeshCore

Bridge-facing endpoints authenticated with a per-node Bearer token (`Authorization: Bearer <api_key>`).

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/meshcore/contact`](#post-apimeshcorecontact) | Bearer | Report a companion contact from a MeshCore bridge. |
| `GET` | [`/api/meshcore/pending-commands`](#get-apimeshocorepending-commands) | Bearer | Poll for device commands queued for this bridge (e.g. remove_contact). |
| `POST` | [`/api/meshcore/commands/{id}/ack`](#post-apimeshcorecommandsidack) | Bearer | Acknowledge that a device command has been sent to the radio. |

---

#### `POST /api/meshcore/contact`

**Requires Bearer token** (packet-BBS node API key)

Called by the MeshCore bridge when a stored companion contact is received from the radio. Creates or updates a `meshcore_contacts` row. If a user has already registered a prefix-only contact matching this key, that row is claimed and updated with the full key.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `pub_key_hex` | string | Yes | Full 64-char lowercase hex public key |
| `bridge_node_id` | string | Yes | Bridge node ID (from `SelfInfo`) |
| `name` | string | No | Contact name from radio |
| `adv_type` | string | No | Advertisement type |
| `latitude` | float\|null | No | GPS latitude |
| `longitude` | float\|null | No | GPS longitude |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `id` | integer | Contact record ID |
| `action` | string | `inserted`, `updated`, or `claimed` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing or invalid `pub_key_hex` |
| 401 | Missing or invalid Bearer token |

---

#### `GET /api/meshcore/pending-commands`

**Requires Bearer token** (packet-BBS node API key)

Returns unexecuted device commands queued for this bridge node. The bridge polls this endpoint on the same interval as pending messages and executes each command against the radio.

**Query Parameters**

| Parameter | Required | Description |
|-----------|----------|-------------|
| `bridge_node_id` | Yes | Full 64-char hex public key of the bridge node |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `commands` | array | List of pending command objects |
| `commands[].id` | integer | Command record ID (used for ACK) |
| `commands[].command_type` | string | Command type, e.g. `remove_contact` |
| `commands[].payload` | object | Command-specific data (see below) |

**`remove_contact` payload fields**

| Field | Type | Description |
|-------|------|-------------|
| `pub_key_full` | string | Full 64-char hex public key of the contact to remove |

---

#### `POST /api/meshcore/commands/{id}/ack`

**Requires Bearer token** (packet-BBS node API key)

Marks a device command as executed. The bridge calls this after dispatching the command to the radio, regardless of whether the radio acknowledged it.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `bridge_node_id` | string | Yes | Full 64-char hex public key of the bridge node |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if the command was found and marked executed |

---

### Messages

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/messages/recent`](#get-apimessagesrecent) | Yes | Retrieve recent netmail and echomail messages for the user. |
| `GET` | [`/api/messages/netmail`](#get-apimessagesnetmail) | Yes | Retrieve paginated netmail messages for the authenticated user. |
| `GET` | [`/api/messages/netmail/stats`](#get-apimessagesnetmailstats) | Yes | Get netmail statistics (total and unread message counts). |
| `GET` | [`/api/messages/netmail/{id}`](#get-apimessagesnetmailid) | Yes | Retrieve a single netmail message by ID with full details. |
| `GET` | [`/api/messages/netmail/{id}/conversation`](#get-apimessagesnetmailidconversation) | Yes | Retrieve a conversation thread containing a specific netmail message. |
| `DELETE` | [`/api/messages/netmail/{id}`](#delete-apimessagesnetmailid) | Yes | Delete a netmail message by ID. |
| `GET` | [`/api/messages/netmail/{id}/download`](#get-apimessagesnetmailiddownload) | Yes | Download a netmail message as a plain text file with headers. |
| `POST` | [`/api/messages/netmail/{id}/edit`](#post-apimessagesnetmailidedit) | Yes | Edit netmail message metadata (art format, charset). |
| `POST` | [`/api/messages/netmail/bulk-delete`](#post-apimessagesnetmailbulk-delete) | Yes | Delete multiple netmail messages in bulk. |
| `POST` | [`/api/messages/netmail/read`](#post-apimessagesnetmailread) | Yes | Mark multiple netmail messages as read in bulk. |
| `GET` | [`/api/messages/echomail`](#get-apimessagesechomail) | Yes | List echomail messages from subscribed areas with filtering. |
| `POST` | [`/api/messages/echomail/read`](#post-apimessagesechomailread) | Yes | Mark multiple echomail messages as read in bulk. |
| `POST` | [`/api/messages/echomail/delete`](#post-apimessagesechomaildelete) | Yes | Delete multiple echomail messages (admin only). |
| `POST` | [`/api/messages/echomail/ignore-rules`](#post-apimessagesechomailignore-rules) | Yes | Create an echomail ignore rule for the authenticated user. |
| `GET` | [`/api/messages/echomail/stats`](#get-apimessagesechomailstats) | Yes | Get aggregate echomail statistics for all areas. |
| `GET` | [`/api/messages/echomail/stats/{echoarea}`](#get-apimessagesechomailstatsechoarea) | Yes | Get echomail statistics for a specific echo area. |
| `GET` | [`/api/messages/echomail/message/{id}`](#get-apimessagesechomailmessageid) | Yes | Retrieve a specific echomail message by ID. |
| `GET` | [`/api/messages/echomail/message/{id}/conversation`](#get-apimessagesechomailmessageidconversation) | Yes | Get conversation thread for an echomail message. |
| `POST` | [`/api/messages/echomail/{id}/save-ad`](#post-apimessagesechomailidsave-ad) | Yes | Save an ANSI echomail message to the ad library (admin only). |
| `GET` | [`/api/messages/echomail/{id}/download`](#get-apimessagesechomailiddownload) | Yes | Download an echomail message as a text file. |
| `POST` | [`/api/messages/echomail/{id}/edit`](#post-apimessagesechomailidedit) | Yes | Edit echomail message metadata (admin only). |
| `GET` | [`/api/messages/echomail/{echoarea}`](#get-apimessagesechomailechoarea) | Yes | Retrieve echomail messages from a specific echo area with pagination and filtering. |
| `GET` | [`/api/messages/echomail/{echoarea}/{id}`](#get-apimessagesechomailechoareaid) | Yes | Retrieve a single echomail message by ID with full content and metadata. |
| `POST` | [`/api/messages/send`](#post-apimessagessend) | Yes | Send a netmail or echomail message with optional attachments and formatting. |
| `GET` | [`/api/messages/markdown-support`](#get-apimessagesmarkdown-support) | Yes | Check markdown support and posting name policy for a destination. |
| `POST` | [`/api/messages/markdown-preview`](#post-apimessagesmarkdown-preview) | Yes | Render markdown text to HTML for preview in compose UI. |
| `POST` | [`/api/messages/draft`](#post-apimessagesdraft) | Yes | Save a message draft for later completion and sending. |
| `GET` | [`/api/messages/drafts`](#get-apimessagesdrafts) | Yes | Retrieve authenticated user's draft messages. |
| `GET` | [`/api/messages/drafts/{id}`](#get-apimessagesdraftsid) | Yes | Retrieve a specific draft message by ID. |
| `DELETE` | [`/api/messages/drafts/{id}`](#delete-apimessagesdraftsid) | Yes | Delete a draft message. |
| `GET` | [`/api/messages/templates`](#get-apimessagestemplates) | Yes | List message templates for authenticated user. |
| `GET` | [`/api/messages/templates/{id}`](#get-apimessagestemplatesid) | Yes | Retrieve a single message template with full body. |
| `POST` | [`/api/messages/templates`](#post-apimessagestemplates) | Yes | Create or update a message template. |
| `DELETE` | [`/api/messages/templates/{id}`](#delete-apimessagestemplatesid) | Yes | Delete a message template. |
| `GET` | [`/api/messages/search`](#get-apimessagessearch) | Yes | Search messages with optional field-specific and date filters. |
| `POST` | [`/api/messages/{type}/{id}/read`](#post-apimessagestypeidread) | Yes | Mark a message as read for the authenticated user. |
| `POST` | [`/api/messages/{type}/{id}/save`](#post-apimessagestypeidsave) | Yes | Save a message for later viewing. |
| `DELETE` | [`/api/messages/{type}/{id}/save`](#delete-apimessagestypeidsave) | Yes | Remove a message from the authenticated user's saved collection. |
| `POST` | [`/api/messages/{type}/{id}/forward-email`](#post-apimessagestypeidforward-email) | Yes | Forward a message to user's email address. |
| `GET` | [`/api/messages/echomail/delete-test`](#get-apimessagesechomaildelete-test) | No | Test endpoint for message delete functionality. |
| `POST` | [`/api/messages/echomail/{id}/share`](#post-apimessagesechomailidshare) | Yes | Create a share link for an echomail message. |
| `GET` | [`/api/messages/echomail/{id}/shares`](#get-apimessagesechomailidshares) | Yes | List share links for an echomail message. |
| `DELETE` | [`/api/messages/echomail/{id}/share`](#delete-apimessagesechomailidshare) | Yes | Revoke a shared echomail message link. |
| `POST` | [`/api/messages/echomail/{id}/share/friendly-url`](#post-apimessagesechomailidsharefriendly-url) | Yes | Generate a friendly URL slug for an existing message share. |
| `POST` | [`/api/messages/echomail/{id}/share/image`](#post-apimessagesechomailidsharimage) | Yes | Upload an OG preview image for an existing message share. |
| `DELETE` | [`/api/messages/echomail/{id}/share/image`](#delete-apimessagesechomailidsharimage) | Yes | Remove the OG preview image from an existing message share. |
| `POST` | [`/api/messages/echomail/{id}/share-summary`](#post-apimessagesechomailidshare-summary) | Yes | Generate an AI summary for a shared echomail message. |
| `GET` | [`/api/messages/shared/{area}/{slug}`](#get-apimessagessharedareaslug) | Yes | Retrieve a shared echomail message by friendly URL slug. |
| `GET` | [`/api/messages/shared/{shareKey}`](#get-apimessagessharedsharekey) | Yes | Retrieve a shared message by share key. |
| `POST` | [`/api/messages/ai-assist`](#post-apimessagesai-assist) | Yes | Generate AI-assisted response for echomail or netmail messages. |

#### `GET /api/messages/recent`

**Requires authentication**

Fetches the 10 most recent messages for the authenticated user, combining netmail (direct messages) and echomail (echo area messages). Only includes echomail from areas the user is subscribed to. Results are ordered by date written (newest first). Includes echoarea tag and color for echomail messages.

**Response** _(JSON)_

JSON object containing array of recent messages

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array of objects | Array of recent message objects |
| `messages[].id` | integer | Message ID |
| `messages[].type` | string | Message type: `netmail` or `echomail` |
| `messages[].from_name` | string | Sender display name |
| `messages[].subject` | string | Message subject |
| `messages[].date_written` | string | ISO 8601 date the message was composed |
| `messages[].echoarea` | string\|null | Echo area tag (null for netmail) |
| `messages[].echoarea_color` | string\|null | Echo area display colour (null for netmail) |

---

#### `GET /api/messages/netmail`

**Requires authentication**

Fetches netmail messages with support for pagination, filtering, sorting, and optional thread grouping. Supports multiple sort orders (date_desc, date_asc, subject, author) and filters (all, unread, etc.). Returns localized error messages.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number (default: 1) |
| `filter` | string | No | Filter type, e.g. 'all', 'unread' (default: 'all') |
| `threaded` | boolean | No | Group messages by thread (default: false) |
| `sort` | string | No | Sort order: date_desc, date_asc, subject, author (default: date_desc) |

**Response** _(JSON)_

Paginated netmail message list

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Array of netmail message objects (see shape below) |
| `pagination.page` | integer | Current page number |
| `pagination.limit` | integer | Messages per page (from user setting, default 25) |
| `pagination.total` | integer | Total message count matching the current filter |
| `pagination.pages` | integer | Total number of pages |

**Netmail object**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Message ID |
| `from_name` | string | Sender display name (UTF-8 normalized) |
| `from_address` | string | Sender FidoNet address (e.g. `1:123/456`) |
| `to_name` | string | Recipient display name |
| `to_address` | string | Recipient FidoNet address |
| `subject` | string | Message subject; masked as `"••••••••"` for AreaFix/FileFix robot messages |
| `date_received` | string | UTC timestamp when the message was stored server-side — reliable for display and sorting |
| `date_written` | string | Timestamp from the FTN packet header — reflects when the sender composed the message; may be wrong or in the future if the remote clock is incorrect |
| `user_id` | integer | ID of the local user who owns (sent or received) this message |
| `attributes` | integer | FTN message attribute bitmask (FTS-0001) |
| `is_sent` | boolean | True if this message was sent by the local system |
| `is_freq` | boolean | True if this is a file-request message |
| `reply_to_id` | integer\|null | ID of the message this is a reply to, or null |
| `is_read` | integer | `1` if the authenticated user has read this message, `0` otherwise |
| `has_attachment` | integer | `1` if one or more file attachments exist for this message, `0` otherwise |
| `is_saved` | integer | `1` if the authenticated user has saved this message, `0` otherwise |
| `replyto_address` | string\|null | FidoNet address parsed from the `REPLYTO` kludge, if present |
| `replyto_name` | string\|null | Recipient name parsed from the `REPLYTO` kludge, if present |
| `from_domain` | string\|null | FTN domain name resolved from `from_address`, or null if unresolvable |
| `to_domain` | string\|null | FTN domain name resolved from `to_address`, or null if unresolvable |

---

#### `GET /api/messages/netmail/stats`

**Requires authentication**

Returns aggregate netmail statistics for the authenticated user, including total messages and unread count. Accounts for both received messages and sent messages (via system address). Uses message_read_status table to track read state. Handles cases where FidoNet address configuration is unavailable.

**Response** _(JSON)_

Netmail statistics

| Field | Type | Description |
|-------|------|-------------|
| `total` | integer | Total netmail messages (received + sent) |
| `unread` | integer | Count of unread messages |

---

#### `GET /api/messages/netmail/{id}`

**Requires authentication**

Fetches a complete netmail message including kludge lines, REPLYTO header parsing, file attachments (if enabled), and edit permissions. Marks message as read via activity tracking. Includes parsed reply-to address and name extracted from message headers.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Netmail message ID |

**Response** _(JSON)_

Complete netmail message with metadata

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Message ID |
| `message_text` | string | Message body |
| `kludge_lines` | string | FidoNet kludge lines |
| `replyto_address` | string | Parsed REPLYTO FidoNet address |
| `replyto_name` | string | Parsed REPLYTO recipient name |
| `attachments` | array of objects | File attachments (empty array if feature disabled) |
| `attachments[].id` | integer | File record ID |
| `attachments[].filename` | string | Original filename |
| `attachments[].filesize` | integer | File size in bytes |
| `attachments[].short_description` | string\|null | Short file description |
| `attachments[].long_description` | string\|null | Extended file description |
| `attachments[].source_type` | string | Origin: `netmail_attachment`, `user`, or `fidonet` |
| `attachments[].status` | string | Approval status: `approved`, `pending`, `rejected`, or `quarantined` |
| `attachments[].created_at` | string | UTC timestamp when the file was stored |
| `attachments[].area_tag` | string | File area tag the attachment belongs to |
| `attachments[].is_private` | boolean | True if the file area is private |
| `can_edit` | boolean | Whether current user can edit this message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found or user lacks access |

---

#### `GET /api/messages/netmail/{id}/conversation`

**Requires authentication**

Fetches all messages in a conversation thread anchored by the specified message ID. Returns the full thread context including related messages. Useful for displaying message conversations in a threaded view.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Netmail message ID to anchor conversation |

**Response** _(JSON)_

Full conversation thread, flattened in display order

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Netmail message objects in the thread, flattened for display (same shape as the list endpoint, without `is_saved`) |
| `unreadCount` | integer | Number of unread messages in this thread |
| `threaded` | boolean | Always `true` for this endpoint |
| `pagination.page` | integer | Always `1` (full thread is returned) |
| `pagination.limit` | integer | Number of messages returned |
| `pagination.total` | integer | Total messages in the thread |
| `pagination.pages` | integer | Always `1` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found or conversation has no messages |

---

#### `DELETE /api/messages/netmail/{id}`

**Requires authentication**

Removes a netmail message. Only the message owner (user_id) can delete their own messages. Returns success confirmation or error if deletion fails or message not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Netmail message ID to delete |

**Response** _(JSON)_

Deletion result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Deletion success status |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found or user lacks permission to delete |

---

#### `GET /api/messages/netmail/{id}/download`

**Requires authentication**

Retrieves a netmail message by ID and streams it as a downloadable .txt file. The message is formatted with standard email headers (From, To, Subject, Date) followed by the message body. Access is restricted to the message owner or admins. The filename is derived from the message subject and sanitized for Windows compatibility.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Netmail message ID |

**Response** _(JSON)_

Plain text file download with CRLF line endings

| Field | Type | Description |
|-------|------|-------------|
| `body` | string | Email headers (From, To, Subject, Date) followed by message body |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found or user lacks access |

---

#### `POST /api/messages/netmail/{id}/edit`

**Requires authentication**

Updates display metadata for a netmail message. Only the message owner or admins can edit. Supports setting art_format (ansi, amiga_ansi, petscii, plain, or empty) and message_charset (uppercase). At least one field must be provided. Returns 403 if user lacks permission.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Netmail message ID |

**Request Body** _(JSON)_

Message metadata updates

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `art_format` | string | No | Display format: 'ansi', 'amiga_ansi', 'petscii', 'plain', or empty string to clear |
| `message_charset` | string | No | Character set (e.g., 'UTF-8', 'CP437'). Empty string clears it. |

**Response** _(JSON)_

JSON success response with update confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid art_format or no fields provided |
| 403 | User is not message owner and not admin |
| 404 | Message not found |

---

#### `POST /api/messages/netmail/bulk-delete`

**Requires authentication**

Deletes one or more netmail messages owned by the authenticated user. Only the message owner can delete their own messages. Returns count of successfully deleted messages. Requires non-empty message_ids array.

**Request Body** _(JSON)_

List of message IDs to delete

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message_ids` | array<integer> | Yes | Non-empty array of netmail message IDs |

**Response** _(JSON)_

Deletion summary with localization support

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `deleted` | integer | Number of messages successfully deleted |
| `total` | integer | Total messages requested for deletion |
| `message_code` | string | Localization key for UI message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | message_ids missing, empty, or not an array |

---

#### `POST /api/messages/netmail/read`

**Requires authentication**

Marks one or more netmail messages as read for the authenticated user. Uses upsert semantics — already-read messages are silently updated. Fires a BinkStream `message_read` event so other open tabs reflect the change immediately.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `messageIds` | array<integer> | Yes | Non-empty array of netmail message IDs |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `marked` | integer | Number of messages processed |
| `total` | integer | Total messages requested |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | messageIds missing, empty, or not an array |
| 500 | Database error |

---

#### `GET /api/messages/echomail`

**Requires authentication**

Retrieves paginated echomail messages from areas the user is subscribed to. Supports filtering (all, unread, etc.), sorting (date_desc, date_asc, subject, author), and optional threaded view. Returns localized error payloads on failure.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number (default: 1) |
| `filter` | string | No | Filter type: 'all', 'unread', etc. (default: 'all') |
| `sort` | string | No | Sort order: 'date_desc', 'date_asc', 'subject', 'author' (default: 'date_desc') |
| `threaded` | boolean | No | Enable threaded view (default: false) |

**Response** _(JSON)_

Paginated echomail message list

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Array of echomail message objects (see shape below) |
| `unreadCount` | integer | Total unread messages across all subscribed areas matching the current filter |
| `pagination.page` | integer | Current page number |
| `pagination.limit` | integer | Messages per page (from user setting, default 25) |
| `pagination.total` | integer | Total message count matching the current filter |
| `pagination.pages` | integer | Total number of pages |
| `info` | string | _(optional)_ Human-readable notice when the user has no subscriptions |

**Echomail object**

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Message ID |
| `from_name` | string | Sender display name (UTF-8 normalized) |
| `from_address` | string | Sender FidoNet address (e.g. `1:123/456`) |
| `to_name` | string | Recipient display name (often `"All"` for public posts) |
| `subject` | string | Message subject |
| `date_received` | string | UTC timestamp when the message was stored server-side — reliable for display and sorting |
| `date_written` | string | Timestamp from the FTN packet header — reflects when the sender composed the message; may be wrong or in the future if the remote clock is incorrect |
| `echoarea_id` | integer | ID of the echo area this message belongs to |
| `echoarea` | string | Echo area tag (e.g. `"FIDONEWS"`) |
| `echoarea_color` | string | Hex color code configured for this echo area (e.g. `"#28a745"`) |
| `echoarea_domain` | string | Domain of the echo area (e.g. `"lovlynet"`) |
| `message_id` | string | FTN Message-ID kludge value from the original packet |
| `reply_to_id` | integer\|null | ID of the message this is a reply to, or null |
| `art_format` | string\|null | Art format hint: `"ansi"`, `"amiga_ansi"`, `"petscii"`, or null (message-level value takes precedence over area default) |
| `is_read` | integer | `1` if the authenticated user has read this message, `0` otherwise |
| `is_shared` | integer | `1` if an active share link exists for this message, `0` otherwise |
| `is_saved` | integer | `1` if the authenticated user has saved this message, `0` otherwise |
| `replyto_address` | string\|null | FidoNet address parsed from the `REPLYTO` kludge, if present |
| `replyto_name` | string\|null | Recipient name parsed from the `REPLYTO` kludge, if present |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/messages/echomail/read`

**Requires authentication**

Marks specified echomail messages as read for the authenticated user and advances last_read_id watermarks per echoarea. Uses database transactions for consistency. Updates message_read_status table and user_echoarea_subscriptions watermarks.

**Request Body** _(JSON)_

List of message IDs to mark as read

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `messageIds` | array<integer> | Yes | Non-empty array of echomail message IDs |

**Response** _(JSON)_

Read status update summary

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `marked` | integer | Number of messages marked as read |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | messageIds missing, empty, or not an array |

---

#### `POST /api/messages/echomail/delete`

**Requires authentication**

Permanently deletes echomail messages from the database. Admin privileges required. Clears reply_to_id references and recalculates message_count for affected echoareas. Requires non-empty messageIds array.

**Request Body** _(JSON)_

List of message IDs to delete

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `messageIds` | array<integer> | Yes | Non-empty array of echomail message IDs |

**Response** _(JSON)_

Deletion summary with localization support

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `deleted` | integer | Number of messages deleted |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | messageIds missing, empty, or not an array |
| 403 | User lacks admin privileges |

---

#### `POST /api/messages/echomail/ignore-rules`

**Requires authentication**

Adds a filter rule to automatically ignore echomail messages matching sender name, sender address, and/or subject keywords. Sender name is required; other fields optional. Validates field lengths (max 255 chars). Returns localized success message with sender name.

**Request Body** _(JSON)_

Ignore rule criteria

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `sender_name` | string | Yes | Sender name to match (max 255 chars) |
| `sender_address` | string | No | Sender address to match (max 255 chars) |
| `subject_contains` | string | No | Subject substring to match (max 255 chars) |

**Response** _(JSON)_

Rule creation confirmation with localization

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Rule saved successfully |
| `message_code` | string | Localization key for UI message |
| `message_params` | object | Localization parameters for message_code |
| `message_params.sender_name` | string | Sender name from the saved ignore rule |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | sender_name empty or field length exceeds 255 chars |
| 500 | Failed to save rule to database |

---

#### `GET /api/messages/echomail/stats`

**Requires authentication**

Returns overall echomail statistics for the authenticated user across all subscribed echo areas. Statistics include message counts and activity metrics. This endpoint must be called before area-specific stats endpoints.

**Response** _(JSON)_

Aggregate echomail statistics object

| Field | Type | Description |
|-------|------|-------------|
| `total` | integer | Total echomail message count across all subscribed areas |
| `recent` | integer | Messages received in the last 24 hours |
| `unread` | integer | Unread message count across all subscribed areas |
| `areas` | integer\|null | Number of subscribed echo areas, or null for single-area queries |
| `filter_counts` | object | Message counts broken down by filter type |
| `filter_counts.all` | integer | Total message count |
| `filter_counts.unread` | integer | Unread message count |
| `filter_counts.read` | integer | Read message count |
| `filter_counts.tome` | integer | Messages addressed to the current user |
| `filter_counts.saved` | integer | Saved message count |
| `filter_counts.drafts` | integer | Echomail draft count |

---

#### `GET /api/messages/echomail/stats/{echoarea}`

**Requires authentication**

Returns statistics for a single echo area. Non-admin users must be subscribed to the area. The echoarea parameter supports URL encoding and optional domain suffix (format: `tag@domain`). Admin users bypass subscription checks.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `echoarea` | string | Echo area tag, optionally with domain (e.g., 'GENERAL' or 'GENERAL@fidonet.org'). URL-encoded. |

**Response** _(JSON)_

Statistics for the specified echo area

| Field | Type | Description |
|-------|------|-------------|
| `echoarea` | string | The requested echo area tag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User is not subscribed to this echo area (non-admin only) |

---

#### `GET /api/messages/echomail/message/{id}`

**Requires authentication**

Fetches a single echomail message by its ID. Parses REPLYTO kludge lines from message text and includes reply-to address and name in response. Applies media permission resolution. Returns 404 if message not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID |

**Response** _(JSON)_

Complete echomail message object with parsed metadata

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Message ID |
| `replyto_address` | string | Parsed REPLYTO address from kludge lines (if present) |
| `replyto_name` | string | Parsed REPLYTO name from kludge lines (if present) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found |

---

#### `GET /api/messages/echomail/message/{id}/conversation`

**Requires authentication**

Retrieves the full conversation thread containing the specified message, including all related messages in the thread. Returns 404 if the message is not found or has no conversation data.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to get conversation for |

**Response** _(JSON)_

Full conversation thread, flattened in display order

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Echomail message objects in the thread, flattened for display (same shape as the list endpoint, without `replyto_address` / `replyto_name`) |
| `unreadCount` | integer | Number of unread messages in this thread |
| `threaded` | boolean | Always `true` for this endpoint |
| `pagination.page` | integer | Always `1` (full thread is returned) |
| `pagination.limit` | integer | Number of messages returned |
| `pagination.total` | integer | Total messages in the thread |
| `pagination.pages` | integer | Always `1` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found or no conversation data available |

---

#### `POST /api/messages/echomail/{id}/save-ad`

**Requires authentication**

Converts an ANSI-formatted echomail message into an advertisement and saves it to the ad library. Admin privileges required. Validates that the message is ANSI-capable before saving. Creates an inactive ad with metadata extracted from the message.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to save as ad |

**Response** _(JSON)_

Confirmation of ad creation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if ad was created |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin privileges required |
| 404 | Message not found |
| 400 | Message is not ANSI-formatted or not suitable for ad library |

---

#### `GET /api/messages/echomail/{id}/download`

**Requires authentication**

Exports an echomail message as a downloadable text file with RFC-style headers (From, To, Subject, Date, Area). Attempts to convert content to CP437 charset if iconv is available, otherwise uses UTF-8. Sanitizes filename for Windows compatibility.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to download |

**Response** _(JSON)_

Message content as plain text file attachment

| Field | Type | Description |
|-------|------|-------------|
| `Content-Type` | header | text/plain; charset=utf-8 or charset=cp437 |
| `Content-Disposition` | header | attachment; filename=<sanitized_subject>.txt |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message not found |

---

#### `POST /api/messages/echomail/{id}/edit`

**Requires authentication**

Updates message metadata fields (art_format, message_charset) for an echomail message. Admin privileges required. Accepts JSON body with optional art_format and message_charset fields. Valid art formats: '', 'ansi', 'amiga_ansi', 'petscii', 'plain'. Returns 404 if message not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to edit |

**Request Body** _(JSON)_

Message metadata updates

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `art_format` | string | No | Art format: '', 'ansi', 'amiga_ansi', 'petscii', or 'plain' |
| `message_charset` | string | No | Character set (uppercase, e.g., 'UTF-8', 'CP437') |

**Response** _(JSON)_

Confirmation of metadata update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if update succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin access required |
| 400 | Invalid art_format or no fields to update |
| 404 | Message not found |

---

#### `GET /api/messages/echomail/{echoarea}`

**Requires authentication**

Fetches a paginated list of echomail messages from the specified echo area. Supports filtering by read/unread status, sorting by date or subject, and optional threaded view. The echoarea parameter supports URL-encoded names and optional @domain suffix. Tracks user activity for analytics.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `echoarea` | string | Echo area tag, optionally with @domain suffix (URL-encoded). Domain is extracted and used for filtering. |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number for pagination (default: 1) |
| `filter` | string | No | Filter messages: 'all', 'unread', or 'read' (default: 'all') |
| `threaded` | boolean | No | Return messages in threaded view (default: false) |
| `sort` | string | No | Sort order: 'date_desc', 'date_asc', 'subject', or 'author' (default: 'date_desc') |

**Response** _(JSON)_

Paginated list of echomail messages with metadata

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Array of echomail message objects (see **Echomail object** in `GET /api/messages/echomail`) |
| `page` | integer | Current page number |
| `total_pages` | integer | Total number of pages available |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `GET /api/messages/echomail/{echoarea}/{id}`

**Requires authentication**

Fetches a complete echomail message including headers, body, kludge lines, and parsed REPLYTO information. Validates that the requested message belongs to the specified echo area and domain (case-insensitive). Extracts REPLYTO kludge data from both message text and kludge_lines fields. Applies media permission resolution for attachments.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `echoarea` | string | Echo area tag with optional @domain suffix (URL-encoded) |
| `id` | integer | Message ID |

**Response** _(JSON)_

Complete echomail message object

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | Message ID |
| `echoarea` | string | Echo area tag |
| `domain` | string | Domain name |
| `message_text` | string | Message body |
| `kludge_lines` | string | FidoNet kludge lines |
| `replyto_address` | string | Parsed REPLYTO address (if present) |
| `replyto_name` | string | Parsed REPLYTO name (if present) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 404 | Message not found or does not belong to specified echo area/domain |

---

#### `POST /api/messages/send`

**Requires authentication**

Sends a message (netmail or echomail) with support for multiple charsets, markdown/plaintext markup, and file attachments. Enforces 16 KB FidoNet message body limit. For netmail, resolves attachment tokens to file paths. Supports crashmail flag and file request (FREQ) mode. Validates charset against a whitelist of safe values. Defaults to system address if no recipient specified for netmail.

**Request Body** _(JSON)_

Message composition payload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | 'netmail' or 'echomail' |
| `message_text` | string | Yes | Message body (max 16384 bytes UTF-8) |
| `markup_type` | string | No | 'markdown' or null for plaintext (legacy: send_markdown boolean) |
| `charset` | string | No | Target charset (UTF-8, CP437, CP850, ISO-8859-1, etc.; default: UTF-8) |
| `to_address` | string | No | Netmail recipient address (defaults to system address if empty) |
| `attachment_token` | string | No | 32-character hex token from attachment upload endpoint |
| `crashmail` | boolean | No | Send as crashmail (netmail only) |
| `is_freq` | boolean | No | Mark as file request (netmail only) |

**Response** _(JSON)_

Send result with message ID or error details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Send status |
| `message_id` | integer | ID of sent message (if successful) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Message body exceeds 16 KB limit |
| 401 | Authentication required |
| 500 | Send failed (validation or database error) |

---

#### `GET /api/messages/markdown-support`

**Requires authentication**

Determines whether markdown is allowed for a given netmail address, domain, or echomail area. Local echo areas always allow markdown. For remote areas, checks domain-level markdown configuration. Returns posting name policy (real_name or username) for the destination. Handles both local areas (NULL/empty domain) and remote areas with explicit domains.

**Response** _(JSON)_

Markdown support and posting policy for destination

| Field | Type | Description |
|-------|------|-------------|
| `allowed` | boolean | Whether markdown is permitted for this destination |
| `posting_name_policy` | string | 'real_name' or 'username' — policy for sender name in message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/messages/markdown-preview`

**Requires authentication**

Converts markdown-formatted text to HTML for live preview during message composition. Returns empty HTML for empty input. Uses the application's MarkdownRenderer for consistent formatting.

**Request Body** _(JSON)_

Markdown text to render

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `text` | string | Yes | Markdown-formatted text |

**Response** _(JSON)_

Rendered HTML output

| Field | Type | Description |
|-------|------|-------------|
| `html` | string | HTML representation of markdown input |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/messages/draft`

**Requires authentication**

Persists a partially-composed message as a draft. Accepts the same payload structure as the send endpoint but stores it for retrieval and editing later. Returns success status with a message code for UI feedback. Requires valid user session to associate draft with user account.

**Request Body** _(JSON)_

Draft message payload (same as send endpoint)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `type` | string | Yes | 'netmail' or 'echomail' |
| `message_text` | string | Yes | Draft message body |
| `to_address` | string | No | Recipient address (netmail) |
| `echoarea` | string | No | Target echo area (echomail) |

**Response** _(JSON)_

Draft save result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Save status |
| `draft_id` | integer | ID of saved draft |
| `message_code` | string | UI message code (default: 'ui.compose.draft.saved_success') |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid draft payload |
| 401 | Authentication required |
| 500 | Failed to save draft or unable to resolve user session |

---

#### `GET /api/messages/drafts`

**Requires authentication**

Fetches all draft messages for the authenticated user, optionally filtered by message type (netmail or echomail). Returns a list of draft metadata. Requires valid user session with either 'user_id' or 'id' field.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Filter drafts by type: 'netmail' or 'echomail'. If omitted, returns all drafts. |

**Response** _(JSON)_

Array of draft objects with metadata.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Indicates successful retrieval. |
| `drafts` | array of objects | List of draft message objects. |
| `drafts[].id` | integer | Draft ID. |
| `drafts[].type` | string | Draft type: `netmail` or `echomail`. |
| `drafts[].to_address` | string\|null | FidoNet address of the recipient (netmail only). |
| `drafts[].to_name` | string\|null | Recipient display name. |
| `drafts[].echoarea` | string\|null | Echo area tag (echomail only). |
| `drafts[].subject` | string\|null | Message subject. |
| `drafts[].message_text` | string\|null | Draft message body. |
| `drafts[].reply_to_id` | integer\|null | ID of the message this draft replies to, or null. |
| `drafts[].created_at` | string | UTC timestamp when the draft was created. |
| `drafts[].updated_at` | string | UTC timestamp of the last update. |
| `drafts[].meta` | object\|null | Additional metadata (e.g. cross-post area list), or null. |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | User ID cannot be resolved from session or draft retrieval failed. |

---

#### `GET /api/messages/drafts/{id}`

**Requires authentication**

Fetches the full content of a single draft message for the authenticated user. Verifies ownership before returning. Returns 404 if draft does not exist or does not belong to the user.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The draft message ID. |

**Response** _(JSON)_

Single draft object with full content.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Indicates successful retrieval. |
| `draft` | object | Complete draft message object. |
| `draft.id` | integer | Draft ID. |
| `draft.type` | string | Draft type: `netmail` or `echomail`. |
| `draft.to_address` | string\|null | FidoNet address of the recipient (netmail only). |
| `draft.to_name` | string\|null | Recipient display name. |
| `draft.echoarea` | string\|null | Echo area tag (echomail only). |
| `draft.subject` | string\|null | Message subject. |
| `draft.message_text` | string\|null | Draft message body. |
| `draft.reply_to_id` | integer\|null | ID of the message this draft replies to, or null. |
| `draft.created_at` | string | UTC timestamp when the draft was created. |
| `draft.updated_at` | string | UTC timestamp of the last update. |
| `draft.meta` | object\|null | Additional metadata (e.g. cross-post area list), or null. |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Draft not found or does not belong to authenticated user. |
| 500 | User ID cannot be resolved or draft retrieval failed. |

---

#### `DELETE /api/messages/drafts/{id}`

**Requires authentication**

Permanently deletes a draft message belonging to the authenticated user. Verifies ownership before deletion. Returns success with localized message code on successful deletion.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The draft message ID to delete. |

**Response** _(JSON)_

Deletion result with success status and message code.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Indicates successful deletion. |
| `message_code` | string | Localization key for UI message (e.g., 'ui.drafts.deleted_success'). |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | User ID cannot be resolved or deletion failed. |

---

#### `GET /api/messages/templates`

**Requires authentication**

Retrieves all message templates owned by the authenticated user, optionally filtered by type. Requires valid license. Returns template metadata (id, name, type, subject, created_at) sorted by name.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `type` | string | No | Filter templates by type: 'netmail' or 'echomail'. Templates with type='both' are always included. |

**Response** _(JSON)_

Array of template metadata objects.

| Field | Type | Description |
|-------|------|-------------|
| `templates` | array of objects | List of template metadata objects. |
| `templates[].id` | integer | Template ID. |
| `templates[].name` | string | Template name (up to 100 characters). |
| `templates[].type` | string | Template type: `netmail`, `echomail`, or `both`. |
| `templates[].subject` | string | Template subject line. |
| `templates[].created_at` | string | UTC timestamp when the template was created. |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Message templates require a valid registered license. |

---

#### `GET /api/messages/templates/{id}`

**Requires authentication**

Fetches complete template content including body for the authenticated user. Verifies ownership and license validity. Returns 404 if template does not exist or does not belong to user.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The template ID. |

**Response** _(JSON)_

Complete template object with all fields.

| Field | Type | Description |
|-------|------|-------------|
| `template` | object | Complete template object. |
| `template.id` | integer | Template ID. |
| `template.name` | string | Template name (up to 100 characters). |
| `template.type` | string | Template type: `netmail`, `echomail`, or `both`. |
| `template.subject` | string | Template subject line. |
| `template.body` | string | Template message body. |
| `template.created_at` | string | UTC timestamp when the template was created. |
| `template.updated_at` | string | UTC timestamp of the last modification. |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Message templates require a valid registered license. |
| 404 | Template not found or does not belong to authenticated user. |

---

#### `POST /api/messages/templates`

**Requires authentication**

Creates a new template or updates an existing one (if 'id' is provided). Validates name (required, max 100 chars), type (netmail/echomail/both), subject, and body. Requires valid license. Returns template ID and success message code.

**Request Body** _(JSON)_

Template data to create or update.

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | Yes | Template name (1–100 characters). |
| `type` | string | No | Template type: 'netmail', 'echomail', or 'both' (default: 'both'). |
| `subject` | string | No | Template subject line. |
| `body` | string | No | Template message body. |
| `id` | integer | No | If provided, updates existing template; otherwise creates new one. |

**Response** _(JSON)_

Created or updated template with ID and success message.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Indicates successful creation or update. |
| `id` | integer | Template ID (new or existing). |
| `message_code` | string | Localization key (e.g., 'ui.compose.templates.saved'). |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Name is required, exceeds 100 characters, or validation failed. |
| 403 | Message templates require a valid registered license. |
| 404 | Template ID provided but not found or does not belong to user. |

---

#### `DELETE /api/messages/templates/{id}`

**Requires authentication**

Permanently deletes a template belonging to the authenticated user. Requires valid license. Verifies ownership before deletion. Returns 404 if template does not exist.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The template ID to delete. |

**Response** _(JSON)_

Deletion confirmation with success status.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Indicates successful deletion. |
| `message_code` | string | Localization key (e.g., 'ui.compose.templates.deleted'). |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Message templates require a valid registered license. |
| 404 | Template not found or does not belong to authenticated user. |

---

#### `GET /api/messages/search`

**Requires authentication**

Searches messages across drafts, netmail, and echomail. Supports general query (2+ chars) or advanced field-specific searches (from_name, subject, body, message_id, date_from, date_to). Date parameters must be YYYY-MM-DD format. Returns paginated results with message metadata.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | No | General search query (minimum 2 characters if used alone). |
| `type` | string | No | Filter by message type: 'netmail', 'echomail', or 'draft'. |
| `echoarea` | string | No | Filter by echo area name (URL-encoded). |
| `from_name` | string | No | Search sender name (minimum 2 characters). |
| `subject` | string | No | Search subject line (minimum 2 characters). |
| `body` | string | No | Search message body (minimum 2 characters). |
| `message_id` | string | No | Search by FidoNet message ID (minimum 2 characters). |
| `date_from` | string | No | Start date filter (YYYY-MM-DD format). |
| `date_to` | string | No | End date filter (YYYY-MM-DD format). |

**Response** _(JSON)_

Search results with message metadata and per-area counts.

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array | Array of matching message objects |
| `messages[].id` | integer | Message ID |
| `messages[].from_name` | string | Sender name |
| `messages[].from_address` | string | Sender FTN address |
| `messages[].to_name` | string | Recipient name |
| `messages[].subject` | string | Message subject |
| `messages[].date_received` | string | Server receipt timestamp (ISO 8601) |
| `messages[].date_written` | string | Sender-written timestamp (ISO 8601) |
| `messages[].echoarea_id` | integer\|null | Echo area ID (echomail only) |
| `messages[].echoarea` | string\|null | Echo area tag (echomail only) |
| `messages[].echoarea_domain` | string\|null | Echo area domain (echomail only) |
| `echoarea_counts` | array | Per-area message counts (echomail searches only; empty for netmail/draft) |
| `echoarea_counts[].tag` | string | Echo area tag |
| `echoarea_counts[].domain` | string | Echo area domain |
| `echoarea_counts[].message_count` | integer | Number of matching messages in this area |
| `filter_counts` | object | Counts by read/saved status across all results (echomail only) |
| `filter_counts.all` | integer | Total matches |
| `filter_counts.unread` | integer | Unread matches |
| `filter_counts.read` | integer | Read matches |
| `filter_counts.tome` | integer | Messages addressed to the current user |
| `filter_counts.saved` | integer | Saved matches |
| `filter_counts.drafts` | integer | Always 0 (reserved) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Search query or field-specific search is less than 2 characters, or date format is invalid. |

---

#### `POST /api/messages/{type}/{id}/read`

**Requires authentication**

Records that the authenticated user has read a specific message (echomail or netmail). Uses upsert logic to handle duplicate reads gracefully. Emits a real-time notification via BinkStream to sync read status across user tabs. Message type must be 'echomail' or 'netmail'.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `type` | string | Message type: 'echomail' or 'netmail' |
| `id` | integer | Message ID |

**Response** _(JSON)_

Success response with optional real-time notification status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid message type (not 'echomail' or 'netmail') |
| 500 | Failed to mark message as read or unable to resolve user session |

---

#### `POST /api/messages/{type}/{id}/save`

**Requires authentication**

Adds a message to the authenticated user's saved messages collection. Idempotent—saving an already-saved message has no effect. Supports both echomail and netmail types.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `type` | string | Message type: 'echomail' or 'netmail' |
| `id` | integer | Message ID |

**Response** _(JSON)_

Success response with localized message code

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `message_code` | string | Localization key: 'ui.api.messages.saved' |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid message type |
| 500 | Failed to save message or unable to resolve user session |

---

#### `DELETE /api/messages/{type}/{id}/save`

**Requires authentication**

Deletes a saved message entry for the authenticated user. Returns success even if the message was not previously saved (idempotent). Supports both echomail and netmail types.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `type` | string | Message type: 'echomail' or 'netmail' |
| `id` | integer | Message ID |

**Response** _(JSON)_

Success or not-saved status with localization key

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if unsaved; false if message was not saved |
| `message_code` | string | Localization key: 'ui.api.messages.unsaved' on success |
| `error_code` | string | Localization key on failure: 'errors.messages.unsave.not_saved' |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid message type |
| 500 | Failed to unsave message or unable to resolve user session |

---

#### `POST /api/messages/{type}/{id}/forward-email`

**Requires authentication**

Converts and sends an echomail or netmail message to the authenticated user's registered email address. Validates message ownership, email configuration, and message type. Requires user to have a valid email address on file. Supports both echomail (with area/domain context) and netmail types.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `type` | string | Message type: 'echomail' or 'netmail' |
| `id` | integer | Message ID to forward |

**Response** _(JSON)_

Email forwarding confirmation.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Email sent successfully |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | User has no email address or invalid message type |
| 404 | Message not found or user lacks access |
| 503 | Email sending not configured on system |

---

#### `GET /api/messages/echomail/delete-test`

Public

Debug endpoint that verifies the delete endpoint is accessible. Returns success status with a message code. No authentication required.

**Response** _(JSON)_

Success confirmation with message code

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |
| `message_code` | string | Localization key: 'ui.api.debug.delete_endpoint_accessible' |

---

#### `POST /api/messages/echomail/{id}/share`

**Requires authentication**

Creates a shareable link for an echomail message with optional expiration and public/private visibility. Supports custom OpenGraph summaries for AI-generated previews. Returns share metadata including the generated share token.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | string | Message ID |

**Request Body** _(JSON)_

Share configuration

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `public` | boolean | No | Whether share is publicly accessible (default: false) |
| `expires_hours` | integer | No | Hours until share expires; null or ≤0 means no expiration |
| `ai_og_summary` | string | No | Custom OpenGraph summary for preview |

**Response** _(JSON)_

Share creation result with token and metadata

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether share was created |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid input or share creation failed |
| 500 | Server error during share creation |

---

#### `GET /api/messages/echomail/{id}/shares`

**Requires authentication**

Retrieves all active share links for a specific echomail message. Requires authentication. Returns array of shares with metadata including expiration and visibility settings.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | string | Message ID |

**Response** _(JSON)_

Share links for this message, split by ownership

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `my_shares` | array | Share links created by the authenticated user |
| `my_shares[].share_key` | string | Unique share token used in the share URL |
| `my_shares[].share_url` | string | Full share URL (friendly URL if slug is set, otherwise key-based) |
| `my_shares[].has_friendly_url` | boolean | True if a slug-based friendly URL is available |
| `my_shares[].created_at` | string | ISO 8601 timestamp when the share was created |
| `my_shares[].expires_at` | string\|null | ISO 8601 expiry timestamp, or null if non-expiring |
| `my_shares[].is_public` | boolean | Whether the share is publicly accessible |
| `my_shares[].access_count` | integer | Number of times the share URL has been accessed |
| `my_shares[].last_accessed_at` | string\|null | ISO 8601 timestamp of last access, or null |
| `my_shares[].og_image_path` | string\|null | Server path to custom OG preview image, or null |
| `my_shares[].og_image_slug` | string\|null | Slug for the OG image URL, or null |
| `my_shares[].top_referrers` | array | Reserved; currently always empty |
| `other_shares` | array | Active public share links for this message created by other users |
| `other_shares[].share_url` | string | Full share URL |
| `other_shares[].shared_by_username` | string | Username of the user who created the share |
| `other_shares[].created_at` | string | ISO 8601 timestamp when the share was created |
| `other_shares[].is_public` | boolean | Whether the share is publicly accessible |
| `other_shares[].top_referrers` | array | Reserved; currently always empty |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load share links |

---

#### `DELETE /api/messages/echomail/{id}/share`

**Requires authentication**

Deletes the share link for an echomail message, preventing further access via the share URL. The authenticated user must own the message. Returns success status or 404 if the message or share does not exist.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to revoke sharing for |

**Response** _(JSON)_

Share revocation result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the share was successfully revoked |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message or share not found |
| 500 | Failed to revoke share link |

---

#### `POST /api/messages/echomail/{id}/share/friendly-url`

**Requires authentication**

Creates a human-readable slug for an already-shared echomail message, enabling access via `/api/messages/shared/{area}/{slug}` instead of the share key. The authenticated user must own the message.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to generate a slug for |

**Response** _(JSON)_

Slug generation result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the slug was successfully generated |
| `slug` | string | The generated friendly URL slug |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Message or share not found |
| 500 | Cannot generate share slug for this message |

---

#### `POST /api/messages/echomail/{id}/share/image`

**Requires authentication**

Uploads an image to use as the Open Graph preview (`og:image`) for an existing message share. The image is stored in the sharer's private file area under `shared-messages/`. Accepts a multipart form upload with field name `image`. Replaces any previously uploaded image for the same share.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID whose share receives the image |

**Request** _(multipart/form-data)_

| Field | Type | Description |
|-------|------|-------------|
| `image` | file | Image file (JPG, PNG, GIF, etc.); maximum 5 MB |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | `true` on success |
| `share_key` | string | 32-char hex share key |
| `og_image_slug` | string | Filename slug including extension (e.g. `abc123….jpg`); use with `/shared-image/{og_image_slug}` |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | No file uploaded, invalid MIME type, or file too large |
| 404 | Share not found |
| 500 | Server error storing the image |

---

#### `DELETE /api/messages/echomail/{id}/share/image`

**Requires authentication**

Removes the Open Graph preview image from an existing message share and deletes the stored file.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID whose share image is removed |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | `true` on success |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Share not found |
| 500 | Server error removing the image |

---

#### `POST /api/messages/echomail/{id}/share-summary`

**Requires authentication**

Uses AI to generate a concise summary of an echomail message for sharing purposes. Requires the AI share summary feature to be enabled in BBS config. Returns the generated summary text or 403 if the feature is disabled.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The echomail message ID to summarize |

**Response** _(JSON)_

AI-generated summary result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the summary was successfully generated |
| `summary` | string | The AI-generated summary text |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | AI share summaries are not enabled |
| 404 | Message not found |
| 500 | Failed to generate summary |

---

#### `GET /api/messages/shared/{area}/{slug}`

**Requires authentication**

Fetches a shared echomail message using its friendly URL slug and area. Authentication is required if the share has login restrictions. Returns 401 if login is required but user is not authenticated, or 404 if the share does not exist.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `area` | string | The message area identifier |
| `slug` | string | The friendly URL slug for the shared message |

**Response** _(JSON)_

Shared message data

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the message was successfully retrieved |
| `message` | object | The echomail or netmail message record |
| `message.id` | integer | Message ID |
| `message.from_name` | string | Sender name |
| `message.to_name` | string | Recipient name |
| `message.subject` | string | Message subject |
| `message.message_text` | string | Message body |
| `message.date_written` | string | Sender-written timestamp (ISO 8601) |
| `message.date_received` | string | Server receipt timestamp (ISO 8601) |
| `message.echoarea` | string\|null | Echo area tag (echomail only) |
| `message.echoarea_color` | string\|null | Echo area color (echomail only) |
| `message.echoarea_domain` | string\|null | Echo area domain (echomail only) |
| `message.from_system_name` | string\|null | Nodelist system name for sender address |
| `share_info` | object | Share metadata |
| `share_info.id` | integer | Share record ID |
| `share_info.share_key` | string | Share token string |
| `share_info.shared_by` | string | Real name (or username) of the user who shared the message |
| `share_info.created_at` | string | Share creation timestamp (ISO 8601) |
| `share_info.expires_at` | string\|null | Share expiry timestamp; null for no expiry |
| `share_info.is_public` | boolean | Whether share is publicly accessible |
| `share_info.access_count` | integer | Number of times the share has been accessed |
| `share_info.ai_og_summary` | string\|null | AI-generated OpenGraph summary if provided |
| `share_info.og_image_path` | string\|null | Server path to OpenGraph preview image |
| `share_info.og_image_slug` | string\|null | URL slug for OpenGraph preview image |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Login required to access this shared message |
| 404 | Shared message not found |
| 500 | Failed to load shared message |

---

#### `GET /api/messages/shared/{shareKey}`

**Requires authentication**

Fetches a shared message using its unique share key. Authentication is optional; if provided, the user context is used for access control. Returns 401 if the share requires login but user is not authenticated, or 404 if the share key is invalid.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `shareKey` | string | The unique share key for the message |

**Response** _(JSON)_

Shared message data

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the message was successfully retrieved |
| `message` | object | The echomail or netmail message record |
| `message.id` | integer | Message ID |
| `message.from_name` | string | Sender name |
| `message.to_name` | string | Recipient name |
| `message.subject` | string | Message subject |
| `message.message_text` | string | Message body |
| `message.date_written` | string | Sender-written timestamp (ISO 8601) |
| `message.date_received` | string | Server receipt timestamp (ISO 8601) |
| `message.echoarea` | string\|null | Echo area tag (echomail only) |
| `message.echoarea_color` | string\|null | Echo area color (echomail only) |
| `message.echoarea_domain` | string\|null | Echo area domain (echomail only) |
| `message.from_system_name` | string\|null | Nodelist system name for sender address |
| `share_info` | object | Share metadata |
| `share_info.id` | integer | Share record ID |
| `share_info.share_key` | string | Share token string |
| `share_info.shared_by` | string | Real name (or username) of the user who shared the message |
| `share_info.created_at` | string | Share creation timestamp (ISO 8601) |
| `share_info.expires_at` | string\|null | Share expiry timestamp; null for no expiry |
| `share_info.is_public` | boolean | Whether share is publicly accessible |
| `share_info.access_count` | integer | Number of times the share has been accessed |
| `share_info.ai_og_summary` | string\|null | AI-generated OpenGraph summary if provided |
| `share_info.og_image_path` | string\|null | Server path to OpenGraph preview image |
| `share_info.og_image_slug` | string\|null | URL slug for OpenGraph preview image |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Login required to access this shared message |
| 404 | Shared message not found |
| 500 | Failed to load shared message |

---

#### `POST /api/messages/ai-assist`

**Requires authentication**

Calls an AI assistant (OpenAI or Anthropic) to generate a response based on a user prompt and optional message context. Requires AI assistant to be enabled and configured via environment variables. Prompt is limited to 500 characters. Supports both echomail and netmail message types.

**Request Body** _(JSON)_

AI assistance request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `prompt` | string | Yes | User prompt (max 500 chars) |
| `message_id` | integer | No | Optional message ID for context |
| `message_type` | string | No | Message type: 'echomail' or 'netmail' (default: 'echomail') |

**Response** _(JSON)_

AI assistant result with the generated reply and resulting credit information.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True when the request completed successfully |
| `response` | string | AI-generated reply text |
| `credits_used` | integer | Credits charged for this AI request |
| `balance` | integer | User's remaining credit balance after the request |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | AI assistant is disabled on this system |
| 503 | AI assistant not configured (missing API keys) |
| 400 | Prompt is empty or exceeds 500 character limit |
| 402 | Insufficient credits for the AI request |

---

### Netmail

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/netmail/attachment/upload`](#post-apinetmailattachmentupload) | Yes | Upload a file for attachment to an outbound netmail message. |

#### `POST /api/netmail/attachment/upload`

**Requires authentication**

Accepts a multipart file upload and stores it temporarily with a unique token. The token is returned and must be provided when sending the netmail. Enforces file size limits (default 10 MB, configurable via NETMAIL_ATTACHMENT_MAX_SIZE). Sanitizes filenames to alphanumeric characters, dots, hyphens, and underscores. Files are stored in data/netmail_attachments with token prefix.

**Request Body** _(JSON)_

Multipart form data with file upload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `file` | file | Yes | File to upload (max size configurable, default 10 MB) |

**Response** _(JSON)_

Upload success with attachment token

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Upload status |
| `token` | string | 32-character hex token to reference file in send request |
| `filename` | string | Sanitized original filename |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | No file provided, upload error, or file exceeds size limit |
| 401 | Authentication required |
| 500 | Server error creating attachment directory or moving file |

---

### Nodelist

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/nodelist/node`](#get-apinodelistnode) | Yes | Look up a nodelist entry by exact FTN address. |
| `GET` | [`/api/nodelist/search`](#get-apinodelistsearch) | Yes | Search nodelist nodes by name, sysop, or location. |

#### `GET /api/nodelist/node`

**Requires authentication**

Retrieves nodelist information for a given FTN address. If the exact address is not found and it is a point address (contains a dot), falls back to searching for the parent node. Returns null if no match found. Requires exact address match.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `address` | string | Yes | FTN address to look up (e.g., '1:123/456' or '1:123/456.789') |

**Response** _(JSON)_

Nodelist entry or null if not found

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true; check node field for null |
| `node` | object\|null | Node object, or null if address not found |
| `node.address` | string | Full FTN node address |
| `node.system_name` | string | System name from nodelist |
| `node.location` | string | Node location |
| `node.domain` | string | Network domain |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing or empty address parameter |

---

#### `GET /api/nodelist/search`

**Requires authentication**

Searches the nodelist for nodes matching a query term against system name, sysop name, or location. Returns up to 10 results suitable for autocomplete interfaces. Requires a minimum query length of 2 characters; shorter queries return an empty result set.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | Yes | Search term (minimum 2 characters) |

**Response** _(JSON)_

JSON object containing search results

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on successful request |
| `nodes` | array | Array of matching nodes (max 10 items) |
| `nodes[].address` | string | FTN node address |
| `nodes[].system_name` | string | Node system name |
| `nodes[].sysop_name` | string | Sysop name |
| `nodes[].location` | string | Node location |
| `nodes[].domain` | string | Network domain |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

### Notify

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/notify/state`](#get-apinotifystate) | Yes | Retrieve current notification state for authenticated user. |
| `POST` | [`/api/notify/state`](#post-apinotifystate) | Yes | Update notification state for authenticated user. |
| `POST` | [`/api/notify/seen`](#post-apinotifyseen) | Yes | Mark notification target as seen up to a given count. |

#### `GET /api/notify/state`

**Requires authentication**

Returns the user's stored notification tracking state including mail counts, unread flags, and last-seen IDs for chat, files, and approvals. Returns sensible defaults if no state has been saved yet.

**Response** _(JSON)_

Notification state object

| Field | Type | Description |
|-------|------|-------------|
| `state` | object | Notification state |
| `state.mailLastCounts` | object | Last-seen mail counts |
| `state.mailLastCounts.netmail` | integer | Last-seen netmail count |
| `state.mailLastCounts.echomail` | integer | Last-seen echomail max ID |
| `state.mailUnread` | object | Unread mail flags |
| `state.mailUnread.netmail` | boolean | Whether netmail has unread messages |
| `state.mailUnread.echomail` | boolean | Whether echomail has unread messages |
| `state.chatLastTotal` | integer | Last-seen chat message ID |
| `state.chatUnread` | boolean | Whether chat has unread messages |
| `state.filesLastMaxId` | integer | Last-seen file max ID |
| `state.filesUnread` | boolean | Whether there are new files |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Unable to resolve user session |

---

#### `POST /api/notify/state`

**Requires authentication**

Persists notification tracking state (mail counts, unread flags, last-seen IDs). Normalizes and validates all numeric values (non-negative integers) and boolean flags before storage.

**Request Body** _(JSON)_

Notification state to persist

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `state` | object | Yes | State object with mailLastCounts, mailUnread, chatLastTotal, chatUnread, filesLastMaxId, filesUnread |

**Response** _(JSON)_

Update confirmation with normalized state

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether state was saved |
| `state` | object | Normalized state as stored |
| `state.mailLastCounts` | object | Last-seen mail counts |
| `state.mailLastCounts.netmail` | integer | Last-seen netmail count |
| `state.mailLastCounts.echomail` | integer | Last-seen echomail max ID |
| `state.mailUnread` | object | Unread mail flags |
| `state.mailUnread.netmail` | boolean | Whether netmail has unread messages |
| `state.mailUnread.echomail` | boolean | Whether echomail has unread messages |
| `state.chatLastTotal` | integer | Last-seen chat message ID |
| `state.chatUnread` | boolean | Whether chat has unread messages |
| `state.filesLastMaxId` | integer | Last-seen file max ID |
| `state.filesUnread` | boolean | Whether there are new files |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid state payload or unable to resolve user session |

---

#### `POST /api/notify/seen`

**Requires authentication**

Updates the user's last-seen marker for a specific notification target (netmail, echomail, chat, files, or file-approvals). File-approvals is admin-only. Stores either a count (netmail) or max row ID (others) depending on target type.

**Request Body** _(JSON)_

Seen notification data

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `target` | string | Yes | Notification target: 'netmail', 'echomail', 'chat', 'files', or 'file-approvals' |
| `current_count` | integer | Yes | Count (netmail) or max row ID (others) to mark as seen |

**Response** _(JSON)_

Confirmation of seen marker update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether marker was updated |
| `target` | string | Target that was updated |
| `count` | integer | Count/ID that was stored |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid target or unable to resolve user session |
| 403 | Insufficient permissions (file-approvals requires admin) |

---

### Pending Users

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/admin/pending-users`](#get-apiadminpending-users) | Yes | List all pending user registrations (admin only). |
| `GET` | [`/api/admin/pending-users/{id}`](#get-apiadminpending-usersid) | Yes | Retrieve single pending user registration details. |
| `POST` | [`/api/admin/pending-users/{id}/approve`](#post-apiadminpending-usersidapprove) | Yes | Approve pending user registration and create active account. |
| `POST` | [`/api/admin/pending-users/{id}/reject`](#post-apiadminpending-usersidreject) | Yes | Reject pending user registration. |

#### `GET /api/admin/pending-users`

**Requires authentication**

Retrieves all users awaiting admin approval. Requires admin privileges. Returns array of pending user records with application details. Throws 500 on database errors.

**Response** _(JSON)_

List of pending user registrations

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `users` | array | Array of pending user registration objects |
| `users[].id` | integer | Pending user record ID |
| `users[].username` | string | Requested username |
| `users[].email` | string\|null | Email address provided during registration |
| `users[].real_name` | string\|null | Real name provided during registration |
| `users[].reason` | string\|null | Reason for registration (if required) |
| `users[].requested_at` | string | Registration request timestamp (ISO 8601) |
| `users[].ip_address` | string\|null | IP address at time of registration |
| `users[].status` | string | Current status (pending, approved, rejected) |
| `users[].reviewed_by` | integer\|null | User ID of admin who reviewed |
| `users[].reviewed_at` | string\|null | Review timestamp (ISO 8601) |
| `users[].admin_notes` | string\|null | Admin notes on the registration |
| `users[].reviewed_by_username` | string\|null | Username of reviewing admin |
| `users[].registration_source` | string | Registration source (web, terminal, etc.) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User is not an admin |
| 401 | Authentication required |
| 500 | Database error |

---

#### `GET /api/admin/pending-users/{id}`

**Requires authentication**

Fetches a specific pending user record by ID, including referrer information (username and real name). Admin-only endpoint. Returns 404 if pending user not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Pending user ID |

**Response** _(JSON)_

Pending user registration details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `user` | object | Pending user registration details |
| `user.id` | integer | Pending user record ID |
| `user.username` | string | Requested username |
| `user.email` | string\|null | Email address |
| `user.real_name` | string\|null | Real name |
| `user.reason` | string\|null | Registration reason |
| `user.requested_at` | string | Registration request timestamp (ISO 8601) |
| `user.ip_address` | string\|null | IP address at registration |
| `user.status` | string | Current status (pending, approved, rejected) |
| `user.admin_notes` | string\|null | Admin notes |
| `user.referrer_id` | integer\|null | User ID of the referrer |
| `user.referrer_username` | string\|null | Username of the referrer |
| `user.referrer_real_name` | string\|null | Real name of the referrer |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User is not an admin |
| 404 | Pending user not found |
| 401 | Authentication required |
| 500 | Database error |

---

#### `POST /api/admin/pending-users/{id}/approve`

**Requires authentication**

Converts a pending user to an active user account. Admin-only. Accepts optional notes field. Returns newly created user ID on success. Throws 400 if approval fails (e.g., duplicate username, invalid state).

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Pending user ID to approve |

**Request Body** _(JSON)_

Approval details

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `notes` | string | No | Optional admin notes for approval |

**Response** _(JSON)_

Approval confirmation with new user ID

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if approved |
| `new_user_id` | integer | ID of newly created active user |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Approval failed (invalid state, duplicate username, etc.) |
| 403 | User is not an admin |
| 401 | Authentication required |

---

#### `POST /api/admin/pending-users/{id}/reject`

**Requires authentication**

Denies a pending user registration. Admin-only. Accepts optional notes field. Removes the pending user record. Throws 400 if rejection fails.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Pending user ID to reject |

**Request Body** _(JSON)_

Rejection details

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `notes` | string | No | Optional admin notes for rejection |

**Response** _(JSON)_

Rejection confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if rejected |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Rejection failed |
| 403 | User is not an admin |
| 401 | Authentication required |

---

### Polls

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/polls/active`](#get-apipollsactive) | Yes | Retrieve all active polls with options and user vote status. |
| `POST` | [`/api/polls/{id}/vote`](#post-apipollsidvote) | Yes | Submit a vote for a specific poll option. |
| `POST` | [`/api/polls/create`](#post-apipollscreate) | Yes | Create a new poll with question and multiple choice options. |

#### `GET /api/polls/active`

**Requires authentication**

Fetches active polls from the database with their options and indicates which polls the authenticated user has already voted on. Returns an empty array if no active polls exist. Polls are ordered by creation date (newest first).

**Response** _(JSON)_

JSON object containing array of active polls

| Field | Type | Description |
|-------|------|-------------|
| `polls` | array of objects | Array of poll objects. Unvoted polls come first, then voted polls. |
| `polls[].id` | integer | Poll ID |
| `polls[].question` | string | Poll question text |
| `polls[].options` | array | Answer options |
| `polls[].options[].id` | integer | Option ID |
| `polls[].options[].option_text` | string | Option display text |
| `polls[].has_voted` | boolean | Whether the authenticated user has voted on this poll |
| `polls[].results` | array | _(present only when `has_voted` is true)_ Per-option vote counts |
| `polls[].results[].option_id` | integer | Option ID |
| `polls[].results[].option_text` | string | Option display text |
| `polls[].results[].votes` | integer | Vote count for this option |
| `polls[].total_votes` | integer | _(present only when `has_voted` is true)_ Total votes cast |

---

#### `POST /api/polls/{id}/vote`

**Requires authentication**

Records a vote for the authenticated user on an active poll. Validates that the poll exists and is active, and that the option belongs to the poll. Prevents duplicate votes via database constraint. Returns success or appropriate error with localized message.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The poll ID to vote on |

**Request Body** _(JSON)_

JSON object with poll option selection

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `option_id` | integer | Yes | The poll option ID to vote for (must be > 0) |

**Response** _(JSON)_

JSON object with success status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True when vote is recorded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing or invalid option_id, invalid option for poll, or vote recording failed |
| 404 | Poll not found or is not active |

---

#### `POST /api/polls/create`

**Requires authentication**

Creates a new poll with validation for question length (10-500 chars) and option count (2-10 options, each max 200 chars). Prevents duplicate options. Requires authenticated user. Returns created poll details or validation error.

**Request Body** _(JSON)_

JSON object with poll details

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `question` | string | Yes | Poll question (10-500 characters) |
| `options` | array of strings | Yes | Array of 2-10 poll options (each max 200 characters, no duplicates) |

**Response** _(JSON)_

JSON object with created poll ID and details

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | The newly created poll ID |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing question, invalid question length, invalid option count, empty option, option too long, or duplicate options |

---

### Qwk

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/qwk/upload`](#post-apiqwkupload) | Yes | Upload and process a QWK REP packet for offline mail import. |
| `GET` | [`/api/qwk/status`](#get-apiqwkstatus) | Yes | Retrieve user's QWK subscription status and pending message counts. |
| `POST` | [`/api/qwk/format`](#post-apiqwkformat) | Yes | Save user's preferred QWK packet format (QWK or QWKE). |
| `POST` | [`/api/qwk/reset`](#post-apiqwkreset) | Yes | Dev-only: reset all QWK state for the current user. |
| `GET` | [`/api/qwk/area-selections`](#get-apiqwkarea-selections) | Yes | Retrieve user's QWK area selections and available subscriptions. |
| `POST` | [`/api/qwk/area-selections`](#post-apiqwkarea-selections) | Yes | Save user's QWK area selection for packet generation. |
| `GET` | [`/api/qwk/area-search`](#get-apiqwkarea-search) | Yes | Search echo areas by tag or description for QWK selection. |

#### `POST /api/qwk/upload`

**Requires authentication**

Accepts a multipart form upload containing a REP packet (field name: "rep") and processes it for the authenticated user. Returns import statistics including message counts and any processing errors. QWK feature must be enabled on the system. Validates file type and handles various upload/processing failures with specific error codes.

**Request Body** _(JSON)_

Multipart form data with REP packet file

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `rep` | file | Yes | REP packet file (QWK reply packet) |

**Response** _(JSON)_

Import result with message statistics

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether processing completed successfully |
| `imported` | integer | Number of messages imported from the packet |
| `skipped` | integer | Number of messages skipped (duplicates, errors) |
| `errors` | array of strings | Error messages encountered during processing; empty array if none |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | No file uploaded, invalid file extension, or upload error |
| 403 | QWK feature is disabled on this system |
| 500 | REP packet processing failed |

---

#### `GET /api/qwk/status`

**Requires authentication**

Returns the user's current QWK configuration including subscribed conferences and the number of new messages waiting since the last download. Respects custom area selections if enabled; otherwise uses all subscribed echoareas. Includes netmail status and per-area message counts.

**Response** _(JSON)_

QWK status with subscriptions and message counts

| Field | Type | Description |
|-------|------|-------------|
| `total_new_messages` | integer | Total new messages across all conferences |
| `last_download` | string\|null | Timestamp of the last packet download (ISO 8601); null if never downloaded |
| `conferences` | array | List of QWK conference objects |
| `conferences[].number` | integer | QWK conference number (0 = Personal Mail) |
| `conferences[].name` | string | Conference name (echoarea tag or 'Personal Mail') |
| `conferences[].is_netmail` | boolean | Whether this conference is the personal netmail conference |
| `conferences[].new_messages` | integer | Number of new messages in this conference |
| `format` | string | User's preferred packet format ('qwk' or 'qwke') |
| `limit` | integer | Maximum messages per packet (user-configurable) |
| `hard_cap` | integer | System-wide maximum messages per packet |
| `is_dev` | boolean | Whether the system is in development mode |
| `has_custom_selection` | boolean | Whether user has a custom area selection active |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | QWK feature is disabled |

---

#### `POST /api/qwk/format`

**Requires authentication**

Persists the user's packet format preference to UserMeta. Accepts either 'qwk' (standard) or 'qwke' (extended) format. Used by the client to request the appropriate packet type on download.

**Request Body** _(JSON)_

Format preference

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `format` | string | Yes | Either 'qwk' or 'qwke' |

**Response** _(JSON)_

Confirmation of saved format

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `format` | string | The saved format value |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid format value (must be 'qwk' or 'qwke') |
| 403 | QWK feature is disabled |

---

#### `POST /api/qwk/reset`

**Requires authentication**

Purges all QWK-related database records (conference state, download log, message index, imported hashes) for the authenticated user, allowing packets to be re-downloaded from scratch. Only available when IS_DEV=true in environment configuration.

**Response** _(JSON)_

Reset confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if reset completed |
| `error` | string | Error message if reset failed |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Not in dev mode (IS_DEV != 'true') |
| 500 | Database operation failed |

---

#### `GET /api/qwk/area-selections`

**Requires authentication**

Returns the user's current QWK area selection (if custom mode is active) plus the full list of areas they are subscribed to. Used by the UI to render the area picker. When custom selection is inactive, the selections array is empty (indicating all subscribed areas are used).

**Response** _(JSON)_

Area selection state and available areas

| Field | Type | Description |
|-------|------|-------------|
| `has_custom` | boolean | True if user has an explicit custom selection active |
| `selections` | array | Currently selected areas (empty if has_custom is false) |
| `selections[].id` | integer | Echo area ID |
| `selections[].tag` | string | Echo area tag |
| `selections[].domain` | string | Echo area domain |
| `selections[].description` | string | Echo area description |
| `subscribed` | array | All echo areas the user is subscribed to |
| `subscribed[].id` | integer | Echo area ID |
| `subscribed[].tag` | string | Echo area tag |
| `subscribed[].domain` | string | Echo area domain |
| `subscribed[].description` | string | Echo area description |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | QWK feature is disabled |

---

#### `POST /api/qwk/area-selections`

**Requires authentication**

Replaces the user's QWK area selection with the provided list of echoarea IDs. An empty array clears custom selection and reverts to using all subscribed areas. Validates that each area is active and accessible (respects sysop-only restrictions). Atomically updates the selection and toggles custom mode flag.

**Request Body** _(JSON)_

Area selection update

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `echoarea_ids` | array | Yes | Array of echoarea IDs to select (empty array clears custom selection) |
| `reset` | boolean | No | If true, clears custom mode and reverts to all-subscribed behavior |

**Response** _(JSON)_

Confirmation of saved selection

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if selection was saved |
| `count` | integer\|null | Number of areas saved; null if reset=true |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing or invalid echoarea_ids array |
| 403 | QWK feature is disabled or user lacks access to specified areas |

---

#### `GET /api/qwk/area-search`

**Requires authentication**

Full-text search across active echoareas by tag or description. Returns up to 20 matching results. Respects sysop-only restrictions for non-admin users. Requires minimum 2-character search term. Used by the area picker UI to help users discover and add areas.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `q` | string | Yes | Search term (minimum 2 characters) |

**Response** _(JSON)_

Search results

| Field | Type | Description |
|-------|------|-------------|
| `areas` | array | Matching echo area objects (up to 20 results) |
| `areas[].id` | integer | Echo area ID |
| `areas[].tag` | string | Echo area tag |
| `areas[].domain` | string | Echo area domain |
| `areas[].description` | string | Echo area description |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | QWK feature is disabled |

---

### Referrals

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/referrals/my-stats`](#get-apireferralsmy-stats) | Yes | Get authenticated user's referral statistics. |
| `GET` | [`/api/referrals/admin/stats`](#get-apireferralsadminstats) | Yes | Get system-wide referral statistics (admin only). |

#### `GET /api/referrals/my-stats`

**Requires authentication**

Returns the user's referral code, shareable referral URL, list of users they've referred, total referral count, earnings from referrals, and the per-referral bonus amount. Requires authentication and returns 404 if user has no referral code.

**Response** _(JSON)_

User's referral statistics and earnings

| Field | Type | Description |
|-------|------|-------------|
| `referral_code` | string | Unique referral code for this user |
| `referral_url` | string | Full URL for sharing referral link |
| `referrals` | array | List of users referred by this user |
| `referrals[].username` | string | Username of the referred user |
| `referrals[].real_name` | string | Real name of the referred user |
| `referrals[].created_at` | string | Account creation timestamp (ISO 8601) |
| `total_count` | integer | Total number of users referred |
| `total_earned` | integer | Total credits earned from referral bonuses |
| `referral_bonus` | integer | Credits awarded per successful referral |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 404 | Referral code not found for user |

---

#### `GET /api/referrals/admin/stats`

**Requires authentication**

Returns aggregated referral metrics including total referrals, top 10 referrers with counts, 10 most recent referrals, and total credits awarded system-wide. Requires admin authentication.

**Response** _(JSON)_

System-wide referral statistics

| Field | Type | Description |
|-------|------|-------------|
| `total_referrals` | integer | Total number of users referred across system |
| `top_referrers` | array | Top 10 referrers |
| `top_referrers[].username` | string | Referrer's username |
| `top_referrers[].real_name` | string | Referrer's real name |
| `top_referrers[].referral_count` | integer | Number of successful referrals |
| `recent_referrals` | array | 10 most recent referral signups |
| `recent_referrals[].username` | string | Referred user's username |
| `recent_referrals[].created_at` | string | Account creation timestamp (ISO 8601) |
| `recent_referrals[].referrer` | string | Username of the referrer |
| `total_credits_awarded` | integer | Total credits distributed as referral bonuses |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin privileges required |

---

### Register

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `POST` | [`/api/register`](#post-apiregister) | No | Register a new user account with anti-spam protections. |

#### `POST /api/register`

Public

Creates a new user account with built-in anti-spam validation (honeypot, timing checks). Supports both JSON and form-encoded requests. Terminal clients (telnet/SSH) can bypass browser-only anti-spam checks by providing a valid X-Binkterm-Registration-Token header. Accepts optional X-Binkterm-Registration-Source and X-Binkterm-Client-IP headers for terminal registrations.

**Request Body** _(JSON)_

User registration data (JSON or form-encoded)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Desired username |
| `password` | string | Yes | Account password |
| `email` | string | Yes | Email address |
| `website` | string | No | Honeypot field—must be empty or request fails silently |

**Response** _(JSON)_

Registration result with success status and optional email confirmation details

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether registration succeeded |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid submission (honeypot triggered, too fast, missing fields, or validation failed) |
| 429 | Rate limit exceeded |
| 500 | Server error during registration |

---

### Shoutbox

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/shoutbox`](#get-apishoutbox) | Yes | Retrieve recent shoutbox messages with pagination. |
| `POST` | [`/api/shoutbox`](#post-apishoutbox) | Yes | Post a new message to the shoutbox. |

#### `GET /api/shoutbox`

**Requires authentication**

Fetches non-hidden shoutbox messages ordered by creation date (newest first). Supports pagination via limit and offset query parameters. Limit is capped at 100 and defaults to 20. Includes username and timestamp for each message.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `limit` | integer | No | Number of messages to return (default 20, max 100) |
| `offset` | integer | No | Number of messages to skip for pagination (default 0) |

**Response** _(JSON)_

JSON object containing array of shoutbox messages

| Field | Type | Description |
|-------|------|-------------|
| `messages` | array of objects | Array of shoutbox message objects |
| `messages[].id` | integer | Message ID |
| `messages[].message` | string | Message text |
| `messages[].created_at` | string | ISO 8601 creation timestamp |
| `messages[].username` | string | Username of the poster |

---

#### `POST /api/shoutbox`

**Requires authentication**

Adds a new message to the shoutbox for the authenticated user. Validates message is not empty and does not exceed 280 characters. Returns success confirmation or validation error with localized message.

**Request Body** _(JSON)_

JSON object with shoutbox message

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `message` | string | Yes | Message text (1-280 characters) |

**Response** _(JSON)_

JSON object with success status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True when message is posted |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Message is empty or exceeds 280 characters |

---

### Stream

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/stream`](#get-apistream) | No | Server-Sent Events stream for real-time updates. |
| `POST` | [`/api/stream`](#post-apistream) | Yes | Execute a real-time command (e.g., presence, notifications). |

#### `GET /api/stream`

Public

Establishes a short-lived SSE connection that pushes events newer than the client's Last-Event-ID cursor. Connection closes after sending buffered events with a 'reconnect' event, allowing clients to reconnect without hammering the server. No authentication required; user context determined from session if available.

**Response** _(JSON)_

Server-Sent Events stream

| Field | Type | Description |
|-------|------|-------------|
| `id` | string | Event ID (numeric cursor for reconnection) |
| `event` | string | Event type (e.g., 'message', 'user_online', 'reconnect') |
| `data` | string | JSON-encoded event payload |

---

#### `POST /api/stream`

**Requires authentication**

Dispatches real-time commands to the CommandDispatcher for actions like updating presence, triggering notifications, or other real-time state changes. Command and payload structure depend on registered command handlers.

**Request Body** _(JSON)_

Real-time command

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `command` | string | Yes | Command name (case-insensitive) |
| `payload` | object | Yes | Command-specific payload |

**Response** _(JSON)_

Command execution result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether command executed successfully |
| `*` | mixed | Command-specific response fields |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid payload (not JSON or missing command/payload) |
| 400 | Unknown real-time command |

---

### Subscriptions

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/subscriptions/user`](#get-apisubscriptionsuser) | No | Retrieve user subscription information. |
| `POST` | [`/api/subscriptions/user`](#post-apisubscriptionsuser) | No | Create or update user subscription. |
| `GET` | [`/api/subscriptions/admin`](#get-apisubscriptionsadmin) | No | Retrieve admin subscription statistics. |
| `POST` | [`/api/subscriptions/admin`](#post-apisubscriptionsadmin) | No | Manage admin subscription settings. |

#### `GET /api/subscriptions/user`

**Requires authentication**

Fetches all active echo areas with the authenticated user's subscription status for each.

**Response** _(JSON)_

Echo areas with per-user subscription state

| Field | Type | Description |
|-------|------|-------------|
| `echoareas` | array | All active echo areas visible to the user |
| `echoareas[].id` | integer | Echo area ID |
| `echoareas[].tag` | string | Echo area tag |
| `echoareas[].description` | string | Human-readable description |
| `echoareas[].domain` | string | Domain (e.g. `"lovlynet"`) |
| `echoareas[].is_local` | boolean | Whether the area is local-only |
| `echoareas[].is_sysop_only` | boolean | Whether the area is restricted to sysops |
| `echoareas[].is_default_subscription` | boolean | Whether new users are auto-subscribed |
| `echoareas[].is_new` | boolean | True if the area was created in the last 30 days |
| `echoareas[].subscribed` | boolean\|null | True if the user has an active subscription, null if never subscribed |
| `echoareas[].subscription_type` | string\|null | `"user"` (manually subscribed) or `"auto"` (default subscription), null if not subscribed |
| `echoareas[].subscribed_at` | string\|null | ISO 8601 timestamp when the user subscribed, null if not subscribed |

---

#### `POST /api/subscriptions/user`

**Requires authentication**

Subscribe or unsubscribe the authenticated user from an echo area.

**Request Body** _(JSON)_

Subscription action

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | Either `"subscribe"` or `"unsubscribe"` |
| `echoarea_id` | integer | Yes | Echo area to act on |

**Response** _(JSON)_

Subscription action result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if the action was applied |
| `message_code` | string | Localization key for UI message (present on success; e.g. `"ui.user_subscriptions.subscribed_success"`) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing echoarea_id or invalid action |
| 401 | Authentication required |

---

#### `GET /api/subscriptions/admin`

**Requires authentication**

Fetches all active echo areas with subscriber statistics and system-wide subscription totals. Requires admin privileges.

**Response** _(JSON)_

Echo area subscription statistics

| Field | Type | Description |
|-------|------|-------------|
| `echoareas` | array | Active echo areas with subscriber counts |
| `echoareas[].id` | integer | Echo area ID |
| `echoareas[].tag` | string | Echo area tag |
| `echoareas[].description` | string | Description |
| `echoareas[].is_default_subscription` | boolean | Whether the area is a default subscription |
| `echoareas[].subscriber_count` | integer | Total active subscribers |
| `echoareas[].user_subscribers` | integer | Subscribers with type `"user"` (manually subscribed) |
| `echoareas[].auto_subscribers` | integer | Subscribers with type `"auto"` (default subscription) |
| `stats` | object | System-wide subscription totals |
| `stats.total_echoareas` | integer | Total active echo areas |
| `stats.default_echoareas` | integer | Echo areas marked as default subscriptions |
| `stats.total_subscriptions` | integer | Total active subscriptions across all users |
| `stats.subscribed_users` | integer | Number of distinct users with at least one active subscription |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 403 | Admin access required |

---

#### `POST /api/subscriptions/admin`

**Requires authentication**

Update administrative subscription settings for an echo area. Requires admin privileges.

**Request Body** _(JSON)_

Admin subscription action

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `action` | string | Yes | Currently supported: `"set_default"` |
| `echoarea_id` | integer | Yes | Echo area to update |
| `is_default` | boolean | No | Required for `set_default`; true to mark as default, false to unmark |

**Response** _(JSON)_

Admin action result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if the action was applied |
| `message_code` | string | Localization key for UI message (present on success; e.g. `"ui.admin_subscriptions.default_enabled_success"`) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Missing echoarea_id or invalid action |
| 401 | Authentication required |
| 403 | Admin access required |

---

### System

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/system/status`](#get-apisystemstatus) | Yes | Get basic system status information. |

#### `GET /api/system/status`

**Requires authentication**

Returns system-level statistics including message count for the current day. The last_poll field is not yet implemented. This endpoint provides minimal status info; more comprehensive monitoring may require additional endpoints.

**Response** _(JSON)_

System status metrics

| Field | Type | Description |
|-------|------|-------------|
| `last_poll` | null | Last BinkP poll timestamp (not yet implemented) |
| `messages_today` | integer | Count of echomail messages received today |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

### Taglines

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/taglines`](#get-apitaglines) | Yes | Retrieve all configured BBS taglines. |

#### `GET /api/taglines`

**Requires authentication**

Loads and returns all taglines from the BBS taglines configuration file. Taglines are parsed from newline-separated entries and empty lines are filtered out. Useful for displaying random taglines in the UI.

**Response** _(JSON)_

List of available taglines

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether taglines were successfully loaded |
| `taglines` | array of strings | Plain text tagline strings, one per entry |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load taglines |

---

### Test

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/test`](#get-apitest) | No | Simple health check endpoint. |

#### `GET /api/test`

Public

Returns a basic success response with current server timestamp. Useful for verifying API availability and connectivity.

**Response** _(JSON)_

Test success response with timestamp

| Field | Type | Description |
|-------|------|-------------|
| `test` | string | Always 'success' |
| `timestamp` | string | Current server time (Y-m-d H:i:s format) |

---

### Url Preview

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/url-preview`](#get-apiurl-preview) | Yes | Fetch Open Graph metadata from a URL for preview unfurling. |

#### `GET /api/url-preview`

**Requires authentication**

Retrieves Open Graph and meta tags from a given URL for rich preview display in the compose UI. Includes SSRF protection: blocks requests to private IP ranges (10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16, 127.0.0.0/8, 169.254.0.0/16). Follows redirects (max 5) with 8-second timeout. Validates URL format and protocol (http/https only).

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `url` | string | Yes | URL to fetch preview for (must start with http:// or https://) |

**Response** _(JSON)_

Open Graph metadata or error

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Fetch status |
| `title` | string | og:title or page title |
| `description` | string | og:description or meta description |
| `image` | string | og:image URL |
| `error_code` | string | Error code if fetch failed |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid URL format, private IP range, or fetch timeout |
| 401 | Authentication required |

---

### User

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/user/echomail-ignore-rules`](#get-apiuserechomail-ignore-rules) | Yes | Retrieve all echomail ignore rules for the authenticated user. |
| `DELETE` | [`/api/user/echomail-ignore-rules/{id}`](#delete-apiuserechomail-ignore-rulesid) | Yes | Delete an echomail ignore rule for the authenticated user. |
| `GET` | [`/api/user/profile`](#get-apiuserprofile) | Yes | Retrieve the authenticated user's profile information. |
| `GET` | [`/api/user/public-profile/{id}`](#get-apiuserpublic-profileid) | Yes | Retrieve public profile information for an active user by ID. |
| `POST` | [`/api/user/change-password`](#post-apiuserchange-password) | Yes | Change the authenticated user's password. |
| `POST` | [`/api/user/profile`](#post-apiuserprofile) | Yes | Update the authenticated user's profile information. |
| `GET` | [`/api/user/stats`](#get-apiuserstats) | Yes | Retrieve message and file transfer statistics for the authenticated user. |
| `GET` | [`/api/user/stats/{userId}`](#get-apiuserstatsuserid) | Yes | Retrieve user statistics including message counts. |
| `GET` | [`/api/user/transactions/{userId}`](#get-apiusertransactionsuserid) | Yes | Retrieve paginated transaction history for a user. |
| `GET` | [`/api/user/activity/{userId}`](#get-apiuseractivityuserid) | Yes | Retrieve paginated activity log for a user. |
| `GET` | [`/api/user/credits`](#get-apiusercredits) | Yes | Get current user's credit balance. |
| `GET` | [`/api/user/sessions`](#get-apiusersessions) | Yes | List all active sessions for authenticated user. |
| `DELETE` | [`/api/user/sessions/{sessionId}`](#delete-apiusersessionssessionid) | Yes | Revoke a specific user session. |
| `DELETE` | [`/api/user/sessions/all`](#delete-apiusersessionsall) | Yes | Revoke all sessions for authenticated user. |
| `GET` | [`/api/user/echolist-preference`](#get-apiuserecholist-preference) | Yes | Retrieve echolist filter preferences for authenticated user. |
| `POST` | [`/api/user/echolist-preference`](#post-apiuserecholist-preference) | Yes | Update echolist filter preferences for authenticated user. |
| `POST` | [`/api/user/activity`](#post-apiuseractivity) | Yes | Update user's current activity status. |
| `GET` | [`/api/user/shares`](#get-apiusershares) | Yes | List all message shares created by the authenticated user. |
| `GET` | [`/api/user/settings`](#get-apiusersettings) | Yes | Retrieve authenticated user's settings and preferences. |
| `POST` | [`/api/user/settings`](#post-apiusersettings) | Yes | Update authenticated user's settings and preferences. |
| `POST` | [`/api/user/reset-onboarding`](#post-apiuserreset-onboarding) | Yes | Reset echomail onboarding flag for user. |
| `GET` | [`/api/user/mcp-key`](#get-apiusermcp-key) | Yes | Check MCP server key enrollment status. |
| `POST` | [`/api/user/mcp-key/generate`](#post-apiusermcp-keygenerate) | Yes | Generate new MCP server authentication key. |
| `DELETE` | [`/api/user/mcp-key`](#delete-apiusermcp-key) | Yes | Revoke user's MCP server key. |
| `GET` | [`/api/user/packetbbs-totp/status`](#get-apiuserpacketbbs-totpstatus) | Yes | Check PacketBBS TOTP enrollment status. |
| `POST` | [`/api/user/packetbbs-totp/setup`](#post-apiuserpacketbbs-totpsetup) | Yes | Generate a new pending TOTP secret for PacketBBS authenticator enrollment. |
| `POST` | [`/api/user/packetbbs-totp/verify-enrollment`](#post-apiuserpacketbbs-totpverify-enrollment) | Yes | Verify TOTP code and activate the pending secret. |
| `POST` | [`/api/user/packetbbs-totp/disable`](#post-apiuserpacketbbs-totpdisable) | Yes | Disable and clear the user's PacketBBS TOTP secret. |
| `GET` | [`/api/user/meshcore/contacts`](#get-apiusermeshcorecontacts) | Yes | List the current user's registered MeshCore radio contacts. |
| `POST` | [`/api/user/meshcore/contacts`](#post-apiusermeshcorecontacts) | Yes | Register a MeshCore radio contact for the current user. |
| `PUT` | [`/api/user/meshcore/contacts/{id}`](#put-apiusermeshcorecontactsid) | Yes | Update a user's MeshCore contact name. |
| `DELETE` | [`/api/user/meshcore/contacts/{id}`](#delete-apiusermeshcorecontactsid) | Yes | Delete a user's MeshCore contact. |
| `GET` | [`/api/user/terminal-settings`](#get-apiuserterminal-settings) | Yes | Retrieve user's terminal display settings. |
| `POST` | [`/api/user/terminal-settings`](#post-apiuserterminal-settings) | Yes | Update user's terminal display settings. |
| `GET` | [`/api/user/terminal-mail-state`](#get-apiuserterminal-mail-state) | Yes | Retrieve user's terminal mail navigation state. |
| `POST` | [`/api/user/terminal-mail-state`](#post-apiuserterminal-mail-state) | Yes | Update user's terminal mail navigation state. |
| `GET` | [`/api/user/web-mail-state`](#get-apiuserweb-mail-state) | Yes | Retrieve web-specific mail pagination state for authenticated user. |
| `POST` | [`/api/user/web-mail-state`](#post-apiuserweb-mail-state) | Yes | Update web mail pagination state for authenticated user. |

#### `GET /api/user/echomail-ignore-rules`

**Requires authentication**

Fetches the complete list of echomail ignore rules created by the authenticated user. Returns array of rule objects with sender name, address, and subject criteria.

**Response** _(JSON)_

User's echomail ignore rules

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation succeeded |
| `rules` | array<object> | Array of ignore rule objects with sender_name, sender_address, subject_contains |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `DELETE /api/user/echomail-ignore-rules/{id}`

**Requires authentication**

Removes a specific ignore rule belonging to the authenticated user. The rule ID must be a positive integer. Returns 404 if the rule does not exist or does not belong to the user. Returns 400 if the rule ID is invalid.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | The ignore rule ID to delete |

**Response** _(JSON)_

Confirmation of successful deletion

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `message_code` | string | Localization key for the success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid rule ID (must be positive integer) |
| 404 | Rule not found or does not belong to user |

---

#### `GET /api/user/profile`

**Requires authentication**

Returns basic profile fields for the authenticated user: email, location, and about_me bio. All fields are strings and may be empty.

**Response** _(JSON)_

User profile data

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |
| `profile` | object | Profile object containing email, location, about_me |
| `profile.email` | string | User email address |
| `profile.location` | string | User location |
| `profile.about_me` | string | User bio/about section |

---

#### `GET /api/user/public-profile/{id}`

**Requires authentication**

Returns a limited public profile for an active user. This endpoint is intended for authenticated client features such as the terminal Who's Online profile viewer and returns only public-facing fields rather than account management data.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | User ID of the active user whose public profile should be loaded |

**Response** _(JSON)_

Public profile fields

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always `true` on success |
| `profile` | object | Public profile data |
| `profile.user_id` | integer | User ID |
| `profile.username` | string | Username |
| `profile.real_name` | string | Full/real name (may be empty) |
| `profile.location` | string | Location (may be empty) |
| `profile.about_me` | string | Biography/about-me text (may be empty) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |
| 404 | User not found |

---

#### `POST /api/user/change-password`

**Requires authentication**

Updates the user's password after verifying the current password. New password must be at least 6 characters. Accepts JSON request body with old_password and new_password fields.

**Request Body** _(JSON)_

Password change request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `old_password` | string | Yes | Current password for verification |
| `new_password` | string | Yes | New password (minimum 6 characters) |

**Response** _(JSON)_

Success response with localization key

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Password updated successfully |
| `message_code` | string | Localization key: 'ui.profile.updated_successfully' |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid input, current password incorrect, or new password too short |
| 500 | Failed to update password |

---

#### `POST /api/user/profile`

**Requires authentication**

Updates email, location, and about_me fields. Optionally changes password if current_password and new_password are provided. Real name cannot be changed. Accepts JSON or form-encoded input.

**Request Body** _(JSON)_

Profile update request

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `real_name` | string | No | Real name (read-only, ignored) |
| `email` | string | No | Email address |
| `location` | string | No | User location |
| `about_me` | string | No | Bio/about section |
| `current_password` | string | No | Current password (required if changing password) |
| `new_password` | string | No | New password (minimum 6 characters) |

**Response** _(JSON)_

Success response with updated real name and localization key

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Profile updated successfully |
| `real_name` | string | User's real name (unchanged) |
| `message_code` | string | Localization key: 'ui.profile.updated_successfully' |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Current password incorrect, new password too short, or other validation error |
| 500 | Failed to update profile |

---

#### `GET /api/user/stats`

**Requires authentication**

Returns counts of netmail composed, echomail posted, and file downloads/uploads. Netmail count matches messages by username or real_name and local system addresses (includes pending/unspooled). Echomail count is user_id-based. File counts come from activity log (types 6=download, 7=upload).

**Response** _(JSON)_

User activity statistics

| Field | Type | Description |
|-------|------|-------------|
| `netmail_count` | integer | Number of netmail messages composed by user |
| `echomail_count` | integer | Number of echomail messages posted by user |
| `downloads` | integer | Number of file downloads |
| `uploads` | integer | Number of file uploads |

---

#### `GET /api/user/stats/{userId}`

**Requires authentication**

Fetches aggregated statistics for a specific user including netmail and echomail counts. Admin-only endpoint that verifies the target user exists and is active. Counts netmail by matching sender name (username or real name) and local system addresses to include pending/unspooled messages.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `userId` | integer | ID of the user to retrieve statistics for |

**Response** _(JSON)_

User statistics object with message counts

| Field | Type | Description |
|-------|------|-------------|
| `netmail_count` | integer | Total netmail messages composed by user |
| `echomail_count` | integer | Total echomail messages composed by user |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin privileges required |
| 404 | User not found or inactive |

---

#### `GET /api/user/transactions/{userId}`

**Requires authentication**

Returns transaction records for a specific user with pagination support. Admin-only endpoint. Transactions are ordered by creation date descending. Limit is capped at 50 records per request.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `userId` | integer | ID of the user to retrieve transactions for |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `offset` | integer | No | Number of records to skip (default: 0) |
| `limit` | integer | No | Number of records to return, max 50 (default: 10) |

**Response** _(JSON)_

Paginated transaction list

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `transactions` | array | Array of transaction objects |
| `transactions[].id` | integer | Transaction ID |
| `transactions[].user_id` | integer | User who owns this transaction |
| `transactions[].other_party_id` | integer\|null | Other party user ID (for transfers) |
| `transactions[].amount` | integer | Credit amount (positive = credit, negative = debit) |
| `transactions[].balance_after` | integer | Balance after this transaction |
| `transactions[].description` | string | Human-readable description |
| `transactions[].transaction_type` | string | Transaction type code |
| `transactions[].created_at` | string | ISO 8601 creation timestamp |
| `offset` | integer | Current offset used in query |
| `limit` | integer | Current limit used in query |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin privileges required |
| 404 | User not found or inactive |

---

#### `GET /api/user/activity/{userId}`

**Requires authentication**

Returns user activity log entries with category and activity type information. Admin-only endpoint. Activities are ordered by creation date descending. Limit is capped at 100 records per request.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `userId` | integer | ID of the user to retrieve activity log for |

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `offset` | integer | No | Number of records to skip (default: 0) |
| `limit` | integer | No | Number of records to return, max 100 (default: 25) |

**Response** _(JSON)_

Paginated activity log entries

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `activity` | array | Array of activity log entries |
| `activity[].id` | integer | Activity log entry ID |
| `activity[].created_at` | string | ISO 8601 timestamp of activity |
| `activity[].category` | string | Activity category name |
| `activity[].activity` | string | Activity type label |
| `activity[].object_name` | string\|null | Name of the object involved in the activity |
| `activity[].meta` | object\|null | Additional metadata (structure varies by activity type) |
| `offset` | integer | Current offset used in query |
| `limit` | integer | Current limit used in query |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Admin privileges required |
| 404 | User not found or inactive |

---

#### `GET /api/user/credits`

**Requires authentication**

Returns the authenticated user's current credit balance and basic user information. No admin privileges required.

**Response** _(JSON)_

User credit information

| Field | Type | Description |
|-------|------|-------------|
| `id` | integer | User ID |
| `username` | string | Username |
| `credit_balance` | integer | Current credit balance |

---

#### `GET /api/user/sessions`

**Requires authentication**

Returns all non-expired sessions for the authenticated user with IP addresses and creation timestamps. Marks the current session. Useful for session management and security monitoring.

**Response** _(JSON)_

List of active sessions

| Field | Type | Description |
|-------|------|-------------|
| `sessions` | array | Array of active session objects |
| `sessions[].id` | string | Session token ID |
| `sessions[].ip_address` | string\|null | IP address the session was created from |
| `sessions[].created_at` | string | Session creation timestamp (ISO 8601) |
| `sessions[].expires_at` | string | Session expiry timestamp (ISO 8601) |
| `sessions[].is_current` | integer | 1 if this is the currently active session, 0 otherwise |

---

#### `DELETE /api/user/sessions/{sessionId}`

**Requires authentication**

Deletes a single session belonging to the authenticated user. Users can only revoke their own sessions. Returns success message on deletion or 404 if session not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `sessionId` | string | Session ID to revoke |

**Response** _(JSON)_

Revocation confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Revocation success flag |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | Session not found or does not belong to user |

---

#### `DELETE /api/user/sessions/all`

**Requires authentication**

Deletes all sessions for the authenticated user and clears the session cookie, effectively logging out from all devices. Returns success message or 500 on failure.

**Response** _(JSON)_

Logout confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Logout success flag |
| `message_code` | string | Localization key for success message |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to revoke sessions |

---

#### `GET /api/user/echolist-preference`

**Requires authentication**

Returns the user's echolist display preferences including whether to show only subscribed echoes and/or only unread messages. Preferences are stored per-user in the user_settings table and default to false if not previously set.

**Response** _(JSON)_

User's echolist filter preferences

| Field | Type | Description |
|-------|------|-------------|
| `subscribed_only` | boolean | If true, display only subscribed echoes |
| `unread_only` | boolean | If true, display only echoes with unread messages |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/user/echolist-preference`

**Requires authentication**

Sets the user's echolist display preferences. Uses upsert logic to create or update the user_settings record. Boolean values are coerced from the input (any truthy value enables the filter).

**Request Body** _(JSON)_

Echolist filter preferences to set

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `subscribed_only` | boolean | No | Show only subscribed echoes |
| `unread_only` | boolean | No | Show only echoes with unread messages |

**Response** _(JSON)_

Confirmation of preference update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on successful update |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/user/activity`

**Requires authentication**

Records the user's current activity in their active session. Requires a valid session cookie. The activity string is stored and visible to admins in the whosonline endpoint.

**Request Body** _(JSON)_

Activity status to record

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `activity` | string | No | Description of current activity (e.g., 'Reading messages', 'Composing reply') |

**Response** _(JSON)_

Confirmation of activity update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if activity was recorded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | No active session found (missing session cookie) |
| 401 | Authentication required |

---

#### `GET /api/user/shares`

**Requires authentication**

Retrieves all active message shares owned by the authenticated user, including share keys, slugs, and metadata. Useful for managing and tracking shared messages.

**Response** _(JSON)_

User's message shares

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether the shares were successfully retrieved |
| `shares` | array | Array of message share objects |
| `shares[].id` | integer | Share record ID |
| `shares[].message_id` | integer | ID of the shared message |
| `shares[].message_type` | string | Message type ('echomail' or 'netmail') |
| `shares[].message_subject` | string\|null | Subject of the shared message |
| `shares[].area_tag` | string | Echo area tag (or 'netmail' for netmail shares) |
| `shares[].share_key` | string | Share token string |
| `shares[].share_url` | string | Full URL of the share link |
| `shares[].created_at` | string | Share creation timestamp (ISO 8601) |
| `shares[].expires_at` | string\|null | Share expiry timestamp; null for no expiry |
| `shares[].is_public` | boolean | Whether share is publicly accessible |
| `shares[].access_count` | integer | Number of times the share has been accessed |
| `shares[].last_accessed_at` | string\|null | Last access timestamp (ISO 8601) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load user shares |

---

#### `GET /api/user/settings`

**Requires authentication**

Fetches user settings including locale, shell preference, notification sounds, and composition options. Resolves and persists locale based on user preferences. Returns license validity status. Settings are merged from both user_settings table and UserMeta storage.

**Response** _(JSON)_

User settings object with locale, shell, notification preferences, and license status.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `settings` | object | User settings |
| `settings.locale` | string | UI locale code (e.g., 'en', 'fr') |
| `settings.timezone` | string | User's timezone (e.g., 'America/Los_Angeles') |
| `settings.theme` | string | UI theme (e.g., 'light', 'dark', 'amber') |
| `settings.messages_per_page` | integer | Number of messages shown per page |
| `settings.threaded_view` | boolean | Whether echomail is shown in threaded mode |
| `settings.netmail_threaded_view` | boolean | Whether netmail is shown in threaded mode |
| `settings.default_sort` | string | Default sort order (date_desc, date_asc, subject, author) |
| `settings.font_family` | string | UI font family |
| `settings.font_size` | integer | UI font size in pixels |
| `settings.date_format` | string | Date format locale code (e.g., 'en-US') |
| `settings.quote_coloring` | boolean | Whether quoted text is colorized |
| `settings.default_echo_list` | string | Default echo list view (reader, list) |
| `settings.signature_text` | string\|null | User's message signature |
| `settings.default_tagline` | string\|null | Default message tagline |
| `settings.shell` | string | UI shell preference ('web' or 'bbs-menu') |
| `settings.chat_notification_sound` | string | Chat notification sound (disabled, notify1–5) |
| `settings.echomail_notification_sound` | string | Echomail notification sound (disabled, notify1–5) |
| `settings.netmail_notification_sound` | string | Netmail notification sound (disabled, notify1–5) |
| `settings.file_notification_sound` | string | File notification sound (disabled, notify1–5) |
| `settings.compose_advanced_open` | boolean | Whether advanced compose panel is open by default |
| `settings.compose_hard_wrap` | integer | Hard-wrap column for message composition (0 = disabled) |
| `settings.media_render_mode` | string | Media rendering mode ('click', 'auto') |
| `settings.license_valid` | boolean | Whether the system has a valid license |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to load user settings |

---

#### `POST /api/user/settings`

**Requires authentication**

Updates user settings including locale, shell preference, and notification sounds. Validates notification sound values against allowed set (disabled, notify1-5). Shell changes respect AppearanceConfig lock. Locale changes are persisted. Composition settings (hard wrap, advanced mode) are stored in UserMeta.

**Request Body** _(JSON)_

Settings update payload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `settings` | object | Yes | Object containing settings to update: locale, shell, chat_notification_sound, echomail_notification_sound, netmail_notification_sound, file_notification_sound, compose_advanced_open, compose_hard_wrap, media_render_mode |

**Response** _(JSON)_

Confirmation of successful settings update.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid input or missing settings object |
| 500 | Failed to update user settings |

---

#### `POST /api/user/reset-onboarding`

**Requires authentication**

Clears the interests_onboarded flag in UserMeta, allowing the user to be guided through the echomail onboarding process again.

**Response** _(JSON)_

Confirmation of successful reset.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to reset onboarding flag |

---

#### `GET /api/user/mcp-key`

**Requires authentication**

Returns whether the user has an MCP server key enrolled. Shows only a preview (first 8 chars + asterisks) if key exists. Requires MCP_SERVER_URL environment variable and valid license.

**Response** _(JSON)_

MCP key enrollment status.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `has_key` | boolean | Whether user has an MCP key enrolled |
| `key_preview` | string | First 8 characters of key followed by asterisks (only if has_key is true) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | MCP services not enabled or valid license required |

---

#### `POST /api/user/mcp-key/generate`

**Requires authentication**

Creates a new 64-character hex-encoded MCP server key and stores it in UserMeta. Returns the full key only at generation time; subsequent retrievals show preview only. Requires MCP_SERVER_URL and valid license.

**Response** _(JSON)_

Newly generated MCP server key.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `key` | string | Full 64-character hex-encoded MCP server key |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | MCP services not enabled or valid license required |
| 500 | Failed to generate MCP key |

---

#### `DELETE /api/user/mcp-key`

**Requires authentication**

Deletes the user's MCP server key by setting it to null in UserMeta. Requires MCP_SERVER_URL and valid license.

**Response** _(JSON)_

Confirmation of successful key revocation.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | MCP services not enabled or valid license required |
| 500 | Failed to revoke MCP key |

---

#### `GET /api/user/packetbbs-totp/status`

**Requires authentication**

Returns whether the user has PacketBBS TOTP (time-based one-time password) authentication enabled. Status is stored in UserMeta.

**Response** _(JSON)_

PacketBBS TOTP enrollment status.

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `enabled` | boolean | Whether PacketBBS TOTP is enabled for user |

---

#### `POST /api/user/packetbbs-totp/setup`

**Requires authentication**

Initiates TOTP setup by generating a new secret and storing it as pending in user metadata. Returns the BASE32 secret, otpauth URI, and QR code SVG for client-side display. The secret is not activated until verified via /verify-enrollment. Useful for setting up time-based one-time password authentication.

**Response** _(JSON)_

TOTP setup data including secret and QR code

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `secret` | string | BASE32-encoded TOTP secret |
| `uri` | string | otpauth:// URI for authenticator apps |
| `qr_code` | string | QR code as data:image/svg+xml;base64 URI |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Setup failed (e.g., metadata write error) |

---

#### `POST /api/user/packetbbs-totp/verify-enrollment`

**Requires authentication**

Validates a 6-digit code against the pending TOTP secret. On success, promotes the pending secret to active, sets enrollment state to enabled, and clears the pending secret. Code must be exactly 6 digits. Fails if no pending secret exists or code is invalid.

**Request Body** _(JSON)_

TOTP code for verification

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `code` | string | Yes | 6-digit code from authenticator app |

**Response** _(JSON)_

Confirmation of successful enrollment

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if verification succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid code format (not 6 digits) or code verification failed |
| 400 | No pending secret found; setup must be initiated first |
| 500 | Failed to activate secret (metadata write error) |

---

#### `POST /api/user/packetbbs-totp/disable`

**Requires authentication**

Removes all TOTP-related metadata for the authenticated user, including active secret, pending secret, and enabled flag. Effectively disables two-factor authentication via TOTP for the user.

**Response** _(JSON)_

Confirmation of successful disabling

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if disabling succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 500 | Failed to disable authenticator (metadata write error) |

---

#### `GET /api/user/meshcore/contacts`

**Requires authentication**

Returns all MeshCore radio contacts registered by the current user.

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `contacts` | array | List of contact objects |
| `contacts[].id` | integer | Contact record ID |
| `contacts[].pub_key_prefix` | string | 12-char hex node ID prefix |
| `contacts[].pub_key_full` | string\|null | Full 64-char public key, if known |
| `contacts[].name` | string\|null | Display name |
| `contacts[].adv_type` | string\|null | Advertisement type reported by the radio |
| `contacts[].last_seen_at` | string\|null | ISO 8601 timestamp of last bridge contact |

---

#### `POST /api/user/meshcore/contacts`

**Requires authentication**

Registers a MeshCore radio contact for the current user. Accepts either a 12-character node ID prefix or a full 64-character public key hex string.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `node_id` | string | Yes | 12-char or 64-char lowercase hex node ID |
| `name` | string | No | Optional display name |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |
| `id` | integer | Newly created contact record ID |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | `errors.meshcore.invalid_node_id` — node_id must be 12 or 64 lowercase hex chars |
| 409 | `errors.meshcore.contact_exists` — a contact with this key already exists for the user |

---

#### `PUT /api/user/meshcore/contacts/{id}`

**Requires authentication**

Updates the display name of a MeshCore contact owned by the current user.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `name` | string | No | New display name (empty string clears the name) |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | `errors.meshcore.not_found` — contact not found or not owned by this user |

---

#### `DELETE /api/user/meshcore/contacts/{id}`

**Requires authentication**

Deletes a MeshCore contact owned by the current user.

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Operation success flag |

**Error Responses**

| Status | Description |
|--------|-------------|
| 404 | `errors.meshcore.not_found` — contact not found or not owned by this user |

---

#### `GET /api/user/terminal-settings`

**Requires authentication**

Fetches terminal configuration preferences for the authenticated user, including character set and ANSI color support. Returns current values from user metadata.

**Response** _(JSON)_

User terminal settings

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |
| `settings` | object | Terminal configuration settings |
| `settings.terminal_charset` | string\|null | Active character set: `utf8`, `cp437`, or `ascii`; null if not set |
| `settings.terminal_ansi_color` | string\|null | ANSI color mode: `yes` or `no`; null if not set |
| `settings.term_shell_mode` | string\|null | Terminal shell mode (e.g. `auto` or a configured shell name); null if not set |

---

#### `POST /api/user/terminal-settings`

**Requires authentication**

Updates terminal configuration preferences for the authenticated user. Accepts both wrapped (settings object) and flat request formats. Validates values against allowed options: terminal_charset (utf8, cp437, ascii) and terminal_ansi_color (yes, no).

**Request Body** _(JSON)_

Terminal settings to update (wrapped or flat)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `settings` | object | No | Wrapped settings object (alternative to flat format) |
| `terminal_charset` | string | No | Character set: utf8, cp437, or ascii |
| `terminal_ansi_color` | string | No | ANSI color support: yes or no |

**Response** _(JSON)_

Confirmation of update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if update succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid value for terminal_charset or terminal_ansi_color |

---

#### `GET /api/user/terminal-mail-state`

**Requires authentication**

Fetches saved terminal navigation state for the authenticated user, including mail-reader positions, the saved netmail and echomail sort selections, and the last selected local chat target. Used to restore UI state across sessions.

**Response** _(JSON)_

User mail navigation state

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true |
| `settings` | object | Saved terminal navigation state |
| `settings.terminal_netmail_page` | integer\|null | Last viewed netmail page number, or null if not set |
| `settings.terminal_netmail_selected_message_id` | integer\|null | ID of the last selected netmail message, or null if not set |
| `settings.terminal_netmail_folder` | string\|null | Last viewed netmail folder (`inbox` or `sent`), or null if not set |
| `settings.terminal_netmail_sort` | string\|null | Last used netmail sort order (`date_desc`, `date_asc`, `subject`, `author`), or null if not set |
| `settings.terminal_echomail_areas_page` | integer\|null | Last viewed echomail areas page number, or null if not set |
| `settings.terminal_echomail_positions` | object\|string\|null | Per-area read position map (JSON object or string), or null if not set |
| `settings.terminal_echomail_sort` | string\|null | Last used echomail sort order (`date_desc`, `date_asc`, `subject`, `author`), or null if not set |
| `settings.terminal_chat_target` | object\|string\|null | Last selected terminal chat target (JSON object or string), or null if not set |

---

#### `POST /api/user/terminal-mail-state`

**Requires authentication**

Persists terminal navigation state for the authenticated user, including mail-reader positions, the saved netmail and echomail sort selections, and the last selected local chat target. Accepts both wrapped and flat request formats. Integer fields must be positive or null; terminal_echomail_positions and terminal_chat_target accept JSON string or object.

**Request Body** _(JSON)_

Mail state to update (wrapped or flat)

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `settings` | object | No | Wrapped settings object (alternative to flat format) |
| `terminal_netmail_page` | integer | No | Current netmail page (≥1 or null) |
| `terminal_netmail_selected_message_id` | integer | No | Selected netmail message ID (≥1 or null) |
| `terminal_netmail_folder` | string | No | Saved netmail folder: `inbox` or `sent` |
| `terminal_netmail_sort` | string | No | Saved netmail list sort: `date_desc`, `date_asc`, `subject`, or `author` |
| `terminal_echomail_areas_page` | integer | No | Current echomail areas page (≥1 or null) |
| `terminal_echomail_positions` | object|string | No | Area positions as JSON object or string |
| `terminal_echomail_sort` | string | No | Saved echomail list sort: `date_desc`, `date_asc`, `subject`, or `author` |
| `terminal_chat_target` | object|string | No | Last selected terminal chat target as JSON object or string |

When `terminal_chat_target` is present it must include:

| Field | Type | Description |
|-------|------|-------------|
| `type` | string | `room` or `dm` |
| `id` | integer | Target room ID or DM user ID |
| `label` | string | Display label used when restoring the target |

**Response** _(JSON)_

Confirmation of update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if update succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid value for integer field (not numeric or < 1), invalid terminal_echomail_sort, or invalid terminal_echomail_positions/terminal_chat_target JSON |

---

#### `GET /api/user/web-mail-state`

**Requires authentication**

Fetches user metadata for web interface mail positions, including netmail page number and per-area echomail page positions. This state is separate from telnet-based navigation and persists user's browsing position across web sessions. Returns null values if not previously set.

**Response** _(JSON)_

User's web mail state settings

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always true on success |
| `settings` | object | Mail state object containing web_netmail_page and web_echomail_positions |
| `settings.web_netmail_page` | string|null | Current page number in netmail view |
| `settings.web_echomail_positions` | string|null | JSON-encoded object mapping area tags to {page: N} objects |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/user/web-mail-state`

**Requires authentication**

Persists user's web interface mail positions. Validates web_netmail_page as positive integer and web_echomail_positions as JSON object with area tags (max 128 chars) mapping to page numbers (minimum 1). Null values clear stored state. Rejects invalid formats with 400 error.

**Request Body** _(JSON)_

Mail state update payload

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `settings` | object | No | Object containing web_netmail_page and/or web_echomail_positions to update |
| `web_netmail_page` | integer|null | No | Page number (≥1) or null to clear |
| `web_echomail_positions` | object|string | No | Object or JSON string mapping area tags to {page: N}; pages <1 reset to 1 |

**Response** _(JSON)_

Confirmation of state update

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True if update succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid web_netmail_page (non-numeric or <1) or malformed web_echomail_positions JSON |
| 401 | Authentication required |

---

### Users

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/admin/users`](#get-apiadminusers) | Yes | List all active users with pagination and search. |
| `GET` | [`/api/admin/users/{id}`](#get-apiadminusersid) | Yes | Retrieve single user details for admin editing. |
| `POST` | [`/api/admin/users/{id}/credits`](#post-apiadminusersidcredits) | Yes | Grant credits to a user account |
| `POST` | [`/api/admin/users/{id}`](#post-apiadminusersid) | Yes | Update user account details |
| `POST` | [`/api/admin/users/{id}/toggle-status`](#post-apiadminusersidtoggle-status) | Yes | Toggle user active/inactive status |
| `POST` | [`/api/admin/users/create`](#post-apiadminuserscreate) | Yes | Create a new user account |
| `POST` | [`/api/admin/users/cleanup`](#post-apiadminuserscleanup) | Yes | Clean up old pending registrations |
| `POST` | [`/api/admin/users/{userId}/send-reminder`](#post-apiadminusersuseridsend-reminder) | Yes | Send account reminder to a user |
| `GET` | [`/api/admin/users/need-reminders`](#get-apiadminusersneed-reminders) | Yes | List users eligible for account reminders |

#### `GET /api/admin/users`

**Requires authentication**

Retrieves paginated list of active user accounts. Admin-only. Supports full-text search by username/email and configurable page size (max 100). Default limit is 25 per page.

**Query Parameters**

| Name | Type | Required | Description |
|------|------|----------|-------------|
| `page` | integer | No | Page number (default 1, minimum 1) |
| `limit` | integer | No | Results per page (default 25, max 100) |
| `search` | string | No | Search term for username/email filtering |

**Response** _(JSON)_

Paginated user list

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `users` | array | Array of user account objects |
| `users[].id` | integer | User ID |
| `users[].username` | string | Username |
| `users[].email` | string\|null | Email address |
| `users[].real_name` | string | Real name |
| `users[].fidonet_address` | string\|null | User's FidoNet address |
| `users[].created_at` | string | Account creation timestamp (ISO 8601) |
| `users[].last_login` | string\|null | Last login timestamp (ISO 8601) |
| `users[].last_reminded` | string\|null | Last reminder timestamp (ISO 8601) |
| `users[].is_active` | boolean | Whether account is active |
| `users[].is_admin` | boolean | Whether user has admin privileges |
| `users[].is_system` | boolean | Whether this is a system account |
| `users[].days_since_reminder` | integer\|null | Days since last reminder was sent |
| `pagination` | object | Pagination metadata |
| `pagination.page` | integer | Current page number |
| `pagination.limit` | integer | Results per page |
| `pagination.total` | integer | Total matching users |
| `pagination.pages` | integer | Total number of pages |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User is not an admin |
| 401 | Authentication required |
| 500 | Database error |

---

#### `GET /api/admin/users/{id}`

**Requires authentication**

Fetches a specific active user's editable fields including username, real name, email, credit balance, status flags, and timestamps. Admin-only. Returns 404 if user not found.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | User ID |

**Response** _(JSON)_

User details for editing

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | True on success |
| `user` | object | User account details |
| `user.id` | integer | User ID |
| `user.username` | string | Username |
| `user.real_name` | string | Real name |
| `user.email` | string\|null | Email address |
| `user.credit_balance` | integer | Current credit balance |
| `user.is_active` | boolean | Whether account is active |
| `user.is_admin` | boolean | Whether user has admin privileges |
| `user.is_system` | boolean | Whether this is a system account |
| `user.echomail_moderation_forced` | boolean | Whether echomail moderation is forced for this user |
| `user.created_at` | string | Account creation timestamp (ISO 8601) |
| `user.last_login` | string\|null | Last login timestamp (ISO 8601) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | User is not an admin |
| 404 | User not found |
| 401 | Authentication required |
| 500 | Database error |

---

#### `POST /api/admin/users/{id}/credits`

**Requires authentication**

Allows admins to manually grant credits to a user with a required note for audit purposes. The credits system must be enabled. Amount must be positive and a descriptive note is mandatory. Credits are recorded with admin adjustment type and include the granting admin's ID.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | Target user ID |

**Request Body** _(JSON)_

Credit grant details

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `amount` | integer | Yes | Positive credit amount to grant |
| `note` | string | Yes | Audit note explaining the credit grant |

**Response** _(JSON)_

Credit grant result with success status

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether credits were granted |
| `new_balance` | integer | User's updated credit balance |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 404 | Target user not found |
| 400 | Credits disabled, invalid amount, or missing note |

---

#### `POST /api/admin/users/{id}`

**Requires authentication**

Allows admins to modify user properties including name, email, status flags, and password. Real name is required. Password is optional; if provided, it replaces the current password. Moderation enforcement and system/admin flags can be toggled.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | User ID to update |

**Request Body** _(JSON)_

User update fields

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `real_name` | string | Yes | User's real name |
| `email` | string | No | User's email address |
| `is_active` | integer | No | 1 for active, 0 for inactive (default: 1) |
| `is_admin` | integer | No | 1 to grant admin, 0 to revoke (default: 0) |
| `is_system` | integer | No | 1 for system account, 0 otherwise (default: 0) |
| `echomail_moderation_forced` | integer | No | 1 to force moderation, 0 to allow (default: 0) |
| `password` | string | No | New password (if provided, updates user's password) |

**Response** _(JSON)_

Update confirmation

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether update succeeded |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 404 | User not found |
| 400 | Missing required real_name field |

---

#### `POST /api/admin/users/{id}/toggle-status`

**Requires authentication**

Quickly enable or disable a user account. Accepts is_active flag (1 for active, 0 for inactive). Returns success message with action description.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `id` | integer | User ID to toggle |

**Request Body** _(JSON)_

Status toggle

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `is_active` | integer | No | 1 to enable, 0 to disable (default: 1) |

**Response** _(JSON)_

Toggle result with localized message

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether toggle succeeded |
| `message_code` | string | Localization key for success message |
| `message_params` | object | Parameters for localized message |
| `message_params.action` | string | Action taken: 'enable' or 'disable' |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 404 | User not found or no rows affected |

---

#### `POST /api/admin/users/create`

**Requires authentication**

Admin endpoint to create user accounts with validation of username format, restricted names, and password strength. Username is normalized per config. Password must be at least 8 characters. Supports setting admin and system flags at creation.

**Request Body** _(JSON)_

New user details

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `username` | string | Yes | Unique username (normalized, validated against format and restrictions) |
| `real_name` | string | Yes | User's real name (validated against restrictions) |
| `password` | string | Yes | Password (minimum 8 characters) |
| `email` | string | No | User's email address |
| `is_active` | integer | No | 1 for active, 0 for inactive (default: 1) |
| `is_admin` | integer | No | 1 to create as admin, 0 otherwise (default: 0) |
| `is_system` | integer | No | 1 for system account, 0 otherwise (default: 0) |

**Response** _(JSON)_

New user creation result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether user was created |
| `user_id` | integer | ID of newly created user |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 400 | Missing required fields, invalid username format, restricted name, or password too short |
| 409 | Username already exists |

---

#### `POST /api/admin/users/cleanup`

**Requires authentication**

Performs full cleanup of old registration records including approved and rejected entries. Returns counts of removed records. Useful for maintenance and database hygiene.

**Response** _(JSON)_

Cleanup operation results

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether cleanup completed |
| `result` | object | Cleanup statistics |
| `result.approved_removed` | integer | Number of approved registration records removed |
| `result.old_rejected_removed` | integer | Number of old rejected registration records removed |
| `result.total_cleaned` | integer | Total records removed |
| `message_code` | string | Localization key for success message |
| `message_params` | object | Parameters for localized message |
| `message_params.approved` | integer | Count of approved records removed |
| `message_params.rejected` | integer | Count of rejected records removed |
| `message_params.total` | integer | Total records removed |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 500 | Cleanup operation failed |

---

#### `POST /api/admin/users/{userId}/send-reminder`

**Requires authentication**

Sends an account reminder message to a specific user. Checks if user is eligible for reminders before sending. Can send email notification if configured. Returns success status and email delivery confirmation.

**Path Parameters**

| Name | Type | Description |
|------|------|-------------|
| `userId` | integer | Target user ID |

**Response** _(JSON)_

Reminder send result

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether reminder was sent |
| `message_code` | string | Localization key for result message |
| `email_sent` | boolean | Whether email notification was delivered |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 404 | Target user not found |
| 400 | User is not eligible for reminder |
| 500 | Reminder send failed |

---

#### `GET /api/admin/users/need-reminders`

**Requires authentication**

Retrieves list of users who need account reminders sent. Useful for admin dashboard to identify inactive or at-risk accounts.

**Response** _(JSON)_

List of users needing reminders

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Whether query succeeded |
| `users` | array | Array of user objects needing reminders |
| `users[].id` | integer | User ID |
| `users[].username` | string | Username |
| `users[].real_name` | string | Real name |
| `users[].email` | string\|null | Email address |
| `users[].created_at` | string | Account creation timestamp (ISO 8601) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 403 | Requester is not an admin |
| 500 | Query failed |

---

### Verify

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/verify`](#get-apiverify) | No | Public endpoint returning system name and software version for network registry verification. |

#### `GET /api/verify`

Public

Returns identifying information about the BBS system without requiring authentication. Used by network registries like LovlyNet to verify site ownership and confirm the software in use. Response includes the configured system name and full software version string.

**Response** _(JSON)_

System identification data

| Field | Type | Description |
|-------|------|-------------|
| `system_name` | string | Configured BBS system name |
| `software` | string | Full software version string |

---

### Whosonline

| Method | Path | Auth | Summary |
|--------|------|------|---------|
| `GET` | [`/api/whosonline`](#get-apiwhosonline) | Yes | Get list of users currently online (last 15 minutes). |

#### `GET /api/whosonline`

**Requires authentication**

Returns active sessions from the past 15 minutes with user details. Admins receive additional fields including activity description, service type, and last activity timestamp. Non-admin users see only basic user info (id, username, location).

**Response** _(JSON)_

Online users and session count

| Field | Type | Description |
|-------|------|-------------|
| `users` | array | Array of online user objects |
| `users[].user_id` | integer | User ID |
| `users[].username` | string | Username |
| `users[].location` | string | User's location (may be empty) |
| `users[].activity` | string | Current activity description (admin only) |
| `users[].service` | string | Service type (e.g., 'web', 'binkp') (admin only) |
| `users[].last_activity_ts` | integer | Unix timestamp of last activity (admin only) |
| `online_user_count` | integer | Total count of online users |
| `online_minutes` | integer | Time window for online status (always 15) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `GET /api/config/term-menu-keys`

**Requires authentication**

Returns the effective terminal main menu key map for the current system. Used by the Telnet/SSH terminal server at session start to determine which key dispatches which action. Returns the sysop-configured custom map if one is saved; otherwise returns the built-in defaults. Actions absent from the map are disabled.

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | Always `true` |
| `term_menu_keys` | object | Map of action ID to single-character key string |
| `term_menu_keys.netmail` | string | Key for Netmail |
| `term_menu_keys.echomail` | string | Key for Echomail |
| `term_menu_keys.shoutbox` | string | Key for Shoutbox |
| `term_menu_keys.bulletins` | string | Key for Bulletins |
| `term_menu_keys.polls` | string | Key for Polls |
| `term_menu_keys.doors` | string | Key for Doors |
| `term_menu_keys.files` | string | Key for Files |
| `term_menu_keys.settings` | string | Key for Settings |
| `term_menu_keys.interests` | string | Key for Interests |
| `term_menu_keys.whosonline` | string | Key for Who's Online |
| `term_menu_keys.qwk` | string | Key for QWK offline mail |
| `term_menu_keys.bbslist` | string | Key for BBS List |
| `term_menu_keys.nodelist` | string | Key for Nodelist |
| `term_menu_keys.localchat` | string | Key for Local Chat |
| `term_menu_keys.quit` | string | Key to quit (always present) |

**Error Responses**

| Status | Description |
|--------|-------------|
| 401 | Authentication required |

---

#### `POST /api/admin/appearance/term-menu-keys`

**Requires admin**

Saves a custom terminal main menu key map. Each action maps to a unique single ASCII letter (`a-z`, stored lowercase). Actions omitted from the payload are disabled (hidden from the menu). `quit` must always be present.

**Request Body** _(JSON)_

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `term_menu_keys` | object | Yes | Map of action ID to key char. Known action IDs: `netmail`, `echomail`, `shoutbox`, `bulletins`, `polls`, `doors`, `files`, `settings`, `interests`, `whosonline`, `qwk`, `bbslist`, `nodelist`, `localchat`, `quit` |

**Response** _(JSON)_

| Field | Type | Description |
|-------|------|-------------|
| `success` | boolean | `true` on success |

**Error Responses**

| Status | Description |
|--------|-------------|
| 400 | Invalid key (not a single letter), duplicate key, or `quit` missing |
| 401 | Authentication required |
| 403 | Admin privileges required |
| 500 | Failed to save settings |

---
