<?php

// Telnet server UI strings (English)
return [

    // --- Connection / rate limiting ---
    'ui.terminalserver.server.rate_limited'            => 'Troppi tentativi di accesso non riusciti. Riprova più tardi.',

    // --- Login / register menu (pre-auth) ---
    'ui.terminalserver.server.login_menu.prompt'       => 'Vorresti:',
    'ui.terminalserver.server.login_menu.login'        => '  (L) Accedi account esistente',
    'ui.terminalserver.server.login_menu.reset_password' => '  (R) Reimposta la password persa',
    'ui.terminalserver.server.login_menu.register'     => '  (N) Registra un nuovo account',
    'ui.terminalserver.server.login_menu.qwk_transfer' => '  (K) Trasferimento QWK',
    'ui.terminalserver.server.login_menu.quit'         => '  (Q) Esci',
    'ui.terminalserver.server.login_menu.choice'       => 'La tua scelta: ',
    'ui.terminalserver.server.login_menu.invalid_choice' => 'Selezione non valida.',
    'ui.terminalserver.server.goodbye'                 => 'Arrivederci!',
    'ui.terminalserver.server.press_enter_disconnect'  => 'Premi Invio per disconnetterti.',

    // --- Login ---
    'ui.terminalserver.server.login.username_prompt'   => 'Nome utente: ',
    'ui.terminalserver.server.login.password_prompt'   => 'Password: ',
    'ui.terminalserver.server.login.success'           => 'Accesso effettuato con successo.',
    'ui.terminalserver.server.login.failed_remaining'  => 'Accesso non riuscito. Rimangono {remaining} tentativi.',
    'ui.terminalserver.server.login.failed_max'        => 'Accesso non riuscito. Numero massimo di tentativi superato.',

    // --- Lost password reset ---
    'ui.terminalserver.server.reset_password.title'    => '=== Reimpostazione password ===',
    'ui.terminalserver.server.reset_password.intro'    => 'Inserisci nome utente, nome reale o indirizzo email e ti invieremo un link per reimpostare la password se l’account esiste.',
    'ui.terminalserver.server.reset_password.cancel_hint' => '(Premi Invio su un prompt vuoto per annullare)',
    'ui.terminalserver.server.reset_password.identifier_prompt' => 'Nome utente, nome reale o email: ',
    'ui.terminalserver.server.reset_password.submitting' => 'Richiesta reimpostazione password...',
    'ui.terminalserver.server.reset_password.success'  => 'Se esiste un account con quel nome utente, nome reale o email, è stato inviato un link per reimpostare la password.',
    'ui.terminalserver.server.reset_password.check_email' => 'Controlla la posta in arrivo e la cartella spam per il link di reimpostazione.',
    'ui.terminalserver.server.reset_password.failed'   => 'Impossibile elaborare la richiesta di reimpostazione password.',

    // --- Registration ---
    'ui.terminalserver.server.registration.title'          => '=== Registrazione nuovo utente ===',
    'ui.terminalserver.server.registration.intro'          => 'Fornisci le seguenti informazioni per creare il tuo account.',
    'ui.terminalserver.server.registration.cancel_hint'    => '(Digita "cancel" in qualsiasi prompt per interrompere la registrazione)',
    'ui.terminalserver.server.registration.username'       => 'Nome utente (3-20 caratteri, lettere/numeri/underscore): ',
    'ui.terminalserver.server.registration.password'       => 'Password (minimo 8 caratteri): ',
    'ui.terminalserver.server.registration.confirm'        => 'Conferma password: ',
    'ui.terminalserver.server.registration.password_mismatch' => 'Errore: le password non corrispondono.',
    'ui.terminalserver.server.registration.realname'       => 'Nome reale: ',
    'ui.terminalserver.server.registration.email'          => 'Email (opzionale): ',
    'ui.terminalserver.server.registration.location'       => 'Località (opzionale): ',
    'ui.terminalserver.server.registration.reason'         => 'Motivo dell’iscrizione (opzionale): ',
    'ui.terminalserver.server.registration.submitting'     => 'Invio registrazione...',
    'ui.terminalserver.server.registration.success'        => 'Registrazione completata!',
    'ui.terminalserver.server.registration.pending'        => 'Il tuo account è stato creato ed è in attesa di approvazione.',
    'ui.terminalserver.server.registration.pending_review' => 'Riceverai una notifica quando un amministratore avrà esaminato la tua registrazione.',

    // --- Anti-bot challenge ---
    'ui.terminalserver.server.press_esc'               => 'Premi ESC due volte per continuare...',

    // --- Banner (login screen) ---
    'ui.terminalserver.server.banner.title'            => 'Terminale BinktermPHP',
    'ui.terminalserver.server.banner.system'           => 'Sistema: ',
    'ui.terminalserver.server.banner.location'         => 'Località: ',
    'ui.terminalserver.server.banner.origin'           => 'Origine: ',
    'ui.terminalserver.server.banner.web'              => 'Web: ',
    'ui.terminalserver.server.banner.visit_web'        => 'Visitateci sul web @ {url}',
    'ui.terminalserver.server.banner.tls'             => 'Connesso tramite TLS',
    'ui.terminalserver.server.banner.no_tls'          => 'Connesso senza TLS - usa la porta {port} per una connessione cifrata',
    'ui.terminalserver.server.ssh_banner.welcome'     => 'Benvenuto su {system}.',
    'ui.terminalserver.server.ssh_banner.line2'       => 'Accedi con le credenziali del tuo account, oppure inserisci qualsiasi nome utente/password',
    'ui.terminalserver.server.ssh_banner.line3'       => 'per continuare alla schermata principale di login della BBS.',

    // --- Main menu ---
    'ui.terminalserver.server.menu.title'              => 'Menu principale',
    'ui.terminalserver.server.menu.select_option'      => 'Seleziona opzione:',
    'ui.terminalserver.server.menu.netmail'            => 'N) Netmail ({count} messaggi)',
    'ui.terminalserver.server.menu.echomail'           => 'E) Echomail ({count} messaggi)',
    'ui.terminalserver.server.menu.whos_online'        => 'W) Chi è online',
    'ui.terminalserver.server.menu.shoutbox'           => 'S) Shoutbox',
    'ui.terminalserver.server.menu.polls'              => 'P) Sondaggi',
    'ui.terminalserver.server.menu.doors'              => 'D) Giochi door',
    'ui.terminalserver.server.menu.quit'              => 'Q) Esci',

    // --- Farewell ---
    'ui.terminalserver.server.farewell'                => 'Grazie per la visita, buona giornata!',
    'ui.terminalserver.server.visit_web'               => 'Torna a visitarci sul web all’indirizzo {url}',

    // --- Who's Online ---
    'ui.terminalserver.server.whos_online.title'       => 'Chi è online (ultimi {minutes} minuti)',
    'ui.terminalserver.server.whos_online.empty'       => 'Nessun utente online.',

    // --- Idle timeout ---
    'ui.terminalserver.server.idle.disconnect'         => 'Timeout inattività - disconnessione...',
    'ui.terminalserver.server.idle.warning_line'       => 'Sei ancora lì? (Premi Invio per continuare)',
    'ui.terminalserver.server.idle.warning_key'        => 'Sei ancora lì? (Premi un tasto per continuare)',

    // --- Shared UI prompts ---
    'ui.terminalserver.server.press_any_key'           => 'Premi un tasto per tornare...',
    'ui.terminalserver.server.press_continue'          => 'Premi un tasto per continuare...',

    // --- Message editor (full screen) ---
    'ui.terminalserver.editor.title'                   => 'EDITOR MESSAGGI - MODALITÀ SCHERMO INTERO',
    'ui.terminalserver.editor.shortcuts'               => 'Ctrl+K=Aiuto  Ctrl+Z=Invia  Ctrl+C=Annulla',
    'ui.terminalserver.editor.cancelled'               => 'Messaggio annullato.',
    'ui.terminalserver.editor.saved'                   => 'Messaggio salvato e pronto per l’invio.',
    'ui.terminalserver.editor.starting_text'           => 'Inizio con testo citato. Inserisci la risposta sotto.',
    'ui.terminalserver.editor.instructions'            => 'Inserisci il testo del messaggio. Termina con una singola riga ".". Digita "/abort" per annullare.',

    // --- Message editor help ---
    'ui.terminalserver.editor.help.title'              => 'AIUTO EDITOR MESSAGGI',
    'ui.terminalserver.editor.help.separator'          => '-------------------',
    'ui.terminalserver.editor.help.navigate'           => 'Tasti freccia = Sposta cursore',
    'ui.terminalserver.editor.help.edit'               => 'Backspace/Delete = Modifica testo',
    'ui.terminalserver.editor.help.help'               => 'Ctrl+K = Aiuto',
    'ui.terminalserver.editor.help.start_of_line'      => 'Ctrl+A = Inizio riga',
    'ui.terminalserver.editor.help.end_of_line'        => 'Ctrl+E = Fine riga',
    'ui.terminalserver.editor.help.delete_line'        => 'Ctrl+Y = Elimina intera riga',
    'ui.terminalserver.editor.help.save'               => 'Ctrl+Z = Salva messaggio e invia',
    'ui.terminalserver.editor.help.cancel'             => 'Ctrl+C = Annulla ed elimina il messaggio',

    // --- Compose (shared between netmail and echomail) ---
    'ui.terminalserver.compose.to_name'                => 'Nome destinatario: ',
    'ui.terminalserver.compose.to_address'             => 'Indirizzo destinatario: ',
    'ui.terminalserver.compose.subject'                => 'Oggetto: ',
    'ui.terminalserver.compose.no_recipient'           => 'Nome destinatario obbligatorio. Messaggio annullato.',
    'ui.terminalserver.compose.enter_message'          => 'Inserisci il messaggio qui sotto:',
    'ui.terminalserver.compose.select_tagline'         => 'Seleziona una tagline:',
    'ui.terminalserver.compose.no_tagline'             => ' 0) Nessuna',
    'ui.terminalserver.compose.tagline_default'        => 'Tagline # [{default}] (Invio per predefinita): ',
    'ui.terminalserver.compose.tagline_none'           => 'Tagline # (Invio per nessuna): ',
    'ui.terminalserver.compose.message_cancelled'      => 'Messaggio annullato (vuoto).',

    // --- Echomail ---
    'ui.terminalserver.echomail.no_areas'              => 'Non sei iscritto ad alcuna area.',
    'ui.terminalserver.echomail.areas_header'          => 'Aree echo (pagina {page}/{total}):',
    'ui.terminalserver.echomail.areas_nav'             => 'Inserisci #, n/p (succ/prec), / (cerca), q (esci)',
    'ui.terminalserver.echomail.areas_nav_interests'   => 'Inserisci #, n/p (succ/prec), / (cerca), i (per interesse), q (esci)',
    'ui.terminalserver.echomail.areas_filter'          => 'Filtro: {term} ({count} risultati)',
    'ui.terminalserver.echomail.areas_no_results'      => 'Nessuna area corrisponde alla ricerca.',
    'ui.terminalserver.echomail.areas_search_prompt'   => 'Cerca: ',
    'ui.terminalserver.echomail.areas_nav_clear'       => 'c (cancella filtro)',
    'ui.terminalserver.echomail.interests_title'       => 'Sfoglia per interesse',
    'ui.terminalserver.echomail.interests_none'        => 'Nessun interesse disponibile.',
    'ui.terminalserver.echomail.interests_prompt'      => 'Inserisci # per sfogliare, Q per tornare:',
    'ui.terminalserver.echomail.interest_areas_header' => '{name} (pagina {page}/{total}):',
    'ui.terminalserver.echomail.no_messages'           => 'Nessun messaggio echomail.',
    'ui.terminalserver.echomail.messages_header'       => 'Echomail: {area} (pagina {page}/{total})',
    'ui.terminalserver.echomail.compose_title'         => '=== Componi Echomail ===',
    'ui.terminalserver.echomail.area_label'            => 'Area: {area}',
    'ui.terminalserver.echomail.posting'               => 'Pubblicazione echomail...',
    'ui.terminalserver.echomail.post_success'          => '✓ Echomail pubblicata correttamente!',
    'ui.terminalserver.echomail.post_failed'           => '✗ Impossibile pubblicare echomail: {error}',

    // --- Netmail ---
    'ui.terminalserver.netmail.no_messages'            => 'Nessun messaggio netmail.',
    'ui.terminalserver.netmail.header'                 => 'Netmail (pagina {page}/{total}):',
    'ui.terminalserver.netmail.compose_title'          => '=== Componi Netmail ===',
    'ui.terminalserver.netmail.sending'                => 'Invio netmail...',
    'ui.terminalserver.netmail.send_success'           => '✓ Netmail inviata correttamente!',
    'ui.terminalserver.netmail.send_failed'            => '✗ Impossibile inviare netmail: {error}',
    'ui.terminalserver.netmail.attachments_none'       => 'Nessun allegato in questo messaggio.',
    'ui.terminalserver.netmail.attachments_header'     => 'Allegati:',
    'ui.terminalserver.netmail.attachment_download_prompt' => 'Allegato # da scaricare (Invio per annullare): ',

    // --- Polls ---
    'ui.terminalserver.polls.disabled'                 => 'La cabina di voto è disabilitata.',
    'ui.terminalserver.polls.title'                    => 'Sondaggi',
    'ui.terminalserver.polls.no_polls'                 => 'Nessun sondaggio attivo.',
    'ui.terminalserver.polls.detail_title'             => 'Dettaglio sondaggio',
    'ui.terminalserver.polls.total_votes'              => 'Voti totali: {count}',
    'ui.terminalserver.polls.enter_poll'               => 'Inserisci # sondaggio o Q per tornare: ',
    'ui.terminalserver.polls.vote_prompt'              => 'Vota con opzione # o Q per tornare: ',
    'ui.terminalserver.polls.voted'                    => 'Voto registrato.',

    // --- Shoutbox ---
    'ui.terminalserver.shoutbox.title'                 => 'Shoutbox',
    'ui.terminalserver.shoutbox.recent_title'          => 'Shoutbox recente',
    'ui.terminalserver.shoutbox.no_messages'           => 'Nessun messaggio shoutbox.',
    'ui.terminalserver.shoutbox.menu'                  => '[P]ubblica  [R]icarica  [Q]uit: ',
    'ui.terminalserver.shoutbox.new_shout'             => 'Nuovo shout (vuoto per annullare): ',
    'ui.terminalserver.shoutbox.posted'                => 'Shout pubblicato.',
    'ui.terminalserver.shoutbox.post_failed'           => 'Impossibile pubblicare lo shout.',

    // --- Main menu: files ---
    'ui.terminalserver.server.menu.files'              => 'F) File',

    // --- File areas ---
    'ui.terminalserver.files.no_areas'                 => 'Nessuna area file disponibile.',
    'ui.terminalserver.files.areas_header'             => 'Aree file (pagina {page}/{total}):',
    'ui.terminalserver.files.areas_nav'                => 'Inserisci #, n/p (succ/prec), q (esci)',
    'ui.terminalserver.files.area_header'              => 'File: {area} (pagina {page}/{total})',
    'ui.terminalserver.files.no_files'                 => 'Nessun file in questa area.',
    'ui.terminalserver.files.files_nav'                => 'D)ownload  n/p (succ/prec)  Q)uit',
    'ui.terminalserver.files.files_nav_upload'         => 'D)ownload  U)pload  n/p (succ/prec)  Q)uit',
    'ui.terminalserver.files.files_nav_upload_only'    => 'U)pload  n/p (succ/prec)  Q)uit',
    'ui.terminalserver.files.files_nav_none'           => 'n/p (succ/prec)  Q)uit',
    'ui.terminalserver.files.transfer_unavailable'     => 'ZMODEM disabilitato: installa lrzsz (sz/rz) sul server per abilitare i trasferimenti.',
    'ui.terminalserver.files.invalid_selection'        => 'Selezione non valida.',
    'ui.terminalserver.files.download_prompt'          => 'File # da scaricare (Invio per annullare): ',
    'ui.terminalserver.files.download_error'           => 'File non trovato sul server.',
    'ui.terminalserver.files.download_starting'        => 'Avvio download ZMODEM: {name}',
    'ui.terminalserver.files.download_hint'            => 'Avvia ora la ricezione ZMODEM nel terminale...',
    'ui.terminalserver.files.download_done'            => 'Trasferimento completato.',
    'ui.terminalserver.files.download_failed'          => 'Trasferimento non riuscito o annullato.',
    'ui.terminalserver.files.upload_title'             => '=== Caricamento file ===',
    'ui.terminalserver.files.upload_area'              => 'Area: {area}',
    'ui.terminalserver.files.upload_desc_prompt'       => 'Descrizione breve (vuoto per annullare): ',
    'ui.terminalserver.files.upload_cancelled'         => 'Caricamento annullato.',
    'ui.terminalserver.files.upload_starting'          => 'Avvia ora l’invio ZMODEM nel terminale...',
    'ui.terminalserver.files.upload_failed'            => 'Trasferimento non riuscito o annullato.',
    'ui.terminalserver.files.upload_done'              => 'File caricato correttamente (ID: {id}).',
    'ui.terminalserver.files.upload_error'             => 'Errore caricamento: {error}',
    'ui.terminalserver.files.upload_duplicate'         => 'Questo file esiste già in questa area.',
    'ui.terminalserver.files.upload_readonly'          => 'Questa area è in sola lettura. I caricamenti non sono consentiti.',
    'ui.terminalserver.files.upload_admin_only'        => 'Solo gli amministratori possono caricare in questa area.',
    'ui.terminalserver.files.files_back_hint'          => 'B)ack alla cartella superiore',
    'ui.terminalserver.files.not_a_file'               => 'Questa voce è una cartella, non un file.',
    'ui.terminalserver.files.enter_folder_or_file'     => 'Inserisci il numero di una cartella per sfogliare, o il numero di un file per vedere i dettagli.',
    'ui.terminalserver.qwk.action_logout'              => 'Q) Logout',

    // --- Main menu: terminal settings ---
    'ui.terminalserver.server.menu.terminal_settings'  => 'T) Impostazioni terminale',

    // --- Main menu ---
    'ui.terminalserver.server.menu.settings'           => 'T) Impostazioni',

    // --- Tabbed settings screen (AnsiTabComponent / SettingsHandler) ---
    'ui.terminalserver.settings.tab_title'             => 'Impostazioni BBS',
    'ui.terminalserver.settings.tab_terminal'          => 'Terminale',
    'ui.terminalserver.settings.tab_display'           => 'Visualizzazione',
    'ui.terminalserver.settings.tab_messaging'         => 'Messaggistica',
    'ui.terminalserver.settings.tab_account'           => 'Account',
    'ui.terminalserver.settings.tab_ai'                => 'AI',
    'ui.terminalserver.settings.hint_navigate'         => '  ↑↓ Sposta   ◄► Cambia   [ ] Schede   S) Salva   Q) Esci',
    'ui.terminalserver.settings.hint_navigate_ascii'   => '  Su/Giù Sposta   Sin/Des Cambia   [/] Schede   S) Salva   Q) Esci',
    'ui.terminalserver.settings.discarded'             => 'Modifiche scartate.',

    // Terminal tab fields
    'ui.terminalserver.settings.terminal.charset'      => 'Set di caratteri',
    'ui.terminalserver.settings.terminal.ansi_color'   => 'Colore ANSI',
    'ui.terminalserver.settings.terminal.run_wizard'   => 'Esegui configurazione guidata',

    // Display tab fields
    'ui.terminalserver.settings.display.messages_per_page' => 'Messaggi per pagina',
    'ui.terminalserver.settings.display.timezone'          => 'Fuso orario',
    'ui.terminalserver.settings.display.language'          => 'Lingua',
    'ui.terminalserver.settings.display.date_format'       => 'Formato data',
    'ui.terminalserver.settings.display.default_echo_list' => 'Lista echo predefinita',
    'ui.terminalserver.settings.display.echo_list_system'  => 'Predefinito di sistema',
    'ui.terminalserver.settings.display.echo_list_reader'  => 'Lettore',
    'ui.terminalserver.settings.display.echo_list_all'     => 'Tutte le aree',

    // Messaging tab fields
    'ui.terminalserver.settings.messaging.signature'        => 'Firma',
    'ui.terminalserver.settings.messaging.signature_hint'   => 'INVIO per modificare (max 4 righe)',
    'ui.terminalserver.settings.messaging.tagline'          => 'Tagline predefinita',
    'ui.terminalserver.settings.messaging.tagline_none'     => '(Nessuna tagline)',
    'ui.terminalserver.settings.messaging.tagline_random'   => '(Casuale)',
    'ui.terminalserver.settings.messaging.threaded_echo'    => 'Vista echomail a thread',
    'ui.terminalserver.settings.messaging.threaded_net'     => 'Vista netmail a thread',
    'ui.terminalserver.settings.messaging.quote_coloring'   => 'Colora testo citato',
    'ui.terminalserver.settings.messaging.forward_netmail'  => 'Inoltra netmail a email',
    'ui.terminalserver.settings.messaging.echomail_digest'  => 'Riepilogo echomail',
    'ui.terminalserver.settings.messaging.digest_none'      => 'Nessuno',
    'ui.terminalserver.settings.messaging.digest_daily'     => 'Giornaliero',
    'ui.terminalserver.settings.messaging.digest_weekly'    => 'Settimanale',
    'ui.terminalserver.settings.messaging.echomail_badge_mode'       => 'Indicatore Nuovo Echomail',
    'ui.terminalserver.settings.messaging.badge_mode_new'    => 'Nuovi dall\'ultima visita',
    'ui.terminalserver.settings.messaging.badge_mode_unread' => 'Totale non letti',

    // Account tab actions
    'ui.terminalserver.settings.account.change_password'        => 'Cambia password',
    'ui.terminalserver.settings.account.view_sessions'          => 'Visualizza sessioni attive',
    'ui.terminalserver.settings.account.reset_onboarding'       => 'Reimposta onboarding echomail',
    'ui.terminalserver.settings.account.sessions_title'         => 'Sessioni attive',
    'ui.terminalserver.settings.account.no_sessions'            => '  Nessuna sessione attiva trovata.',
    'ui.terminalserver.settings.account.sessions_hint'          => 'Inserisci il numero della sessione da revocare, oppure Q per tornare: ',
    'ui.terminalserver.settings.account.old_password_prompt'    => 'Password attuale: ',
    'ui.terminalserver.settings.account.new_password_prompt'    => 'Nuova password: ',
    'ui.terminalserver.settings.account.confirm_password_prompt' => 'Conferma nuova password: ',
    'ui.terminalserver.settings.account.password_mismatch'      => 'Le password non corrispondono.',
    'ui.terminalserver.settings.account.password_changed'       => 'Password modificata correttamente.',
    'ui.terminalserver.settings.account.password_failed'        => 'Impossibile modificare la password.',
    'ui.terminalserver.settings.account.onboarding_reset'       => 'Onboarding echomail reimpostato. Alla prossima visita verrai guidato nella selezione degli interessi.',
    'ui.terminalserver.settings.account.onboarding_reset_failed' => 'Impossibile reimpostare l’onboarding.',

    // AI/MCP tab
    'ui.terminalserver.settings.ai.generate_key'      => 'Genera chiave MCP',
    'ui.terminalserver.settings.ai.regenerate_key'    => 'Rigenera chiave MCP',
    'ui.terminalserver.settings.ai.regenerate_confirm' => 'La rigenerazione invaliderà la chiave esistente. Continuare? (S/N): ',
    'ui.terminalserver.settings.ai.revoke_key'        => 'Revoca chiave MCP',
    'ui.terminalserver.settings.ai.revoke_confirm'    => 'Revocare la chiave MCP? Tutti i client AI connessi saranno disconnessi. (S/N): ',
    'ui.terminalserver.settings.ai.mcp_key_exists'    => 'Chiave attiva: {preview}',
    'ui.terminalserver.settings.ai.mcp_no_key'        => 'Nessuna chiave ancora generata',
    'ui.terminalserver.settings.ai.key_generated'     => 'Chiave MCP generata. Copiala ora — non verrà mostrata di nuovo:',
    'ui.terminalserver.settings.ai.generate_failed'   => 'Impossibile generare la chiave.',
    'ui.terminalserver.settings.ai.key_revoked'       => 'Chiave MCP revocata.',
    'ui.terminalserver.settings.ai.revoke_failed'     => 'Impossibile revocare la chiave.',

    // --- Legacy terminal settings page (kept for TerminalSettingsHandler compatibility) ---
    'ui.terminalserver.settings.title'                 => '=== Impostazioni terminale ===',
    'ui.terminalserver.settings.charset_label'         => 'Set caratteri : {value}',
    'ui.terminalserver.settings.ansi_label'            => 'Colore ANSI   : {value}',
    'ui.terminalserver.settings.not_set'               => 'Non configurato',
    'ui.terminalserver.settings.menu_detect'           => 'D) Esegui configurazione guidata',
    'ui.terminalserver.settings.menu_charset'          => 'C) Cambia manualmente set caratteri',
    'ui.terminalserver.settings.menu_ansi'             => 'A) Attiva/disattiva colore ANSI',
    'ui.terminalserver.settings.menu_quit'             => 'Q) Torna al menu principale',
    'ui.terminalserver.settings.saved'                 => 'Impostazioni salvate.',
    'ui.terminalserver.settings.save_failed'           => 'Avviso: impossibile salvare le impostazioni.',
    'ui.terminalserver.settings.invalid_choice'        => 'Scelta non valida.',
    'ui.terminalserver.settings.charset_prompt'        => 'Seleziona: (U)TF-8, (C)P437, (A)SCII: ',

    // --- Terminal detection wizard ---
    'ui.terminalserver.detect.title'                   => '=== Configurazione terminale ===',
    'ui.terminalserver.detect.intro'                   => 'La BBS testerà ora il terminale per assicurarsi che i contenuti vengano visualizzati correttamente.',
    'ui.terminalserver.detect.charset_intro'           => 'Test set di caratteri:',
    'ui.terminalserver.detect.charset_question'        => 'Gli elementi sopra appaiono come frecce, segni di spunta e lettere accentate? (S/N): ',
    'ui.terminalserver.detect.charset_utf8'            => 'Set di caratteri UTF-8 abilitato.',
    'ui.terminalserver.detect.charset_cp437_intro'     => 'Test linee box CP437:',
    'ui.terminalserver.detect.charset_cp437_question'  => 'Gli elementi sopra appaiono come un riquadro disegnato con linee e angoli? (S/N): ',
    'ui.terminalserver.detect.charset_cp437'           => 'Set di caratteri CP437 (DOS/ANSI) abilitato.',
    'ui.terminalserver.detect.charset_ascii'           => 'Modalità ASCII abilitata.',
    'ui.terminalserver.detect.ansi_intro'              => 'Test colori:',
    'ui.terminalserver.detect.ansi_question'           => 'Le parole sopra appaiono in colori diversi? (S/N): ',
    'ui.terminalserver.detect.ansi_yes'                => 'Colore ANSI abilitato.',
    'ui.terminalserver.detect.ansi_no'                 => 'Colore ANSI disabilitato.',
    'ui.terminalserver.detect.complete'                => 'Configurazione terminale completata. Impostazioni salvate.',
    'ui.terminalserver.detect.press_enter'             => 'Premi Invio per continuare...',

    // --- Door games ---
    'ui.terminalserver.doors.no_doors'                 => 'Nessuna door è attualmente disponibile.',
    'ui.terminalserver.doors.title'                    => '=== Giochi door ===',
    'ui.terminalserver.doors.enter_choice'             => 'Inserisci il numero per giocare, oppure Q per tornare: ',
    'ui.terminalserver.doors.invalid'                  => 'Selezione non valida.',
    'ui.terminalserver.doors.launching'                => 'Avvio di {name}...',
    'ui.terminalserver.doors.launch_error'             => 'Errore: {error}',
    'ui.terminalserver.doors.connecting'               => 'Connessione al server di gioco...',
    'ui.terminalserver.doors.connect_failed'           => 'Impossibile connettersi al bridge del gioco. Il bridge DOS door è in esecuzione?',
    'ui.terminalserver.doors.connected'                => 'Connesso! Avvio del gioco...',
    'ui.terminalserver.doors.returned'                 => 'Rientrato da {name}.',

    'ui.terminalserver.message.headers_title'          => '=== Intestazioni messaggio ===',
    'ui.terminalserver.message.no_headers'             => '(Nessuna intestazione messaggio)',
];
