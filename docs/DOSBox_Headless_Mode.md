# DOSBox-X Headless Mode Configuration

This document describes the headless mode configuration for running DOSBox door games in production.

## Problem Statement

DOSBox door games need to run on a server without visible windows, while maintaining full serial port functionality for the TCP/WebSocket bridge.

## Testing Results (Windows)

We tested multiple approaches to achieve headless operation:

| Configuration | Window Visible? | Serial Works? | Result |
|--------------|-----------------|---------------|---------|
| `SDL_VIDEODRIVER=dummy` only | No | ❌ No | **FAILED** - Breaks serial I/O |
| `-nogui` flag only | Yes (minimized) | ✅ Yes | Partial - Window still visible |
| `SDL_VIDEODRIVER=dummy` + `-nogui` | No | ❌ No | **FAILED** - Breaks serial I/O |
| `windowposition=-2000,-2000` + `-nogui` | No (offscreen) | ✅ Yes | **SUCCESS** ✅ |

## Solution: Windows

**Working Configuration:**
- **Config file**: `windowposition=-2000,-2000` in `[sdl]` section
- **Command line**: `-nogui` flag
- **Environment**: No `SDL_VIDEODRIVER` variable

**Result:**
- Window exists but is positioned far offscreen (-2000, -2000 pixels)
- Users cannot see or interact with the window
- Serial port communication works perfectly
- FOSSIL driver loads and functions correctly

**Code Implementation:**
```php
// Windows - use -nogui flag
$cmd = sprintf('"%s" -nogui -conf "%s"', $dosboxExe, $sessionConfigPath);

// Don't set SDL_VIDEODRIVER=dummy on Windows
$env = null;
```

**Config file (`dosbox-bridge-production.conf`):**
```ini
[sdl]
windowposition=-2000,-2000

[dosbox]
machine=svga_s3
memsize=16
```

## Linux Configuration (Untested)

**Option 1: SDL_VIDEODRIVER=dummy**
Linux/X11 may handle `SDL_VIDEODRIVER=dummy` differently than Windows. This needs testing:

```bash
SDL_VIDEODRIVER=dummy dosbox-x -nogui -conf dosbox.conf
```

**Option 2: Xvfb (Virtual Framebuffer)**
Run DOSBox in a virtual X server:

```bash
Xvfb :99 -screen 0 640x400x8 &
DISPLAY=:99 dosbox-x -conf dosbox.conf
```

**Option 3: windowposition (Fallback)**
Same as Windows - position window offscreen:

```ini
[sdl]
windowposition=-2000,-2000
```

**Testing Required:**
- Test if `SDL_VIDEODRIVER=dummy` works with serial ports on Linux
- Test if Xvfb is needed or if windowposition alone works
- Verify FOSSIL driver functionality on Linux

## Key Findings

1. **DOSBox-X requires video initialization for serial ports**: True "headless" mode (`SDL_VIDEODRIVER=dummy`) prevents proper serial port initialization on Windows.

2. **Invisible != Headless**: The window must exist (even if invisible/offscreen) for hardware emulation to work correctly.

3. **Platform Differences**: Windows and Linux may handle SDL differently. What breaks on Windows might work on Linux.

4. **The `-nogui` flag**: On Windows, this flag doesn't fully hide the window, but combined with `windowposition` it works well.

## Production Deployment

### Windows Servers
- Use `windowposition=-2000,-2000` + `-nogui`
- Confirmed working, no visible windows
- No additional dependencies needed

### Linux Servers
- **Recommended**: Test `SDL_VIDEODRIVER=dummy` first (may work on Linux)
- **Fallback**: Use `windowposition=-2000,-2000` (should work everywhere)
- **Alternative**: Use Xvfb if needed

### Docker/Containers
- May need Xvfb or virtual framebuffer
- Test `SDL_VIDEODRIVER=dummy` in container environment
- Consider using `windowposition` as most portable solution

## Configuration Files

**Production Config**: `dosbox-bridge/dosbox-bridge-production.conf`
- Uses `windowposition=-2000,-2000`
- Includes comments for Linux alternatives

**Session Manager**: `src/DoorSessionManager.php`
- Uses `-nogui` flag in headless mode
- Contains commented code for `SDL_VIDEODRIVER` testing on Linux
- Platform-specific handling for Windows vs Linux

## Testing Checklist

When testing headless mode on a new platform:

- [ ] DOSBox process starts successfully
- [ ] Bridge server connects to DOSBox TCP port
- [ ] Web client connects to bridge WebSocket
- [ ] Terminal displays output from DOSBox
- [ ] Keyboard input reaches DOSBox
- [ ] FOSSIL driver loads (check via `SET FOSSIL=` in DOS)
- [ ] Door game launches and runs
- [ ] No visible windows on desktop
- [ ] No windows in taskbar/dock
- [ ] Process can be killed cleanly

## Future Work

- Test and document Linux configuration
- Test in Docker containers
- Test on macOS
- Consider creating systemd service for Linux
- Add health checks for stuck sessions
