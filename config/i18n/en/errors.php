<?php

return [
    // Generic
    'errors.generic' => 'An unexpected error occurred',

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
    'errors.messages.netmail.not_found' => 'Message not found',
    'errors.messages.netmail.delete_failed' => 'Failed to delete message',
    'errors.messages.netmail.bulk_delete.invalid_input' => 'A non-empty message ID list is required',
    'errors.messages.echomail.bulk_read.invalid_input' => 'A non-empty message ID list is required',
    'errors.messages.echomail.bulk_read.failed' => 'Failed to mark messages as read',
    'errors.messages.echomail.bulk_delete.admin_required' => 'Admin privileges are required',
    'errors.messages.echomail.bulk_delete.invalid_input' => 'A non-empty message ID list is required',
    'errors.messages.echomail.stats.subscription_required' => 'Subscription required for this echo area',
    'errors.messages.echomail.not_found' => 'Message not found',
    'errors.messages.netmail.attachment.no_file' => 'No attachment uploaded',
    'errors.messages.netmail.attachment.upload_error' => 'Attachment upload failed',
    'errors.messages.netmail.attachment.too_large' => 'Attachment exceeds maximum allowed size',
    'errors.messages.netmail.attachment.store_failed' => 'Failed to store uploaded attachment',
    'errors.messages.send.invalid_type' => 'Invalid message type',
    'errors.messages.send.failed' => 'Failed to send message',
    'errors.messages.send.exception' => 'Failed to send message',

    // Notify
    'errors.notify.user_id_missing' => 'Unable to resolve user session',
    'errors.notify.invalid_state' => 'Invalid notification state payload',
    'errors.notify.invalid_target' => 'Invalid notification target',

    // Polls
    'errors.polls.option_required' => 'A poll option is required',
    'errors.polls.not_found' => 'Poll not found',
    'errors.polls.invalid_option' => 'Invalid poll option',
    'errors.polls.vote_failed' => 'Failed to record vote',
    'errors.polls.insufficient_credits' => 'Failed to deduct credits. You may have insufficient balance.',
    'errors.polls.question_required' => 'Poll question is required',
    'errors.polls.question_length_invalid' => 'Poll question must be between 10 and 500 characters',
    'errors.polls.options_count_invalid' => 'Poll must include between 2 and 10 options',
    'errors.polls.option_empty' => 'Poll options cannot be empty',
    'errors.polls.option_length_invalid' => 'Poll options must be 200 characters or fewer',
    'errors.polls.options_duplicate' => 'Poll options must be unique',
    'errors.polls.create_failed' => 'Failed to create poll',

    // Shoutbox
    'errors.shoutbox.message_required' => 'Message is required',
    'errors.shoutbox.message_too_long' => 'Message cannot exceed 280 characters',

    // Chat
    'errors.chat.feature_disabled' => 'Chat is disabled',
    'errors.chat.invalid_message_query' => 'Invalid chat message query',
    'errors.chat.invalid_send_target' => 'Invalid chat target',
    'errors.chat.message_length_invalid' => 'Message must be between 1 and 1000 characters',
    'errors.chat.room_not_found' => 'Chat room not found',
    'errors.chat.user_banned' => 'You are banned from this room',
    'errors.chat.recipient_not_found' => 'Recipient not found',
    'errors.chat.send_blocked' => 'Message could not be sent',
    'errors.chat.admin_required' => 'Admin privileges are required',
    'errors.chat.invalid_moderation_request' => 'Invalid moderation request',
    'errors.chat.user_not_found' => 'User not found',

    // Echo Areas
    'errors.echoareas.admin_required' => 'Admin privileges are required',
    'errors.echoareas.not_found' => 'Echo area not found',
    'errors.echoareas.invalid_posting_name_policy' => 'Invalid posting name policy',
    'errors.echoareas.tag_description_required' => 'Tag and description are required',
    'errors.echoareas.invalid_tag_format' => 'Invalid tag format',
    'errors.echoareas.invalid_color_format' => 'Invalid color format',
    'errors.echoareas.create_failed' => 'Failed to create echo area',
    'errors.echoareas.not_found_or_unchanged' => 'Echo area not found or no changes made',
    'errors.echoareas.update_failed' => 'Failed to update echo area',
    'errors.echoareas.delete_blocked_has_messages' => 'Cannot delete echo area with existing messages',
    'errors.echoareas.delete_failed' => 'Failed to delete echo area',

    // File Areas
    'errors.fileareas.not_found' => 'File area not found',
    'errors.fileareas.create_failed' => 'Failed to create file area',
    'errors.fileareas.update_failed' => 'Failed to update file area',
    'errors.fileareas.delete_failed' => 'Failed to delete file area',

    // Files
    'errors.files.feature_disabled' => 'File areas feature is disabled',
    'errors.files.area_id_required' => 'File area ID is required',
    'errors.files.access_denied' => 'Access denied to this file area',
    'errors.files.not_found' => 'File not found',
    'errors.files.share_not_found_or_forbidden' => 'Share link not found or not permitted',
    'errors.files.delete_failed' => 'Failed to delete file',
    'errors.files.upload.no_file' => 'No file uploaded',
    'errors.files.upload.area_id_required' => 'File area ID is required',
    'errors.files.upload.short_description_required' => 'Short description is required',
    'errors.files.upload.area_not_found' => 'File area not found',
    'errors.files.upload.read_only' => 'This file area is read-only',
    'errors.files.upload.admin_only' => 'Only administrators can upload files to this area',
    'errors.files.upload.failed' => 'Failed to upload file',

    // Admin Users
    'errors.admin.users.not_found' => 'User not found',
    'errors.admin.users.create_failed' => 'Failed to create user',
    'errors.admin.users.update_failed' => 'Failed to update user',
    'errors.admin.users.delete_failed' => 'Failed to delete user',

    // Admin Polls
    'errors.admin.polls.question_required' => 'Question is required',
    'errors.admin.polls.options_required' => 'At least two options are required',
    'errors.admin.polls.not_found' => 'Poll not found',
    'errors.admin.polls.create_failed' => 'Failed to create poll',
    'errors.admin.polls.update_failed' => 'Failed to update poll',
    'errors.admin.polls.delete_failed' => 'Failed to delete poll',
];
