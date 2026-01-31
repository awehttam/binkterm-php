# Code Duplication Analysis Report

**Generated:** 2026-01-30
**Scope:** PHP files in src/, routes/, scripts/, and public_html/ (excluding vendor/)

This report identifies duplicate code patterns across the BinktermPHP codebase and provides recommendations for consolidation to improve maintainability, reduce bugs, and follow DRY (Don't Repeat Yourself) principles.

---

## Executive Summary

### Key Findings

- **143 instances** of authentication initialization pattern
- **228 instances** of HTTP response code setting
- **41 instances** of JSON input parsing
- **194 instances** of `new Auth()` object creation
- **63 instances** of user queries across 25 files
- **30+ admin authentication checks** in admin-routes.php alone

### Priority Consolidation Opportunities

1. **HIGH**: Authentication and authorization middleware
2. **HIGH**: API response formatting and error handling
3. **MEDIUM**: Database query patterns for common operations
4. **MEDIUM**: Validation logic for user inputs
5. **LOW**: Configuration access patterns

---

## 1. Authentication and Authorization Patterns

### 1.1 Repeated Authentication Initialization

**Pattern Found:** Every route file and controller repeats the same authentication initialization code.

**Locations:**
- `routes/admin-routes.php`: 62 occurrences
- `routes/api-routes.php`: 76 occurrences
- `routes/web-routes.php`: 3 occurrences
- `src/SubscriptionController.php`: 2 occurrences

**Example Duplication:**

```php
// Pattern repeated across 143 locations
$auth = new Auth();
$user = $auth->requireAuth();
```

**Found in:**
- `routes/admin-routes.php` (lines 15-16, 43-44, 55-56, 67-68, etc.)
- `routes/web-routes.php` (lines 208-209, 258-259, 281-282)
- Multiple other route handlers

**Impact:** HIGH - Every authenticated endpoint repeats this 2-line pattern

**Consolidation Recommendation:**

Create a `RouteHelper` or `Middleware` class:

```php
class RouteHelper {
    public static function requireAuth(): array {
        $auth = new Auth();
        return $auth->requireAuth();
    }

    public static function requireAdmin(): array {
        $auth = new Auth();
        $user = $auth->requireAuth();
        $adminController = new AdminController();
        $adminController->requireAdmin($user);
        return $user;
    }
}
```

**Usage:**
```php
// Instead of:
$auth = new Auth();
$user = $auth->requireAuth();
$adminController = new AdminController();
$adminController->requireAdmin($user);

// Use:
$user = RouteHelper::requireAdmin();
```

---

### 1.2 Admin Access Verification

**Pattern Found:** Admin access check is duplicated 30+ times in `routes/admin-routes.php`

**Example Duplication:**

```php
// Pattern repeated 30+ times in admin-routes.php
$auth = new Auth();
$user = $auth->requireAuth();

$adminController = new AdminController();
$adminController->requireAdmin($user);
```

**Locations:**
- `routes/admin-routes.php`: Lines 15-19, 43-47, 55-59, 67-71, 79-83, 91-95, etc.

**Impact:** HIGH - Maintenance nightmare if authentication logic changes

**Consolidation Recommendation:**

Use router middleware or create a helper in `src/functions.php`:

```php
function requireAdmin(): array {
    $auth = new Auth();
    $user = $auth->requireAuth();

    $adminController = new AdminController();
    $adminController->requireAdmin($user);

    return $user;
}
```

**Alternative:** Implement SimpleRouter middleware for admin routes

---

### 1.3 Manual Admin Check Pattern

**Pattern Found:** Inline admin checking duplicated across route files

**Example Duplication:**

```php
// Pattern in routes/web-routes.php (lines 266-274)
if (!$user['is_admin']) {
    http_response_code(403);
    $template = new Template();
    $template->renderResponse('error.twig', [
        'error_title' => 'Access Denied',
        'error' => 'Only administrators can access BinkP functionality.'
    ]);
    return;
}
```

```php
// Similar pattern in routes/api-routes.php and other files
if (!$user['is_admin']) {
    http_response_code(403);
    echo json_encode(['error' => 'Admin access required']);
    exit;
}
```

**Locations:**
- `routes/web-routes.php`: Line 266
- `routes/api-routes.php`: Multiple locations
- `src/SubscriptionController.php`: Line 77-81
- `src/functions.php`: Line 141-146

**Impact:** MEDIUM - Inconsistent error responses across different endpoints

**Consolidation Recommendation:**

Add to `Auth` class:

```php
public function requireAdminAccess(): array {
    $user = $this->requireAuth();

    if (!$user['is_admin']) {
        http_response_code(403);
        throw new \Exception('Admin access required');
    }

    return $user;
}
```

---

## 2. API Response Patterns

### 2.1 JSON Response Headers

**Pattern Found:** Setting JSON content-type header before every API response

**Locations:** 7 files (routes/api-routes.php, routes/admin-routes.php, routes/webdoor-routes.php, src/Auth.php, src/Web/NodelistController.php, src/functions.php, public_html/webdoors/blackjack/index.php)

**Example Duplication:**

```php
// Repeated across multiple API endpoints
header('Content-Type: application/json');
echo json_encode(['error' => 'Some error']);
```

**Found in:**
- `routes/api-routes.php`: Lines 43, 68, 82, 135 (and many more - 158 occurrences total)
- `routes/webdoor-routes.php`: Lines 222, 236, 250, 266, 270, 284, 298, 312, 326
- `src/Auth.php`: Line 135

**Impact:** HIGH - 228 instances of `http_response_code()`, many with JSON responses

**Consolidation Recommendation:**

Create a `JsonResponse` utility class in `src/JsonResponse.php`:

```php
namespace BinktermPHP;

class JsonResponse {
    public static function success(array $data = [], int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['success' => true], $data));
        exit;
    }

    public static function error(string $message, int $statusCode = 400, array $extra = []): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode(array_merge(['error' => $message], $extra));
        exit;
    }

    public static function json(array $data, int $statusCode = 200): void {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
```

**Usage:**
```php
// Instead of:
header('Content-Type: application/json');
http_response_code(400);
echo json_encode(['error' => 'Username and password required']);
return;

// Use:
JsonResponse::error('Username and password required', 400);
```

---

### 2.2 JSON Input Parsing

**Pattern Found:** Reading and parsing JSON input from request body

**Locations:** 4 files with 41 total occurrences

**Example Duplication:**

```php
// Repeated in nearly every POST/PUT/PATCH API endpoint
$input = json_decode(file_get_contents('php://input'), true);
```

**Found in:**
- `routes/api-routes.php`: 24 occurrences
- `routes/admin-routes.php`: 14 occurrences
- `src/SubscriptionController.php`: 2 occurrences
- `public_html/webdoors/blackjack/index.php`: 1 occurrence

**Impact:** MEDIUM - Repetitive and could benefit from error handling

**Consolidation Recommendation:**

Add to `RouteHelper` or create `Request` class:

```php
class Request {
    public static function getJsonInput(): ?array {
        $input = file_get_contents('php://input');
        if (empty($input)) {
            return null;
        }

        $decoded = json_decode($input, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Invalid JSON input: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
```

---

## 3. Database Query Patterns

### 3.1 Database Connection Initialization

**Pattern Found:** Every class that uses the database has identical constructor pattern

**Locations:** 47 files

**Example Duplication:**

```php
// Repeated in almost every controller and service class
private $db;

public function __construct() {
    $this->db = Database::getInstance()->getPdo();
}
```

**Found in:**
- `src/Auth.php`: Lines 7, 10-11
- `src/AdminController.php`: Lines 7, 10-11
- `src/MessageHandler.php`: Lines 7, 10-11
- `src/PasswordResetController.php`: Lines 7, 10-11
- `src/SubscriptionController.php`: Lines 7, 10-11
- `src/WebDoorController.php`: Lines 10, 24-26
- And 41+ more files

**Impact:** MEDIUM - Creates tight coupling but low duplication risk

**Consolidation Recommendation:**

Create an abstract `BaseController` class:

```php
namespace BinktermPHP;

abstract class BaseController {
    protected $db;

    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }
}
```

Then extend it:
```php
class AdminController extends BaseController {
    // No need to define $db or constructor
}
```

---

### 3.2 User Query Patterns

**Pattern Found:** Querying users table with WHERE clause repeated across 25 files (63 occurrences)

**Example Duplication:**

```php
// Various user lookups scattered across codebase
SELECT * FROM users WHERE username = ?
SELECT * FROM users WHERE id = ?
SELECT * FROM users WHERE email = ?
SELECT id FROM users WHERE username = ?
```

**Locations:**
- `src/AdminController.php`: 10 occurrences (lines vary)
- `src/Auth.php`: Line 16
- `src/MessageHandler.php`: 4 occurrences
- `scripts/user-manager.php`: 4 occurrences
- Many test files

**Impact:** MEDIUM - Scattered user queries make schema changes difficult

**Consolidation Recommendation:**

Create a `UserRepository` class in `src/UserRepository.php`:

```php
namespace BinktermPHP;

class UserRepository {
    private $db;

    public function __construct() {
        $this->db = Database::getInstance()->getPdo();
    }

    public function findById(int $id): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function findByUsername(string $username): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        return $stmt->fetch() ?: null;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $stmt->execute([$email]);
        return $stmt->fetch() ?: null;
    }

    public function findByUsernameOrEmail(string $usernameOrEmail): ?array {
        $stmt = $this->db->prepare('
            SELECT * FROM users
            WHERE (username = ? OR email = ?) AND is_active = TRUE
        ');
        $stmt->execute([$usernameOrEmail, $usernameOrEmail]);
        return $stmt->fetch() ?: null;
    }
}
```

---

### 3.3 Count Queries

**Pattern Found:** Similar COUNT(*) queries across multiple files

**Example Duplication:**

```php
// Pattern repeated with slight variations
SELECT COUNT(*) as count FROM [table] WHERE [condition]
SELECT COUNT(*) as total FROM [table] WHERE [condition]
```

**Locations:**
- `scripts/activity_digest.php`: 10+ count queries (lines 160-195)
- `routes/api-routes.php`: 30+ count queries for stats
- `src/AdminController.php`: Multiple count queries for statistics

**Impact:** LOW - These are often contextual and specific

**Consolidation Recommendation:**

For frequently counted entities, add methods to repositories:

```php
class UserRepository {
    public function countActive(): int {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_active = TRUE");
        return (int)$stmt->fetch()['count'];
    }

    public function countAdmins(): int {
        $stmt = $this->db->query("SELECT COUNT(*) as count FROM users WHERE is_admin = TRUE");
        return (int)$stmt->fetch()['count'];
    }
}
```

---

## 4. Validation Patterns

### 4.1 Empty Input Validation

**Pattern Found:** Checking for empty inputs before processing

**Locations:** 44 files with 112 total occurrences

**Example Duplication:**

```php
// Repeated pattern across API endpoints
if (empty($username) || empty($password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Username and password required']);
    return;
}
```

**Found in:**
- `routes/api-routes.php`: 15 occurrences
- `src/AdminController.php`: 4 occurrences
- Many script files

**Impact:** MEDIUM - Inconsistent validation responses

**Consolidation Recommendation:**

Create a `Validator` class:

```php
namespace BinktermPHP;

class Validator {
    public static function required(array $fields, array $input): void {
        $missing = [];
        foreach ($fields as $field) {
            if (empty($input[$field])) {
                $missing[] = $field;
            }
        }

        if (!empty($missing)) {
            JsonResponse::error(
                ucfirst(implode(', ', $missing)) . ' required',
                400
            );
        }
    }

    public static function validateUsername(string $username): bool {
        return preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username) === 1;
    }

    public static function validatePassword(string $password): bool {
        return strlen($password) >= 8;
    }
}
```

---

### 4.2 Username/Password Validation

**Pattern Found:** Same validation logic for username and password duplicated

**Example Duplication:**

```php
// In routes/api-routes.php (lines 229-232)
if (!preg_match('/^[a-zA-Z0-9_]{3,20}$/', $username)) {
    echo json_encode(['error' => 'Username must be 3-20 characters, letters, numbers, and underscores only']);
    return;
}

if (strlen($password) < 8) {
    echo json_encode(['error' => 'Password must be at least 8 characters long']);
    return;
}
```

```php
// In src/PasswordResetController.php (lines 136-141)
if (strlen($newPassword) < 8) {
    return [
        'success' => false,
        'message' => 'Password must be at least 8 characters long.'
    ];
}
```

**Impact:** MEDIUM - Validation rules should be centralized

**Consolidation Recommendation:**

Use the `Validator` class suggested above, with consistent error responses.

---

### 4.3 FidoNet Address Validation

**Pattern Found:** FidoNet address validation logic duplicated

**Location:** `src/functions.php` has a helper, but inline checks exist elsewhere

**Example:**

```php
// In src/functions.php (lines 92-95)
function isValidFidonetAddress($address) {
    return preg_match('/^\d+:\d+\/\d+(?:\.\d+)?(?:@\w+)?$/', trim($address));
}
```

**Impact:** LOW - Already has a helper function, but usage could be more consistent

**Recommendation:** Ensure all FidoNet address validation uses the centralized function

---

## 5. Configuration Access Patterns

### 5.1 BinkpConfig Initialization

**Pattern Found:** Getting BinkpConfig instance with try-catch for defaults

**Example Duplication:**

```php
// Repeated pattern for getting system configuration
try {
    $binkpConfig = \BinktermPHP\Binkp\Config\BinkpConfig::getInstance();
    $systemName = $binkpConfig->getSystemName();
    $systemAddress = $binkpConfig->getSystemAddress();
} catch (\Exception $e) {
    $systemName = 'BinktermPHP System';
    $systemAddress = 'Not configured';
}
```

**Found in:**
- `routes/web-routes.php`: Lines 290-298
- `src/PasswordResetController.php`: Lines 232-237
- Multiple other locations

**Impact:** LOW - Fairly specific to context

**Consolidation Recommendation:**

Add default-safe getters to BinkpConfig:

```php
public function getSystemNameOrDefault(): string {
    try {
        return $this->getSystemName();
    } catch (\Exception $e) {
        return 'BinktermPHP System';
    }
}
```

---

## 6. Error Handling Patterns

### 6.1 Exception Throwing

**Pattern Found:** Similar exception patterns across files (239 occurrences in 36 files)

**Example Duplication:**

```php
// Common pattern
throw new \Exception('Some error message');
```

**Impact:** LOW - Exceptions are context-specific

**Recommendation:** Consider creating domain-specific exception classes:

```php
namespace BinktermPHP\Exceptions;

class AuthenticationException extends \Exception {}
class AuthorizationException extends \Exception {}
class ValidationException extends \Exception {}
class NotFoundException extends \Exception {}
```

---

### 6.2 Database Transaction Pattern

**Pattern Found:** Transaction handling is sometimes duplicated

**Example from `src/PasswordResetController.php` (lines 147-187):**

```php
try {
    $this->db->beginTransaction();

    // Multiple database operations

    $this->db->commit();
    return ['success' => true];
} catch (\Exception $e) {
    $this->db->rollBack();
    error_log("Error: " . $e->getMessage());
    return ['success' => false, 'message' => 'Failed'];
}
```

**Impact:** LOW - Context-specific

**Recommendation:** Consider a transaction helper method if this pattern grows:

```php
class Database {
    public function transaction(callable $callback) {
        try {
            $this->pdo->beginTransaction();
            $result = $callback($this->pdo);
            $this->pdo->commit();
            return $result;
        } catch (\Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
```

---

## 7. Template/UI Patterns

### 7.1 Template Rendering

**Pattern Found:** Template initialization and rendering is fairly consistent but could be centralized

**Example:**

```php
$template = new Template();
$template->renderResponse('some_template.twig', $data);
```

**Impact:** LOW - Already quite clean

**Recommendation:** Consider static helper if needed:

```php
Template::render('template.twig', $data);
```

---

## 8. Utility Functions Already Centralized

The codebase already has some good centralization in `src/functions.php`:

- `filterKludgeLines()` - Lines 4-31
- `generateInitials()` - Lines 37-56
- `quoteMessageText()` - Lines 62-86
- `isValidFidonetAddress()` - Lines 92-95
- `parseReplyToKludge()` - Lines 102-134
- `requireBinkpAdmin()` - Lines 137-149
- `generateTzutc()` - Lines 158-182

These are good examples of proper consolidation.

---

## Implementation Roadmap

### Phase 1: High Priority (Immediate Impact)

1. **Create `JsonResponse` utility class** (Reduces 200+ lines of duplication)
   - File: `src/JsonResponse.php`
   - Update: All API routes to use new class
   - Estimated effort: 4-6 hours

2. **Create `RouteHelper` with auth methods** (Reduces 143+ instances)
   - File: `src/RouteHelper.php`
   - Add: `requireAuth()`, `requireAdmin()` methods
   - Update: All route files
   - Estimated effort: 6-8 hours

3. **Create `Request` input parser** (Reduces 41 instances)
   - File: `src/Request.php`
   - Add: `getJsonInput()` method with error handling
   - Update: All routes that parse JSON
   - Estimated effort: 2-3 hours

### Phase 2: Medium Priority (Maintainability)

4. **Create `BaseController` abstract class** (Reduces constructor duplication)
   - File: `src/BaseController.php`
   - Update: 47+ controller files
   - Estimated effort: 3-4 hours

5. **Create `UserRepository` class** (Centralizes user queries)
   - File: `src/UserRepository.php`
   - Update: 25+ files with user queries
   - Estimated effort: 6-8 hours

6. **Create `Validator` utility class** (Centralizes validation)
   - File: `src/Validator.php`
   - Update: All routes with validation
   - Estimated effort: 4-5 hours

### Phase 3: Low Priority (Nice to Have)

7. **Create domain-specific exception classes**
   - Directory: `src/Exceptions/`
   - Files: AuthenticationException, ValidationException, etc.
   - Update: Error handling across codebase
   - Estimated effort: 3-4 hours

8. **Add repository classes for other entities**
   - Files: `MessageRepository.php`, `EchoareaRepository.php`, etc.
   - Update: Scattered queries
   - Estimated effort: 8-10 hours per repository

---

## Testing Strategy

For each consolidation:

1. **Before refactoring:**
   - Document all locations using the old pattern
   - Create/run existing tests to establish baseline

2. **During refactoring:**
   - Implement new consolidated class/method
   - Update one file at a time
   - Test each file after update

3. **After refactoring:**
   - Run full test suite
   - Check for any behavioral changes
   - Update documentation

---

## Risks and Mitigation

### Risk 1: Breaking Existing Functionality
**Mitigation:**
- Incremental updates, one pattern at a time
- Extensive testing after each change
- Keep old code temporarily with deprecation notices

### Risk 2: Inconsistent Error Responses
**Mitigation:**
- Document expected error format before consolidation
- Ensure new response classes match current behavior
- Update API documentation if response format changes

### Risk 3: Performance Impact
**Mitigation:**
- Profile critical paths before and after changes
- Ensure no N+1 query problems introduced
- Cache expensive operations where appropriate

---

## Conclusion

The BinktermPHP codebase has significant opportunities for consolidation, particularly in:

1. **Authentication/Authorization** - 143+ duplicate patterns
2. **API Response Formatting** - 228+ instances of similar code
3. **Database Queries** - 63+ user queries scattered across 25 files
4. **Input Validation** - 112+ empty checks and validation logic

Implementing the Phase 1 recommendations alone would:
- Reduce code duplication by ~400 lines
- Improve maintainability significantly
- Standardize error responses
- Make future changes safer and easier

The estimated total effort for all three phases is approximately 40-50 hours, but can be spread across multiple iterations with Phase 1 providing immediate value.

---

## Appendix: File Statistics

### Files with Most Duplication

| File | Auth Instances | JSON Responses | DB Queries | Priority |
|------|----------------|----------------|------------|----------|
| routes/api-routes.php | 76 | 158 | 26 | HIGH |
| routes/admin-routes.php | 62 | 38 | 4 | HIGH |
| src/AdminController.php | 0 | 1 | 10 | MEDIUM |
| src/MessageHandler.php | 2 | 0 | 17 | MEDIUM |
| routes/web-routes.php | 3 | 3 | 0 | LOW |

### Recommendations by Impact

**Highest Impact (>100 instances):**
1. JsonResponse class - affects 228 locations
2. RouteHelper::requireAuth() - affects 143 locations
3. Empty validation - affects 112 locations

**Medium Impact (40-100 instances):**
4. UserRepository - affects 63 locations
5. Request::getJsonInput() - affects 41 locations

**Lower Impact (<40 instances):**
6. BaseController - affects 47 locations but low risk
7. Exception classes - affects 239 locations but context-specific
