# Source Games WebDoor

A community server browser for Source engine games (Half-Life 2, Counter-Strike: Source, Team Fortress 2, etc.)

## Features

- Display configured Source engine game servers
- Show server details (map, max players, Steam App ID)
- One-click connect via Steam protocol
- Fallback console instructions for manual connection
- Mobile-friendly responsive design

## Configuration

Servers are configured through the BBS admin panel when enabling this WebDoor. Each server entry supports:

### Server Properties

- **name**: Display name of the server (e.g., "Community HL2:DM Server")
- **game**: Game name (e.g., "Half-Life 2 Deathmatch", "Counter-Strike: Source")
- **address**: Server IP:PORT (e.g., "example.com:27015" or "192.168.1.1:27015")
- **description**: Brief description shown on the server card
- **map**: Current/default map name (e.g., "dm_lockdown", "de_dust2")
- **maxPlayers**: Maximum player count
- **steamAppId**: Steam App ID for the game (enables Steam protocol connection)

### Common Steam App IDs

- Half-Life 2 Deathmatch: 320
- Counter-Strike: Source: 240
- Team Fortress 2: 440
- Day of Defeat: Source: 300
- Garry's Mod: 4000
- Left 4 Dead: 500
- Left 4 Dead 2: 550
- Portal 2: 620

## How Users Connect

1. Click "Connect to Server" button
2. If Steam is running and App ID is configured, Steam launches automatically
3. Otherwise, users get console command instructions
4. Users can manually connect via game console: `connect <address>`

## Future Enhancements

- Live server status querying (players online, current map)
- Server favorites system
- Player statistics integration
- Discord/Steam community links per server
- Server voting/ratings

## Credits System

This door does not require or use BBS credits.
