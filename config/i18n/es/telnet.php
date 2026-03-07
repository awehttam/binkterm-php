<?php

// Telnet server UI strings (Spanish — AI-generated, may contain inaccuracies)
return [

    // --- Connection / rate limiting ---
    'ui.telnet.server.rate_limited'            => 'Demasiados intentos fallidos de inicio de sesión. Por favor, inténtelo más tarde.',

    // --- Login / register menu (pre-auth) ---
    'ui.telnet.server.login_menu.prompt'       => '¿Qué desea hacer?',
    'ui.telnet.server.login_menu.login'        => '  (L) Iniciar sesión en cuenta existente',
    'ui.telnet.server.login_menu.register'     => '  (R) Registrar nueva cuenta',
    'ui.telnet.server.login_menu.quit'         => '  (Q) Salir',
    'ui.telnet.server.login_menu.choice'       => 'Su elección: ',
    'ui.telnet.server.goodbye'                 => '¡Adiós!',
    'ui.telnet.server.press_enter_disconnect'  => 'Presione Enter para desconectarse.',

    // --- Login ---
    'ui.telnet.server.login.username_prompt'   => 'Usuario: ',
    'ui.telnet.server.login.password_prompt'   => 'Contraseña: ',
    'ui.telnet.server.login.success'           => 'Inicio de sesión exitoso.',
    'ui.telnet.server.login.failed_remaining'  => 'Error de inicio de sesión. {remaining} intento(s) restante(s).',
    'ui.telnet.server.login.failed_max'        => 'Error de inicio de sesión. Máximo de intentos superado.',

    // --- Registration ---
    'ui.telnet.server.registration.title'          => '=== Registro de Nuevo Usuario ===',
    'ui.telnet.server.registration.intro'          => 'Por favor, proporcione la siguiente información para crear su cuenta.',
    'ui.telnet.server.registration.cancel_hint'    => '(Escriba "cancel" en cualquier solicitud para cancelar el registro)',
    'ui.telnet.server.registration.username'       => 'Usuario (3-20 caracteres, letras/números/guión bajo): ',
    'ui.telnet.server.registration.password'       => 'Contraseña (mínimo 8 caracteres): ',
    'ui.telnet.server.registration.confirm'        => 'Confirmar contraseña: ',
    'ui.telnet.server.registration.password_mismatch' => 'Error: Las contraseñas no coinciden.',
    'ui.telnet.server.registration.realname'       => 'Nombre real: ',
    'ui.telnet.server.registration.email'          => 'Correo electrónico (opcional): ',
    'ui.telnet.server.registration.location'       => 'Ubicación (opcional): ',
    'ui.telnet.server.registration.submitting'     => 'Enviando registro...',
    'ui.telnet.server.registration.success'        => '¡Registro exitoso!',
    'ui.telnet.server.registration.pending'        => 'Su cuenta ha sido creada y está pendiente de aprobación.',
    'ui.telnet.server.registration.pending_review' => 'Será notificado una vez que un administrador haya revisado su registro.',

    // --- Anti-bot challenge ---
    'ui.telnet.server.press_esc'               => 'Presione ESC dos veces para continuar...',

    // --- Banner (login screen) ---
    'ui.telnet.server.banner.title'            => 'Terminal BinktermPHP',
    'ui.telnet.server.banner.system'           => 'Sistema: ',
    'ui.telnet.server.banner.location'         => 'Ubicación: ',
    'ui.telnet.server.banner.origin'           => 'Origen: ',
    'ui.telnet.server.banner.web'              => 'Web: ',
    'ui.telnet.server.banner.visit_web'        => 'Para una buena experiencia visítenos en la web @ {url}',
    'ui.telnet.server.banner.tls'             => 'Conectado usando TLS',
    'ui.telnet.server.banner.no_tls'          => 'Conectado sin TLS - use el puerto {port} para una conexión cifrada',

    // --- Main menu ---
    'ui.telnet.server.menu.title'              => 'Menú Principal',
    'ui.telnet.server.menu.select_option'      => 'Seleccione una opción:',
    'ui.telnet.server.menu.netmail'            => 'N) Netmail ({count} mensajes)',
    'ui.telnet.server.menu.echomail'           => 'E) Echomail ({count} mensajes)',
    'ui.telnet.server.menu.whos_online'        => 'W) Quién está en línea',
    'ui.telnet.server.menu.shoutbox'           => 'S) Shoutbox',
    'ui.telnet.server.menu.polls'              => 'P) Encuestas',
    'ui.telnet.server.menu.doors'              => 'D) Juegos de Puertas',
    'ui.telnet.server.menu.quit'              => 'Q) Salir',

    // --- Farewell ---
    'ui.telnet.server.farewell'                => '¡Gracias por visitarnos, que tenga un excelente día!',
    'ui.telnet.server.visit_web'               => 'Vuelva a visitarnos en la web en {url}',

    // --- Who's Online ---
    'ui.telnet.server.whos_online.title'       => 'Quién está en línea (últimos {minutes} minutos)',
    'ui.telnet.server.whos_online.empty'       => 'No hay usuarios en línea.',

    // --- Idle timeout ---
    'ui.telnet.server.idle.disconnect'         => 'Tiempo de inactividad agotado - desconectando...',
    'ui.telnet.server.idle.warning_line'       => '¿Sigue ahí? (Presione Enter para continuar)',
    'ui.telnet.server.idle.warning_key'        => '¿Sigue ahí? (Presione cualquier tecla para continuar)',

    // --- Shared UI prompts ---
    'ui.telnet.server.press_any_key'           => 'Presione cualquier tecla para volver...',
    'ui.telnet.server.press_continue'          => 'Presione cualquier tecla para continuar...',

    // --- Message editor (full screen) ---
    'ui.telnet.editor.title'                   => 'EDITOR DE MENSAJES - MODO PANTALLA COMPLETA',
    'ui.telnet.editor.shortcuts'               => 'Ctrl+K=Ayuda  Ctrl+Z=Enviar  Ctrl+C=Cancelar',
    'ui.telnet.editor.cancelled'               => 'Mensaje cancelado.',
    'ui.telnet.editor.saved'                   => 'Mensaje guardado y listo para enviar.',
    'ui.telnet.editor.starting_text'           => 'Comenzando con texto citado. Ingrese su respuesta a continuación.',
    'ui.telnet.editor.instructions'            => 'Ingrese el texto del mensaje. Termine con una línea que contenga solo ".". Escriba "/abort" para cancelar.',

    // --- Message editor help ---
    'ui.telnet.editor.help.title'              => 'AYUDA DEL EDITOR DE MENSAJES',
    'ui.telnet.editor.help.separator'          => '-------------------',
    'ui.telnet.editor.help.navigate'           => 'Flechas = Mover cursor',
    'ui.telnet.editor.help.edit'               => 'Retroceso/Supr = Editar texto',
    'ui.telnet.editor.help.help'               => 'Ctrl+K = Ayuda',
    'ui.telnet.editor.help.start_of_line'      => 'Ctrl+A = Inicio de línea',
    'ui.telnet.editor.help.end_of_line'        => 'Ctrl+E = Fin de línea',
    'ui.telnet.editor.help.delete_line'        => 'Ctrl+Y = Borrar línea completa',
    'ui.telnet.editor.help.save'               => 'Ctrl+Z = Guardar mensaje y enviar',
    'ui.telnet.editor.help.cancel'             => 'Ctrl+C = Cancelar y descartar mensaje',

    // --- Compose (shared between netmail and echomail) ---
    'ui.telnet.compose.to_name'                => 'Para: ',
    'ui.telnet.compose.to_address'             => 'Dirección de destino: ',
    'ui.telnet.compose.subject'                => 'Asunto: ',
    'ui.telnet.compose.no_recipient'           => 'Se requiere nombre del destinatario. Mensaje cancelado.',
    'ui.telnet.compose.enter_message'          => 'Ingrese su mensaje a continuación:',
    'ui.telnet.compose.select_tagline'         => 'Seleccione una etiqueta:',
    'ui.telnet.compose.no_tagline'             => ' 0) Ninguna',
    'ui.telnet.compose.tagline_default'        => 'Etiqueta # [{default}] (Enter para predeterminada): ',
    'ui.telnet.compose.tagline_none'           => 'Etiqueta # (Enter para ninguna): ',
    'ui.telnet.compose.message_cancelled'      => 'Mensaje cancelado (vacío).',

    // --- Echomail ---
    'ui.telnet.echomail.no_areas'              => 'No hay áreas de echomail disponibles.',
    'ui.telnet.echomail.areas_header'          => 'Áreas de echomail (página {page}/{total}):',
    'ui.telnet.echomail.areas_nav'             => 'Ingrese #, n/p (siguiente/anterior), q (salir)',
    'ui.telnet.echomail.no_messages'           => 'No hay mensajes de echomail.',
    'ui.telnet.echomail.messages_header'       => 'Echomail: {area} (página {page}/{total})',
    'ui.telnet.echomail.compose_title'         => '=== Redactar Echomail ===',
    'ui.telnet.echomail.area_label'            => 'Área: {area}',
    'ui.telnet.echomail.posting'               => 'Publicando echomail...',
    'ui.telnet.echomail.post_success'          => '✓ ¡Echomail publicado exitosamente!',
    'ui.telnet.echomail.post_failed'           => '✗ Error al publicar echomail: {error}',

    // --- Netmail ---
    'ui.telnet.netmail.no_messages'            => 'No hay mensajes de netmail.',
    'ui.telnet.netmail.header'                 => 'Netmail (página {page}/{total}):',
    'ui.telnet.netmail.compose_title'          => '=== Redactar Netmail ===',
    'ui.telnet.netmail.sending'                => 'Enviando netmail...',
    'ui.telnet.netmail.send_success'           => '✓ ¡Netmail enviado exitosamente!',
    'ui.telnet.netmail.send_failed'            => '✗ Error al enviar netmail: {error}',

    // --- Polls ---
    'ui.telnet.polls.disabled'                 => 'La cabina de votación está deshabilitada.',
    'ui.telnet.polls.title'                    => 'Encuestas',
    'ui.telnet.polls.no_polls'                 => 'No hay encuestas activas.',
    'ui.telnet.polls.detail_title'             => 'Detalle de encuesta',
    'ui.telnet.polls.total_votes'              => 'Total de votos: {count}',
    'ui.telnet.polls.enter_poll'               => 'Ingrese # de encuesta o Q para volver: ',
    'ui.telnet.polls.vote_prompt'              => 'Vote con opción # o Q para volver: ',
    'ui.telnet.polls.voted'                    => 'Voto registrado.',

    // --- Shoutbox ---
    'ui.telnet.shoutbox.title'                 => 'Shoutbox',
    'ui.telnet.shoutbox.recent_title'          => 'Shoutbox reciente',
    'ui.telnet.shoutbox.no_messages'           => 'No hay mensajes en el shoutbox.',
    'ui.telnet.shoutbox.menu'                  => '[P]ublicar  [R]efrescar  [Q]uit: ',
    'ui.telnet.shoutbox.new_shout'             => 'Nuevo mensaje (en blanco para cancelar): ',
    'ui.telnet.shoutbox.posted'                => 'Mensaje publicado.',
    'ui.telnet.shoutbox.post_failed'           => 'Error al publicar el mensaje.',

    // --- Door games ---
    'ui.telnet.doors.no_doors'                 => 'No hay puertas disponibles actualmente.',
    'ui.telnet.doors.title'                    => '=== Juegos de Puertas ===',
    'ui.telnet.doors.enter_choice'             => 'Ingrese número para jugar, o Q para volver: ',
    'ui.telnet.doors.invalid'                  => 'Selección inválida.',
    'ui.telnet.doors.launching'                => 'Iniciando {name}...',
    'ui.telnet.doors.launch_error'             => 'Error: {error}',
    'ui.telnet.doors.connecting'               => 'Conectando al servidor de juegos...',
    'ui.telnet.doors.connect_failed'           => 'No se pudo conectar al puente de juegos. ¿Está ejecutándose el puente de puertas DOS?',
    'ui.telnet.doors.connected'                => '¡Conectado! Iniciando juego...',
    'ui.telnet.doors.returned'                 => 'Regresó de {name}.',
];
