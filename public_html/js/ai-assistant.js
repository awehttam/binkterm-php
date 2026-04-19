/**
 * AI Assistant for echomail and netmail readers.
 *
 * Depends on:
 *   - Bootstrap 5 (modal)
 *   - window.t() for i18n
 *   - currentMessageId (global in echomail.js / netmail.js)
 */

// Prompts shown when a message is open — "this message" refers to the loaded context.
const AI_ASSISTANT_PROMPTS_WITH_CONTEXT = [
    { label: 'Summarize',     prompt: 'Summarize the key points of this message in 2-3 sentences.' },
    { label: 'Key Points',    prompt: 'What are the main topics being discussed in this message?' },
    { label: 'Explain Terms', prompt: 'Explain any technical or jargon terms used in this message.' },
    { label: 'Thread Context', prompt: 'Summarize the full conversation thread this message belongs to.' },
];

// Prompts shown when no message is open — general BBS questions.
const AI_ASSISTANT_PROMPTS_NO_CONTEXT = [
    { label: 'Recent Activity', prompt: 'What are the most active echoareas right now?' },
    { label: 'Top Posters',     prompt: 'Who are the most active posters recently?' },
    { label: 'Search Help',     prompt: 'Help me find messages about a topic. What should I search for?' },
];

/** Message context captured when the modal is opened. */
let _aiMessageId   = null;
let _aiMessageType = 'echomail';

/**
 * Open the AI assistant modal.
 *
 * @param {number|null} messageId   ID of the message currently being viewed
 * @param {string}      messageType 'echomail' or 'netmail'
 */
function openAiAssistant(messageId, messageType) {
    _aiMessageId   = messageId || null;
    _aiMessageType = messageType || 'echomail';

    // Reset modal state
    $('#aiPromptText').val('');
    $('#aiResponseSection').addClass('d-none');
    $('#aiResponseText').text('');
    $('#aiCreditsUsed').text('');

    // Context indicator
    const $ctx = $('#aiContextInfo').empty();
    if (_aiMessageId) {
        const ctxMsg = window.t(
            'ui.ai_assistant.context_message',
            { id: _aiMessageId },
            'Message #' + _aiMessageId + ' is loaded as context — quick prompts will refer to it.'
        );
        $('<div>')
            .addClass('alert alert-info py-2 small mb-0')
            .html('<i class="fas fa-circle-info me-2"></i>' + ctxMsg)
            .appendTo($ctx);
        $('#aiPromptText').attr(
            'placeholder',
            window.t('ui.ai_assistant.prompt_placeholder_ctx', {}, 'Ask about this message...')
        );
    } else {
        const ctxMsg = window.t(
            'ui.ai_assistant.context_none',
            {},
            'No message is open. Open a message first for context-aware prompts, or ask a general question.'
        );
        $('<div>')
            .addClass('alert alert-secondary py-2 small mb-0')
            .html('<i class="fas fa-circle-exclamation me-2"></i>' + ctxMsg)
            .appendTo($ctx);
        $('#aiPromptText').attr(
            'placeholder',
            window.t('ui.ai_assistant.prompt_placeholder', {}, 'Ask the AI about this message...')
        );
    }

    _updateCharCount();

    // Build quick-prompt buttons — different set based on whether context is available
    const prompts = _aiMessageId ? AI_ASSISTANT_PROMPTS_WITH_CONTEXT : AI_ASSISTANT_PROMPTS_NO_CONTEXT;
    const $container = $('#aiQuickPrompts').empty();
    prompts.forEach(function(item) {
        $('<button>')
            .addClass('btn btn-outline-secondary btn-sm')
            .text(item.label)
            .on('click', function() {
                $('#aiPromptText').val(item.prompt);
                _updateCharCount();
            })
            .appendTo($container);
    });

    bootstrap.Modal.getOrCreateInstance(document.getElementById('aiAssistantModal')).show();
}

/**
 * Execute the AI assistant request.
 * Called by the Execute button in the modal.
 */
function executeAiAssistant() {
    const prompt = $('#aiPromptText').val().trim();
    if (!prompt) {
        return;
    }

    if (prompt.length > 500) {
        const msg = window.t('errors.ai_assistant.prompt_too_long', {}, 'Prompt exceeds the 500 character limit.');
        _showAiError(msg);
        return;
    }

    _setLoading(true);
    $('#aiResponseSection').addClass('d-none');

    $.ajax({
        url: '/api/messages/ai-assist',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            prompt:       prompt,
            message_id:   _aiMessageId,
            message_type: _aiMessageType,
        }),
        success: function(data) {
            _setLoading(false);
            if (data.success) {
                $('#aiResponseText').html(marked.parse(data.response));
                if (typeof data.credits_used === 'number' && data.credits_used > 0) {
                    const creditsLabel = window.t(
                        'ui.ai_assistant.credits_used',
                        { credits: data.credits_used },
                        data.credits_used + ' credits used'
                    );
                    $('#aiCreditsUsed').text(creditsLabel);
                } else {
                    $('#aiCreditsUsed').text('');
                }
                $('#aiResponseSection').removeClass('d-none');

                // Update credit balance display if it exists on the page
                if (typeof data.balance === 'number') {
                    $('.credit-balance-display').text(data.balance);
                }
            } else {
                _showAiError(window.getApiErrorMessage(data, window.t('errors.ai_assistant.failed', {}, 'AI request failed.')));
            }
        },
        error: function(xhr) {
            _setLoading(false);
            let payload = {};
            try { payload = JSON.parse(xhr.responseText); } catch (_) {}
            _showAiError(window.getApiErrorMessage(payload, window.t('errors.ai_assistant.failed', {}, 'AI request failed.')));
        },
    });
}

function _setLoading(loading) {
    $('#aiExecuteBtn').prop('disabled', loading);
    $('#aiExecuteSpinner').toggleClass('d-none', !loading);
    $('#aiExecuteIcon').toggleClass('d-none', loading);
}

function _showAiError(message) {
    $('#aiResponseText').text(message);
    $('#aiCreditsUsed').text('');
    $('#aiResponseSection').removeClass('d-none');
}

function _updateCharCount() {
    const remaining = 500 - ($('#aiPromptText').val() || '').length;
    const label = window.t('ui.ai_assistant.chars_remaining', { remaining: remaining }, remaining + ' characters remaining');
    $('#aiCharCount').text(label);
}

// Live character counter
$(document).on('input', '#aiPromptText', _updateCharCount);

// Allow Ctrl+Enter to submit
$(document).on('keydown', '#aiPromptText', function(e) {
    if (e.ctrlKey && e.key === 'Enter') {
        executeAiAssistant();
    }
});
