let pmPoints = [];
let pmNetworks = [];
let pmAreasModal;
let pmFileAreasModal;

document.addEventListener('DOMContentLoaded', function() {
    pmAreasModal = new bootstrap.Modal(document.getElementById('pmAreasModal'));
    pmFileAreasModal = new bootstrap.Modal(document.getElementById('pmFileAreasModal'));
    loadPointManagement();
});

function loadPointManagement() {
    fetch('/api/point-management')
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.load_failed', {}, 'Failed to load your points')));
            }
            pmPoints = data.points || [];
            document.getElementById('pointManagementLoading').classList.add('d-none');
            document.getElementById('pointManagementCreate').classList.remove('d-none');

            if (pmPoints.length === 0) {
                document.getElementById('pointManagementNoPoints').classList.remove('d-none');
                document.getElementById('pointManagementList').classList.add('d-none');
            } else {
                document.getElementById('pointManagementNoPoints').classList.add('d-none');
                document.getElementById('pointManagementList').classList.remove('d-none');
                renderPointManagementList();
            }
            loadPmNetworks();
        })
        .catch(error => {
            document.getElementById('pointManagementLoading').classList.add('d-none');
            showError(error.message);
        });
}

function loadPmNetworks() {
    fetch('/api/point-management/networks')
        .then(response => response.json())
        .then(data => {
            pmNetworks = (data.success && Array.isArray(data.networks)) ? data.networks : [];

            if (pmNetworks.length === 0) {
                document.getElementById('pointManagementNoNetworks').classList.remove('d-none');
                document.getElementById('pointManagementCreateForm').classList.add('d-none');
                return;
            }

            document.getElementById('pointManagementNoNetworks').classList.add('d-none');
            document.getElementById('pointManagementCreateForm').classList.remove('d-none');
            const select = document.getElementById('pmCreateNetwork');
            select.innerHTML = pmNetworks.map(n =>
                `<option value="${escapeHtml(n.address)}">${escapeHtml(n.network_name || n.address)} (${escapeHtml(n.address)})</option>`
            ).join('');
        })
        .catch(() => {});
}

function createPoint() {
    const bossAddress = document.getElementById('pmCreateNetwork').value;
    if (!bossAddress) {
        showError(window.t('ui.point_management.select_network_required', {}, 'Please select a network'));
        return;
    }

    fetch('/api/point-management', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ boss_address: bossAddress })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.create_failed', {}, 'Failed to create point')));
            }
            showSuccess(window.t('ui.point_management.created', {}, 'Point address created'));
            loadPointManagement();
        })
        .catch(error => showError(error.message));
}

function renderPointManagementList() {
    const container = document.getElementById('pointManagementList');
    container.innerHTML = pmPoints.map(renderPointCard).join('');
}

function renderPointCard(point) {
    const id = point.id;
    return `
    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>
                <strong class="font-monospace">${escapeHtml(point.node_address)}</strong>
                ${point.network_name ? `<span class="text-muted ms-2">${escapeHtml(point.network_name)}</span>` : ''}
                <span class="text-muted ms-2">${escapeHtml(window.t('ui.point_management.point_number_label', {}, 'Point number'))}: ${escapeHtml(String(point.point_number))}</span>
            </div>
            <div>
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" data-bs-toggle="collapse" data-bs-target="#pmSettings-${id}" aria-expanded="false" aria-controls="pmSettings-${id}">
                    <i class="fas fa-pen me-1"></i>${escapeHtml(window.t('ui.common.edit', {}, 'Edit'))}
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="openPmAreasModal(${id})">
                    <i class="fas fa-list me-1"></i>${escapeHtml(window.t('ui.point_management.manage_areas', {}, 'Echoareas'))}
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="openPmFileAreasModal(${id})">
                    <i class="fas fa-folder me-1"></i>${escapeHtml(window.t('ui.point_management.manage_fileareas', {}, 'Fileareas'))}
                </button>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deletePmPoint(${id})">
                    <i class="fas fa-trash me-1"></i>${escapeHtml(window.t('ui.common.delete', {}, 'Delete'))}
                </button>
            </div>
        </div>
        <div class="collapse" id="pmSettings-${id}">
        <div class="card-body">
            <div id="pmAlert-${id}"></div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="pmSessionPassword-${id}">${escapeHtml(window.t('ui.point_management.session_password', {}, 'Session Password'))}</label>
                    <div class="input-group">
                        <input type="password" class="form-control font-monospace" id="pmSessionPassword-${id}" value="${escapeHtml(point.session_password || '')}" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePmPasswordVisibility('pmSessionPassword-${id}', this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="pmPacketPassword-${id}">${escapeHtml(window.t('ui.point_management.packet_password', {}, 'Packet Password'))}</label>
                    <div class="input-group">
                        <input type="password" class="form-control font-monospace" id="pmPacketPassword-${id}" value="${escapeHtml(point.packet_password || '')}" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePmPasswordVisibility('pmPacketPassword-${id}', this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="pmAreafixPassword-${id}">${escapeHtml(window.t('ui.point_management.areafix_password', {}, 'Areafix Password'))}</label>
                    <div class="input-group">
                        <input type="password" class="form-control font-monospace" id="pmAreafixPassword-${id}" value="${escapeHtml(point.areafix_password || '')}" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePmPasswordVisibility('pmAreafixPassword-${id}', this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="pmFilefixPassword-${id}">${escapeHtml(window.t('ui.point_management.filefix_password', {}, 'Filefix Password'))}</label>
                    <div class="input-group">
                        <input type="password" class="form-control font-monospace" id="pmFilefixPassword-${id}" value="${escapeHtml(point.filefix_password || '')}" autocomplete="off">
                        <button class="btn btn-outline-secondary" type="button" onclick="togglePmPasswordVisibility('pmFilefixPassword-${id}', this)"><i class="fas fa-eye"></i></button>
                    </div>
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="pmInetHost-${id}">${escapeHtml(window.t('ui.point_management.inet_host', {}, 'Internet Host'))}</label>
                    <input type="text" class="form-control" id="pmInetHost-${id}" value="${escapeHtml(point.inet_host || '')}">
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="pmPort-${id}">${escapeHtml(window.t('ui.point_management.port', {}, 'Port'))}</label>
                    <input type="number" min="1" max="65535" class="form-control" id="pmPort-${id}" value="${point.port || ''}">
                </div>
                <div class="col-md-6">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pmHoldMail-${id}" ${point.hold_mail ? 'checked' : ''}>
                        <label class="form-check-label" for="pmHoldMail-${id}">${escapeHtml(window.t('ui.point_management.hold_mail', {}, 'Hold Mail'))}</label>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="form-check mt-2">
                        <input class="form-check-input" type="checkbox" id="pmCompressOutbound-${id}" ${point.compress_outbound ? 'checked' : ''}>
                        <label class="form-check-label" for="pmCompressOutbound-${id}">${escapeHtml(window.t('ui.point_management.compress_outbound', {}, 'Compress Outbound'))}</label>
                    </div>
                </div>
            </div>
            <div class="mt-3">
                <button type="button" class="btn btn-primary" onclick="savePmPoint(${id})">
                    <i class="fas fa-save me-1"></i>${escapeHtml(window.t('ui.common.save', {}, 'Save'))}
                </button>
            </div>
        </div>
        </div>
    </div>`;
}

function togglePmPasswordVisibility(inputId, button) {
    const input = document.getElementById(inputId);
    const icon = button.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.remove('fa-eye');
        icon.classList.add('fa-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.remove('fa-eye-slash');
        icon.classList.add('fa-eye');
    }
}

function savePmPoint(id) {
    const payload = {
        session_password: document.getElementById(`pmSessionPassword-${id}`).value,
        packet_password: document.getElementById(`pmPacketPassword-${id}`).value,
        areafix_password: document.getElementById(`pmAreafixPassword-${id}`).value,
        filefix_password: document.getElementById(`pmFilefixPassword-${id}`).value,
        inet_host: document.getElementById(`pmInetHost-${id}`).value.trim(),
        port: document.getElementById(`pmPort-${id}`).value,
        hold_mail: document.getElementById(`pmHoldMail-${id}`).checked,
        compress_outbound: document.getElementById(`pmCompressOutbound-${id}`).checked,
    };

    fetch(`/api/point-management/${encodeURIComponent(id)}`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.save_failed', {}, 'Failed to save point')));
            }
            showSuccess(window.t('ui.point_management.saved', {}, 'Point saved'));
            loadPointManagement();
        })
        .catch(error => {
            const alertEl = document.getElementById(`pmAlert-${id}`);
            if (alertEl) {
                alertEl.innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
            } else {
                showError(error.message);
            }
        });
}

function deletePmPoint(id) {
    if (!confirm(window.t('ui.point_management.delete_confirm', {}, 'Delete this point registration? This cannot be undone.'))) {
        return;
    }
    fetch(`/api/point-management/${encodeURIComponent(id)}`, { method: 'DELETE' })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.delete_failed', {}, 'Failed to delete point')));
            }
            showSuccess(window.t('ui.point_management.deleted', {}, 'Point deleted'));
            loadPointManagement();
        })
        .catch(error => showError(error.message));
}

function openPmAreasModal(id) {
    document.getElementById('pmAreasModalAlert').innerHTML = '';
    document.getElementById('pmAreasPointId').value = id;
    document.getElementById('pmAreasList').innerHTML = `<div class="text-muted">${escapeHtml(window.t('ui.common.loading', {}, 'Loading...'))}</div>`;
    pmAreasModal.show();

    fetch(`/api/point-management/${encodeURIComponent(id)}/areas`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.areas.load_failed', {}, 'Failed to load area subscriptions')));
            }
            renderPmAreaChecklist('pmAreasList', data.areas || []);
        })
        .catch(error => {
            document.getElementById('pmAreasModalAlert').innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        });
}

function savePmAreas() {
    const id = document.getElementById('pmAreasPointId').value;
    const checkedIds = Array.from(document.querySelectorAll('#pmAreasList .pm-area-checkbox:checked')).map(el => parseInt(el.value, 10));

    fetch(`/api/point-management/${encodeURIComponent(id)}/areas`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ echoarea_ids: checkedIds })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.areas.save_failed', {}, 'Failed to save area subscriptions')));
            }
            showSuccess(window.t('ui.point_management.areas.saved', {}, 'Subscriptions saved'));
            pmAreasModal.hide();
        })
        .catch(error => {
            document.getElementById('pmAreasModalAlert').innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        });
}

function openPmFileAreasModal(id) {
    document.getElementById('pmFileAreasModalAlert').innerHTML = '';
    document.getElementById('pmFileAreasPointId').value = id;
    document.getElementById('pmFileAreasList').innerHTML = `<div class="text-muted">${escapeHtml(window.t('ui.common.loading', {}, 'Loading...'))}</div>`;
    pmFileAreasModal.show();

    fetch(`/api/point-management/${encodeURIComponent(id)}/fileareas`)
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.fileareas.load_failed', {}, 'Failed to load file area subscriptions')));
            }
            renderPmAreaChecklist('pmFileAreasList', data.fileareas || [], 'pm-filearea-checkbox');
        })
        .catch(error => {
            document.getElementById('pmFileAreasModalAlert').innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        });
}

function savePmFileAreas() {
    const id = document.getElementById('pmFileAreasPointId').value;
    const checkedIds = Array.from(document.querySelectorAll('#pmFileAreasList .pm-filearea-checkbox:checked')).map(el => parseInt(el.value, 10));

    fetch(`/api/point-management/${encodeURIComponent(id)}/fileareas`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ file_area_ids: checkedIds })
    })
        .then(response => response.json())
        .then(data => {
            if (!data.success) {
                throw new Error(getApiErrorMessage(data, window.t('ui.point_management.fileareas.save_failed', {}, 'Failed to save file area subscriptions')));
            }
            showSuccess(window.t('ui.point_management.fileareas.saved', {}, 'Subscriptions saved'));
            pmFileAreasModal.hide();
        })
        .catch(error => {
            document.getElementById('pmFileAreasModalAlert').innerHTML = `<div class="alert alert-danger">${escapeHtml(error.message)}</div>`;
        });
}

function renderPmAreaChecklist(containerId, areas, checkboxClass = 'pm-area-checkbox') {
    const container = document.getElementById(containerId);
    if (areas.length === 0) {
        container.innerHTML = `<div class="text-muted">${escapeHtml(window.t('ui.point_management.areas.none_available', {}, 'No areas available.'))}</div>`;
        return;
    }
    container.innerHTML = areas.map(area => `
        <div class="form-check">
            <input class="form-check-input ${checkboxClass}" type="checkbox" value="${area.id}" id="${checkboxClass}-${area.id}" ${area.subscribed ? 'checked' : ''}>
            <label class="form-check-label" for="${checkboxClass}-${area.id}">
                <span class="font-monospace">${escapeHtml(area.tag)}</span>
                ${area.description ? ` - ${escapeHtml(area.description)}` : ''}
            </label>
        </div>
    `).join('');
}
