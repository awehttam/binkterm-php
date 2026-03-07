<?php

// Telnet server UI strings (English)
return [

    // --- Connection / rate limiting ---
    'ui.telnet.server.rate_limited'            => 'Too many failed login attempts. Please try again later.',

    // --- Login / register menu (pre-auth) ---
    'ui.telnet.server.login_menu.prompt'       => 'Would you like to:',
    'ui.telnet.server.login_menu.login'        => '  (L) Login to existing account',
    'ui.telnet.server.login_menu.register'     => '  (R) Register new account',
    'ui.telnet.server.login_menu.quit'         => '  (Q) Quit',
    'ui.telnet.server.login_menu.choice'       => 'Your choice: ',
    'ui.telnet.server.goodbye'                 => 'Goodbye!',
    'ui.telnet.server.press_enter_disconnect'  => 'Press Enter to disconnect.',

    // --- Login ---
    'ui.telnet.server.login.username_prompt'   => 'Username: ',
    'ui.telnet.server.login.password_prompt'   => 'Password: ',
    'ui.telnet.server.login.success'           => 'Login successful.',
    'ui.telnet.server.login.failed_remaining'  => 'Login failed. {remaining} attempt(s) remaining.',
    'ui.telnet.server.login.failed_max'        => 'Login failed. Maximum attempts exceeded.',

    // --- Registration ---
    'ui.telnet.server.registration.title'          => '=== New User Registration ===',
    'ui.telnet.server.registration.intro'          => 'Please provide the following information to create your account.',
    'ui.telnet.server.registration.cancel_hint'    => '(Type "cancel" at any prompt to abort registration)',
    'ui.telnet.server.registration.username'       => 'Username (3-20 chars, letters/numbers/underscore): ',
    'ui.telnet.server.registration.password'       => 'Password (min 8 characters): ',
    'ui.telnet.server.registration.confirm'        => 'Confirm password: ',
    'ui.telnet.server.registration.password_mismatch' => 'Error: Passwords do not match.',
    'ui.telnet.server.registration.realname'       => 'Real Name: ',
    'ui.telnet.server.registration.email'          => 'Email (optional): ',
    'ui.telnet.server.registration.location'       => 'Location (optional): ',
    'ui.telnet.server.registration.submitting'     => 'Submitting registration...',
    'ui.telnet.server.registration.success'        => 'Registration successful!',
    'ui.telnet.server.registration.pending'        => 'Your account has been created and is pending approval.',
    'ui.telnet.server.registration.pending_review' => 'You will be notified once an administrator has reviewed your registration.',

    // --- Anti-bot challenge ---
    'ui.telnet.server.press_esc'               => 'Press ESC twice to continue...',

    // --- Banner (login screen) ---
    'ui.telnet.server.banner.title'            => 'BinktermPHP Telnet Service',
    'ui.telnet.server.banner.system'           => 'System: ',
    'ui.telnet.server.banner.location'         => 'Location: ',
    'ui.telnet.server.banner.origin'           => 'Origin: ',
    'ui.telnet.server.banner.web'              => 'Web: ',
    'ui.telnet.server.banner.visit_web'        => 'For a good time visit us on the web @ {url}',
    'ui.telnet.server.banner.tls'             => 'Connected using TLS',
    'ui.telnet.server.banner.no_tls'          => 'Connected without TLS - use port {port} for an encrypted connection',

    // --- Main menu ---
    'ui.telnet.server.menu.title'              => 'Main Menu',
    'ui.telnet.server.menu.select_option'      => 'Select option:',
    'ui.telnet.server.menu.netmail'            => 'N) Netmail ({count} messages)',
    'ui.telnet.server.menu.echomail'           => 'E) Echomail ({count} messages)',
    'ui.telnet.server.menu.whos_online'        => "W) Who's Online",
    'ui.telnet.server.menu.shoutbox'           => 'S) Shoutbox',
    'ui.telnet.server.menu.polls'              => 'P) Polls',
    'ui.telnet.server.menu.doors'              => 'D) Door Games',
    'ui.telnet.server.menu.quit'              => 'Q) Quit',

    // --- Farewell ---
    'ui.telnet.server.farewell'                => 'Thank you for visiting, have a great day!',
    'ui.telnet.server.visit_web'               => 'Come back and visit us on the web at {url}',

    // --- Who's Online ---
    'ui.telnet.server.whos_online.title'       => "Who's Online (last {minutes} minutes)",
    'ui.telnet.server.whos_online.empty'       => 'No users online.',

    // --- Idle timeout ---
    'ui.telnet.server.idle.disconnect'         => 'Idle timeout - disconnecting...',
    'ui.telnet.server.idle.warning_line'       => 'Are you still there? (Press Enter to continue)',
    'ui.telnet.server.idle.warning_key'        => 'Are you still there? (Press any key to continue)',

    // --- Shared UI prompts ---
    'ui.telnet.server.press_any_key'           => 'Press any key to return...',
    'ui.telnet.server.press_continue'          => 'Press any key to continue...',

    // --- Message editor (full screen) ---
    'ui.telnet.editor.title'                   => 'MESSAGE EDITOR - FULL SCREEN MODE',
    'ui.telnet.editor.shortcuts'               => 'Ctrl+K=Help  Ctrl+Z=Send  Ctrl+C=Cancel',
    'ui.telnet.editor.cancelled'               => 'Message cancelled.',
    'ui.telnet.editor.saved'                   => 'Message saved and ready to send.',
    'ui.telnet.editor.starting_text'           => 'Starting with quoted text. Enter your reply below.',
    'ui.telnet.editor.instructions'            => 'Enter message text. End with a single "." line. Type "/abort" to cancel.',

    // --- Message editor help ---
    'ui.telnet.editor.help.title'              => 'MESSAGE EDITOR HELP',
    'ui.telnet.editor.help.separator'          => '-------------------',
    'ui.telnet.editor.help.navigate'           => 'Arrow Keys = Navigate cursor',
    'ui.telnet.editor.help.edit'               => 'Backspace/Delete = Edit text',
    'ui.telnet.editor.help.help'               => 'Ctrl+K = Help',
    'ui.telnet.editor.help.start_of_line'      => 'Ctrl+A = Start of line',
    'ui.telnet.editor.help.end_of_line'        => 'Ctrl+E = End of line',
    'ui.telnet.editor.help.delete_line'        => 'Ctrl+Y = Delete entire line',
    'ui.telnet.editor.help.save'               => 'Ctrl+Z = Save message and send',
    'ui.telnet.editor.help.cancel'             => 'Ctrl+C = Cancel and discard message',

    // --- Compose (shared between netmail and echomail) ---
    'ui.telnet.compose.to_name'                => 'To Name: ',
    'ui.telnet.compose.to_address'             => 'To Address: ',
    'ui.telnet.compose.subject'                => 'Subject: ',
    'ui.telnet.compose.no_recipient'           => 'Recipient name required. Message cancelled.',
    'ui.telnet.compose.enter_message'          => 'Enter your message below:',
    'ui.telnet.compose.select_tagline'         => 'Select a tagline:',
    'ui.telnet.compose.no_tagline'             => ' 0) None',
    'ui.telnet.compose.tagline_default'        => 'Tagline # [{default}] (Enter for Default): ',
    'ui.telnet.compose.tagline_none'           => 'Tagline # (Enter for None): ',
    'ui.telnet.compose.message_cancelled'      => 'Message cancelled (empty).',

    // --- Echomail ---
    'ui.telnet.echomail.no_areas'              => 'No echoareas available.',
    'ui.telnet.echomail.areas_header'          => 'Echoareas (page {page}/{total}):',
    'ui.telnet.echomail.areas_nav'             => 'Enter #, n/p (next/prev), q (quit)',
    'ui.telnet.echomail.no_messages'           => 'No echomail messages.',
    'ui.telnet.echomail.messages_header'       => 'Echomail: {area} (page {page}/{total})',
    'ui.telnet.echomail.compose_title'         => '=== Compose Echomail ===',
    'ui.telnet.echomail.area_label'            => 'Area: {area}',
    'ui.telnet.echomail.posting'               => 'Posting echomail...',
    'ui.telnet.echomail.post_success'          => '✓ Echomail posted successfully!',
    'ui.telnet.echomail.post_failed'           => '✗ Failed to post echomail: {error}',

    // --- Netmail ---
    'ui.telnet.netmail.no_messages'            => 'No netmail messages.',
    'ui.telnet.netmail.header'                 => 'Netmail (page {page}/{total}):',
    'ui.telnet.netmail.compose_title'          => '=== Compose Netmail ===',
    'ui.telnet.netmail.sending'                => 'Sending netmail...',
    'ui.telnet.netmail.send_success'           => '✓ Netmail sent successfully!',
    'ui.telnet.netmail.send_failed'            => '✗ Failed to send netmail: {error}',

    // --- Polls ---
    'ui.telnet.polls.disabled'                 => 'Voting booth is disabled.',
    'ui.telnet.polls.title'                    => 'Polls',
    'ui.telnet.polls.no_polls'                 => 'No active polls.',
    'ui.telnet.polls.detail_title'             => 'Poll Detail',
    'ui.telnet.polls.total_votes'              => 'Total votes: {count}',
    'ui.telnet.polls.enter_poll'               => 'Enter poll # or Q to return: ',
    'ui.telnet.polls.vote_prompt'              => 'Vote with option # or Q to return: ',
    'ui.telnet.polls.voted'                    => 'Vote recorded.',

    // --- Shoutbox ---
    'ui.telnet.shoutbox.title'                 => 'Shoutbox',
    'ui.telnet.shoutbox.recent_title'          => 'Recent Shoutbox',
    'ui.telnet.shoutbox.no_messages'           => 'No shoutbox messages.',
    'ui.telnet.shoutbox.menu'                  => '[P]ost  [R]efresh  [Q]uit: ',
    'ui.telnet.shoutbox.new_shout'             => 'New shout (blank to cancel): ',
    'ui.telnet.shoutbox.posted'                => 'Shout posted.',
    'ui.telnet.shoutbox.post_failed'           => 'Failed to post shout.',

    // --- Door games ---
    'ui.telnet.doors.no_doors'                 => 'No doors are currently available.',
    'ui.telnet.doors.title'                    => '=== Door Games ===',
    'ui.telnet.doors.enter_choice'             => 'Enter number to play, or Q to return: ',
    'ui.telnet.doors.invalid'                  => 'Invalid selection.',
    'ui.telnet.doors.launching'                => 'Launching {name}...',
    'ui.telnet.doors.launch_error'             => 'Error: {error}',
    'ui.telnet.doors.connecting'               => 'Connecting to game server...',
    'ui.telnet.doors.connect_failed'           => 'Could not connect to game bridge. Is the DOS door bridge running?',
    'ui.telnet.doors.connected'                => 'Connected! Starting game...',
    'ui.telnet.doors.returned'                 => 'Returned from {name}.',
];
