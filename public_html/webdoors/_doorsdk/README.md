# WebDoor SDK

This directory contains common reusable functions and utilities (SDK) for WebDoor games to use when integrating with BinktermPHP.

## Purpose

The WebDoor SDK provides standardized interfaces and helper functions for:
- **API Communication**: Making authenticated API calls to BBS endpoints
- **Credits Integration**: Displaying and updating user credit balances
- **PostMessage Communication**: Communicating with the parent BBS window
- **User Information**: Accessing current user data and preferences
- **Common UI Patterns**: Shared UI components and styling helpers
- **Error Handling**: Standardized error reporting and display

## Structure

```
_doorsdk/
├── js/           # JavaScript SDK files
│   ├── api.js         # API communication helpers
│   ├── credits.js     # Credit system integration
│   └── messaging.js   # PostMessage API wrapper
└── php/          # PHP SDK files
    └── helpers.php    # Server-side utility functions
```

## Usage in WebDoors

### JavaScript

Include SDK files in your WebDoor's HTML:

```html
<script src="/webdoors/_doorsdk/js/api.js"></script>
<script src="/webdoors/_doorsdk/js/credits.js"></script>
```

### PHP

Include the SDK in your WebDoor's server-side code. **This should be the first include** in your PHP file:

```php
// Include WebDoor SDK (handles autoload, database, and session initialization)
require_once __DIR__ . '/../_doorsdk/php/helpers.php';
```

The SDK automatically handles:
- Defining `BINKTERMPHP_BASEDIR` constant
- Loading BinktermPHP autoloader (`vendor/autoload.php`)
- Initializing database connection
- Starting PHP session

This means you no longer need to include these in your WebDoor:
```php
// ❌ Don't do this - SDK handles it
require_once BINKTERMPHP_BASEDIR . '/vendor/autoload.php';
Database::getInstance();
session_start();
```

## Best Practices

1. **Use SDK functions instead of duplicating logic** - If functionality exists in the SDK, use it
2. **Contribute back to SDK** - If you create a reusable function, consider adding it to the SDK
3. **Maintain backward compatibility** - SDK changes should not break existing WebDoors
4. **Document new SDK functions** - Add clear comments and examples for new SDK features
5. **Version SDK carefully** - Consider versioning if making breaking changes

## Security Notes

- SDK functions handle authentication and authorization automatically
- Always validate user input even when using SDK helpers
- SDK enforces server-side credit transactions (client cannot directly modify credits)
- PostMessage APIs validate message origins for security

## Contributing to SDK

When adding new SDK functionality:
1. Add the function to the appropriate SDK file
2. Document with clear comments and parameter descriptions
3. Add usage examples in comments
4. Test with multiple WebDoors to ensure compatibility
5. Update this README with new features

---

**Note**: The underscore prefix (`_doorsdk`) indicates this is a system directory, not a game directory.
