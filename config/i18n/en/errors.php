<?php

return [
    // Auth
    'errors.auth.authentication_required' => 'Authentication required',
    'errors.auth.invalid_csrf_token' => 'Invalid CSRF token',
    'errors.auth.missing_credentials' => 'Username and password required',
    'errors.auth.invalid_credentials' => 'Invalid credentials',
    'errors.auth.invalid_api_key' => 'Invalid API key',
    'errors.auth.gateway_token_missing_fields' => 'userid and token are required',
    'errors.auth.invalid_or_expired_gateway_token' => 'Invalid or expired token',
    'errors.auth.username_or_email_required' => 'Username or email is required',
    'errors.auth.token_required' => 'Token is required',
    'errors.auth.invalid_or_expired_token' => 'Invalid or expired token',
    'errors.auth.token_and_password_required' => 'Token and new password are required',

    // Registration
    'errors.register.invalid_submission' => 'Invalid submission',
    'errors.register.too_fast' => 'Please take your time filling out the form.',
    'errors.register.session_expired' => 'Session expired. Please refresh the page and try again.',
    'errors.register.rate_limited' => 'Too many registration attempts. Please try again later.',
    'errors.register.required_fields' => 'Username, password, and real name are required',
    'errors.register.invalid_username_format' => 'Username must be 3-20 characters, letters, numbers, and underscores only',
    'errors.register.restricted_name' => 'This username or real name is not allowed',
    'errors.register.weak_password' => 'Password must be at least 8 characters long',
    'errors.register.user_exists' => 'A user with this username or name already exists. Please try logging in or contact the sysop for assistance.',
    'errors.register.failed' => 'Registration failed. Please try again later.',

    // Reminder
    'errors.reminder.username_required' => 'Username is required',
    'errors.reminder.user_not_found_or_logged_in' => 'User not found or already logged in',
    'errors.reminder.send_failed' => 'Failed to send reminder. Please try again later.',

    // Settings
    'errors.settings.invalid_input' => 'Invalid input',
    'errors.settings.update_failed' => 'Failed to update settings',
    'errors.settings.exception' => 'Failed to update settings',

    // Messages
    'errors.messages.share_create_failed' => 'Failed to create share link',
    'errors.messages.share_lookup_failed' => 'Failed to load share links',
    'errors.messages.share_revoke_failed' => 'Failed to revoke share link',
];
