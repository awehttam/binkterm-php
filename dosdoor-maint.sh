#!/bin/bash
# DOSBox Door Maintenance Launcher
# Launches DOSBox-X with mounted door environment for configuration

echo "Starting DOSBox-X in maintenance mode..."
echo

# Try to find dosbox-x executable
DOSBOX_EXE="${DOSBOX_EXECUTABLE:-/usr/bin/dosbox-x}"

if [ ! -f "$DOSBOX_EXE" ]; then
    # Try 'which' to find it
    DOSBOX_EXE=$(which dosbox-x 2>/dev/null)
    if [ -z "$DOSBOX_EXE" ]; then
        echo "Error: DOSBox-X not found. Please set DOSBOX_EXECUTABLE environment variable."
        exit 1
    fi
fi

"$DOSBOX_EXE" -conf "dosbox-bridge/maintenance.conf"

echo
echo "DOSBox-X closed."
