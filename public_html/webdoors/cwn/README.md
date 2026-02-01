# Community Wireless Node List (CWN) WebDoor

## Overview

A WebDoor for discovering and sharing community wireless networks - mesh networks, wireless BBSs, community WiFi, and other grassroots wireless infrastructure.

## Features

- **Submit Networks** - Earn 3 credits per network submitted
- **Interactive Map** - Leaflet.js with marker clustering
- **Search by Location** - Find networks within a radius (costs 1 credit)
- **Network Details** - View full information including WiFi passwords
- **User Management** - Edit/delete your own submissions

## Installation

1. **Run Database Migration**
   ```bash
   php scripts/upgrade.php
   ```

2. **Access the WebDoor**
   - Navigate to `/webdoors` in your BBS
   - Click on "Community Wireless Node List"
   - Or direct URL: `/webdoors/cwn/index.html`

## Usage

### Submitting a Network

1. Click "Submit Network" button
2. Fill in required fields:
   - SSID (network name)
   - Latitude/Longitude (or use geolocation)
   - Description (10-500 characters)
3. Optional: Add WiFi password and network type
4. Submit to earn 3 credits

### Searching Networks

1. Click "Show Nearby Networks" to search by your location (1 credit)
2. View all networks on the map for free
3. Click markers or list items to view details

### Managing Your Networks

- Click on any network you submitted to view details
- Use "Edit" or "Delete" buttons in the details modal
- Only you (or admins) can modify your submissions

## Credits Economy

| Action | Cost/Reward |
|--------|-------------|
| Submit network | **+3 credits** |
| Search networks | **-1 credit** |
| View/browse | **Free** |
| Edit own network | **Free** |
| Delete own network | **Free** |

## Rate Limits

- **Submissions:** 20 per day
- **Searches:** 50 per day

## Files

```
public_html/webdoors/cwn/
├── webdoor.json          # Manifest
├── index.html            # Main UI
├── api.php               # Backend API
├── css/
│   └── cwn.css          # Styles
├── js/
│   └── cwn.js           # JavaScript
└── README.md            # This file
```

## Database Tables

- `cwn_networks` - Network storage
- `cwn_searches` - Search history
- `cwn_sessions` - Usage tracking

## Network Types

- **mesh** - Mesh Network
- **bbs** - Wireless BBS
- **community** - Community WiFi
- **experimental** - Experimental/Research
- **event** - Event Network
- **isp** - Community ISP
- **other** - Other/Uncategorized

## API Endpoints

All accessed via `api.php?action=<action>`:

- `list` - List all networks
- `get&id=N` - Get network details
- `submit` - Submit new network (POST)
- `update&id=N` - Update network (POST)
- `delete&id=N` - Delete network (DELETE)
- `search` - Search networks (POST)

## Future Features

- Inter-BBS federation via HTTP API
- Network verification/rating system
- Advanced filtering and search
- Mobile app integration
- Network statistics and analytics

## Support

See main proposal: `docs/CWN_Proposal.md`

## Version

1.0.0 - Initial Release
