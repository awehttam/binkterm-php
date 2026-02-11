# DOSBox Bridge Test - Phase 1 Proof of Concept

This directory contains a basic proof-of-concept test for the DOSBox door bridge system.

## Overview

This test demonstrates:
- ✅ TCP-to-WebSocket bridge (Node.js)
- ✅ DOSBox serial port connection via TCP
- ✅ xterm.js web terminal client
- ✅ Character encoding conversion (CP437 ↔ UTF-8)
- ✅ Bidirectional communication

## Prerequisites

### Required Software

1. **Node.js 18.x or newer**
   ```bash
   node --version  # Should show v18.x or v20.x
   ```

2. **Node.js packages**
   ```bash
   cd scripts/dosbox-bridge
   npm install
   ```

3. **DOSBox or DOSBox-X**
   ```bash
   dosbox --version
   # or
   dosbox-x --version
   ```

4. **Web browser** (Chrome, Firefox, Edge, Safari)

## Quick Start

### Step 1: Start the Bridge Server

Open a terminal and run:

```bash
cd /path/to/binkterm
node scripts/dosbox-bridge/server.js 5000 5001 test-session
```

You should see:
```
=== DOSBox Door Bridge Server ===
Session ID: test-session
TCP Port (DOSBox): 5000
WebSocket Port (Client): 5001

[TCP] Listening on 127.0.0.1:5000
[TCP] Waiting for DOSBox connection...
[WS] WebSocket server listening on port 5001
[WS] Waiting for web client connection...

Bridge running. Press Ctrl+C to stop.
```

### Step 2: Start DOSBox

In a **new terminal**, run:

```bash
cd /path/to/binkterm
dosbox -conf test/dosbox-bridge/dosbox-bridge-test.conf
```

DOSBox should start and connect to the bridge. You'll see in the bridge terminal:
```
[TCP] DOSBox connected from 127.0.0.1
```

### Step 3: Open the Web Client

1. Open your web browser
2. Navigate to: `file:///path/to/binkterm/test/dosbox-bridge/test-client.html`

   Or if you have a web server running:
   `http://localhost:1244/test/dosbox-bridge/test-client.html`

3. Click the **"Connect"** button

You should see:
- Status changes to "Connected"
- Terminal shows "Connected to bridge!"
- Terminal displays DOSBox welcome message

### Step 4: Test Communication

In the web terminal, type DOS commands:

```
DIR
ECHO Hello from the web!
VER
```

You should see the output appear in the web terminal.

## Troubleshooting

### Bridge server won't start

**Error: "Module not found"**
```bash
# Install required packages
cd scripts/dosbox-bridge
npm install
```

**Error: "Address already in use"**
```bash
# Find what's using the port
netstat -ano | findstr :5000
# Or on Linux/Mac:
lsof -i :5000

# Use different ports
node scripts/door-bridge-server.js 6000 6001 test
```

### DOSBox won't connect

**Error: "Connection refused"**
- Make sure bridge server is running first
- Check that port 5000 is open: `telnet localhost 5000`

**DOSBox hangs at startup**
- Check DOSBox error messages
- Try regular DOSBox instead of DOSBox-X
- Verify serial port config in dosbox-bridge-test.conf

### Web client won't connect

**Error: "Connection failed"**
- Make sure bridge server is running
- Check WebSocket port (default 5001)
- Try ws://localhost:5001 directly
- Check browser console for errors (F12)

**CORS or mixed content errors**
- Use http:// for testing (not https://)
- WebSocket must be ws:// (not wss://)

### Character encoding issues

**Seeing strange characters**
- This is normal for some DOS characters
- Bridge handles CP437 to UTF-8 conversion
- Make sure iconv-lite is installed

## Testing Scenarios

### Scenario 1: Basic Text I/O

1. Connect everything
2. Type: `ECHO Testing 123`
3. Verify output appears correctly

### Scenario 2: Directory Listing

1. Connect everything
2. Type: `DIR C:\`
3. Verify directory listing displays properly

### Scenario 3: Long Output

1. Type: `HELP` or `MEM`
2. Verify scrolling and ANSI codes work
3. Check for any dropped characters

### Scenario 4: Interactive Program

1. Type: `EDIT test.txt` (if available)
2. Test keyboard input
3. Use arrow keys, Enter, Esc
4. Verify responsiveness

### Scenario 5: Disconnect/Reconnect

1. Close web browser tab
2. Open new tab and reconnect
3. DOSBox should still be connected
4. Type commands to verify

## Architecture

```
┌─────────────┐
│   Browser   │
│  (xterm.js) │
└──────┬──────┘
       │ WebSocket (port 5001)
       │ UTF-8
       ▼
┌─────────────────┐
│  Bridge Server  │
│   (Node.js)     │
│                 │
│  CP437 ↔ UTF-8  │
└──────┬──────────┘
       │ TCP (port 5000)
       │ CP437
       ▼
┌─────────────┐
│   DOSBox    │
│   COM1      │
└─────────────┘
```

## Files in This Directory

```
test/dosbox-bridge/
├── README.md                       # This file
├── test-client.html               # Web terminal test client
├── dosbox-bridge-test.conf        # DOSBox config (visible console for testing)
├── dosbox-bridge-production.conf  # DOSBox config (headless for production)
├── test.sh                        # Quick test script (Linux/Mac)
└── test.bat                       # Quick test script (Windows)
```

## Configuration Files

**`dosbox-bridge-test.conf`** - Development/Testing
- Shows DOSBox-X window for debugging
- Use this when testing and debugging door games
- Allows you to see DOS console output directly
- Default for test launcher scripts

**`dosbox-bridge-production.conf`** - Production/Headless
- Runs DOSBox-X in headless mode (minimal video output)
- Use this for server deployment
- All I/O goes through COM1/bridge only
- Reduces resource usage on server

**Related Files:**
- **`../../scripts/dosbox-bridge/server.js`** - Bridge server (production)
- **`../../scripts/dosbox-bridge/package.json`** - Node.js dependencies

## Phase 1 Results

✅ **TCP-to-WebSocket bridge works!**
- DOSBox-X connects to bridge via nullmodem TCP
- Web terminal connects via WebSocket
- Character encoding (CP437 ↔ UTF-8) works correctly
- Bidirectional communication confirmed
- Commands execute successfully

⚠️ **CTTY limitation discovered:**
- Using `CTTY COM1` for console redirection causes prompt echo/loop
- This is a DOSBox-X serial emulation quirk, not a bridge bug
- Commands still work despite the visual artifact
- **Solution:** Real door games use FOSSIL drivers, not CTTY

## Next Steps (Phase 2) - FOSSIL Driver Testing

Phase 2 will test with real BBS door software using FOSSIL drivers:

1. ✅ Basic bridge - **COMPLETE**
2. ⏭️ Install FOSSIL driver (X00.SYS or BNU.COM) in DOSBox-X
3. ⏭️ Generate drop files (DOOR.SYS format)
4. ⏭️ Test with real door game (LORD - Legend of the Red Dragon)
5. ⏭️ Verify FOSSIL communication works without echo issues
6. ⏭️ Session management integration (PHP)
7. ⏭️ WebDoor integration

## Known Limitations

This is a proof-of-concept test, so:

- ❌ No session management
- ❌ No authentication
- ❌ No drop files generated
- ❌ No automatic DOSBox spawn
- ❌ No cleanup on disconnect
- ⚠️ Manual setup required
- ⚠️ Single session only
- ⚠️ No error recovery
- ⚠️ **CTTY COM1 has echo/loop issues** - prompts repeat continuously
  - This is a DOSBox-X serial emulation quirk with CTTY redirection
  - Commands still work despite the repeating prompts
  - Real BBS door games use FOSSIL drivers instead of CTTY (Phase 2)

These will be addressed in later phases.

## Success Criteria

Phase 1 is successful if:

✅ Bridge server starts without errors
✅ DOSBox connects to TCP port
✅ Web client connects to WebSocket
✅ Keyboard input reaches DOSBox
✅ DOSBox output appears in terminal
✅ DOS commands execute correctly
✅ ANSI colors display properly
✅ No character corruption

## Getting Help

If you encounter issues:

1. Check all terminals for error messages
2. Verify all prerequisites are installed
3. Test each component individually
4. Review the troubleshooting section above
5. Check Node.js and DOSBox versions

## Performance Notes

Expected performance:
- Latency: < 50ms (local)
- Throughput: Sufficient for text I/O
- CPU: < 5% per connection
- RAM: ~50MB bridge + ~100MB DOSBox

If performance is poor:
- Check CPU usage
- Verify network latency
- Reduce DOSBox cycles if laggy
- Check for CPU throttling

## Security Notes

**This is a test environment only!**

- Binding to 127.0.0.1 (localhost only)
- No authentication implemented
- No input validation
- No resource limits
- Not suitable for production

Production implementation will add:
- User authentication
- Session isolation
- Resource limits
- Input sanitization
- Proper error handling

## Feedback

This is Phase 1 of the DOSBox door bridge implementation.

Please report:
- What worked ✅
- What didn't work ❌
- Performance issues 🐌
- Compatibility problems 🔧
- Ideas for improvement 💡
