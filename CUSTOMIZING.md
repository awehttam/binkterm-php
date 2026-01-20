# Customizing BinktermPHP

This document describes the current customization options available in BinktermPHP and proposes improvements for making UI customization easier.

## Table of Contents

1. [Current Customization Options](#current-customization-options)
2. [Customizing the Color Scheme](#customizing-the-color-scheme)
3. [Customizing the Menu](#customizing-the-menu)
4. [Adding New Pages](#adding-new-pages)
5. [Template Overrides](#template-overrides)
6. [Twig Template Variables](#twig-template-variables)
7. [Bootstrap 5 Quick Reference](#bootstrap-5-quick-reference)

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

## Twig Template Variables

BinktermPHP uses Twig templating. This section documents the variables available in your templates.

### Global Variables

These variables are automatically available in **all templates**:

| Variable | Type | Description |
|----------|------|-------------|
| `current_user` | Object/null | The logged-in user, or null if not authenticated |
| `system_name` | String | The BBS system name |
| `sysop_name` | String | System operator's name |
| `fidonet_origin` | String | FidoNet node address (e.g., `1:234/567`) |
| `system_address` | String | Alias for `fidonet_origin` |
| `app_version` | String | Version number (e.g., `1.4.2`) |
| `app_name` | String | Application name (`BinktermPHP`) |
| `app_full_version` | String | Full version string (e.g., `BinktermPHP v1.4.2`) |
| `terminal_enabled` | Boolean | Whether terminal feature is enabled |
| `stylesheet` | String | Path to the active stylesheet |

### User Object Properties

When `current_user` is not null, it has these properties:

```twig
{{ current_user.id }}           {# User ID #}
{{ current_user.username }}     {# Username #}
{{ current_user.email }}        {# Email address #}
{{ current_user.is_admin }}     {# Boolean: admin status #}
{{ current_user.created_at }}   {# Account creation date #}
```

### Conditional Examples

**Check if user is logged in:**
```twig
{% if current_user %}
    <p>Welcome back, {{ current_user.username }}!</p>
{% else %}
    <p>Please <a href="/login">log in</a> to continue.</p>
{% endif %}
```

**Check if user is admin:**
```twig
{% if current_user and current_user.is_admin %}
    <a href="/admin/">Admin Panel</a>
{% endif %}
```

**Check if terminal is enabled:**
```twig
{% if terminal_enabled %}
    <a href="/terminal">Launch Terminal</a>
{% endif %}
```

### Page-Specific Variables

Some pages pass additional variables:

**Dashboard (`dashboard.twig`):**
- `system_news_content` - Rendered HTML from systemnews.twig

**Login Page (`login.twig`):**
- `welcome_message` - Custom welcome text from config/welcome.txt

**Echomail (`echomail.twig`):**
- `echoarea` - Current echo area tag (or null for area list)

**Netmail (`netmail.twig`):**
- `system_address` - Node address for addressing messages

**Shared Message (`shared_message.twig`):**
- `shareKey` - The 32-character share key
- `message` - The shared message data object
- `share_info` - Share metadata (expiry, access count, etc.)

**Error Pages (`error.twig`):**
- `error_title` - Error heading
- `error_message` - Error description

**404 Page (`404.twig`):**
- `requested_url` - The URL that was not found

### Template Blocks

When extending `base.twig`, these blocks are available:

```twig
{% extends "base.twig" %}

{% block title %}Page Title - {{ parent() }}{% endblock %}

{% block meta_tags %}
    {# Add custom meta tags for SEO #}
    <meta name="description" content="Page description">
{% endblock %}

{% block content %}
    {# Main page content goes here #}
{% endblock %}

{% block scripts %}
    {# Page-specific JavaScript #}
    <script>
        // Your code here
    </script>
{% endblock %}
```

### Twig Syntax Quick Reference

**Output variables:**
```twig
{{ variable }}                    {# Output with escaping #}
{{ variable|raw }}                {# Output without escaping (use carefully) #}
```

**Filters:**
```twig
{{ name|upper }}                  {# UPPERCASE #}
{{ name|lower }}                  {# lowercase #}
{{ name|capitalize }}             {# Capitalize #}
{{ name|title }}                  {# Title Case #}
{{ text|length }}                 {# Character count #}
{{ list|join(', ') }}             {# Join array with separator #}
{{ date|date('Y-m-d') }}          {# Format date #}
{{ number|number_format(2) }}     {# Format number #}
{{ text|nl2br }}                  {# Newlines to <br> tags #}
{{ text|trim }}                   {# Remove whitespace #}
{{ text|striptags }}              {# Remove HTML tags #}
{{ html|e }}                      {# HTML escape (default) #}
{{ value|default('fallback') }}   {# Default if empty/null #}
```

**Conditionals:**
```twig
{% if condition %}
    ...
{% elseif other_condition %}
    ...
{% else %}
    ...
{% endif %}

{# Ternary operator #}
{{ condition ? 'yes' : 'no' }}

{# Null coalescing #}
{{ value ?? 'default' }}
```

**Loops:**
```twig
{% for item in items %}
    {{ item.name }}
{% else %}
    No items found.
{% endfor %}

{# Loop with index #}
{% for item in items %}
    {{ loop.index }}    {# 1-indexed #}
    {{ loop.index0 }}   {# 0-indexed #}
    {{ loop.first }}    {# Boolean: first iteration #}
    {{ loop.last }}     {# Boolean: last iteration #}
    {{ loop.length }}   {# Total items #}
{% endfor %}
```

**Include other templates:**
```twig
{% include 'partial.twig' %}
{% include 'partial.twig' with {'var': value} %}
{% include 'partial.twig' ignore missing %}
```

**Set variables:**
```twig
{% set name = 'value' %}
{% set items = ['a', 'b', 'c'] %}
{% set user = {'name': 'John', 'age': 30} %}
```

**Comments:**
```twig
{# This is a Twig comment - not rendered in output #}
```

### Further Reading

- [Twig Documentation](https://twig.symfony.com/doc/3.x/)
- [Twig for Template Designers](https://twig.symfony.com/doc/3.x/templates.html)

---

## Bootstrap 5 Quick Reference

BinktermPHP uses Bootstrap 5 for its UI components. This section provides a quick reference for common elements you may want to use in custom templates.

### Layout

**Container:**
```html
<div class="container">...</div>      <!-- Fixed-width centered container -->
<div class="container-fluid">...</div> <!-- Full-width container -->
```

**Grid System:**
```html
<div class="row">
    <div class="col-md-6">Half width on medium+ screens</div>
    <div class="col-md-6">Half width on medium+ screens</div>
</div>
<div class="row">
    <div class="col-12 col-lg-4">Full on mobile, 1/3 on large</div>
    <div class="col-12 col-lg-8">Full on mobile, 2/3 on large</div>
</div>
```

### Spacing

**Margin and Padding:**
- `m-{size}` - margin all sides (0-5, auto)
- `mt-{size}` - margin top
- `mb-{size}` - margin bottom
- `ms-{size}` - margin start (left in LTR)
- `me-{size}` - margin end (right in LTR)
- `mx-{size}` - margin horizontal
- `my-{size}` - margin vertical
- `p-{size}` - padding (same suffixes as margin)

```html
<div class="mt-4 mb-3">Top margin 4, bottom margin 3</div>
<div class="p-3">Padding all sides</div>
<div class="px-4 py-2">Horizontal padding 4, vertical padding 2</div>
```

### Typography

```html
<h1 class="display-1">Large display heading</h1>
<p class="lead">Emphasized paragraph</p>
<p class="text-muted">Muted/secondary text</p>
<p class="text-primary">Primary colored text</p>
<p class="text-success">Success/green text</p>
<p class="text-danger">Danger/red text</p>
<p class="text-warning">Warning/yellow text</p>
<small class="text-muted">Small muted text</small>
<strong>Bold text</strong>
<code>Inline code</code>
```

### Buttons

```html
<button class="btn btn-primary">Primary</button>
<button class="btn btn-secondary">Secondary</button>
<button class="btn btn-success">Success</button>
<button class="btn btn-danger">Danger</button>
<button class="btn btn-warning">Warning</button>
<button class="btn btn-info">Info</button>
<button class="btn btn-light">Light</button>
<button class="btn btn-dark">Dark</button>
<button class="btn btn-outline-primary">Outline Primary</button>
<button class="btn btn-link">Link style</button>

<!-- Sizes -->
<button class="btn btn-primary btn-lg">Large</button>
<button class="btn btn-primary btn-sm">Small</button>
```

### Alerts

```html
<div class="alert alert-primary">Primary alert</div>
<div class="alert alert-success">Success alert</div>
<div class="alert alert-danger">Error alert</div>
<div class="alert alert-warning">Warning alert</div>
<div class="alert alert-info">Info alert</div>

<!-- Dismissible -->
<div class="alert alert-warning alert-dismissible fade show">
    Warning message
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
```

### Cards

```html
<div class="card">
    <div class="card-header">Header</div>
    <div class="card-body">
        <h5 class="card-title">Title</h5>
        <p class="card-text">Content goes here.</p>
        <a href="#" class="btn btn-primary">Action</a>
    </div>
    <div class="card-footer text-muted">Footer</div>
</div>
```

### Tables

```html
<table class="table">...</table>                    <!-- Basic table -->
<table class="table table-striped">...</table>      <!-- Striped rows -->
<table class="table table-hover">...</table>        <!-- Hover effect -->
<table class="table table-bordered">...</table>     <!-- Bordered -->
<table class="table table-sm">...</table>           <!-- Compact -->
<table class="table table-dark">...</table>         <!-- Dark theme -->

<!-- Responsive wrapper -->
<div class="table-responsive">
    <table class="table">...</table>
</div>
```

### Forms

```html
<form>
    <div class="mb-3">
        <label for="email" class="form-label">Email</label>
        <input type="email" class="form-control" id="email">
        <div class="form-text">Help text below input.</div>
    </div>
    <div class="mb-3">
        <label for="message" class="form-label">Message</label>
        <textarea class="form-control" id="message" rows="3"></textarea>
    </div>
    <div class="mb-3 form-check">
        <input type="checkbox" class="form-check-input" id="check">
        <label class="form-check-label" for="check">Check me</label>
    </div>
    <div class="mb-3">
        <select class="form-select">
            <option selected>Choose...</option>
            <option value="1">Option 1</option>
        </select>
    </div>
    <button type="submit" class="btn btn-primary">Submit</button>
</form>
```

### Badges

```html
<span class="badge bg-primary">Primary</span>
<span class="badge bg-secondary">Secondary</span>
<span class="badge bg-success">Success</span>
<span class="badge bg-danger">Danger</span>
<span class="badge bg-warning text-dark">Warning</span>
<span class="badge bg-info text-dark">Info</span>
<span class="badge rounded-pill bg-primary">Pill badge</span>
```

### List Groups

```html
<ul class="list-group">
    <li class="list-group-item">Item 1</li>
    <li class="list-group-item active">Active item</li>
    <li class="list-group-item list-group-item-success">Success item</li>
    <li class="list-group-item disabled">Disabled item</li>
</ul>

<!-- With badges -->
<ul class="list-group">
    <li class="list-group-item d-flex justify-content-between align-items-center">
        Inbox
        <span class="badge bg-primary rounded-pill">14</span>
    </li>
</ul>
```

### Modals

```html
<!-- Trigger button -->
<button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#myModal">
    Open Modal
</button>

<!-- Modal structure -->
<div class="modal fade" id="myModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Modal Title</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                Modal content here.
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary">Save</button>
            </div>
        </div>
    </div>
</div>
```

### Navs and Tabs

```html
<!-- Tabs -->
<ul class="nav nav-tabs" id="myTab">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab1">Tab 1</button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab2">Tab 2</button>
    </li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="tab1">Tab 1 content</div>
    <div class="tab-pane fade" id="tab2">Tab 2 content</div>
</div>

<!-- Pills -->
<ul class="nav nav-pills">
    <li class="nav-item">
        <a class="nav-link active" href="#">Active</a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="#">Link</a>
    </li>
</ul>
```

### Dropdowns

```html
<div class="dropdown">
    <button class="btn btn-secondary dropdown-toggle" data-bs-toggle="dropdown">
        Dropdown
    </button>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="#">Action</a></li>
        <li><a class="dropdown-item" href="#">Another action</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="#">Separated link</a></li>
    </ul>
</div>
```

### Utilities

**Display:**
```html
<div class="d-none">Hidden</div>
<div class="d-block">Block</div>
<div class="d-inline">Inline</div>
<div class="d-flex">Flexbox container</div>
<div class="d-none d-md-block">Hidden on mobile, visible on medium+</div>
```

**Flexbox:**
```html
<div class="d-flex justify-content-between">Space between items</div>
<div class="d-flex justify-content-center">Center items</div>
<div class="d-flex align-items-center">Vertically center items</div>
<div class="d-flex flex-column">Stack vertically</div>
```

**Text Alignment:**
```html
<p class="text-start">Left aligned</p>
<p class="text-center">Center aligned</p>
<p class="text-end">Right aligned</p>
```

**Borders:**
```html
<div class="border">All borders</div>
<div class="border-top">Top border only</div>
<div class="border rounded">Rounded corners</div>
<div class="border rounded-pill">Pill shape</div>
```

**Shadows:**
```html
<div class="shadow-sm">Small shadow</div>
<div class="shadow">Regular shadow</div>
<div class="shadow-lg">Large shadow</div>
```

### Icons (Font Awesome)

BinktermPHP includes Font Awesome 5. Common icons:

```html
<i class="fas fa-home"></i>           <!-- Home -->
<i class="fas fa-user"></i>           <!-- User -->
<i class="fas fa-envelope"></i>       <!-- Envelope/mail -->
<i class="fas fa-cog"></i>            <!-- Settings gear -->
<i class="fas fa-trash"></i>          <!-- Delete/trash -->
<i class="fas fa-edit"></i>           <!-- Edit/pencil -->
<i class="fas fa-plus"></i>           <!-- Plus/add -->
<i class="fas fa-check"></i>          <!-- Checkmark -->
<i class="fas fa-times"></i>          <!-- X/close -->
<i class="fas fa-search"></i>         <!-- Search -->
<i class="fas fa-sync"></i>           <!-- Refresh/sync -->
<i class="fas fa-download"></i>       <!-- Download -->
<i class="fas fa-upload"></i>         <!-- Upload -->
<i class="fas fa-reply"></i>          <!-- Reply -->
<i class="fas fa-share"></i>          <!-- Share -->
<i class="fas fa-star"></i>           <!-- Star/favorite -->
<i class="fas fa-bell"></i>           <!-- Notification bell -->
<i class="fas fa-lock"></i>           <!-- Lock/secure -->
<i class="fas fa-sign-out-alt"></i>   <!-- Logout -->

<!-- Sizing -->
<i class="fas fa-home fa-xs"></i>     <!-- Extra small -->
<i class="fas fa-home fa-sm"></i>     <!-- Small -->
<i class="fas fa-home fa-lg"></i>     <!-- Large -->
<i class="fas fa-home fa-2x"></i>     <!-- 2x size -->
```

### Further Reading

- [Bootstrap 5 Documentation](https://getbootstrap.com/docs/5.3/)
- [Font Awesome 5 Icons](https://fontawesome.com/v5/search)

---
