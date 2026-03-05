<?php

return [
    // Relative time strings used by app.js formatting helpers.
    'time.soon' => 'Pronto',
    'time.in_hours' => 'En {count} hora{suffix}',
    'time.tomorrow' => 'Manana',
    'time.in_days' => 'En {count} dias',
    'time.just_now' => 'Ahora mismo',
    'time.minutes_ago' => 'Hace {count} minutos',
    'time.hours_ago' => 'Hace {count} hora{suffix}',
    'time.yesterday' => 'Ayer',
    'time.days_ago' => 'Hace {count} dias',
    'time.suffix_plural' => 's',
    'time.suffix_singular' => '',

    // Shared/common UI defaults.
    'errors.failed_load_messages' => 'No se pudieron cargar los mensajes',
    'messages.none_found' => 'No se encontraron mensajes',
    'messages.no_subject' => '(Sin asunto)',
    'ui.common.success' => 'Exito',
    'ui.common.unknown_error' => 'Error desconocido',
    'ui.common.saving' => 'Guardando...',
    'ui.common.copy_failed_manual' => 'No se pudo copiar al portapapeles. Copie manualmente.',
    'ui.common.copy_not_supported_manual' => 'El portapapeles no es compatible. Copie manualmente.',

    // Dashboard
    'ui.dashboard.polls.none_active' => 'No hay encuestas activas en este momento.',
    'ui.dashboard.polls.load_failed' => 'No se pudo cargar la encuesta.',
    'ui.dashboard.polls.results' => 'Resultados',
    'ui.dashboard.polls.no_votes' => 'Aun no hay votos.',
    'ui.dashboard.polls.vote_to_see_results' => 'Vote para ver resultados',
    'ui.dashboard.polls.submit_vote' => 'Enviar voto',
    'ui.dashboard.echoareas.none_available' => 'No hay areas de eco disponibles',
    'ui.dashboard.shoutbox.none_yet' => 'Aun no hay shouts. Sea el primero.',
    'ui.dashboard.shoutbox.load_failed' => 'No se pudieron cargar los shouts.',
    'ui.dashboard.shoutbox.post_failed' => 'No se pudo publicar el shout.',
    'ui.dashboard.referrals.error_prefix' => 'Error de estadisticas de referidos:',
    'ui.dashboard.referrals.recent' => 'Referidos recientes',

    // Settings
    'ui.settings.load_failed_console' => 'No se pudo cargar la configuracion',
    'ui.settings.saving' => 'Guardando configuracion...',
    'ui.settings.saved_successfully' => 'Configuracion guardada correctamente.',
    'ui.settings.sessions.none_active' => 'No se encontraron sesiones activas.',
    'ui.settings.sessions.load_failed' => 'No se pudieron cargar las sesiones',
    'ui.settings.sessions.revoke_confirm' => 'Esta seguro de que desea revocar esta sesion?',
    'ui.settings.sessions.revoked_success' => 'Sesion revocada correctamente',
    'ui.settings.sessions.logout_all_confirm' => 'Esta seguro de que desea cerrar sesion en todos los dispositivos? Debera iniciar sesion de nuevo.',
    'ui.settings.polling_uplinks' => 'Consultando uplinks... (esto puede tardar un momento)',
    'ui.settings.poll_complete_prefix' => 'Consulta de uplink completada: ',
    'ui.settings.poll_failed_prefix' => 'Fallo la consulta: ',

    // Compose / Drafts / Address Book
    'ui.compose.draft.empty_content' => 'Agregue contenido antes de guardar el borrador',
    'ui.compose.draft.saved_success' => 'Borrador guardado correctamente',
    'ui.compose.address_book.load_failed_short' => 'No se pudo cargar',
    'ui.compose.address_book.entry_added' => 'Entrada agregada correctamente',
    'ui.compose.address_book.use_entry_confirm' => 'Usar esta entrada para el mensaje actual?',
    'ui.address_book.already_exists' => 'Este contacto ya esta en su libreta de direcciones',
    'ui.address_book.check_existing_failed' => 'No se pudo verificar contactos existentes',
    'ui.drafts.delete_confirm' => 'Esta seguro de que desea eliminar este borrador? Esta accion no se puede deshacer.',
    'ui.messages.none_selected' => 'No hay mensajes seleccionados',

    // Netmail
    'ui.netmail.search.failed' => 'La busqueda fallo',
    'ui.netmail.delete_message_confirm' => 'Esta seguro de que desea eliminar este mensaje? Esta accion no se puede deshacer.',
    'ui.netmail.address_book.load_entry_failed_prefix' => 'No se pudo cargar la entrada: ',
    'ui.netmail.bulk_delete.failed' => 'No se pudieron eliminar los mensajes',

    // Echomail
    'ui.echomail.search.failed' => 'La busqueda fallo',
    'ui.echomail.save_status.update_failed' => 'No se pudo actualizar el estado de guardado',
    'ui.echomail.bulk_delete.failed' => 'No se pudieron eliminar los mensajes',
    'ui.echomail.shares.check_failed' => 'No se pudieron verificar los compartidos existentes',
    'ui.echomail.shares.friendly_url_failed' => 'No se pudo generar la URL amigable',
    'ui.echomail.shares.revoke_confirm' => 'Esta seguro de que desea revocar este enlace compartido? Ya no sera accesible para otros.',
];
