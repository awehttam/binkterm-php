# Customizing BinktermPHP

This document describes the current customization options available in BinktermPHP and proposes improvements for making UI customization easier.

## Table of Contents

1. [Current Customization Options](#current-customization-options)
2. [Customizing the Color Scheme](#customizing-the-color-scheme)
3. [Customizing the Menu](#customizing-the-menu)
4. [Adding New Pages](#adding-new-pages)
5. [Template Overrides](#template-overrides)
6. [Proposals for Easier Customization](#proposals-for-easier-customization)

---

## Current Customization Options

BinktermPHP provides several customization extension points:

### System News (Dashboard Content)

Create a custom dashboard message by copying the example file:

```bash
cp config/systemnews.twig.example config/systemnews.twig
```

Edit `config/systemnews.twig` to add custom content to the user dashboard. Available variables:
- `{{ current_user }}` - The logged-in user object
- `{{ sysop_name }}` - System operator name
- `{{ fidonet_origin }}` - Your FidoNet address
- `{{ app_version }}` - Application version

### Header Insertions (Custom CSS/JS)

Add custom CSS, JavaScript, or meta tags by creating:

```bash
cp templates/custom/header.insert.twig.example templates/custom/header.insert.twig
```

This file is included in the HTML `<head>` section of every page. Use it for:
- Google Analytics or other tracking scripts
- Custom CSS stylesheets
- Additional meta tags
- Third-party JavaScript libraries

### Welcome Message (Login Page)

Customize the login page welcome message:

```bash
cp config/welcome.txt.example config/welcome.txt
```

Supports plain text or HTML content.

### Terminal Welcome

Customize the terminal feature welcome message:

```bash
cp config/terminal_welcome.txt.example config/terminal_welcome.txt
```

### Environment Variables

Key customization options in `.env`:

```env
SITE_URL=https://example.com      # Public URL (important for proxies)
TERMINAL_ENABLED=false            # Enable/disable terminal feature
STYLESHEET=/css/style.css         # Path to custom stylesheet
```

### Custom Stylesheet

You can use a completely custom stylesheet by setting the `STYLESHEET` environment variable:

```env
STYLESHEET=/css/mytheme.css
```

The default stylesheet is `/css/style.css`. Your custom stylesheet should be placed in `public_html/css/` (or another publicly accessible location). This is useful when you want to maintain a separate theme file without modifying the default stylesheet.

#### Built-in Themes

BinktermPHP includes the following themes:

- `/css/style.css` - Default light theme
- `/css/dark.css` - Dark theme with dark backgrounds and light text

To enable the dark theme:

```env
STYLESHEET=/css/dark.css
```

---

## Customizing the Color Scheme

### Current Approach

Colors are defined as CSS custom properties in `public_html/css/style.css`:

```css
:root {
    --fidonet-blue: #0066cc;      /* Primary color */
    --fidonet-green: #009900;     /* Success/online status */
    --fidonet-red: #cc0000;       /* Error/netmail indicator */
    --fidonet-orange: #ff6600;    /* Echo area tags */
    --text-color: #000000;
    --text-color-muted: #6c757d;
    --message-bg: #ffffff;
    --message-quote-bg: #f8f9fa;
    --message-quote-border: #dee2e6;
    --border-color: #dee2e6;
}
```

### How to Override Colors

Create a custom CSS file and include it via `templates/custom/header.insert.twig`:

```html
<link rel="stylesheet" href="/css/custom.css">
```

Then in `public_html/css/custom.css`:

```css
:root {
    --fidonet-blue: #1a73e8;      /* Your custom primary color */
    --fidonet-green: #34a853;     /* Your custom success color */
}
```

### Bootstrap Theme Override

The navbar uses Bootstrap's `bg-primary` class. To change the navbar color without affecting other primary elements:

```css
.navbar.bg-primary {
    background-color: #your-color !important;
}
```

---

## Customizing the Menu

### Current Implementation

The navigation menu is defined in `templates/base.twig` (lines 28-125). The structure includes:

**Left Menu (Authenticated Users):**
- Netmail
- Echomail
- Nodelist
- Terminal (if enabled)
- Binkp (admin only)
- Admin dropdown (admin only)

**Right Menu:**
- User dropdown (profile, settings, subscriptions, logout)
- Login link (non-authenticated)

### How to Modify the Menu

Currently, modifying the menu requires editing `templates/base.twig` directly.

**Adding a Menu Item:**

Find the navbar section and add your item:

```twig
<li class="nav-item">
    <a class="nav-link" href="/your-page">
        <i class="fas fa-icon"></i> Your Page
    </a>
</li>
```

**Conditional Menu Items:**

```twig
{% if current_user %}
    <!-- Show only to authenticated users -->
{% endif %}

{% if current_user and current_user.is_admin %}
    <!-- Show only to admins -->
{% endif %}
```

---

## Adding New Pages

### Step 1: Create the Template

Create a new file in `templates/`:

```twig
{# templates/mypage.twig #}
{% extends "base.twig" %}

{% block title %}My Page - {{ parent() }}{% endblock %}

{% block content %}
<div class="container mt-4">
    <h1>My Custom Page</h1>
    <p>Your content here.</p>

    {# Access global variables #}
    <p>Welcome, {{ current_user.username }}!</p>
    <p>System: {{ system_name }}</p>
</div>
{% endblock %}

{% block scripts %}
<script>
    // Page-specific JavaScript
</script>
{% endblock %}
```

### Step 2: Add the Route

Add a route in `routes/web-routes.php`:

```php
SimpleRouter::get('/mypage', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        header('Location: /login');
        exit;
    }

    $template = new Template();
    $template->renderResponse('mypage.twig', [
        'custom_variable' => 'value'
    ]);
});
```

### Step 3: Add Navigation Link (Optional)

Edit `templates/base.twig` to add a menu link:

```twig
<li class="nav-item">
    <a class="nav-link" href="/mypage">
        <i class="fas fa-star"></i> My Page
    </a>
</li>
```

---

## Template Overrides

### Priority System

Templates are loaded from multiple paths in order:
1. `templates/` - Default templates
2. `config/` - Custom overrides

To override any template, place a file with the same name in the `config/` directory.

### Example: Custom Footer

To customize the footer without editing `base.twig`:

1. The footer is currently embedded in `base.twig`
2. Extract it to a partial: `templates/partials/footer.twig`
3. Include it: `{% include 'partials/footer.twig' %}`
4. Override by creating `config/partials/footer.twig`

---

## Proposals for Easier Customization

The current system requires editing core files for many customizations. Here are proposals to make customization easier and more maintainable.

### Proposal 1: Configuration-Based Menu System

**Problem:** Adding or removing menu items requires editing `base.twig`.

**Solution:** Create a menu configuration file.

Create `config/menu.json`:
```json
{
    "main": [
        {
            "label": "Netmail",
            "url": "/netmail",
            "icon": "fa-envelope",
            "auth_required": true,
            "admin_only": false
        },
        {
            "label": "Echomail",
            "url": "/echomail",
            "icon": "fa-comments",
            "auth_required": true,
            "admin_only": false
        },
        {
            "label": "My Custom Page",
            "url": "/mypage",
            "icon": "fa-star",
            "auth_required": true,
            "admin_only": false
        }
    ],
    "admin": [
        {
            "label": "Dashboard",
            "url": "/admin",
            "icon": "fa-tachometer-alt"
        }
    ],
    "user": [
        {
            "label": "Profile",
            "url": "/profile",
            "icon": "fa-user"
        },
        {
            "label": "Settings",
            "url": "/settings",
            "icon": "fa-cog"
        }
    ]
}
```

**Implementation:**
- Create a `MenuService` class to load and parse the configuration
- Pass menu data to templates as a global variable
- Render menus dynamically using Twig loops

### Proposal 2: Theme System

**Problem:** Changing colors requires creating custom CSS and header insertions.

**Solution:** Create a theme configuration system.

Create `config/theme.json`:
```json
{
    "name": "Custom Theme",
    "colors": {
        "primary": "#1a73e8",
        "success": "#34a853",
        "danger": "#ea4335",
        "warning": "#fbbc04",
        "info": "#4285f4",
        "navbar_bg": "#1a73e8",
        "navbar_text": "#ffffff",
        "message_quote_bg": "#f1f3f4",
        "message_quote_border": "#dadce0"
    },
    "fonts": {
        "body": "'Segoe UI', sans-serif",
        "monospace": "'Consolas', monospace"
    },
    "logo": "/images/custom-logo.png",
    "favicon": "/images/favicon.ico"
}
```

**Implementation:**
- Create a `ThemeService` class to load theme configuration
- Generate CSS custom properties dynamically
- Inject theme CSS into the page header
- Provide preset themes (Default, Dark Mode, Retro BBS, etc.)

### Proposal 3: Page Builder / Custom Pages System

**Problem:** Adding pages requires PHP code changes and template creation.

**Solution:** Database-driven custom pages.

**Database Schema:**
```sql
CREATE TABLE custom_pages (
    id SERIAL PRIMARY KEY,
    slug VARCHAR(100) UNIQUE NOT NULL,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    content_type VARCHAR(20) DEFAULT 'markdown',
    require_auth BOOLEAN DEFAULT false,
    require_admin BOOLEAN DEFAULT false,
    show_in_menu BOOLEAN DEFAULT false,
    menu_icon VARCHAR(50),
    menu_order INTEGER DEFAULT 0,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

**Features:**
- Admin interface for creating/editing pages
- Markdown or HTML content support
- Automatic route registration
- Menu integration options
- Access control (public, auth required, admin only)

### Proposal 4: Widget System for Dashboard

**Problem:** Dashboard customization is limited.

**Solution:** Modular widget system.

Create `config/dashboard.json`:
```json
{
    "layout": "two-column",
    "widgets": [
        {
            "type": "system_news",
            "position": "main",
            "order": 1
        },
        {
            "type": "recent_netmail",
            "position": "main",
            "order": 2,
            "config": {
                "limit": 5
            }
        },
        {
            "type": "recent_echomail",
            "position": "sidebar",
            "order": 1,
            "config": {
                "limit": 10
            }
        },
        {
            "type": "stats",
            "position": "sidebar",
            "order": 2
        },
        {
            "type": "custom_html",
            "position": "sidebar",
            "order": 3,
            "config": {
                "content": "<p>Custom sidebar content</p>"
            }
        }
    ]
}
```

**Widget Types:**
- `system_news` - System announcements
- `recent_netmail` - Recent private messages
- `recent_echomail` - Recent forum posts
- `stats` - User statistics
- `custom_html` - Custom HTML content
- `rss_feed` - External RSS feed
- `weather` - Weather widget (for fun)

### Proposal 5: Admin UI for Customization

**Problem:** All customization requires file editing.

**Solution:** Web-based admin interface for customization.

**Admin Sections:**
1. **Appearance**
   - Theme selection (presets)
   - Color picker for custom colors
   - Logo upload
   - Custom CSS editor

2. **Menu Manager**
   - Drag-and-drop menu ordering
   - Add/remove menu items
   - Set visibility conditions

3. **Page Manager**
   - Create custom pages
   - Edit page content (WYSIWYG or Markdown)
   - Set access permissions

4. **Dashboard Builder**
   - Widget selection
   - Layout configuration
   - Widget ordering

5. **Header/Footer Editor**
   - Custom code injection
   - Script management
   - Meta tag editor

### Proposal 6: Template Override System Enhancement

**Problem:** Overriding templates requires understanding the directory structure.

**Solution:** Improved template override discovery.

**Changes:**
1. Create `templates/custom/` as the primary override location
2. Add admin interface showing available templates
3. One-click "customize this template" feature
4. Template diff viewer to see changes from default

**Directory Structure:**
```
templates/
├── base.twig              # Core template
├── dashboard.twig         # Default dashboard
├── custom/                # User overrides
│   ├── dashboard.twig     # Custom dashboard (overrides default)
│   ├── header.insert.twig # Header insertions
│   └── footer.insert.twig # Footer insertions (new)
```

### Implementation Priority

Recommended implementation order based on impact and effort:

1. **Theme System** (High impact, Medium effort)
   - Most requested feature
   - Improves visual customization significantly
   - Can include dark mode support

2. **Configuration-Based Menu** (High impact, Low effort)
   - Eliminates need to edit base.twig
   - Simple JSON configuration
   - Quick to implement

3. **Custom Pages System** (Medium impact, Medium effort)
   - Enables content creation without code
   - Useful for announcements, help pages, etc.

4. **Admin UI for Customization** (High impact, High effort)
   - Consolidates all customization in one place
   - Most user-friendly option
   - Requires significant development

5. **Widget System** (Medium impact, High effort)
   - Nice to have for power users
   - Can be added incrementally

---

## Summary

BinktermPHP currently offers basic customization through:
- Configuration files (systemnews, welcome, header insertions)
- CSS custom properties
- Template editing

The proposed improvements would enable:
- No-code customization through admin interface
- Configuration-based menus and themes
- Database-driven custom pages
- Modular dashboard widgets

These changes would make the system more accessible to non-developers while maintaining flexibility for advanced users.
