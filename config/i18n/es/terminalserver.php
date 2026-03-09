<?php

// Telnet server UI strings (Spanish — AI-generated, may contain inaccuracies)
return [

    // --- Connection / rate limiting ---
    'ui.terminalserver.server.rate_limited'            => 'Demasiados intentos fallidos de inicio de sesión. Por favor, inténtelo más tarde.',

    // --- Login / register menu (pre-auth) ---
    'ui.terminalserver.server.login_menu.prompt'       => '¿Qué desea hacer?',
    'ui.terminalserver.server.login_menu.login'        => '  (L) Iniciar sesión en cuenta existente',
    'ui.terminalserver.server.login_menu.register'     => '  (R) Registrar nueva cuenta',
    'ui.terminalserver.server.login_menu.quit'         => '  (Q) Salir',
    'ui.terminalserver.server.login_menu.choice'       => 'Su elección: ',
    'ui.terminalserver.server.goodbye'                 => '¡Adiós!',
    'ui.terminalserver.server.press_enter_disconnect'  => 'Presione Enter para desconectarse.',

    // --- Login ---
    'ui.terminalserver.server.login.username_prompt'   => 'Usuario: ',
    'ui.terminalserver.server.login.password_prompt'   => 'Contraseña: ',
    'ui.terminalserver.server.login.success'           => 'Inicio de sesión exitoso.',
    'ui.terminalserver.server.login.failed_remaining'  => 'Error de inicio de sesión. {remaining} intento(s) restante(s).',
    'ui.terminalserver.server.login.failed_max'        => 'Error de inicio de sesión. Máximo de intentos superado.',

    // --- Registration ---
    'ui.terminalserver.server.registration.title'          => '=== Registro de Nuevo Usuario ===',
    'ui.terminalserver.server.registration.intro'          => 'Por favor, proporcione la siguiente información para crear su cuenta.',
    'ui.terminalserver.server.registration.cancel_hint'    => '(Escriba "cancel" en cualquier solicitud para cancelar el registro)',
    'ui.terminalserver.server.registration.username'       => 'Usuario (3-20 caracteres, letras/números/guión bajo): ',
    'ui.terminalserver.server.registration.password'       => 'Contraseña (mínimo 8 caracteres): ',
    'ui.terminalserver.server.registration.confirm'        => 'Confirmar contraseña: ',
    'ui.terminalserver.server.registration.password_mismatch' => 'Error: Las contraseñas no coinciden.',
    'ui.terminalserver.server.registration.realname'       => 'Nombre real: ',
    'ui.terminalserver.server.registration.email'          => 'Correo electrónico (opcional): ',
    'ui.terminalserver.server.registration.location'       => 'Ubicación (opcional): ',
    'ui.terminalserver.server.registration.submitting'     => 'Enviando registro...',
    'ui.terminalserver.server.registration.success'        => '¡Registro exitoso!',
    'ui.terminalserver.server.registration.pending'        => 'Su cuenta ha sido creada y está pendiente de aprobación.',
    'ui.terminalserver.server.registration.pending_review' => 'Será notificado una vez que un administrador haya revisado su registro.',

    // --- Anti-bot challenge ---
    'ui.terminalserver.server.press_esc'               => 'Presione ESC dos veces para continuar...',

    // --- Banner (login screen) ---
    'ui.terminalserver.server.banner.title'            => 'Terminal BinktermPHP',
    'ui.terminalserver.server.banner.system'           => 'Sistema: ',
    'ui.terminalserver.server.banner.location'         => 'Ubicación: ',
    'ui.terminalserver.server.banner.origin'           => 'Origen: ',
    'ui.terminalserver.server.banner.web'              => 'Web: ',
    'ui.terminalserver.server.banner.visit_web'        => 'Para una buena experiencia visítenos en la web @ {url}',
    'ui.terminalserver.server.banner.tls'             => 'Conectado usando TLS',
    'ui.terminalserver.server.banner.no_tls'          => 'Conectado sin TLS - use el puerto {port} para una conexión cifrada',
    'ui.terminalserver.server.ssh_banner.welcome'     => 'Bienvenido a {system}.',
    'ui.terminalserver.server.ssh_banner.line2'       => 'Inicie sesión con sus credenciales, o introduzca cualquier usuario/contraseña',
    'ui.terminalserver.server.ssh_banner.line3'       => 'para continuar a la pantalla principal de inicio de sesión del BBS.',

    // --- Main menu ---
    'ui.terminalserver.server.menu.title'              => 'Menú Principal',
    'ui.terminalserver.server.menu.select_option'      => 'Seleccione una opción:',
    'ui.terminalserver.server.menu.netmail'            => 'N) Netmail ({count} mensajes)',
    'ui.terminalserver.server.menu.echomail'           => 'E) Echomail ({count} mensajes)',
    'ui.terminalserver.server.menu.whos_online'        => 'W) Quién está en línea',
    'ui.terminalserver.server.menu.shoutbox'           => 'S) Shoutbox',
    'ui.terminalserver.server.menu.polls'              => 'P) Encuestas',
    'ui.terminalserver.server.menu.doors'              => 'D) Juegos de Puertas',
    'ui.terminalserver.server.menu.files'              => 'F) Archivos',
    'ui.terminalserver.server.menu.quit'              => 'Q) Salir',

    // --- Farewell ---
    'ui.terminalserver.server.farewell'                => '¡Gracias por visitarnos, que tenga un excelente día!',
    'ui.terminalserver.server.visit_web'               => 'Vuelva a visitarnos en la web en {url}',

    // --- Who's Online ---
    'ui.terminalserver.server.whos_online.title'       => 'Quién está en línea (últimos {minutes} minutos)',
    'ui.terminalserver.server.whos_online.empty'       => 'No hay usuarios en línea.',

    // --- Idle timeout ---
    'ui.terminalserver.server.idle.disconnect'         => 'Tiempo de inactividad agotado - desconectando...',
    'ui.terminalserver.server.idle.warning_line'       => '¿Sigue ahí? (Presione Enter para continuar)',
    'ui.terminalserver.server.idle.warning_key'        => '¿Sigue ahí? (Presione cualquier tecla para continuar)',

    // --- Shared UI prompts ---
    'ui.terminalserver.server.press_any_key'           => 'Presione cualquier tecla para volver...',
    'ui.terminalserver.server.press_continue'          => 'Presione cualquier tecla para continuar...',

    // --- Message editor (full screen) ---
    'ui.terminalserver.editor.title'                   => 'EDITOR DE MENSAJES - MODO PANTALLA COMPLETA',
    'ui.terminalserver.editor.shortcuts'               => 'Ctrl+K=Ayuda  Ctrl+Z=Enviar  Ctrl+C=Cancelar',
    'ui.terminalserver.editor.cancelled'               => 'Mensaje cancelado.',
    'ui.terminalserver.editor.saved'                   => 'Mensaje guardado y listo para enviar.',
    'ui.terminalserver.editor.starting_text'           => 'Comenzando con texto citado. Ingrese su respuesta a continuación.',
    'ui.terminalserver.editor.instructions'            => 'Ingrese el texto del mensaje. Termine con una línea que contenga solo ".". Escriba "/abort" para cancelar.',

    // --- Message editor help ---
    'ui.terminalserver.editor.help.title'              => 'AYUDA DEL EDITOR DE MENSAJES',
    'ui.terminalserver.editor.help.separator'          => '-------------------',
    'ui.terminalserver.editor.help.navigate'           => 'Flechas = Mover cursor',
    'ui.terminalserver.editor.help.edit'               => 'Retroceso/Supr = Editar texto',
    'ui.terminalserver.editor.help.help'               => 'Ctrl+K = Ayuda',
    'ui.terminalserver.editor.help.start_of_line'      => 'Ctrl+A = Inicio de línea',
    'ui.terminalserver.editor.help.end_of_line'        => 'Ctrl+E = Fin de línea',
    'ui.terminalserver.editor.help.delete_line'        => 'Ctrl+Y = Borrar línea completa',
    'ui.terminalserver.editor.help.save'               => 'Ctrl+Z = Guardar mensaje y enviar',
    'ui.terminalserver.editor.help.cancel'             => 'Ctrl+C = Cancelar y descartar mensaje',

    // --- Compose (shared between netmail and echomail) ---
    'ui.terminalserver.compose.to_name'                => 'Para: ',
    'ui.terminalserver.compose.to_address'             => 'Dirección de destino: ',
    'ui.terminalserver.compose.subject'                => 'Asunto: ',
    'ui.terminalserver.compose.no_recipient'           => 'Se requiere nombre del destinatario. Mensaje cancelado.',
    'ui.terminalserver.compose.enter_message'          => 'Ingrese su mensaje a continuación:',
    'ui.terminalserver.compose.select_tagline'         => 'Seleccione una etiqueta:',
    'ui.terminalserver.compose.no_tagline'             => ' 0) Ninguna',
    'ui.terminalserver.compose.tagline_default'        => 'Etiqueta # [{default}] (Enter para predeterminada): ',
    'ui.terminalserver.compose.tagline_none'           => 'Etiqueta # (Enter para ninguna): ',
    'ui.terminalserver.compose.message_cancelled'      => 'Mensaje cancelado (vacío).',

    // --- Echomail ---
    'ui.terminalserver.echomail.no_areas'              => 'No hay áreas de echomail disponibles.',
    'ui.terminalserver.echomail.areas_header'          => 'Áreas de echomail (página {page}/{total}):',
    'ui.terminalserver.echomail.areas_nav'             => 'Ingrese #, n/p (siguiente/anterior), q (salir)',
    'ui.terminalserver.echomail.no_messages'           => 'No hay mensajes de echomail.',
    'ui.terminalserver.echomail.messages_header'       => 'Echomail: {area} (página {page}/{total})',
    'ui.terminalserver.echomail.compose_title'         => '=== Redactar Echomail ===',
    'ui.terminalserver.echomail.area_label'            => 'Área: {area}',
    'ui.terminalserver.echomail.posting'               => 'Publicando echomail...',
    'ui.terminalserver.echomail.post_success'          => '✓ ¡Echomail publicado exitosamente!',
    'ui.terminalserver.echomail.post_failed'           => '✗ Error al publicar echomail: {error}',

    // --- Netmail ---
    'ui.terminalserver.netmail.no_messages'            => 'No hay mensajes de netmail.',
    'ui.terminalserver.netmail.header'                 => 'Netmail (página {page}/{total}):',
    'ui.terminalserver.netmail.compose_title'          => '=== Redactar Netmail ===',
    'ui.terminalserver.netmail.sending'                => 'Enviando netmail...',
    'ui.terminalserver.netmail.send_success'           => '✓ ¡Netmail enviado exitosamente!',
    'ui.terminalserver.netmail.send_failed'            => '✗ Error al enviar netmail: {error}',

    // --- Polls ---
    'ui.terminalserver.polls.disabled'                 => 'La cabina de votación está deshabilitada.',
    'ui.terminalserver.polls.title'                    => 'Encuestas',
    'ui.terminalserver.polls.no_polls'                 => 'No hay encuestas activas.',
    'ui.terminalserver.polls.detail_title'             => 'Detalle de encuesta',
    'ui.terminalserver.polls.total_votes'              => 'Total de votos: {count}',
    'ui.terminalserver.polls.enter_poll'               => 'Ingrese # de encuesta o Q para volver: ',
    'ui.terminalserver.polls.vote_prompt'              => 'Vote con opción # o Q para volver: ',
    'ui.terminalserver.polls.voted'                    => 'Voto registrado.',

    // --- Shoutbox ---
    'ui.terminalserver.shoutbox.title'                 => 'Shoutbox',
    'ui.terminalserver.shoutbox.recent_title'          => 'Shoutbox reciente',
    'ui.terminalserver.shoutbox.no_messages'           => 'No hay mensajes en el shoutbox.',
    'ui.terminalserver.shoutbox.menu'                  => '[P]ublicar  [R]efrescar  [Q]uit: ',
    'ui.terminalserver.shoutbox.new_shout'             => 'Nuevo mensaje (en blanco para cancelar): ',
    'ui.terminalserver.shoutbox.posted'                => 'Mensaje publicado.',
    'ui.terminalserver.shoutbox.post_failed'           => 'Error al publicar el mensaje.',

    // --- File areas ---
    'ui.terminalserver.files.no_areas'                 => 'No hay áreas de archivos disponibles.',
    'ui.terminalserver.files.areas_header'             => 'Áreas de archivos (página {page}/{total}):',
    'ui.terminalserver.files.areas_nav'                => 'Ingrese #, n/p (siguiente/anterior), q (salir)',
    'ui.terminalserver.files.area_header'              => 'Archivos: {area} (página {page}/{total})',
    'ui.terminalserver.files.no_files'                 => 'No hay archivos en esta área.',
    'ui.terminalserver.files.files_nav'                => 'D)escargar  n/p (siguiente/anterior)  Q)salir',
    'ui.terminalserver.files.files_nav_upload'         => 'D)escargar  S)ubir  n/p (siguiente/anterior)  Q)salir',
    'ui.terminalserver.files.files_nav_upload_only'    => 'S)ubir  n/p (siguiente/anterior)  Q)salir',
    'ui.terminalserver.files.files_nav_none'           => 'n/p (siguiente/anterior)  Q)salir',
    'ui.terminalserver.files.transfer_unavailable'     => 'ZMODEM deshabilitado: instale lrzsz (sz/rz) en el servidor para habilitar transferencias.',
    'ui.terminalserver.files.invalid_selection'        => 'Selección inválida.',
    'ui.terminalserver.files.download_prompt'          => 'Número de archivo a descargar (Enter para cancelar): ',
    'ui.terminalserver.files.download_error'           => 'Archivo no encontrado en el servidor.',
    'ui.terminalserver.files.download_starting'        => 'Iniciando descarga ZMODEM: {name}',
    'ui.terminalserver.files.download_hint'            => 'Inicie la recepción ZMODEM en su terminal ahora...',
    'ui.terminalserver.files.download_done'            => 'Transferencia completada.',
    'ui.terminalserver.files.download_failed'          => 'La transferencia falló o fue cancelada.',
    'ui.terminalserver.files.upload_title'             => '=== Subir Archivo ===',
    'ui.terminalserver.files.upload_area'              => 'Área: {area}',
    'ui.terminalserver.files.upload_desc_prompt'       => 'Descripción breve (en blanco para cancelar): ',
    'ui.terminalserver.files.upload_cancelled'         => 'Subida cancelada.',
    'ui.terminalserver.files.upload_starting'          => 'Inicie el envío ZMODEM en su terminal ahora...',
    'ui.terminalserver.files.upload_failed'            => 'La transferencia falló o fue cancelada.',
    'ui.terminalserver.files.upload_done'              => 'Archivo subido exitosamente (ID: {id}).',
    'ui.terminalserver.files.upload_error'             => 'Error al subir: {error}',
    'ui.terminalserver.files.upload_duplicate'         => 'Este archivo ya existe en esta área.',
    'ui.terminalserver.files.upload_readonly'          => 'Esta área es de solo lectura. No se permiten subidas.',
    'ui.terminalserver.files.upload_admin_only'        => 'Solo los administradores pueden subir a esta área.',

    // --- Door games ---
    'ui.terminalserver.doors.no_doors'                 => 'No hay puertas disponibles actualmente.',
    'ui.terminalserver.doors.title'                    => '=== Juegos de Puertas ===',
    'ui.terminalserver.doors.enter_choice'             => 'Ingrese número para jugar, o Q para volver: ',
    'ui.terminalserver.doors.invalid'                  => 'Selección inválida.',
    'ui.terminalserver.doors.launching'                => 'Iniciando {name}...',
    'ui.terminalserver.doors.launch_error'             => 'Error: {error}',
    'ui.terminalserver.doors.connecting'               => 'Conectando al servidor de juegos...',
    'ui.terminalserver.doors.connect_failed'           => 'No se pudo conectar al puente de juegos. ¿Está ejecutándose el puente de puertas DOS?',
    'ui.terminalserver.doors.connected'                => '¡Conectado! Iniciando juego...',
    'ui.terminalserver.doors.returned'                 => 'Regresó de {name}.',
];

