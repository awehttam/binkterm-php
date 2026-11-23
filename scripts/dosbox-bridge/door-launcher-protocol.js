'use strict';

/**
 * Wire protocol between the bridge and a door launcher (socket strategy).
 *
 * Every message is one frame: 1 byte type, 4 byte big-endian payload length,
 * then the payload. Control messages carry JSON; DATA and OUTPUT carry raw
 * terminal bytes. Resize and exit status travel in-band so one connection is
 * all a session needs.
 *
 * See docs/proposals/NativeDoorHardening.md.
 */

/** Frame types sent by the bridge. */
const TO_LAUNCHER = {
    HELLO: 'H',
    SPAWN: 'S',
    DATA: 'D',
    RESIZE: 'R',
    KILL: 'K',
    PROBE: 'P'
};

/** Frame types sent by the launcher. */
const FROM_LAUNCHER = {
    READY: 'r',
    SPAWNED: 's',
    OUTPUT: 'd',
    EXIT: 'x',
    ERROR: 'e',
    PROBED: 'p'
};

const MAX_FRAME_BYTES = 1024 * 1024;
const HEADER_BYTES = 5;

/**
 * @param {string} type One-character frame type
 * @param {Buffer|string} [payload]
 * @returns {Buffer}
 */
function encodeFrame(type, payload) {
    const body = Buffer.isBuffer(payload) ? payload : Buffer.from(payload || '', 'utf8');
    if (body.length > MAX_FRAME_BYTES) {
        throw new Error('Frame too large');
    }
    const frame = Buffer.allocUnsafe(HEADER_BYTES + body.length);
    frame.write(type, 0, 1, 'latin1');
    frame.writeUInt32BE(body.length, 1);
    body.copy(frame, HEADER_BYTES);
    return frame;
}

/**
 * @param {string} type
 * @param {Object} obj
 * @returns {Buffer}
 */
function encodeJsonFrame(type, obj) {
    return encodeFrame(type, JSON.stringify(obj));
}

/**
 * Incremental frame decoder. Feed it socket chunks; it returns whole frames.
 */
class FrameDecoder {
    constructor() {
        this.buffer = Buffer.alloc(0);
    }

    /**
     * @param {Buffer} chunk
     * @returns {{type: string, payload: Buffer}[]}
     * @throws {Error} On an oversized frame (the connection must be dropped)
     */
    push(chunk) {
        this.buffer = this.buffer.length === 0 ? chunk : Buffer.concat([this.buffer, chunk]);
        const frames = [];
        while (this.buffer.length >= HEADER_BYTES) {
            const length = this.buffer.readUInt32BE(1);
            if (length > MAX_FRAME_BYTES) {
                throw new Error('Frame too large');
            }
            if (this.buffer.length < HEADER_BYTES + length) {
                break;
            }
            frames.push({
                type: String.fromCharCode(this.buffer[0]),
                payload: this.buffer.subarray(HEADER_BYTES, HEADER_BYTES + length)
            });
            this.buffer = this.buffer.subarray(HEADER_BYTES + length);
        }
        return frames;
    }
}

/**
 * @param {Buffer} payload
 * @returns {Object|null} Parsed JSON object, or null if it is not one
 */
function parseJsonPayload(payload) {
    try {
        const value = JSON.parse(payload.toString('utf8'));
        return value && typeof value === 'object' && !Array.isArray(value) ? value : null;
    } catch (_) {
        return null;
    }
}

module.exports = {
    TO_LAUNCHER,
    FROM_LAUNCHER,
    MAX_FRAME_BYTES,
    encodeFrame,
    encodeJsonFrame,
    FrameDecoder,
    parseJsonPayload
};
