# DOSBox Door Bridge - Software Requirements

**Quick Reference Guide**
**Last Updated:** 2026-02-10

---

## Core Software Versions

| Component | Minimum Version | Recommended Version | Notes |
|-----------|----------------|---------------------|-------|
| **DOSBox** | 0.74-3 | DOSBox-X 2024.10.01+ | DOSBox-X preferred for better serial emulation |
| **Node.js** | 18.x LTS | 20.x LTS (20.11.0+) | Required for bridge server |
| **PHP** | 8.1 | 8.2 or 8.3 | With sockets, pcntl, posix extensions |
| **PostgreSQL** | 13 | 14+ | BinktermPHP database |
| **Linux** | Ubuntu 20.04 | Ubuntu 22.04 LTS | Or Debian 11/12 |

---

## Node.js Dependencies

### Production Dependencies

```json
{
  "dependencies": {
    "ws": "^8.16.0",
    "iconv-lite": "^0.6.3"
  }
}
```

| Package | Version | Purpose |
|---------|---------|---------|
| **ws** | ^8.16.0 | WebSocket server implementation |
| **iconv-lite** | ^0.6.3 | Character encoding (CP437 ↔ UTF-8) |

### Installation

```bash
npm install ws@^8.16.0 iconv-lite@^0.6.3
```

---

## Frontend Dependencies

### xterm.js and Addons

| Package | Version | Required | Purpose |
|---------|---------|----------|---------|
| **xterm** | ^5.3.0 | Yes | Terminal emulator |
| **xterm-addon-fit** | ^0.8.0 | Yes | Auto-sizing terminal |
| **xterm-addon-webgl** | ^0.16.0 | Optional | GPU-accelerated rendering |
| **xterm-addon-web-links** | ^0.9.0 | Optional | Clickable URLs |

### CDN Links (Alternative to npm)

```html
<!-- xterm.js core -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.css">
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>

<!-- Fit addon -->
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.js"></script>

<!-- WebGL addon (optional) -->
<script src="https://cdn.jsdelivr.net/npm/xterm-addon-webgl@0.16.0/lib/xterm-addon-webgl.js"></script>
```

### NPM Installation

```bash
npm install xterm@^5.3.0 \
    xterm-addon-fit@^0.8.0 \
    xterm-addon-webgl@^0.16.0 \
    xterm-addon-web-links@^0.9.0
```

---

## PHP Extensions Required

| Extension | Purpose | Installation |
|-----------|---------|--------------|
| **ext-sockets** | Socket operations | `apt install php8.2-sockets` |
| **ext-pcntl** | Process control | Built-in (Linux) |
| **ext-posix** | POSIX functions | Built-in (Linux) |
| **ext-pgsql** | PostgreSQL | `apt install php8.2-pgsql` |
| **ext-mbstring** | Multibyte strings | `apt install php8.2-mbstring` |

### Verify PHP Extensions

```bash
php -m | grep -E '(sockets|pcntl|posix|pgsql|mbstring)'
```

---

## Optional Production Tools

| Tool | Version | Purpose | Installation |
|------|---------|---------|--------------|
| **Supervisor** | 4.2.5+ | Process management | `apt install supervisor` |
| **Nginx** | 1.24+ | Reverse proxy for WebSockets | `apt install nginx` |
| **Screen** | 4.9.0+ | Terminal multiplexing | `apt install screen` |
| **Tmux** | 3.2+ | Alternative to screen | `apt install tmux` |

---

## DOSBox Versions Comparison

### DOSBox (Original)

- **Latest Stable:** 0.74-3 (2019)
- **Pros:** Stable, well-tested, widely available
- **Cons:** Limited serial port features, slower development
- **Use Case:** Basic door games, simple serial communication

### DOSBox-X

- **Latest Stable:** 2024.10.01+
- **Pros:** Active development, better serial emulation, more features
- **Cons:** Slightly less stable, may have breaking changes
- **Use Case:** Complex door games, better compatibility
- **Repository:** https://github.com/joncampbell123/dosbox-x

**Installation Options:**
1. Package manager: `sudo apt-get install dosbox-x` (if available)
2. Build from source (see instructions below)
3. Download pre-built binaries from GitHub releases

### Recommendation

**Use DOSBox-X 2024.10.01+ for production** due to:
- Better serial port emulation (critical for bridge)
- Active bug fixes and improvements
- More configuration options
- Better multi-session stability

---

## System Package Versions (Ubuntu 22.04)

### Minimal Installation

```bash
# Core components
sudo apt-get install \
    dosbox-x \
    nodejs \
    npm \
    php8.2-cli \
    php8.2-fpm \
    php8.2-sockets \
    php8.2-pgsql \
    postgresql-14

# Verify versions
dosbox -version        # Should show DOSBox-X or 0.74-3+
node --version         # Should show v20.x or v18.x
php --version          # Should show 8.2.x or 8.1.x
psql --version         # Should show 14.x or 13.x
```

---

## Version Compatibility Matrix

| BinktermPHP | DOSBox | Node.js | PHP | xterm.js | Notes |
|-------------|--------|---------|-----|----------|-------|
| **1.10.x+** | 0.74-3+ / X 2024.10+ | 18.x - 20.x | 8.1 - 8.3 | 5.3.0+ | Initial support |
| **2.0.x** (future) | X 2024.10+ | 20.x+ | 8.2+ | 5.3.0+ | DOSBox-X required |

---

## Known Version Issues

### DOSBox 0.74-2 and Earlier
- ❌ Serial port TCP mode may not work correctly
- ❌ Avoid for production use

### Node.js 16.x and Earlier
- ❌ End of life, security vulnerabilities
- ❌ WebSocket library compatibility issues

### PHP 8.0 and Earlier
- ❌ Missing some required socket features
- ❌ Use PHP 8.1+ minimum

### xterm.js 4.x and Earlier
- ⚠️ Works but missing performance improvements
- ⚠️ Use 5.x for better ANSI rendering

---

## Upgrade Paths

### From Standard DOSBox to DOSBox-X

```bash
# Remove old DOSBox
sudo apt-get remove dosbox

# Install DOSBox-X from PPA or source
sudo add-apt-repository ppa:dosbox-x/ppa
sudo apt-get update
sudo apt-get install dosbox-x

# Update base config
sudo cp /etc/dosbox/base.conf /etc/dosbox-x/base.conf

# Test with existing door configs
dosbox-x -conf /var/binkterm/door_sessions/test/dosbox.conf
```

### Node.js Version Upgrade

```bash
# Using nvm (recommended)
nvm install 20
nvm use 20
nvm alias default 20

# Or update via apt
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs

# Reinstall packages
npm install
```

---

## Development vs. Production

### Development Environment

- DOSBox 0.74-3 (apt package) - OK
- Node.js 18.x - OK
- PHP 8.1 - OK
- xterm.js via CDN - OK

### Production Environment

- **DOSBox-X 2024.10.01+** - Required
- **Node.js 20.x LTS** - Required
- **PHP 8.2+** - Recommended
- **xterm.js local bundle** - Recommended
- **Supervisor** - Required
- **Nginx** - Recommended

---

## Testing Your Environment

### Quick Version Check Script

```bash
#!/bin/bash
# scripts/check_dosbox_requirements.sh

echo "=== DOSBox Door Bridge Requirements Check ==="
echo ""

# Check DOSBox
if command -v dosbox &> /dev/null; then
    echo "✓ DOSBox installed: $(dosbox -version 2>&1 | head -n1)"
else
    echo "✗ DOSBox not found"
fi

# Check Node.js
if command -v node &> /dev/null; then
    NODE_VERSION=$(node --version | sed 's/v//')
    echo "✓ Node.js installed: $NODE_VERSION"

    # Check if version >= 18
    NODE_MAJOR=$(echo $NODE_VERSION | cut -d. -f1)
    if [ "$NODE_MAJOR" -ge 18 ]; then
        echo "  ✓ Version meets minimum requirement (18.x)"
    else
        echo "  ✗ Version too old, need 18.x or newer"
    fi
else
    echo "✗ Node.js not found"
fi

# Check PHP
if command -v php &> /dev/null; then
    PHP_VERSION=$(php -v | head -n1 | cut -d' ' -f2)
    echo "✓ PHP installed: $PHP_VERSION"

    # Check extensions
    echo "  Checking required extensions:"
    for ext in sockets pcntl posix pgsql; do
        if php -m | grep -q "^$ext$"; then
            echo "    ✓ $ext"
        else
            echo "    ✗ $ext (missing)"
        fi
    done
else
    echo "✗ PHP not found"
fi

# Check Node packages
if [ -f "package.json" ] || command -v npm &> /dev/null; then
    echo ""
    echo "Checking Node.js packages:"

    if node -e "require('ws')" 2>/dev/null; then
        WS_VERSION=$(node -e "console.log(require('ws/package.json').version)")
        echo "  ✓ ws@$WS_VERSION"
    else
        echo "  ✗ ws not installed"
    fi

    if node -e "require('iconv-lite')" 2>/dev/null; then
        ICONV_VERSION=$(node -e "console.log(require('iconv-lite/package.json').version)")
        echo "  ✓ iconv-lite@$ICONV_VERSION"
    else
        echo "  ✗ iconv-lite not installed"
    fi
fi

echo ""
echo "=== Check Complete ==="
```

Run with: `bash scripts/check_dosbox_requirements.sh`

---

## Troubleshooting Version Issues

### "Module not found: ws"

```bash
# Install missing Node.js packages
npm install ws@^8.16.0 iconv-lite@^0.6.3

# Or install globally
sudo npm install -g ws iconv-lite
```

### "Call to undefined function socket_create()"

```bash
# Install PHP sockets extension
sudo apt-get install php8.2-sockets
sudo systemctl restart php8.2-fpm
```

### "DOSBox serial port not working"

```bash
# Upgrade to DOSBox-X
sudo apt-get install dosbox-x

# Or build DOSBox with serial support
./configure --enable-serial
make
sudo make install
```

### "xterm.js not rendering ANSI correctly"

```javascript
// Upgrade to xterm.js 5.3.0+
npm install xterm@^5.3.0

// Or use CDN
<script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.js"></script>
```

---

## Future Version Considerations

### Planned Upgrades (BinktermPHP 2.0)

- **DOSBox-X only:** Drop support for standard DOSBox
- **Node.js 20+ only:** Drop Node.js 18 support
- **PHP 8.3+:** Leverage newer features
- **xterm.js 6.x:** When released

### Deprecation Timeline

- **2026 Q2:** Drop PHP 8.1 support
- **2026 Q3:** Drop Node.js 18 support
- **2027 Q1:** Require DOSBox-X only

---

## Building DOSBox-X from Source

**⚠️ Note:** These build instructions have not been fully tested.

If DOSBox-X is not available via package manager:

```bash
# Install build dependencies
sudo apt install automake gcc g++ make libncurses-dev nasm \
    libsdl2-dev libsdl2-net-dev libpcap-dev libslirp-dev fluidsynth \
    libfluidsynth-dev libavformat-dev libavcodec-dev libswscale-dev \
    libfreetype-dev libxkbfile-dev libxrandr-dev libglu1-mesa-dev

# Clone and build
git clone https://github.com/joncampbell123/dosbox-x.git
cd dosbox-x
./build
sudo make install

# Verify
dosbox-x --version
```

**Build Dependencies Explained:**

| Package | Purpose |
|---------|---------|
| `automake`, `gcc`, `g++`, `make` | Build toolchain |
| `libncurses-dev` | Terminal UI support |
| `nasm` | Assembly compiler |
| `libsdl2-dev` | Graphics/audio library |
| `libsdl2-net-dev` | Network support |
| `libpcap-dev` | Packet capture (for NE2000 emulation) |
| `libslirp-dev` | User-mode networking |
| `fluidsynth`, `libfluidsynth-dev` | MIDI synthesis |
| `libavformat-dev`, `libavcodec-dev`, `libswscale-dev` | Video codec support |
| `libfreetype-dev` | Font rendering |
| `libxkbfile-dev`, `libxrandr-dev` | X11 keyboard/display |
| `libglu1-mesa-dev` | OpenGL utility library |

**Build Time:** 5-10 minutes on modern hardware

**Troubleshooting:**
- If dependencies missing: `sudo apt-get build-dep dosbox`
- Check build wiki: https://github.com/joncampbell123/dosbox-x/wiki/Building

---

## Quick Start Command Summary

```bash
# Ubuntu 22.04 LTS - Full installation
sudo apt-get update
sudo apt-get install -y dosbox-x nodejs npm \
    php8.2 php8.2-cli php8.2-fpm php8.2-sockets \
    php8.2-pgsql php8.2-mbstring supervisor nginx

# If dosbox-x not available, build from source (see above)

# Install Node.js dependencies
npm install ws@^8.16.0 iconv-lite@^0.6.3

# Install xterm.js
npm install xterm@^5.3.0 xterm-addon-fit@^0.8.0

# Verify installation
dosbox-x --version && node --version && php --version
```

---

**For complete installation guide, see:** `docs/proposals/DOSBox_Door_Bridge_Proposal.md`

**For architecture details, see:** Section "Technical Implementation" in main proposal
