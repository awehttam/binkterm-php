/**
 * Reusable Leaflet map-picker component.
 *
 * Trigger with a button:
 *   <button class="map-picker-trigger"
 *           data-lat-field="#latInputId"
 *           data-lon-field="#lonInputId">...</button>
 *
 * Requires Leaflet to be loaded. The modal partial (map_picker_modal.twig)
 * must be present in the page.
 */
(function () {
    'use strict';

    let map = null;
    let marker = null;
    let modalEl = null;
    let bsModal = null;
    let activeLatField = null;
    let activeLonField = null;
    let pendingLat = null;
    let pendingLon = null;

    function initMap() {
        if (map) return;
        map = L.map('mapPickerMap').setView([20, 0], 2);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
        }).addTo(map);

        map.on('click', function (e) {
            setPickedLocation(e.latlng.lat, e.latlng.lng);
        });
    }

    function setPickedLocation(lat, lon) {
        pendingLat = lat;
        pendingLon = lon;

        if (marker) {
            marker.setLatLng([lat, lon]);
        } else {
            marker = L.marker([lat, lon], { draggable: true }).addTo(map);
            marker.on('dragend', function (e) {
                const pos = e.target.getLatLng();
                updateCoordsDisplay(pos.lat, pos.lng);
                pendingLat = pos.lat;
                pendingLon = pos.lng;
            });
        }

        updateCoordsDisplay(lat, lon);
        document.getElementById('mapPickerConfirm').disabled = false;
    }

    function updateCoordsDisplay(lat, lon) {
        const el = document.getElementById('mapPickerCoords');
        if (el) {
            el.textContent = lat.toFixed(6) + ', ' + lon.toFixed(6);
        }
    }

    function open(latField, lonField) {
        activeLatField = document.querySelector(latField);
        activeLonField = document.querySelector(lonField);

        if (!modalEl) {
            modalEl = document.getElementById('mapPickerModal');
            bsModal  = new bootstrap.Modal(modalEl);

            modalEl.addEventListener('shown.bs.modal', function () {
                map.invalidateSize();
            });
        }

        pendingLat = null;
        pendingLon = null;
        document.getElementById('mapPickerConfirm').disabled = true;
        document.getElementById('mapPickerCoords').textContent = '';

        // Pre-seed from existing input values
        const existingLat = parseFloat(activeLatField ? activeLatField.value : '');
        const existingLon = parseFloat(activeLonField ? activeLonField.value : '');

        initMap();

        if (!isNaN(existingLat) && !isNaN(existingLon)) {
            map.setView([existingLat, existingLon], 13);
            setPickedLocation(existingLat, existingLon);
        } else {
            map.setView([20, 0], 2);
            if (marker) {
                map.removeLayer(marker);
                marker = null;
            }
        }

        bsModal.show();
    }

    function confirm() {
        if (pendingLat === null || pendingLon === null) return;
        if (activeLatField) activeLatField.value = pendingLat.toFixed(6);
        if (activeLonField) activeLonField.value = pendingLon.toFixed(6);
        bsModal.hide();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.addEventListener('click', function (e) {
            const btn = e.target.closest('.map-picker-trigger');
            if (!btn) return;
            e.preventDefault();
            open(btn.dataset.latField, btn.dataset.lonField);
        });

        const confirmBtn = document.getElementById('mapPickerConfirm');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', confirm);
        }
    });
})();
