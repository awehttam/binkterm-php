/**
 * MeshCore node info modal.
 *
 * Call openMeshcoreNodeModal(nodeId) to fetch and display node details.
 * Requires meshcore_node_info_modal.twig to be present in the page.
 */
(function () {
    'use strict';

    let modalEl = null;
    let bsModal = null;

    function getModal() {
        if (!modalEl) {
            modalEl = document.getElementById('meshcoreNodeInfoModal');
            bsModal  = new bootstrap.Modal(modalEl);
        }
        return bsModal;
    }

    function escHtml(s) {
        return String(s ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function copyText(text, btn) {
        navigator.clipboard.writeText(text).then(function () {
            const orig = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check"></i>';
            setTimeout(function () { btn.innerHTML = orig; }, 1500);
        });
    }

    window.openMeshcoreNodeModal = function (nodeId) {
        const modal = getModal();
        const body  = document.getElementById('meshcoreNodeInfoBody');
        const nameEl = document.getElementById('meshcoreNodeInfoName');

        nameEl.textContent = '';
        body.innerHTML = '<div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-2"></i>' +
            window.t('ui.meshcore_nodes.loading', {}, 'Loading…') + '</div>';
        modal.show();

        fetch('/api/meshcore/node/' + encodeURIComponent(nodeId))
            .then(function (r) { return r.json(); })
            .then(function (node) {
                nameEl.textContent = node.handle || node.node_id;
                const publicKey = node.public_key || node.node_id;

                const lat = node.lat != null ? parseFloat(node.lat).toFixed(6) : null;
                const lon = node.lon != null ? parseFloat(node.lon).toFixed(6) : null;
                const coords = (lat && lon) ? (lat + ', ' + lon) : null;

                const lastSeen = node.last_seen_at
                    ? new Date(node.last_seen_at).toLocaleString()
                    : window.t('ui.meshcore_nodes.never_seen', {}, 'Never');

                body.innerHTML =
                    '<dl class="mb-3">' +
                    (node.location
                        ? '<dt>' + window.t('ui.meshcore_nodes.location', {}, 'Location') + '</dt>' +
                          '<dd>' + escHtml(node.location) + '</dd>'
                        : '') +
                    (node.description
                        ? '<dt>' + window.t('ui.meshcore_nodes.description', {}, 'Description') + '</dt>' +
                          '<dd>' + escHtml(node.description) + '</dd>'
                        : '') +
                    (coords
                        ? '<dt>' + window.t('ui.meshcore_nodes.coordinates', {}, 'Coordinates') + '</dt>' +
                          '<dd class="d-flex align-items-center gap-2">' +
                          '<code>' + escHtml(coords) + '</code>' +
                          '<button class="btn btn-sm btn-outline-secondary" id="meshcoreNodeCoordsCopyBtn" data-coords="' + escHtml(coords) + '">' +
                          '<i class="fas fa-copy"></i></button></dd>'
                        : '') +
                    (node.interface_type === 'meshcore'
                        ? '<dt>' + window.t('ui.meshcore_nodes.public_key', {}, 'Public Key') + '</dt>' +
                          '<dd class="d-flex align-items-center gap-2">' +
                          '<code class="text-break small">' + escHtml(publicKey) + '</code>' +
                          '<button class="btn btn-sm btn-outline-secondary flex-shrink-0" id="meshcoreNodeKeyCopyBtn">' +
                          '<i class="fas fa-copy"></i></button></dd>'
                        : '') +
                    '</dl>' +
                    (node.interface_type === 'meshcore'
                        ? '<div class="text-center">' +
                          '<img src="/api/meshcore/node/' + encodeURIComponent(nodeId) + '/qr.svg" ' +
                          'alt="QR Code" class="img-fluid" style="max-width:220px;" loading="lazy">' +
                          '<p class="small text-muted mt-1">' + window.t('ui.meshcore_nodes.qr_hint', {}, 'Scan to add as MeshCore contact') + '</p>' +
                          '</div>'
                        : '');

                const coordsCopyBtn = document.getElementById('meshcoreNodeCoordsCopyBtn');
                if (coordsCopyBtn) {
                    coordsCopyBtn.addEventListener('click', function () {
                        copyText(coordsCopyBtn.dataset.coords, coordsCopyBtn);
                    });
                }

                const copyBtn = document.getElementById('meshcoreNodeKeyCopyBtn');
                if (copyBtn) {
                    copyBtn.addEventListener('click', function () {
                        copyText(publicKey, copyBtn);
                    });
                }
            })
            .catch(function () {
                body.innerHTML = '<div class="alert alert-danger">' +
                    window.t('ui.meshcore_nodes.load_error', {}, 'Failed to load node details.') + '</div>';
            });
    };
})();
