# MRC Chat WebDoor

Multi Relay Chat (MRC) is a real-time multi-user chat system designed for BBS platforms. This WebDoor provides a modern web interface for participating in MRC chat networks.

## Features

- **Real-time Chat**: Join chat rooms and communicate with users across multiple BBSes
- **Multi-Room Support**: Switch between different chat rooms
- **User Presence**: See who's online in each room
- **Room Topics**: View and track room topics
- **Private Messages**: Send private messages to specific users (future feature)
- **Message History**: View recent message history

## Technical Details

### Protocol
MRC Protocol v1.3 - Text-based protocol using tilde (~) field separators

### Architecture
- **Frontend**: HTML5/JavaScript with Bootstrap 5 UI
- **Backend**: PHP daemon maintains persistent connection to MRC server
- **Database**: PostgreSQL for message history and state tracking
- **Communication**: HTTP polling (2-second intervals) for message updates

### Components

1. **MRC Daemon** (`scripts/mrc_daemon.php`)
   - Maintains persistent TCP/SSL connection to MRC server
   - Processes incoming/outgoing packets
   - Manages message queue in database
   - Handles keepalive (PING/IMALIVE)

2. **API Endpoints** (`routes/api-routes.php`)
   - `/api/webdoor/mrc/rooms` - Get room list
   - `/api/webdoor/mrc/messages/{room}` - Get message history
   - `/api/webdoor/mrc/send` - Send chat message
   - `/api/webdoor/mrc/users/{room}` - Get users in room
   - `/api/webdoor/mrc/join` - Join a room
   - `/api/webdoor/mrc/status` - Get connection status

3. **Web Interface**
   - `index.html` - Chat UI (3-column layout)
   - `mrc.js` - JavaScript client (AJAX polling, message handling)
   - `mrc.css` - Styling

## Configuration

MRC is configured through the WebDoors admin interface and `config/mrc.json`:

- **Server Settings**: MRC server host/port, SSL configuration
- **BBS Identity**: BBS name, platform info, sysop name
- **Connection**: Auto-reconnect, timeouts, keepalive intervals
- **Rooms**: Default room, auto-join rooms
- **Messages**: Max length, history limits

## Installation

1. Enable MRC in the WebDoors admin interface
2. Configure MRC server settings in admin panel
3. Run database migration: `php scripts/setup.php`
4. Start MRC daemon: `php scripts/mrc_daemon.php --daemon --pid-file=data/run/mrc_daemon.pid`
5. Access via WebDoors menu

## Protocol Compliance

- 7-field message format with tilde separators
- Handshake sent within 1 second of connection
- Responds to PING with IMALIVE every 60 seconds
- Tilde (~) character blacklisted from all user input
- Spaces in usernames replaced with underscores
- Character range: Chr(32) through Chr(125)

## Future Enhancements

- WebSocket support for real-time updates (replace HTTP polling)
- Private messaging UI
- User ignore/block lists
- Desktop notifications
- @mention highlighting
- Emoji support
- Mobile-optimized responsive design

## Server

Default MRC server: `mrc.bottomlessabyss.net:50001` (SSL)

## Credits

- MRC Protocol v1.3
- BinktermPHP Development Team

## License

See main BinktermPHP license
