# Telnet Daemon (Alpha)

This directory contains an **alpha-quality** telnet daemon for BinktermPHP. It provides a classic BBS terminal interface with message browsing, reading, and composition capabilities that reuses the existing web API endpoints for netmail and echomail.

## Status

- **Alpha Quality** - Early release with ongoing improvements
- **Message Browsing** - Navigate netmail and echomail with screen-aware pagination
- **Full-Screen Editor** - Write and edit messages with arrow key navigation
- **Reply Support** - Quote and reply to messages
- **API Integration** - Uses existing web API to avoid reimplementing netmail/echomail logic

## Features

### Screen-Aware Display
- Automatically detects terminal dimensions via NAWS (Negotiate About Window Size)
- Adapts message lists to fit available screen height
- Prevents list overflow on different terminal sizes
- Dynamic pagination based on terminal rows

### Message Browsing
- List netmail and echomail messages with pagination
- Navigate between pages
- View message details with headers
- Thread awareness and proper message display
- ANSI color support for enhanced readability

### Full-Screen Message Editor
- Arrow key navigation (Up, Down, Left, Right, Home, End, Page Up, Page Down)
- Insert and edit text at any cursor position
- Delete characters with Backspace/Delete
- Line operations:
  - Enter: Insert new line at cursor
  - Ctrl+Y: Delete entire current line
- Save/Cancel operations:
  - Ctrl+Z: Save message and send
  - Ctrl+C: Cancel and discard message
- Visual feedback with colorized prompts
- Message quoting when replying

### Security Features
- **Login Attempts**: Limited to 3 attempts per connection
- **Rate Limiting**: Maximum 5 failed login attempts per minute per IP address
- **Connection Logging**: All login/logout events logged to console
- **Session Management**: Automatic cleanup of expired rate limit entries

### Reliability Features
- **Error Recovery**: Timeout handling in telnet protocol reading
- **API Retry Logic**: Exponential backoff for failed API requests (up to 3 retries)
- **Process Cleanup**: Proper signal handling for SIGCHLD, SIGTERM, and SIGINT
- **Connection Health**: Timeout on socket accept operations
- **Zombie Prevention**: Automatic reaping of child processes

### User Experience
- Colorized prompts and status messages
- Welcome message with BBS website URL
- Goodbye message on logout with reminder to visit website
- Message count display on main menu
- Better error messages from API calls
- Helpful command documentation
- Optional ANSI login screen (`telnet/screens/login.ans`) if present

## Requirements

- PHP 8+
- PHP extensions:
  - `curl` - For API requests
  - `sockets` - For telnet server
  - `pcntl` - For process forking (Linux/macOS only)
- BinktermPHP web API reachable (defaults to SITE_URL from config)

## Usage

### Starting the Daemon

Run the daemon with default settings (0.0.0.0:2323):

```bash
php telnet/telnet_daemon.php
```

### Command Line Options

Specify custom host and port:

```bash
php telnet/telnet_daemon.php --host=0.0.0.0 --port=2323
```

Specify API base URL:

```bash
php telnet/telnet_daemon.php --api-base=http://127.0.0.1
```

For HTTPS with a self-signed certificate:

```bash
php telnet/telnet_daemon.php --api-base=https://your-host --insecure
```

Enable debug logging (shows API URLs, screen dimensions, login attempts):

```bash
php telnet/telnet_daemon.php --debug
```

### Available Options

| Option | Default | Description |
|--------|---------|-------------|
| `--host` | 0.0.0.0 | IP address to bind to (use 127.0.0.1 for localhost only) |
| `--port` | 2323 | TCP port to listen on |
| `--api-base` | SITE_URL | Base URL for API requests (e.g., http://localhost) |
| `--insecure` | (off) | Accept self-signed SSL certificates for API calls |
| `--debug` | (off) | Enable verbose debug logging to console |

## Running as a Service

### Systemd Service

Create a systemd service file for automatic startup:

```bash
sudo nano /etc/systemd/system/binkterm-telnet.service
```

```ini
[Unit]
Description=BinktermPHP Telnet Daemon
After=network.target

[Service]
Type=simple
User=yourusername
Group=yourusername
WorkingDirectory=/path/to/binkterm
ExecStart=/usr/bin/php /path/to/binkterm/telnet/telnet_daemon.php
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start the service:

```bash
sudo systemctl daemon-reload
sudo systemctl enable binkterm-telnet
sudo systemctl start binkterm-telnet
sudo systemctl status binkterm-telnet
```

### Cron (Alternative)

Add to crontab for startup on boot:

```bash
@reboot /usr/bin/php /path/to/binkterm/telnet/telnet_daemon.php >> /path/to/binkterm/data/logs/telnet.log 2>&1
```

## Editor Controls

The full-screen message editor supports the following controls:

### Cursor Navigation
- **Arrow Keys** (Up/Down/Left/Right) - Move cursor position
- **Home** - Jump to beginning of current line
- **End** - Jump to end of current line
- **Page Up** - Scroll up one screen
- **Page Down** - Scroll down one screen

### Editing
- **Backspace/Delete** - Delete character at or before cursor
- **Enter** - Insert new line at cursor position
- **Ctrl+Y** - Delete entire current line

### Save/Cancel
- **Ctrl+Z** - Save message and send
- **Ctrl+C** - Cancel and discard message

### Visual Feedback
The editor displays help text at the top of the screen showing available commands with color-coded indicators:
- Green: Save command
- Red: Cancel command
- Yellow: Delete line command

## Connecting

Users can connect using any telnet client:

### Command Line Telnet

```bash
telnet your-bbs-hostname 2323
```

### PuTTY (Windows)

```bash
putty -telnet your-bbs-hostname -P 2323
```

Or configure a saved session:
1. Host Name: your-bbs-hostname
2. Port: 2323
3. Connection type: Telnet

### SyncTERM (Recommended)

SyncTERM provides the best experience with full ANSI color support:

1. Add new connection
2. Connection Type: Telnet
3. Address: your-bbs-hostname
4. Port: 2323

## Security Considerations

### Authentication
- Users authenticate with their BinktermPHP web credentials
- Passwords are transmitted to the API over HTTP(S)
- Consider using HTTPS for API connections in production

### Rate Limiting
- 3 login attempts per connection before disconnect
- Maximum 5 failed attempts per minute per IP address
- Rate limit entries automatically expire after 60 seconds
- All failed login attempts are logged to console

### Network Security
- Daemon listens on all interfaces (0.0.0.0) by default
- Use `--host=127.0.0.1` to restrict to localhost only
- Consider firewall rules to restrict access by IP
- Monitor console logs for suspicious login activity

### API Access
- Daemon requires access to BinktermPHP web API
- API base defaults to SITE_URL from configuration
- Use `--insecure` flag only for development with self-signed certificates
- In production, use proper SSL certificates

## Known Limitations

### Platform Limitations
- **Windows**: Single connection only (no `pcntl_fork` support)
- **Linux/macOS**: Multiple concurrent connections supported via process forking

### Client Compatibility
- Works best with PuTTY and SyncTERM
- Echo handling varies by telnet client
- Some clients may not properly support NAWS negotiation
- ANSI color support depends on terminal emulator capabilities

### Feature Scope
- **Alpha Quality**: This is an early release with ongoing development
- **Read Focus**: Primarily designed for message browsing and basic composition
- **Limited Features**: Not all web interface features are available via telnet
- **API Dependent**: All functionality requires working web API access

## Troubleshooting

### Connection Issues

**Problem**: Cannot connect to telnet daemon

**Solutions**:
1. Verify daemon is running: `ps aux | grep telnet_daemon`
2. Check port is listening: `netstat -an | grep 2323`
3. Check firewall rules: `sudo ufw status`
4. Try localhost connection: `telnet localhost 2323`

### API Connection Issues

**Problem**: "Failed to authenticate" or API errors

**Solutions**:
1. Verify web interface is accessible
2. Check API base URL setting
3. Test API manually: `curl http://localhost/api/auth/login`
4. Enable debug mode: `--debug` flag
5. Check API logs in web server error log

### Screen Display Issues

**Problem**: Message lists overflow or don't fit screen

**Solutions**:
1. Verify terminal supports NAWS negotiation
2. Check debug output for detected screen dimensions
3. Try different terminal emulator (SyncTERM recommended)
4. Manually resize terminal window to trigger NAWS update

### Custom Login Screen

If `telnet/screens/login.ans` exists, the daemon will display it instead of the default login banner.
This file is sent as raw ANSI with CRLF normalization, so keep it under the terminal width for best results.

### Editor Issues

**Problem**: Arrow keys not working or insert strange characters

**Solutions**:
1. Verify terminal type is set correctly (ANSI or VT100)
2. Try different terminal emulator
3. Check SSH/telnet client configuration
4. Use SyncTERM for best compatibility

### Login Rate Limiting

**Problem**: "Too many failed login attempts"

**Solutions**:
1. Wait 60 seconds for rate limit to expire
2. Check console logs for IP address being rate limited
3. Verify correct username and password
4. Contact administrator if legitimate account is locked

## API Endpoints Used

The telnet daemon uses the following BinktermPHP API endpoints:

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/api/auth/login` | POST | User authentication |
| `/api/messages/netmail` | GET | List netmail messages |
| `/api/messages/netmail/{id}` | GET | Get netmail message details |
| `/api/messages/netmail/send` | POST | Send netmail message |
| `/api/messages/echomail` | GET | List echomail messages |
| `/api/messages/echomail/{id}` | GET | Get echomail message details |
| `/api/messages/echomail/post` | POST | Post echomail message |

All API requests include:
- Cookie-based session management
- Automatic retry with exponential backoff
- Error handling and user-friendly error messages
- Optional SSL certificate verification

## Development Notes

### Debug Mode

Enable debug mode to see detailed information:

```bash
php telnet/telnet_daemon.php --debug
```

Debug output includes:
- API URL being used on startup
- Screen dimensions detected (rows and columns)
- Messages per page calculation
- Connection events and client IPs
- Login attempt tracking
- API request/response details

### Signal Handling

The daemon handles the following signals:

- **SIGCHLD**: Reaps zombie child processes (forked connections)
- **SIGTERM**: Graceful shutdown with cleanup
- **SIGINT**: Graceful shutdown on Ctrl+C

### Connection Flow

1. Client connects to daemon socket
2. Daemon forks child process (Linux/macOS) or handles directly (Windows)
3. Child performs telnet negotiation (NAWS, echo control)
4. Child displays login prompt
5. User authenticates via API
6. Main menu displayed with message counts
7. User navigates menus and performs actions
8. Connection closed and child exits
9. Parent reaps zombie process via SIGCHLD

### Code Structure

- `telnet_daemon.php` - Main daemon script
- Telnet protocol implementation with IAC negotiation
- ANSI escape code support for colors and cursor control
- API client with retry logic and error handling
- Full-screen editor with state management
- Screen-aware pagination system

## Future Improvements

Planned features for future releases:

- File area browsing and downloads
- Chat/messaging features
- User list and who's online
- System statistics and information
- Admin functions for privileged users
- Bulletin display system
- Door game integration
- More comprehensive message management
- Search functionality
- User preferences and settings

## Contributing

When contributing to the telnet daemon:

1. Test with multiple terminal emulators (PuTTY, SyncTERM, standard telnet)
2. Verify Windows compatibility (single connection mode)
3. Test with different screen sizes (24 rows, 40 rows, etc.)
4. Follow existing code conventions
5. Add debug logging for new features
6. Update this documentation

## License

Same as BinktermPHP - BSD License. See main LICENSE.md for details.
