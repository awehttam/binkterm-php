/**
 * CronBuilder — inline cron expression editor widget.
 *
 * Attaches to a text input by ID. A toggle button is appended to the input.
 * Clicking the button opens a small panel that parses the current cron expression
 * into its five individual fields (minute, hour, day-of-month, month, day-of-week).
 * Editing any field immediately updates the main input and shows a human-readable
 * description of the resulting schedule.
 *
 * Usage:
 *   CronBuilder.init('inputId');    // attach widget to input
 *   CronBuilder.refresh('inputId'); // re-parse after setting value programmatically
 */
(function (global) {
    'use strict';

    // ── i18n helper ────────────────────────────────────────────────────────────

    function cbT(key, params, fallback) {
        var result = global.t ? global.t(key, params, fallback) : null;
        if (!result) {
            result = fallback.replace(/\{(\w+)\}/g, function (_, k) {
                return (params && params[k] !== undefined) ? params[k] : '{' + k + '}';
            });
        }
        return result;
    }

    // ── Cron parsing / building ─────────────────────────────────────────────────

    /**
     * Split a 5-field cron expression into an object.
     * Falls back to all-wildcards for malformed input.
     * @param {string} expr
     * @returns {{min:string, hour:string, dom:string, month:string, dow:string}}
     */
    function parseCron(expr) {
        var parts = (expr || '').trim().split(/\s+/);
        if (parts.length === 5) {
            return { min: parts[0], hour: parts[1], dom: parts[2], month: parts[3], dow: parts[4] };
        }
        return { min: '*', hour: '*', dom: '*', month: '*', dow: '*' };
    }

    /**
     * Reassemble five fields into a cron expression string.
     * @param {string} min
     * @param {string} hour
     * @param {string} dom
     * @param {string} month
     * @param {string} dow
     * @returns {string}
     */
    function buildCron(min, hour, dom, month, dow) {
        return [min, hour, dom, month, dow].join(' ');
    }

    // ── Human-readable description ──────────────────────────────────────────────

    var DAY_NAMES   = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    var MONTH_NAMES = ['January', 'February', 'March', 'April', 'May', 'June',
                       'July', 'August', 'September', 'October', 'November', 'December'];

    function pad(n) {
        return String(parseInt(n, 10)).padStart(2, '0');
    }

    /**
     * Convert a day-of-week cron value to a human-readable string.
     * Handles single digits, comma lists, ranges, and weekday/weekend shortcuts.
     */
    function dowStr(v) {
        if (v === '1-5') return cbT('ui.cron_builder.desc.weekdays', {}, 'weekdays');
        if (v === '0,6' || v === '6,0') return cbT('ui.cron_builder.desc.weekends', {}, 'weekends');
        if (v.indexOf(',') !== -1) {
            return v.split(',').map(function (d) {
                return DAY_NAMES[parseInt(d.trim(), 10)] || d.trim();
            }).join(', ');
        }
        var rm = v.match(/^(\d+)-(\d+)$/);
        if (rm) {
            return (DAY_NAMES[parseInt(rm[1], 10)] || rm[1]) + '\u2013' + (DAY_NAMES[parseInt(rm[2], 10)] || rm[2]);
        }
        if (/^\d+$/.test(v)) return DAY_NAMES[parseInt(v, 10)] || v;
        return v;
    }

    function describeCron(expr) {
        if (!expr || !expr.trim()) return '';
        var parts = expr.trim().split(/\s+/);
        if (parts.length !== 5) return cbT('ui.cron_builder.desc.custom', {}, 'Custom schedule');
        var min = parts[0], hour = parts[1], dom = parts[2], month = parts[3], dow = parts[4];

        var allWild = (dom === '*' && month === '*' && dow === '*');
        var hasTime = /^\d+$/.test(hour) && /^\d+$/.test(min);
        var time    = hasTime ? pad(hour) + ':' + pad(min) : null;

        // ── Clean well-known patterns (i18n keys) ────────────────────────────

        if (min === '*' && hour === '*' && dom === '*' && month === '*' && dow === '*')
            return cbT('ui.cron_builder.desc.every_minute', {}, 'Runs every minute');

        if (/^\*\/(\d+)$/.test(min) && hour === '*' && allWild) {
            var n = parseInt(min.slice(2), 10);
            return cbT('ui.cron_builder.desc.every_n_minutes', { n: n }, 'Runs every {n} minutes');
        }
        if (/^\d+$/.test(min) && hour === '*' && allWild)
            return cbT('ui.cron_builder.desc.every_hour_at_minute', { min: min }, 'Runs every hour at minute {min}');
        if (min === '*' && /^\*\/(\d+)$/.test(hour) && allWild) {
            var n = parseInt(hour.slice(2), 10);
            return cbT('ui.cron_builder.desc.every_n_hours', { n: n }, 'Runs every {n} hours');
        }
        if (/^\*\/(\d+)$/.test(min) && /^\*\/(\d+)$/.test(hour) && allWild) {
            var n = parseInt(min.slice(2), 10), m = parseInt(hour.slice(2), 10);
            return cbT('ui.cron_builder.desc.every_n_minutes_every_m_hours', { n: n, m: m }, 'Runs every {n} minutes, every {m} hours');
        }
        if (/^\d+$/.test(min) && /^\*\/(\d+)$/.test(hour) && allWild) {
            var n = parseInt(hour.slice(2), 10);
            return cbT('ui.cron_builder.desc.every_n_hours_at_minute', { n: n, min: min }, 'Runs every {n} hours at minute {min}');
        }
        if (time && dom === '*' && month === '*' && dow === '*')
            return cbT('ui.cron_builder.desc.daily_at', { time: time }, 'Runs daily at {time}');
        if (time && /^\*\/(\d+)$/.test(dom) && month === '*' && dow === '*') {
            var n = parseInt(dom.slice(2), 10);
            return cbT('ui.cron_builder.desc.every_n_days_at', { n: n, time: time }, 'Runs every {n} days at {time}');
        }
        if (time && dom === '*' && month === '*' && dow !== '*')
            return cbT('ui.cron_builder.desc.on_days_at', { days: dowStr(dow), time: time }, 'Runs on {days} at {time}');
        if (time && /^\d+$/.test(dom) && month === '*' && dow === '*')
            return cbT('ui.cron_builder.desc.monthly_on_day', { dom: dom, time: time }, 'Runs monthly on day {dom} at {time}');
        if (time && /^\d+$/.test(dom) && /^\d+$/.test(month) && dow === '*') {
            var monthName = MONTH_NAMES[parseInt(month, 10) - 1] || month;
            return cbT('ui.cron_builder.desc.yearly_on', { month: monthName, dom: dom, time: time }, 'Runs on {month} {dom} at {time}');
        }

        // ── Compositional fallback for mixed/complex expressions ─────────────
        // Build a time fragment then append per-field constraint fragments.

        var timeFrag;
        if (/^\*\/(\d+)$/.test(min) && /^\*\/(\d+)$/.test(hour)) {
            var nm = parseInt(min.slice(2), 10), nh = parseInt(hour.slice(2), 10);
            timeFrag = 'every ' + nm + ' min' + (nm === 1 ? '' : 's') + ', every ' + nh + ' hr' + (nh === 1 ? '' : 's');
        } else if (/^\*\/(\d+)$/.test(min) && hour === '*') {
            var nm = parseInt(min.slice(2), 10);
            timeFrag = 'every ' + nm + ' min' + (nm === 1 ? '' : 's');
        } else if (min === '*' && /^\*\/(\d+)$/.test(hour)) {
            var nh = parseInt(hour.slice(2), 10);
            timeFrag = 'every ' + nh + ' hr' + (nh === 1 ? '' : 's');
        } else if (/^\d+$/.test(min) && /^\*\/(\d+)$/.test(hour)) {
            var nh = parseInt(hour.slice(2), 10);
            timeFrag = 'every ' + nh + ' hr' + (nh === 1 ? '' : 's') + ' at :' + pad(min);
        } else if (/^\d+$/.test(min) && hour === '*') {
            timeFrag = 'every hour at :' + pad(min);
        } else if (time) {
            timeFrag = 'at ' + time;
        } else {
            return cbT('ui.cron_builder.desc.custom', {}, 'Custom schedule');
        }

        var constraints = [];

        if (dom !== '*') {
            if (/^\*\/(\d+)$/.test(dom)) {
                var nd = parseInt(dom.slice(2), 10);
                constraints.push('every ' + nd + ' day' + (nd === 1 ? '' : 's'));
            } else if (dom.indexOf(',') !== -1) {
                constraints.push('days ' + dom.split(',').map(function (v) { return v.trim(); }).join(', '));
            } else if (/^(\d+)-(\d+)$/.test(dom)) {
                var dr = dom.match(/^(\d+)-(\d+)$/);
                constraints.push('days ' + dr[1] + '\u2013' + dr[2]);
            } else {
                constraints.push('day ' + dom);
            }
        }

        if (month !== '*') {
            if (month.indexOf(',') !== -1) {
                var mnames = month.split(',').map(function (v) { return MONTH_NAMES[parseInt(v.trim(), 10) - 1] || v.trim(); });
                constraints.push(mnames.join(', '));
            } else if (/^\*\/(\d+)$/.test(month)) {
                var nm2 = parseInt(month.slice(2), 10);
                constraints.push('every ' + nm2 + ' month' + (nm2 === 1 ? '' : 's'));
            } else if (/^\d+$/.test(month)) {
                constraints.push(MONTH_NAMES[parseInt(month, 10) - 1] || month);
            }
        }

        if (dow !== '*') constraints.push(dowStr(dow));

        return 'Runs ' + timeFrag + (constraints.length ? ', ' + constraints.join(', ') : '');
    }

    // ── Widget construction ─────────────────────────────────────────────────────

    var FIELDS = [
        {
            key:         'min',
            labelKey:    'ui.cron_builder.field.minute',
            label:       'Minute',
            hintKey:     'ui.cron_builder.hint.minute',
            hint:        '0–59',
        },
        {
            key:         'hour',
            labelKey:    'ui.cron_builder.field.hour',
            label:       'Hour',
            hintKey:     'ui.cron_builder.hint.hour',
            hint:        '0–23',
        },
        {
            key:         'dom',
            labelKey:    'ui.cron_builder.field.day',
            label:       'Day',
            hintKey:     'ui.cron_builder.hint.day',
            hint:        '1–31',
        },
        {
            key:         'month',
            labelKey:    'ui.cron_builder.field.month',
            label:       'Month',
            hintKey:     'ui.cron_builder.hint.month',
            hint:        '1–12',
        },
        {
            key:         'dow',
            labelKey:    'ui.cron_builder.field.weekday',
            label:       'Weekday',
            hintKey:     'ui.cron_builder.hint.weekday',
            hint:        '0–6 (0=Sun)',
        },
    ];

    function escHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    /**
     * Build the collapsible panel DOM.
     * @returns {HTMLElement}
     */
    function buildPanel() {
        var panel = document.createElement('div');
        panel.className = 'cb-panel border rounded p-2 mt-1 bg-light d-none';

        // Field row
        var row = document.createElement('div');
        row.className = 'row g-2 align-items-end';

        FIELDS.forEach(function (f) {
            var col = document.createElement('div');
            col.className = 'col';

            var label = document.createElement('label');
            label.className = 'form-label small mb-1 text-muted';
            label.textContent = cbT(f.labelKey, {}, f.label);

            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'form-control form-control-sm font-monospace';
            input.dataset.field = f.key;
            input.placeholder = '*';

            var hint = document.createElement('div');
            hint.className = 'form-text mt-0';
            hint.textContent = cbT(f.hintKey, {}, f.hint);

            col.appendChild(label);
            col.appendChild(input);
            col.appendChild(hint);
            row.appendChild(col);
        });

        panel.appendChild(row);

        // Description line
        var descEl = document.createElement('div');
        descEl.className = 'cb-desc text-muted small mt-2';
        panel.appendChild(descEl);

        return panel;
    }

    /**
     * Populate the panel's field inputs from a cron expression and refresh the description.
     * @param {HTMLElement} panel
     * @param {string} expr
     */
    function populatePanel(panel, expr) {
        var fields = parseCron(expr);
        panel.querySelectorAll('[data-field]').forEach(function (el) {
            el.value = fields[el.dataset.field] || '*';
        });
        refreshDesc(panel, expr);
    }

    /**
     * Update the description line from a cron expression.
     */
    function refreshDesc(panel, expr) {
        var descEl = panel.querySelector('.cb-desc');
        if (!descEl) return;
        var desc = describeCron(expr);
        if (desc) {
            descEl.innerHTML = '<i class="fas fa-clock me-1"></i>' + escHtml(desc);
        } else {
            descEl.textContent = '';
        }
    }

    // ── Public API ──────────────────────────────────────────────────────────────

    /**
     * Attach the cron builder widget to a text input.
     * Safe to call multiple times — no-ops if already attached.
     * @param {string} inputId
     */
    function init(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        if (input.dataset.cbAttached) return;
        input.dataset.cbAttached = '1';

        // Wrap input in an input-group so we can append the toggle button
        var wrapper = document.createElement('div');
        wrapper.className = 'input-group';
        input.parentNode.insertBefore(wrapper, input);
        wrapper.appendChild(input);

        // Toggle button
        var toggleBtn = document.createElement('button');
        toggleBtn.type = 'button';
        toggleBtn.className = 'btn btn-outline-secondary';
        toggleBtn.title = cbT('ui.cron_builder.open_builder', {}, 'Build schedule');
        toggleBtn.innerHTML = '<i class="fas fa-sliders-h"></i>';
        wrapper.appendChild(toggleBtn);

        // Panel
        var panel = buildPanel();
        wrapper.parentNode.insertBefore(panel, wrapper.nextSibling);

        // Flag to prevent the main-input listener from re-syncing the panel
        // while the panel itself is driving the update (avoids a feedback loop
        // that would immediately snap cleared fields back to '*').
        var _panelDriving = false;

        // When a field inside the panel changes, rebuild the cron expression.
        // Empty fields fall back to '*' in the expression written to the main
        // input, but the panel field itself is left alone so the user can
        // finish typing a new value without it being overwritten.
        panel.querySelectorAll('[data-field]').forEach(function (fieldInput) {
            fieldInput.addEventListener('input', function () {
                var min    = panel.querySelector('[data-field="min"]').value.trim()   || '*';
                var hour   = panel.querySelector('[data-field="hour"]').value.trim()  || '*';
                var dom    = panel.querySelector('[data-field="dom"]').value.trim()   || '*';
                var month  = panel.querySelector('[data-field="month"]').value.trim() || '*';
                var dow    = panel.querySelector('[data-field="dow"]').value.trim()   || '*';
                var expr   = buildCron(min, hour, dom, month, dow);
                _panelDriving = true;
                input.value = expr;
                input.dispatchEvent(new Event('input', { bubbles: true }));
                _panelDriving = false;
                refreshDesc(panel, expr);
            });
        });

        // When the main input is edited directly, keep panel in sync if open.
        // Skip when the change was triggered by the panel to avoid the loop.
        input.addEventListener('input', function () {
            if (_panelDriving) return;
            if (!panel.classList.contains('d-none')) {
                populatePanel(panel, input.value);
            }
        });

        // Toggle button opens/closes the panel
        toggleBtn.addEventListener('click', function () {
            if (panel.classList.contains('d-none')) {
                populatePanel(panel, input.value);
                panel.classList.remove('d-none');
                panel.querySelector('[data-field="min"]').focus();
            } else {
                panel.classList.add('d-none');
            }
        });
    }

    /**
     * Re-parse the current input value into the panel fields (if the panel is open).
     * Call this after setting the input value programmatically (e.g. in a modal open handler).
     * @param {string} inputId
     */
    function refresh(inputId) {
        var input = document.getElementById(inputId);
        if (!input) return;
        var panel = input.closest('.input-group')
            ? input.closest('.input-group').nextElementSibling
            : null;
        if (panel && panel.classList.contains('cb-panel') && !panel.classList.contains('d-none')) {
            populatePanel(panel, input.value);
        }
    }

    global.CronBuilder = { init: init, refresh: refresh };

}(window));
