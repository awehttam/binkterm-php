#!/bin/bash
# DOSBox Door Maintenance Launcher
# Launches DOSBox with mounted door environment for configuration

echo "Starting DOSBox in maintenance mode..."
echo

# Try to find dosbox executable (prefer vanilla dosbox over dosbox-x)
DOSBOX_EXE="$DOSBOX_EXECUTABLE"

if [ -z "$DOSBOX_EXE" ]; then
    # Try dosbox first (lighter weight)
    DOSBOX_EXE=$(which dosbox 2>/dev/null)
    if [ -z "$DOSBOX_EXE" ]; then
        # Fall back to dosbox-x
        DOSBOX_EXE=$(which dosbox-x 2>/dev/null)
        if [ -z "$DOSBOX_EXE" ]; then
            echo "Error: DOSBox not found. Please install dosbox or set DOSBOX_EXECUTABLE environment variable."
            exit 1
        fi
    fi
fi

echo "Using: $DOSBOX_EXE"
echo

"$DOSBOX_EXE" -conf "dosbox-bridge/maintenance.conf"

echo
echo "DOSBox closed."
