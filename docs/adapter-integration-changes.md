# Adapter Integration Changes for multiplexing-server.js

## Summary
Replace DOSBox-specific code with adapter pattern to support both DOSBox and DOSEMU.

## Key Changes Needed

### 1. Method Replacements

**Replace:** `allocatePortAndLaunchDosBox(session)`
**With:** `launchEmulator(session)`

```javascript
async launchEmulator(session) {
    const { sessionId, sessionData } = session;

    // Create emulator adapter (auto-selects DOSBox or DOSEMU)
    session.emulator = createEmulatorAdapter(BASE_PATH);
    const emulatorName = session.emulator.getName();

    console.log(`[SESSION] Using ${emulatorName} for session ${sessionId}`);

    // Create session directory
    const sessionPath = path.join(BASE_PATH, 'data', 'run', 'door_sessions', sessionId);
    if (!fs.existsSync(sessionPath)) {
        fs.mkdirSync(sessionPath, { recursive: true });
    }
    session.sessionData.session_path = sessionPath;

    // Generate DOOR.SYS
    if (sessionData.user_data) {
        let userData = sessionData.user_data;
        if (typeof userData === 'string') {
            userData = JSON.parse(userData);
        }

        const dropPath = path.join(BASE_PATH, 'dosbox-bridge', 'dos', 'drops', `node${sessionData.node_number}`);
        if (!fs.existsSync(dropPath)) {
            fs.mkdirSync(dropPath, { recursive: true });
        }

        this.generateDoorSys(dropPath, userData, sessionData.node_number);
    }

    await this.updateSessionPath(sessionId, sessionPath);

    // For DOSBox: allocate port and create TCP listener
    if (emulatorName === 'DOSBox') {
        const tcpPort = this.portPool.allocate();
        session.tcpPort = tcpPort;
        this.sessionsByPort.set(tcpPort, session);

        await session.emulator.createTCPListener(tcpPort, (socket) => {
            this.handleEmulatorConnection(socket, session);
        });

        this.pendingDosBoxConnections.set(tcpPort, session);
    }

    // Launch emulator
    const result = await session.emulator.launch(session, sessionData);
    session.emulatorProcess = result.process;
    session.emulatorPid = result.pid;

    // For DOSEMU: connection is immediate (PTY)
    if (emulatorName === 'DOSEMU') {
        session.emulatorSocket = result.connection;
        this.setupEmulatorHandlers(session);
        console.log(`[SESSION] Multiplexing started for session ${sessionId}`);
    }

    await this.updateSessionPorts(sessionId, session.tcpPort || 0, result.pid);
}
```

**Replace:** `handleDosBoxConnection(socket, session)`
**With:** `handleEmulatorConnection(socket, session)`

```javascript
handleEmulatorConnection(socket, session) {
    const emulatorName = session.emulator.getName();
    console.log(`[${emulatorName}] Connection received for session ${session.sessionId}`);

    // Remove from pending (DOSBox only)
    if (session.tcpPort) {
        this.pendingDosBoxConnections.delete(session.tcpPort);
    }

    session.emulatorSocket = socket;
    this.setupEmulatorHandlers(session);

    console.log(`[SESSION] Multiplexing started for session ${session.sessionId}`);
}
```

**Replace:** `setupDosBoxHandlers(session)`
**With:** `setupEmulatorHandlers(session)`

```javascript
setupEmulatorHandlers(session) {
    const { sessionId } = session;
    const emulatorName = session.emulator.getName();

    // Set up data flow: Emulator -> WebSocket
    session.emulator.onData((data) => {
        session.bytesFromEmulator += data.length;

        try {
            // For DOSBox: convert CP437 to UTF-8
            // For DOSEMU: data is already in correct format from PTY
            const utf8Data = (emulatorName === 'DOSBox')
                ? iconv.decode(data, 'cp437')
                : data.toString('utf8');

            if (session.ws && session.ws.readyState === WebSocket.OPEN) {
                session.ws.send(utf8Data);
            } else {
                console.warn(`[${emulatorName}->WS] WebSocket not ready, dropping ${data.length} bytes`);
            }
        } catch (err) {
            console.error(`[${emulatorName}] Encoding error for session ${sessionId}:`, err.message);
        }
    });
}
```

### 2. WebSocket Handler Updates

In `setupWebSocketHandlers`, replace dosboxSocket with emulator:

```javascript
setupWebSocketHandlers(session) {
    const { ws, sessionId } = session;

    ws.on('message', (data) => {
        session.bytesToEmulator += data.length;

        try {
            const dataStr = data.toString('utf8');

            // For DOSBox: convert UTF-8 to CP437
            // For DOSEMU: pass through as UTF-8
            const emulatorName = session.emulator ? session.emulator.getName() : 'Unknown';
            const emulatorData = (emulatorName === 'DOSBox')
                ? iconv.encode(dataStr, 'cp437')
                : Buffer.from(dataStr, 'utf8');

            if (session.emulator) {
                session.emulator.write(emulatorData);
            } else {
                console.warn(`[WS->EMULATOR] Emulator not ready, dropping ${data.length} bytes`);
            }
        } catch (err) {
            console.error(`[WS] Encoding error for session ${sessionId}:`, err.message);
        }
    });

    ws.on('close', (code, reason) => {
        console.log(`[WS] Client disconnected for session ${sessionId}: ${code} ${reason}`);
        this.handleWebSocketDisconnect(session);
    });

    ws.on('error', (err) => {
        console.error(`[WS] WebSocket error for session ${sessionId}:`, err.message);
    });
}
```

### 3. Cleanup Updates

In `removeSession`, replace DOSBox-specific cleanup:

```javascript
removeSession(session) {
    if (session.isRemoving) return;
    session.isRemoving = true;

    console.log(`[SESSION] Removing session ${session.sessionId}`);

    if (session.disconnectTimer) {
        clearTimeout(session.disconnectTimer);
    }

    // Close emulator using adapter
    if (session.emulator) {
        session.emulator.close();
    }

    // Close WebSocket
    if (session.ws && session.ws.readyState === WebSocket.OPEN) {
        session.ws.close();
    }

    // Release port (DOSBox only)
    if (session.tcpPort) {
        this.portPool.release(session.tcpPort);
        this.sessionsByPort.delete(session.tcpPort);
        this.pendingDosBoxConnections.delete(session.tcpPort);
    }

    // Remove from maps
    if (session.sessionData && session.sessionData.ws_token) {
        this.sessionsByToken.delete(session.sessionData.ws_token);
    }

    this.cleanupSessionFiles(session);
    this.deleteSession(session.sessionId);

    const uptime = Math.floor((Date.now() - session.startTime) / 1000);
    console.log(`[SESSION] Stats - ${session.sessionId}: Uptime ${uptime}s, From Emulator: ${session.bytesFromEmulator}, To Emulator: ${session.bytesToEmulator}`);
}
```

### 4. Remove Old Methods

Delete these methods (now in adapters):
- `generateDosBoxConfig()`
- `launchDosBox()`
- `findDosBoxExecutable()`

Keep `generateDoorSys()` as it's used by both adapters.

## Testing

1. Install node-pty: `cd scripts/dosbox-bridge && npm install`
2. Restart bridge: `pkill -f multiplexing-server && node scripts/dosbox-bridge/multiplexing-server.js &`
3. Test with DOSBox: Should work as before
4. Test with DOSEMU: Set `DOOR_EMULATOR=dosemu` in .env
5. Check memory: DOSEMU should use ~7-10MB vs DOSBox ~180MB
