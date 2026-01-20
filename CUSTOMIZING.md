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
cp templates/custom/systemnews.twig.example templates/custom/systemnews.twig
```

Edit `templates/custom/systemnews.twig` to add custom content to the user dashboard. Available variables:
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

To modify the menu, first create a custom override of the base template:

```bash
cp templates/base.twig templates/custom/base.twig
```

**Adding a Menu Item:**

Edit `templates/custom/base.twig`, find the navbar section and add your item:

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

Create a new file in `templates/custom/`:

```twig
{# templates/custom/mypage.twig #}
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

Create a local routes file to add custom routes without modifying core files:

```bash
cp routes/web-routes.local.php.example routes/web-routes.local.php
```

Edit `routes/web-routes.local.php` and add your route:

```php
<?php

use BinktermPHP\Auth;
use BinktermPHP\Template;
use Pecee\SimpleRouter\SimpleRouter;

SimpleRouter::get('/mypage', function() {
    $auth = new Auth();
    $user = $auth->getCurrentUser();

    if (!$user) {
        return SimpleRouter::response()->redirect('/login');
    }

    $template = new Template();
    $template->renderResponse('mypage.twig', [
        'custom_variable' => 'value'
    ]);
});
```

### Step 3: Add Navigation Link (Optional)

If you haven't already, create a custom base template override:

```bash
cp templates/base.twig templates/custom/base.twig
```

Edit `templates/custom/base.twig` to add a menu link:

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

Templates are loaded from multiple paths in priority order:
1. `templates/custom/` - Custom overrides (checked first)
2. `templates/` - Default templates

To override any template, copy it to the `templates/custom/` directory with the same relative path.

### Example: Override the Base Template

To customize the site layout without editing the original:

```bash
cp templates/base.twig templates/custom/base.twig
```

Edit `templates/custom/base.twig` to make your changes. The custom version will be used instead of the default.

### Example: Override a Specific Page

To customize just the login page:

```bash
cp templates/login.twig templates/custom/login.twig
```

### Benefits of Template Overrides

- **Upgrade-safe**: Your customizations won't be overwritten when updating BinktermPHP
- **Easy rollback**: Delete the custom template to restore the default
- **Selective changes**: Only override the templates you need to modify

---
