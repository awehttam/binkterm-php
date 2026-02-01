/**
 * Community Wireless Node List - Main JavaScript
 */

let map;
let markers;
let allNetworks = [];
let currentUser = null;
let userLocation = null;

// Initialize on page load
$(document).ready(function() {
    initMap();
    loadNetworks();
    loadUserInfo();

    // Character counter for submit description
    $('#description').on('input', function() {
        const len = $(this).val().length;
        $('#charCount').text(len);

        if (len < 10) {
            $('#charCount').removeClass('text-success text-warning').addClass('text-danger');
        } else if (len > 450) {
            $('#charCount').removeClass('text-success text-danger').addClass('text-warning');
        } else {
            $('#charCount').removeClass('text-danger text-warning').addClass('text-success');
        }
    });

    // Character counter for edit description
    $('#editDescription').on('input', function() {
        const len = $(this).val().length;
        $('#editCharCount').text(len);

        if (len < 10) {
            $('#editCharCount').removeClass('text-success text-warning').addClass('text-danger');
        } else if (len > 450) {
            $('#editCharCount').removeClass('text-success text-danger').addClass('text-warning');
        } else {
            $('#editCharCount').removeClass('text-danger text-warning').addClass('text-success');
        }
    });
});

/**
 * Initialize the map
 */
function initMap() {
    // Create map centered on US (will be updated if geolocation available)
    map = L.map('map').setView([39.8283, -98.5795], 4);

    // Add OpenStreetMap tiles
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        maxZoom: 19
    }).addTo(map);

    // Initialize marker cluster group
    markers = L.markerClusterGroup({
        chunkedLoading: true,
        maxClusterRadius: 50
    });

    map.addLayer(markers);

    // Add map click handler for picking location
    map.on('click', function(e) {
        if (window.pickingLocation) {
            $('#latitude').val(e.latlng.lat.toFixed(3));
            $('#longitude').val(e.latlng.lng.toFixed(3));
            window.pickingLocation = false;
            map.getContainer().style.cursor = '';
        }
    });

    // Request user's location and zoom to it
    requestUserLocation();
}

/**
 * Request user's location and center map on it
 */
function requestUserLocation() {
    if (!navigator.geolocation) {
        console.log('Geolocation not supported');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {
            const lat = position.coords.latitude;
            const lon = position.coords.longitude;

            // Store user location
            userLocation = { lat: lat, lng: lon };

            // Center map on user location with good zoom level
            map.setView([lat, lon], 10);

            // Add "You are here" marker
            const userMarker = L.marker([lat, lon], {
                icon: L.divIcon({
                    className: 'user-location-marker',
                    html: '<i class="fas fa-street-view" style="font-size: 24px; color: #dc3545;"></i>',
                    iconSize: [30, 30],
                    iconAnchor: [15, 30]
                })
            }).addTo(map);

            userMarker.bindPopup('<strong>📍 You are here</strong>').openPopup();

            console.log('Map centered on user location:', lat, lon);
        },
        function(error) {
            console.log('Unable to get location:', error.message);
            // Keep default map view on US
        },
        {
            enableHighAccuracy: false,
            timeout: 10000,
            maximumAge: 300000 // Cache for 5 minutes
        }
    );
}

/**
 * Load all networks and display on map
 */
function loadNetworks() {
    $.get('api.php?action=list&limit=500')
        .done(function(data) {
            allNetworks = data.networks;
            displayNetworksOnMap(allNetworks);
            displayNetworksList(allNetworks);
            updateStatistics(data);
        })
        .fail(function(xhr) {
            showError('Failed to load networks: ' + (xhr.responseJSON?.error || 'Unknown error'));
        });
}

/**
 * Display networks on map
 */
function displayNetworksOnMap(networks) {
    markers.clearLayers();

    networks.forEach(function(network) {
        const marker = L.marker([network.latitude, network.longitude]);

        // Create popup content
        const popupContent = `
            <div>
                <h6>${escapeHtml(network.ssid)}</h6>
                <span class="badge network-type-${network.network_type}">${network.network_type}</span>
                <p class="mt-2 mb-2">${escapeHtml(network.description).substring(0, 100)}...</p>
                <small class="text-muted">
                    <i class="fas fa-user"></i> ${escapeHtml(network.submitted_by_username)}@${escapeHtml(network.bbs_name)}<br>
                    <i class="fas fa-calendar"></i> ${formatDate(network.date_added)}
                </small>
                <hr class="my-2">
                <button class="btn btn-sm btn-primary w-100" onclick="viewNetwork(${network.id})">
                    <i class="fas fa-info-circle"></i> View Details
                </button>
            </div>
        `;

        marker.bindPopup(popupContent);
        markers.addLayer(marker);
    });

    $('#totalNetworks').text(networks.length);
}

/**
 * Display networks list
 */
function displayNetworksList(networks) {
    const container = $('#networksList');

    if (networks.length === 0) {
        container.html('<div class="text-center text-muted py-3">No networks found</div>');
        return;
    }

    let html = '<div class="list-group">';

    networks.slice(0, 10).forEach(function(network) {
        html += `
            <div class="list-group-item list-group-item-action network-card" onclick="viewNetwork(${network.id})" style="cursor: pointer;">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="flex-grow-1">
                        <h6 class="mb-1">
                            <i class="fas fa-wifi"></i> ${escapeHtml(network.ssid)}
                            <span class="badge network-type-${network.network_type} ms-2">${network.network_type}</span>
                        </h6>
                        <p class="mb-1">${escapeHtml(network.description).substring(0, 150)}${network.description.length > 150 ? '...' : ''}</p>
                        <div class="network-meta">
                            <i class="fas fa-map-marker-alt"></i> ${network.latitude}, ${network.longitude} &nbsp;
                            <i class="fas fa-user"></i> ${escapeHtml(network.submitted_by_username)} &nbsp;
                            <i class="fas fa-calendar"></i> ${formatDate(network.date_added)}
                        </div>
                    </div>
                </div>
            </div>
        `;
    });

    html += '</div>';

    if (networks.length > 10) {
        html += `<div class="text-center mt-3"><small class="text-muted">Showing 10 of ${networks.length} networks. Use map to see all.</small></div>`;
    }

    container.html(html);
}

/**
 * Load user information
 */
function loadUserInfo() {
    // Get credit balance and user info from API
    $.get('../../api/user/credits')
        .done(function(data) {
            currentUser = data;
            $('#userCredits').text(data.credit_balance || 0);
            updateUserStats();
        })
        .fail(function() {
            $('#userCredits').text('?');
        });
}

/**
 * Update user statistics
 */
function updateUserStats() {
    if (!currentUser) return;

    const myNets = allNetworks.filter(n => n.submitted_by == currentUser.id);
    $('#mySubmissions').text(myNets.length);
    $('#creditsEarned').text(myNets.length * 3);
}

/**
 * Update statistics display
 */
function updateStatistics(data) {
    $('#totalSearches').text('0'); // TODO: Get from user stats
}

/**
 * Show submit form
 */
function showSubmitForm() {
    $('#submitForm')[0].reset();
    $('#charCount').text('0').removeClass('text-success text-warning text-danger');
    $('#submitModal').modal('show');
}

/**
 * Use current location
 */
function useCurrentLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser');
        return;
    }

    navigator.geolocation.getCurrentPosition(
        function(position) {
            $('#latitude').val(position.coords.latitude.toFixed(3));
            $('#longitude').val(position.coords.longitude.toFixed(3));
            userLocation = {
                lat: position.coords.latitude,
                lng: position.coords.longitude
            };
        },
        function(error) {
            alert('Unable to get your location: ' + error.message);
        }
    );
}

/**
 * Pick location on map
 */
function pickOnMap() {
    $('#submitModal').modal('hide');
    alert('Click on the map to select a location');
    map.getContainer().style.cursor = 'crosshair';
    window.pickingLocation = true;

    // Wait for location to be picked, then reopen modal
    const checkPicked = setInterval(function() {
        if (!window.pickingLocation) {
            clearInterval(checkPicked);
            setTimeout(function() {
                $('#submitModal').modal('show');
            }, 500);
        }
    }, 100);
}

/**
 * Toggle password field
 */
function togglePassword() {
    const isOpen = $('#isOpen').is(':checked');
    $('#wifiPassword').prop('disabled', isOpen);
    if (isOpen) {
        $('#wifiPassword').val('');
    }
}

/**
 * Submit new network
 */
function submitNetwork() {
    const data = {
        ssid: $('#ssid').val().trim(),
        latitude: parseFloat($('#latitude').val()),
        longitude: parseFloat($('#longitude').val()),
        description: $('#description').val().trim(),
        wifi_password: $('#wifiPassword').val().trim() || null,
        network_type: $('#networkType').val()
    };

    // Validation
    if (!data.ssid || !data.latitude || !data.longitude || !data.description) {
        alert('Please fill in all required fields');
        return;
    }

    if (data.description.length < 10 || data.description.length > 500) {
        alert('Description must be 10-500 characters');
        return;
    }

    $.ajax({
        url: 'api.php?action=submit',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify(data)
    })
    .done(function(response) {
        $('#submitModal').modal('hide');
        showSuccess(`Network submitted successfully! You earned ${response.credits_earned} credits.`);

        // Refresh data
        loadNetworks();
        loadUserInfo();
    })
    .fail(function(xhr) {
        showError('Submission failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

/**
 * View network details
 */
function viewNetwork(id) {
    $.get(`api.php?action=get&id=${id}`)
        .done(function(network) {
            displayNetworkDetails(network);
        })
        .fail(function(xhr) {
            showError('Failed to load network: ' + (xhr.responseJSON?.error || 'Unknown error'));
        });
}

/**
 * Display network details in modal
 */
function displayNetworkDetails(network) {
    $('#detailsTitle').html(`<i class="fas fa-wifi"></i> ${escapeHtml(network.ssid)}`);

    const passwordDisplay = network.wifi_password
        ? `<span class="password-field">${escapeHtml(network.wifi_password)}</span>`
        : '<em class="text-muted">Open network (no password)</em>';

    const html = `
        <dl class="network-details row">
            <dt class="col-sm-4">Network Name</dt>
            <dd class="col-sm-8">${escapeHtml(network.ssid)}</dd>

            <dt class="col-sm-4">Type</dt>
            <dd class="col-sm-8"><span class="badge network-type-${network.network_type}">${network.network_type}</span></dd>

            <dt class="col-sm-4">Location</dt>
            <dd class="col-sm-8">
                <span class="coordinates">${network.latitude}, ${network.longitude}</span>
                <br>
                <a href="https://www.google.com/maps?q=${network.latitude},${network.longitude}" target="_blank" class="btn btn-sm btn-outline-primary mt-1">
                    <i class="fas fa-map"></i> Get Directions
                </a>
            </dd>

            <dt class="col-sm-4">Description</dt>
            <dd class="col-sm-8">${escapeHtml(network.description)}</dd>

            <dt class="col-sm-4">WiFi Password</dt>
            <dd class="col-sm-8">${passwordDisplay}</dd>

            <dt class="col-sm-4">Submitted By</dt>
            <dd class="col-sm-8">${escapeHtml(network.submitted_by_username)}@${escapeHtml(network.bbs_name)}</dd>

            <dt class="col-sm-4">Date Added</dt>
            <dd class="col-sm-8">${formatDate(network.date_added)}</dd>

            <dt class="col-sm-4">Last Verified</dt>
            <dd class="col-sm-8">${formatDate(network.date_verified)}</dd>
        </dl>
    `;

    $('#detailsBody').html(html);

    // Add edit/delete buttons if user owns this network
    if (currentUser && network.submitted_by == currentUser.id) {
        $('#detailsActions').html(`
            <button class="btn btn-warning" onclick="editNetwork(${network.id})">
                <i class="fas fa-edit"></i> Edit
            </button>
            <button class="btn btn-danger" onclick="deleteNetwork(${network.id})">
                <i class="fas fa-trash"></i> Delete
            </button>
        `);
    } else {
        $('#detailsActions').html('');
    }

    $('#detailsModal').modal('show');

    // Center map on this network
    map.setView([network.latitude, network.longitude], 13);
}

/**
 * Use my location to find nearby networks
 */
function useMyLocation() {
    if (!navigator.geolocation) {
        alert('Geolocation is not supported by your browser');
        return;
    }

    const confirmed = confirm('This will cost 1 credit to search for nearby networks. Continue?');
    if (!confirmed) return;

    navigator.geolocation.getCurrentPosition(
        function(position) {
            searchNearby(position.coords.latitude, position.coords.longitude, 10);
        },
        function(error) {
            alert('Unable to get your location: ' + error.message);
        }
    );
}

/**
 * Search for nearby networks
 */
function searchNearby(lat, lon, radiusKm) {
    $.ajax({
        url: 'api.php?action=search',
        method: 'POST',
        contentType: 'application/json',
        data: JSON.stringify({
            type: 'radius',
            latitude: lat,
            longitude: lon,
            radius_km: radiusKm
        })
    })
    .done(function(response) {
        showSuccess(`Found ${response.count} networks nearby (${response.credits_spent} credit spent)`);

        if (response.results.length > 0) {
            displayNetworksOnMap(response.results);
            displayNetworksList(response.results);

            // Center map on user location
            map.setView([lat, lon], 11);

            // Add marker for user location
            L.marker([lat, lon], {
                icon: L.divIcon({
                    className: 'custom-marker',
                    html: '<i class="fas fa-user"></i>',
                    iconSize: [30, 30]
                })
            }).addTo(map).bindPopup('You are here');
        }

        loadUserInfo(); // Refresh credits
    })
    .fail(function(xhr) {
        showError('Search failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

/**
 * Refresh networks
 */
function refreshNetworks() {
    loadNetworks();
    showSuccess('Networks refreshed');
}

/**
 * Delete network
 */
function deleteNetwork(id) {
    if (!confirm('Are you sure you want to delete this network?')) {
        return;
    }

    $.ajax({
        url: `api.php?action=delete&id=${id}`,
        method: 'DELETE'
    })
    .done(function() {
        $('#detailsModal').modal('hide');
        showSuccess('Network deleted successfully');
        loadNetworks();
    })
    .fail(function(xhr) {
        showError('Delete failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

/**
 * Edit network
 */
function editNetwork(id) {
    // Get the network data
    $.get(`api.php?action=get&id=${id}`)
        .done(function(network) {
            // Close details modal
            $('#detailsModal').modal('hide');

            // Populate edit form
            $('#editNetworkId').val(network.id);
            $('#editDescription').val(network.description);
            $('#editWifiPassword').val(network.wifi_password || '');
            $('#editNetworkType').val(network.network_type);
            $('#editIsOpen').prop('checked', !network.wifi_password);

            // Update character counter
            const len = network.description.length;
            $('#editCharCount').text(len);
            if (len < 10) {
                $('#editCharCount').removeClass('text-success text-warning').addClass('text-danger');
            } else if (len > 450) {
                $('#editCharCount').removeClass('text-success text-danger').addClass('text-warning');
            } else {
                $('#editCharCount').removeClass('text-danger text-warning').addClass('text-success');
            }

            // Show edit modal
            $('#editModal').modal('show');
        })
        .fail(function(xhr) {
            showError('Failed to load network: ' + (xhr.responseJSON?.error || 'Unknown error'));
        });
}

/**
 * Save network edits
 */
function saveNetworkEdit() {
    const id = $('#editNetworkId').val();
    const data = {
        description: $('#editDescription').val().trim(),
        wifi_password: $('#editWifiPassword').val().trim() || null,
        network_type: $('#editNetworkType').val()
    };

    // Validation
    if (!data.description) {
        alert('Description is required');
        return;
    }

    if (data.description.length < 10 || data.description.length > 500) {
        alert('Description must be 10-500 characters');
        return;
    }

    $.ajax({
        url: `api.php?action=update&id=${id}`,
        method: 'PUT',
        contentType: 'application/json',
        data: JSON.stringify(data)
    })
    .done(function(response) {
        $('#editModal').modal('hide');
        showSuccess('Network updated successfully!');

        // Refresh data
        loadNetworks();

        // Reopen the details modal with updated data
        setTimeout(function() {
            viewNetwork(id);
        }, 300);
    })
    .fail(function(xhr) {
        showError('Update failed: ' + (xhr.responseJSON?.error || 'Unknown error'));
    });
}

/**
 * Toggle password field in edit modal
 */
function toggleEditPassword() {
    const isOpen = $('#editIsOpen').is(':checked');
    $('#editWifiPassword').prop('disabled', isOpen);
    if (isOpen) {
        $('#editWifiPassword').val('');
    }
}

/**
 * Utility functions
 */
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function formatDate(dateStr) {
    const date = new Date(dateStr);
    return date.toLocaleDateString() + ' ' + date.toLocaleTimeString();
}

function showSuccess(message) {
    // Simple alert for now - could be enhanced with toast notifications
    alert(message);
}

function showError(message) {
    alert('Error: ' + message);
}
