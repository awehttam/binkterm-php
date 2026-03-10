<?php

// Telnet server UI strings (English)
return [

    // --- Connection / rate limiting ---
    'ui.terminalserver.server.rate_limited'            => 'Too many failed login attempts. Please try again later.',

    // --- Login / register menu (pre-auth) ---
    'ui.terminalserver.server.login_menu.prompt'       => 'Would you like to:',
    'ui.terminalserver.server.login_menu.login'        => '  (L) Login to existing account',
    'ui.terminalserver.server.login_menu.register'     => '  (R) Register new account',
    'ui.terminalserver.server.login_menu.quit'         => '  (Q) Quit',
    'ui.terminalserver.server.login_menu.choice'       => 'Your choice: ',
    'ui.terminalserver.server.goodbye'                 => 'Goodbye!',
    'ui.terminalserver.server.press_enter_disconnect'  => 'Press Enter to disconnect.',

    // --- Login ---
    'ui.terminalserver.server.login.username_prompt'   => 'Username: ',
    'ui.terminalserver.server.login.password_prompt'   => 'Password: ',
    'ui.terminalserver.server.login.success'           => 'Login successful.',
    'ui.terminalserver.server.login.failed_remaining'  => 'Login failed. {remaining} attempt(s) remaining.',
    'ui.terminalserver.server.login.failed_max'        => 'Login failed. Maximum attempts exceeded.',

    // --- Registration ---
    'ui.terminalserver.server.registration.title'          => '=== New User Registration ===',
    'ui.terminalserver.server.registration.intro'          => 'Please provide the following information to create your account.',
    'ui.terminalserver.server.registration.cancel_hint'    => '(Type "cancel" at any prompt to abort registration)',
    'ui.terminalserver.server.registration.username'       => 'Username (3-20 chars, letters/numbers/underscore): ',
    'ui.terminalserver.server.registration.password'       => 'Password (min 8 characters): ',
    'ui.terminalserver.server.registration.confirm'        => 'Confirm password: ',
    'ui.terminalserver.server.registration.password_mismatch' => 'Error: Passwords do not match.',
    'ui.terminalserver.server.registration.realname'       => 'Real Name: ',
    'ui.terminalserver.server.registration.email'          => 'Email (optional): ',
    'ui.terminalserver.server.registration.location'       => 'Location (optional): ',
    'ui.terminalserver.server.registration.submitting'     => 'Submitting registration...',
    'ui.terminalserver.server.registration.success'        => 'Registration successful!',
    'ui.terminalserver.server.registration.pending'        => 'Your account has been created and is pending approval.',
    'ui.terminalserver.server.registration.pending_review' => 'You will be notified once an administrator has reviewed your registration.',

    // --- Anti-bot challenge ---
    'ui.terminalserver.server.press_esc'               => 'Press ESC twice to continue...',

    // --- Banner (login screen) ---
    'ui.terminalserver.server.banner.title'            => 'BinktermPHP Terminal',
    'ui.terminalserver.server.banner.system'           => 'System: ',
    'ui.terminalserver.server.banner.location'         => 'Location: ',
    'ui.terminalserver.server.banner.origin'           => 'Origin: ',
    'ui.terminalserver.server.banner.web'              => 'Web: ',
    'ui.terminalserver.server.banner.visit_web'        => 'For a good time visit us on the web @ {url}',
    'ui.terminalserver.server.banner.tls'             => 'Connected using TLS',
    'ui.terminalserver.server.banner.no_tls'          => 'Connected without TLS - use port {port} for an encrypted connection',
    'ui.terminalserver.server.ssh_banner.welcome'     => 'Welcome to {system}.',
    'ui.terminalserver.server.ssh_banner.line2'       => 'Log in with your account credentials, or enter any username/password',
    'ui.terminalserver.server.ssh_banner.line3'       => 'to continue to the main BBS login screen.',

    // --- Main menu ---
    'ui.terminalserver.server.menu.title'              => 'Main Menu',
    'ui.terminalserver.server.menu.select_option'      => 'Select option:',
    'ui.terminalserver.server.menu.netmail'            => 'N) Netmail ({count} messages)',
    'ui.terminalserver.server.menu.echomail'           => 'E) Echomail ({count} messages)',
    'ui.terminalserver.server.menu.whos_online'        => "W) Who's Online",
    'ui.terminalserver.server.menu.shoutbox'           => 'S) Shoutbox',
    'ui.terminalserver.server.menu.polls'              => 'P) Polls',
    'ui.terminalserver.server.menu.doors'              => 'D) Door Games',
    'ui.terminalserver.server.menu.quit'              => 'Q) Quit',

    // --- Farewell ---
    'ui.terminalserver.server.farewell'                => 'Thank you for visiting, have a great day!',
    'ui.terminalserver.server.visit_web'               => 'Come back and visit us on the web at {url}',

    // --- Who's Online ---
    'ui.terminalserver.server.whos_online.title'       => "Who's Online (last {minutes} minutes)",
    'ui.terminalserver.server.whos_online.empty'       => 'No users online.',

    // --- Idle timeout ---
    'ui.terminalserver.server.idle.disconnect'         => 'Idle timeout - disconnecting...',
    'ui.terminalserver.server.idle.warning_line'       => 'Are you still there? (Press Enter to continue)',
    'ui.terminalserver.server.idle.warning_key'        => 'Are you still there? (Press any key to continue)',

    // --- Shared UI prompts ---
    'ui.terminalserver.server.press_any_key'           => 'Press any key to return...',
    'ui.terminalserver.server.press_continue'          => 'Press any key to continue...',

    // --- Message editor (full screen) ---
    'ui.terminalserver.editor.title'                   => 'MESSAGE EDITOR - FULL SCREEN MODE',
    'ui.terminalserver.editor.shortcuts'               => 'Ctrl+K=Help  Ctrl+Z=Send  Ctrl+C=Cancel',
    'ui.terminalserver.editor.cancelled'               => 'Message cancelled.',
    'ui.terminalserver.editor.saved'                   => 'Message saved and ready to send.',
    'ui.terminalserver.editor.starting_text'           => 'Starting with quoted text. Enter your reply below.',
    'ui.terminalserver.editor.instructions'            => 'Enter message text. End with a single "." line. Type "/abort" to cancel.',

    // --- Message editor help ---
    'ui.terminalserver.editor.help.title'              => 'MESSAGE EDITOR HELP',
    'ui.terminalserver.editor.help.separator'          => '-------------------',
    'ui.terminalserver.editor.help.navigate'           => 'Arrow Keys = Navigate cursor',
    'ui.terminalserver.editor.help.edit'               => 'Backspace/Delete = Edit text',
    'ui.terminalserver.editor.help.help'               => 'Ctrl+K = Help',
    'ui.terminalserver.editor.help.start_of_line'      => 'Ctrl+A = Start of line',
    'ui.terminalserver.editor.help.end_of_line'        => 'Ctrl+E = End of line',
    'ui.terminalserver.editor.help.delete_line'        => 'Ctrl+Y = Delete entire line',
    'ui.terminalserver.editor.help.save'               => 'Ctrl+Z = Save message and send',
    'ui.terminalserver.editor.help.cancel'             => 'Ctrl+C = Cancel and discard message',

    // --- Compose (shared between netmail and echomail) ---
    'ui.terminalserver.compose.to_name'                => 'To Name: ',
    'ui.terminalserver.compose.to_address'             => 'To Address: ',
    'ui.terminalserver.compose.subject'                => 'Subject: ',
    'ui.terminalserver.compose.no_recipient'           => 'Recipient name required. Message cancelled.',
    'ui.terminalserver.compose.enter_message'          => 'Enter your message below:',
    'ui.terminalserver.compose.select_tagline'         => 'Select a tagline:',
    'ui.terminalserver.compose.no_tagline'             => ' 0) None',
    'ui.terminalserver.compose.tagline_default'        => 'Tagline # [{default}] (Enter for Default): ',
    'ui.terminalserver.compose.tagline_none'           => 'Tagline # (Enter for None): ',
    'ui.terminalserver.compose.message_cancelled'      => 'Message cancelled (empty).',

    // --- Echomail ---
    'ui.terminalserver.echomail.no_areas'              => 'No echoareas available.',
    'ui.terminalserver.echomail.areas_header'          => 'Echoareas (page {page}/{total}):',
    'ui.terminalserver.echomail.areas_nav'             => 'Enter #, n/p (next/prev), q (quit)',
    'ui.terminalserver.echomail.no_messages'           => 'No echomail messages.',
    'ui.terminalserver.echomail.messages_header'       => 'Echomail: {area} (page {page}/{total})',
    'ui.terminalserver.echomail.compose_title'         => '=== Compose Echomail ===',
    'ui.terminalserver.echomail.area_label'            => 'Area: {area}',
    'ui.terminalserver.echomail.posting'               => 'Posting echomail...',
    'ui.terminalserver.echomail.post_success'          => '✓ Echomail posted successfully!',
    'ui.terminalserver.echomail.post_failed'           => '✗ Failed to post echomail: {error}',

    // --- Netmail ---
    'ui.terminalserver.netmail.no_messages'            => 'No netmail messages.',
    'ui.terminalserver.netmail.header'                 => 'Netmail (page {page}/{total}):',
    'ui.terminalserver.netmail.compose_title'          => '=== Compose Netmail ===',
    'ui.terminalserver.netmail.sending'                => 'Sending netmail...',
    'ui.terminalserver.netmail.send_success'           => '✓ Netmail sent successfully!',
    'ui.terminalserver.netmail.send_failed'            => '✗ Failed to send netmail: {error}',
    'ui.terminalserver.netmail.attachments_none'       => 'No file attachments on this message.',
    'ui.terminalserver.netmail.attachments_header'     => 'Attachments:',
    'ui.terminalserver.netmail.attachment_download_prompt' => 'Attachment # to download (Enter to cancel): ',

    // --- Polls ---
    'ui.terminalserver.polls.disabled'                 => 'Voting booth is disabled.',
    'ui.terminalserver.polls.title'                    => 'Polls',
    'ui.terminalserver.polls.no_polls'                 => 'No active polls.',
    'ui.terminalserver.polls.detail_title'             => 'Poll Detail',
    'ui.terminalserver.polls.total_votes'              => 'Total votes: {count}',
    'ui.terminalserver.polls.enter_poll'               => 'Enter poll # or Q to return: ',
    'ui.terminalserver.polls.vote_prompt'              => 'Vote with option # or Q to return: ',
    'ui.terminalserver.polls.voted'                    => 'Vote recorded.',

    // --- Shoutbox ---
    'ui.terminalserver.shoutbox.title'                 => 'Shoutbox',
    'ui.terminalserver.shoutbox.recent_title'          => 'Recent Shoutbox',
    'ui.terminalserver.shoutbox.no_messages'           => 'No shoutbox messages.',
    'ui.terminalserver.shoutbox.menu'                  => '[P]ost  [R]efresh  [Q]uit: ',
    'ui.terminalserver.shoutbox.new_shout'             => 'New shout (blank to cancel): ',
    'ui.terminalserver.shoutbox.posted'                => 'Shout posted.',
    'ui.terminalserver.shoutbox.post_failed'           => 'Failed to post shout.',

    // --- Main menu: files ---
    'ui.terminalserver.server.menu.files'              => 'F) Files',

    // --- File areas ---
    'ui.terminalserver.files.no_areas'                 => 'No file areas available.',
    'ui.terminalserver.files.areas_header'             => 'File Areas (page {page}/{total}):',
    'ui.terminalserver.files.areas_nav'                => 'Enter #, n/p (next/prev), q (quit)',
    'ui.terminalserver.files.area_header'              => 'Files: {area} (page {page}/{total})',
    'ui.terminalserver.files.no_files'                 => 'No files in this area.',
    'ui.terminalserver.files.files_nav'                => 'D)ownload  n/p (next/prev)  Q)uit',
    'ui.terminalserver.files.files_nav_upload'         => 'D)ownload  U)pload  n/p (next/prev)  Q)uit',
    'ui.terminalserver.files.files_nav_upload_only'    => 'U)pload  n/p (next/prev)  Q)uit',
    'ui.terminalserver.files.files_nav_none'           => 'n/p (next/prev)  Q)uit',
    'ui.terminalserver.files.transfer_unavailable'     => 'ZMODEM disabled: install lrzsz (sz/rz) on the server to enable transfers.',
    'ui.terminalserver.files.invalid_selection'        => 'Invalid selection.',
    'ui.terminalserver.files.download_prompt'          => 'File # to download (Enter to cancel): ',
    'ui.terminalserver.files.download_error'           => 'File not found on server.',
    'ui.terminalserver.files.download_starting'        => 'Starting ZMODEM download: {name}',
    'ui.terminalserver.files.download_hint'            => 'Start ZMODEM receive in your terminal now...',
    'ui.terminalserver.files.download_done'            => 'Transfer complete.',
    'ui.terminalserver.files.download_failed'          => 'Transfer failed or was cancelled.',
    'ui.terminalserver.files.upload_title'             => '=== Upload File ===',
    'ui.terminalserver.files.upload_area'              => 'Area: {area}',
    'ui.terminalserver.files.upload_desc_prompt'       => 'Short description (blank to cancel): ',
    'ui.terminalserver.files.upload_cancelled'         => 'Upload cancelled.',
    'ui.terminalserver.files.upload_starting'          => 'Start ZMODEM send in your terminal now...',
    'ui.terminalserver.files.upload_failed'            => 'Transfer failed or was cancelled.',
    'ui.terminalserver.files.upload_done'              => 'File uploaded successfully (ID: {id}).',
    'ui.terminalserver.files.upload_error'             => 'Upload error: {error}',
    'ui.terminalserver.files.upload_duplicate'         => 'This file already exists in this area.',
    'ui.terminalserver.files.upload_readonly'          => 'This area is read-only. Uploads are not permitted.',
    'ui.terminalserver.files.upload_admin_only'        => 'Only administrators can upload to this area.',

    // --- Main menu: terminal settings ---
    'ui.terminalserver.server.menu.terminal_settings'  => 'T) Terminal Settings',

    // --- Terminal settings page ---
    'ui.terminalserver.settings.title'                 => '=== Terminal Settings ===',
    'ui.terminalserver.settings.charset_label'         => 'Character set : {value}',
    'ui.terminalserver.settings.ansi_label'            => 'ANSI color    : {value}',
    'ui.terminalserver.settings.not_set'               => 'Not configured',
    'ui.terminalserver.settings.menu_detect'           => 'D) Run detection wizard',
    'ui.terminalserver.settings.menu_charset'          => 'C) Change character set manually',
    'ui.terminalserver.settings.menu_ansi'             => 'A) Toggle ANSI color',
    'ui.terminalserver.settings.menu_quit'             => 'Q) Return to main menu',
    'ui.terminalserver.settings.saved'                 => 'Settings saved.',
    'ui.terminalserver.settings.save_failed'           => 'Warning: could not save settings.',
    'ui.terminalserver.settings.invalid_choice'        => 'Invalid choice.',
    'ui.terminalserver.settings.charset_prompt'        => 'Select: (U)TF-8, (C)P437, (A)SCII: ',

    // --- Terminal detection wizard ---
    'ui.terminalserver.detect.title'                   => '=== Terminal Setup ===',
    'ui.terminalserver.detect.intro'                   => 'BBS will now test your terminal to ensure content displays correctly.',
    'ui.terminalserver.detect.charset_intro'           => 'Character set test:',
    'ui.terminalserver.detect.charset_question'        => 'Do the above appear as arrows, checkmarks, and accented letters? (Y/N): ',
    'ui.terminalserver.detect.charset_utf8'            => 'UTF-8 character set enabled.',
    'ui.terminalserver.detect.charset_cp437_intro'     => 'CP437 box-drawing test:',
    'ui.terminalserver.detect.charset_cp437_question'  => 'Do the above appear as a box drawn with lines and corners? (Y/N): ',
    'ui.terminalserver.detect.charset_cp437'           => 'CP437 (DOS/ANSI) character set enabled.',
    'ui.terminalserver.detect.charset_ascii'           => 'ASCII mode enabled.',
    'ui.terminalserver.detect.ansi_intro'              => 'Color test:',
    'ui.terminalserver.detect.ansi_question'           => 'Do the words above appear in different colors? (Y/N): ',
    'ui.terminalserver.detect.ansi_yes'                => 'ANSI color enabled.',
    'ui.terminalserver.detect.ansi_no'                 => 'ANSI color disabled.',
    'ui.terminalserver.detect.complete'                => 'Terminal setup complete. Settings saved.',
    'ui.terminalserver.detect.press_enter'             => 'Press Enter to continue...',

    // --- Door games ---
    'ui.terminalserver.doors.no_doors'                 => 'No doors are currently available.',
    'ui.terminalserver.doors.title'                    => '=== Door Games ===',
    'ui.terminalserver.doors.enter_choice'             => 'Enter number to play, or Q to return: ',
    'ui.terminalserver.doors.invalid'                  => 'Invalid selection.',
    'ui.terminalserver.doors.launching'                => 'Launching {name}...',
    'ui.terminalserver.doors.launch_error'             => 'Error: {error}',
    'ui.terminalserver.doors.connecting'               => 'Connecting to game server...',
    'ui.terminalserver.doors.connect_failed'           => 'Could not connect to game bridge. Is the DOS door bridge running?',
    'ui.terminalserver.doors.connected'                => 'Connected! Starting game...',
    'ui.terminalserver.doors.returned'                 => 'Returned from {name}.',

    'ui.terminalserver.message.headers_title'          => '=== Message Headers ===',
    'ui.terminalserver.message.no_headers'             => '(No message headers)',
];
