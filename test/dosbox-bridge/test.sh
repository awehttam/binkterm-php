#!/bin/bash
# DOSBox Bridge Test Launcher (Linux/Mac)
# This script helps run the test components

cd "$(dirname "$0")/../.."

echo "========================================"
echo "  DOSBox Bridge Test Launcher"
echo "========================================"
echo ""

show_menu() {
    echo "Please select what to run:"
    echo ""
    echo "  1. Start Bridge Server (Terminal 1)"
    echo "  2. Start DOSBox (Terminal 2)"
    echo "  3. Open Test Client in Browser"
    echo "  4. Install Node.js Dependencies"
    echo "  5. Check Prerequisites"
    echo "  6. Exit"
    echo ""
    read -p "Enter choice (1-6): " choice

    case $choice in
        1) start_bridge ;;
        2) start_dosbox ;;
        3) open_client ;;
        4) install_deps ;;
        5) check_prereqs ;;
        6) exit 0 ;;
        *) echo "Invalid choice. Please try again."; echo ""; show_menu ;;
    esac
}

start_bridge() {
    echo ""
    echo "Starting Bridge Server..."
    echo "Press Ctrl+C to stop"
    echo ""
    node scripts/door-bridge-server.js 5000 5001 test-session
}

start_dosbox() {
    echo ""
    echo "Starting DOSBox..."
    echo ""

    # Try dosbox-x first, then dosbox
    if command -v dosbox-x &> /dev/null; then
        dosbox-x -conf test/dosbox-bridge/dosbox-bridge-test.conf
    elif command -v dosbox &> /dev/null; then
        dosbox -conf test/dosbox-bridge/dosbox-bridge-test.conf
    else
        echo "Error: DOSBox not found"
        echo "Install with: sudo apt install dosbox"
        exit 1
    fi
}

open_client() {
    echo ""
    echo "Opening test client in browser..."

    CLIENT_PATH="$(pwd)/test/dosbox-bridge/test-client.html"

    # Try different browser commands
    if command -v xdg-open &> /dev/null; then
        xdg-open "$CLIENT_PATH"
    elif command -v open &> /dev/null; then
        open "$CLIENT_PATH"
    else
        echo "Could not open browser automatically."
        echo "Please open this file manually:"
        echo "  file://$CLIENT_PATH"
    fi

    echo ""
    read -p "Press Enter to return to menu..."
    show_menu
}

install_deps() {
    echo ""
    echo "Installing Node.js dependencies..."
    npm install
    echo ""
    echo "Done!"
    read -p "Press Enter to return to menu..."
    show_menu
}

check_prereqs() {
    echo ""
    echo "Checking prerequisites..."
    echo ""

    # Check Node.js
    echo "[Node.js]"
    if command -v node &> /dev/null; then
        NODE_VERSION=$(node --version)
        echo "  $NODE_VERSION: OK"
    else
        echo "  NOT FOUND - Install Node.js 18.x or newer"
    fi

    echo ""
    echo "[NPM Packages]"

    # Check ws
    if node -e "require('ws')" 2>/dev/null; then
        WS_VERSION=$(node -e "console.log(require('ws/package.json').version)")
        echo "  ws@$WS_VERSION: OK"
    else
        echo "  ws: NOT FOUND - Run: npm install"
    fi

    # Check iconv-lite
    if node -e "require('iconv-lite')" 2>/dev/null; then
        ICONV_VERSION=$(node -e "console.log(require('iconv-lite/package.json').version)")
        echo "  iconv-lite@$ICONV_VERSION: OK"
    else
        echo "  iconv-lite: NOT FOUND - Run: npm install"
    fi

    echo ""
    echo "[DOSBox]"
    if command -v dosbox-x &> /dev/null; then
        DOSBOX_VERSION=$(dosbox-x --version 2>&1 | head -n1)
        echo "  $DOSBOX_VERSION: OK"
    elif command -v dosbox &> /dev/null; then
        DOSBOX_VERSION=$(dosbox -version 2>&1 | head -n1)
        echo "  $DOSBOX_VERSION: OK"
    else
        echo "  NOT FOUND - Install DOSBox or DOSBox-X"
    fi

    echo ""
    read -p "Press Enter to return to menu..."
    show_menu
}

# Start menu
show_menu
