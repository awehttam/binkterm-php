# WebDoors Specification

## Overview

WebDoors is BinktermPHP's system for integrating external web-based games, applications, and terminal connections into the BBS. Each WebDoor is a self-contained application with its own manifest file that describes its capabilities, requirements, and configuration options.

WebDoors are displayed to users through the `/games` interface and can integrate with BBS features like storage, leaderboards, and the credits system.

## Directory Structure

WebDoors are installed in the `public_html/webdoors/` directory. Each WebDoor resides in its own subdirectory:

```
public_html/webdoors/
├── blackjack/
│   ├── webdoor.json       # Required manifest file
│   ├── index.php          # Entry point
│   ├── icon.svg           # Game icon
│   └── assets/            # Game assets
├── hangman/
│   ├── webdoor.json
│   ├── index.html
│   └── ...
└── revpol/
    ├── webdoor.json
    ├── index.php
    └── ...
```

## Manifest Format

Each WebDoor must include a `webdoor.json` manifest file in its root directory. The manifest describes the game's metadata, requirements, and default configuration.

### Manifest Schema

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "unique-game-id",
    "name": "Display Name",
    "version": "1.0.0",
    "author": "Author Name",
    "description": "Brief description of the game",
    "entry_point": "index.html",
    "icon": "icon.svg",
    "screenshots": []
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["storage", "leaderboard", "credits"],
    "permissions": ["user_display_name"]
  },
  "storage": {
    "max_size_kb": 256,
    "save_slots": 1
  },
  "multiplayer": {
    "enabled": false
  },
  "config": {
    "custom_setting": "default_value"
  }
}
```

### Field Descriptions

#### `webdoor_version` (string, required)
Version of the WebDoor specification. Current version is `"1.0"`.

#### `game` (object, required)
Core game metadata displayed to users.

- `id` (string, required): Unique identifier for the game. Used in configuration and API calls.
- `name` (string, required): Display name shown in the games list.
- `version` (string, required): Game version (semantic versioning recommended).
- `author` (string, required): Creator or developer name.
- `description` (string, required): Brief description shown in the games list.
- `entry_point` (string, required): Entry file relative to the WebDoor directory (e.g., `"index.html"`, `"index.php"`).
- `icon` (string, optional): Icon filename relative to the WebDoor directory. Defaults to `"icon.png"`.
- `screenshots` (array, optional): Array of screenshot filenames for future use.

#### `requirements` (object, optional)
Declares features and permissions the WebDoor needs.

- `min_host_version` (string): Minimum BinktermPHP version required.
- `features` (array): List of BBS features the game requires:
  - `"storage"`: Persistent storage API
  - `"leaderboard"`: Leaderboard/high score API
  - `"credits"`: BBS credits system integration
- `permissions` (array): User data the game needs access to:
  - `"user_display_name"`: Access to user's display name

#### `storage` (object, optional)
Storage requirements for games that save user data.

- `max_size_kb` (integer): Maximum storage size per user in kilobytes.
- `save_slots` (integer): Number of save slots per user.

#### `multiplayer` (object, optional)
Multiplayer capabilities (reserved for future use).

- `enabled` (boolean): Whether the game supports multiplayer.

#### `config` (object, optional)
Default configuration values. These serve as defaults and can be overridden by the sysop in `config/webdoors.json`.

Common configuration keys:
- `display_name`: Override the game's display name
- `display_description`: Override the game's description
- Game-specific settings (varies by WebDoor)

## Configuration System

WebDoors are configured through the `config/webdoors.json` file. This file controls which games are enabled and allows sysops to customize game settings.

### Configuration File Location

`config/webdoors.json`

### Configuration Format

```json
{
  "blackjack": {
    "enabled": true,
    "start_bet": 10,
    "display_name": "21 Blackjack"
  },
  "hangman": {
    "enabled": true
  },
  "revpol": {
    "enabled": true,
    "display_name": "Reverse Polarity BBS",
    "display_description": "Connect to the Reverse Polarity BBS",
    "host": "revpol.lovelybits.org",
    "port": "22",
    "proto": "ssh"
  }
}
```

### Configuration Fields

- `enabled` (boolean, required): Whether the game is active and visible to users.
- Custom settings: Any settings defined in the manifest's `config` section can be overridden here.
- `display_name` (string, optional): Override the game's display name in the games list.
- `display_description` (string, optional): Override the game's description.

### Configuration Priority

1. **Sysop Configuration** (highest priority): Values in `config/webdoors.json`
2. **Manifest Defaults** (fallback): Values in the WebDoor's `webdoor.json` config section
3. **Code Defaults** (lowest priority): Hardcoded defaults in the WebDoor's code

## Discovery and Activation

### Auto-Discovery

The system automatically discovers WebDoors by:
1. Scanning `public_html/webdoors/` for subdirectories
2. Looking for `webdoor.json` in each subdirectory
3. Parsing valid manifests and registering the WebDoor

### Activation

WebDoors are activated by:
1. Creating or editing `config/webdoors.json`
2. Adding an entry for the game with `"enabled": true`
3. The game immediately becomes available at `/games`

### Admin Interface

Sysops can manage WebDoors through the admin interface at `/admin/webdoors-config`, which provides:
- List of discovered WebDoors
- JSON editor for `config/webdoors.json`
- Enable/disable controls

## Requirements System

The requirements system ensures games only run when their dependencies are met.

### Feature Requirements

Games can require BBS features:
```json
"requirements": {
  "features": ["storage", "leaderboard", "credits"]
}
```

If a required feature is not available, the game is not shown in the games list.

### Permission Requirements

Games can request access to user data:
```json
"requirements": {
  "permissions": ["user_display_name"]
}
```

This declares what user information the game needs.

### Checking Requirements

The system validates requirements before displaying games:
```php
function checkManifestRequirements($manifest) {
    $requirements = $manifest['requirements'] ?? [];
    $features = $requirements['features'] ?? [];

    // Check each required feature
    foreach ($features as $feature) {
        if (!isFeatureAvailable($feature)) {
            return false;
        }
    }

    return true;
}
```

## Configuration Overrides

### Display Name Override

Sysops can customize how games appear to users:

**In manifest** (`public_html/webdoors/blackjack/webdoor.json`):
```json
{
  "game": {
    "name": "Blackjack"
  }
}
```

**In configuration** (`config/webdoors.json`):
```json
{
  "blackjack": {
    "enabled": true,
    "display_name": "21 Card Game"
  }
}
```

Users will see "21 Card Game" instead of "Blackjack" in the games list.

### Description Override

Similarly, descriptions can be customized:
```json
{
  "blackjack": {
    "enabled": true,
    "display_description": "Classic card game - try to beat the dealer!"
  }
}
```

### Custom Settings

Game-specific settings from the manifest's `config` section can be overridden:

**Manifest default**:
```json
{
  "config": {
    "start_bet": 10
  }
}
```

**Sysop override**:
```json
{
  "blackjack": {
    "enabled": true,
    "start_bet": 25
  }
}
```

## Game Access

### URL Structure

Games are accessed through standardized URLs:
- Game list: `/games`
- Play game: `/games/{game-id}`

Where `{game-id}` is the directory name of the WebDoor (e.g., `/games/blackjack`).

### Entry Points

When a user accesses `/games/blackjack`, the system:
1. Loads the WebDoor's manifest
2. Applies any configuration overrides
3. Serves the file specified in `entry_point`
4. Injects BBS context (user info, session, etc.)

## Integration with BBS Features

### User Authentication

WebDoors run within authenticated user sessions. Games can access:
- Username
- Display name (with permission)
- User ID
- Session token

### Storage API

Games requiring persistent storage use the BBS storage API to save/load user data.

### Leaderboard API

Games can submit high scores to the BBS leaderboard system for display on the main games page.

### Credits System

Games can integrate with the BBS credits/economy system to charge for plays or award winnings.

## Example: Simple HTML5 Game

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "mygame",
    "name": "My Game",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "A simple HTML5 game",
    "entry_point": "index.html",
    "icon": "icon.png"
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["leaderboard"]
  }
}
```

## Example: PHP-Based Application

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "phpapp",
    "name": "PHP Application",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Server-side PHP application",
    "entry_point": "index.php",
    "icon": "icon.svg"
  },
  "requirements": {
    "min_host_version": "1.0",
    "features": ["storage", "leaderboard", "credits"],
    "permissions": ["user_display_name"]
  },
  "storage": {
    "max_size_kb": 100,
    "save_slots": 3
  },
  "config": {
    "difficulty": "normal",
    "max_players": 10
  }
}
```

## Example: Terminal Gateway

```json
{
  "webdoor_version": "1.0",
  "game": {
    "id": "mybbs",
    "name": "My BBS",
    "version": "1.0.0",
    "author": "Your Name",
    "description": "Connect to my text-based BBS",
    "entry_point": "index.php",
    "icon": "icon.svg"
  },
  "requirements": {
    "min_host_version": "1.0",
    "permissions": ["user_display_name"]
  },
  "config": {
    "display_name": "My BBS",
    "display_description": "Connect to My BBS via telnet",
    "host": "mybbs.example.com",
    "port": "23",
    "proto": "telnet"
  }
}
```

**Sysop configuration** (`config/webdoors.json`):
```json
{
  "mybbs": {
    "enabled": true,
    "display_name": "Community BBS",
    "host": "bbs.example.org",
    "port": "23",
    "proto": "telnet"
  }
}
```

## Installation

To install a new WebDoor:

1. **Copy the WebDoor directory** to `public_html/webdoors/`:
   ```bash
   cp -r mygame/ public_html/webdoors/
   ```

2. **Verify the manifest** exists and is valid:
   ```bash
   cat public_html/webdoors/mygame/webdoor.json
   ```

3. **Enable the game** in `config/webdoors.json`:
   ```json
   {
     "mygame": {
       "enabled": true
     }
   }
   ```

4. **Access the game** at `/games` or directly at `/games/mygame`

## Best Practices

1. **Use semantic versioning** for game versions
2. **Provide meaningful descriptions** that help users understand what the game does
3. **Declare all requirements** your game needs
4. **Include an icon** (SVG or PNG) for better visual presentation
5. **Test with configuration overrides** to ensure defaults work
6. **Document custom config options** in your game's README
7. **Keep entry points simple** - use `index.html` or `index.php`
8. **Use unique game IDs** that won't conflict with other WebDoors

## Security Considerations

1. **User input validation**: Always validate and sanitize user input
2. **Path traversal**: Don't allow users to specify file paths
3. **SQL injection**: Use prepared statements for database queries
4. **XSS protection**: Escape output when displaying user-generated content
5. **Authentication**: WebDoors run in authenticated sessions - verify the user
6. **File permissions**: Ensure game files are readable but not writable by web server
7. **Configuration validation**: Validate configuration values from `config/webdoors.json`

## Troubleshooting

### Game not appearing in list
- Verify `webdoor.json` exists and is valid JSON
- Check that the game is enabled in `config/webdoors.json`
- Ensure requirements are met (features, permissions)
- Check file permissions on the WebDoor directory

### Configuration not taking effect
- Verify `config/webdoors.json` syntax
- Check that configuration keys match those in the manifest
- Restart PHP-FPM if using opcache
- Clear browser cache

### Entry point not loading
- Verify `entry_point` path is correct relative to WebDoor directory
- Check file permissions on the entry point file
- Look for PHP errors in web server error log
