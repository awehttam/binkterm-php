<?php

return [
    // Relative time strings used by app.js formatting helpers.
    'time.soon' => 'Soon',
    'time.in_hours' => 'In {count} hour{suffix}',
    'time.tomorrow' => 'Tomorrow',
    'time.in_days' => 'In {count} days',
    'time.just_now' => 'Just now',
    'time.minutes_ago' => '{count} minutes ago',
    'time.hours_ago' => '{count} hour{suffix} ago',
    'time.yesterday' => 'Yesterday',
    'time.days_ago' => '{count} days ago',
    'time.suffix_plural' => 's',
    'time.suffix_singular' => '',

    // Shared/common UI defaults.
    'errors.failed_load_messages' => 'Failed to load messages',
    'messages.none_found' => 'No messages found',
    'messages.no_subject' => '(No Subject)',
    'ui.common.success' => 'Success',
    'ui.common.unknown_error' => 'Unknown error',
    'ui.common.saving' => 'Saving...',
    'ui.common.copy_failed_manual' => 'Copy to clipboard failed. Please copy manually.',
    'ui.common.copy_not_supported_manual' => 'Copy to clipboard not supported. Please copy manually.',

    // Dashboard
    'ui.dashboard.polls.none_active' => 'No active polls right now.',
    'ui.dashboard.polls.load_failed' => 'Failed to load poll.',
    'ui.dashboard.polls.results' => 'Results',
    'ui.dashboard.polls.no_votes' => 'No votes yet.',
    'ui.dashboard.polls.vote_to_see_results' => 'Vote to see results',
    'ui.dashboard.polls.submit_vote' => 'Submit Vote',
    'ui.dashboard.echoareas.none_available' => 'No echo areas available',
    'ui.dashboard.shoutbox.none_yet' => 'No shouts yet. Be the first!',
    'ui.dashboard.shoutbox.load_failed' => 'Failed to load shouts.',
    'ui.dashboard.shoutbox.post_failed' => 'Failed to post shout.',
    'ui.dashboard.referrals.error_prefix' => 'Referral stats error:',
    'ui.dashboard.referrals.recent' => 'Recent Referrals',

    // Settings
    'ui.settings.load_failed_console' => 'Failed to load settings',
    'ui.settings.saving' => 'Saving settings...',
    'ui.settings.saved_successfully' => 'Settings saved successfully!',
    'ui.settings.sessions.none_active' => 'No active sessions found.',
    'ui.settings.sessions.load_failed' => 'Failed to load sessions',
    'ui.settings.sessions.revoke_confirm' => 'Are you sure you want to revoke this session?',
    'ui.settings.sessions.revoked_success' => 'Session revoked successfully',
    'ui.settings.sessions.logout_all_confirm' => 'Are you sure you want to logout from all devices? You will need to login again.',
    'ui.settings.polling_uplinks' => 'Polling uplinks... (this may take a moment)',
    'ui.settings.poll_complete_prefix' => 'Uplink poll completed: ',
    'ui.settings.poll_failed_prefix' => 'Poll failed: ',

    // Compose / Drafts / Address Book
    'ui.compose.draft.empty_content' => 'Please add some content before saving draft',
    'ui.compose.draft.saved_success' => 'Draft saved successfully',
    'ui.compose.address_book.load_failed_short' => 'Failed to load',
    'ui.compose.address_book.entry_added' => 'Entry added successfully',
    'ui.compose.address_book.use_entry_confirm' => 'Use this entry for the current message?',
    'ui.address_book.already_exists' => 'This contact is already in your address book',
    'ui.address_book.check_existing_failed' => 'Failed to check existing contacts',
    'ui.drafts.delete_confirm' => 'Are you sure you want to delete this draft? This cannot be undone.',
    'ui.messages.none_selected' => 'No messages selected',

    // Netmail
    'ui.netmail.search.failed' => 'Search failed',
    'ui.netmail.delete_message_confirm' => 'Are you sure you want to delete this message? This action cannot be undone.',
    'ui.netmail.address_book.load_entry_failed_prefix' => 'Failed to load entry: ',
    'ui.netmail.bulk_delete.failed' => 'Failed to delete messages',

    // Echomail
    'ui.echomail.search.failed' => 'Search failed',
    'ui.echomail.save_status.update_failed' => 'Failed to update save status',
    'ui.echomail.bulk_delete.failed' => 'Failed to delete messages',
    'ui.echomail.shares.check_failed' => 'Failed to check existing shares',
    'ui.echomail.shares.friendly_url_failed' => 'Failed to generate friendly URL',
    'ui.echomail.shares.revoke_confirm' => 'Are you sure you want to revoke this share link? It will no longer be accessible to others.',
];
