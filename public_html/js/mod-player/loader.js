import { Mod } from './mod.js';

export const loadMod = async (url) => {
    const response = await fetch(url, { credentials: 'same-origin' });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const arrayBuffer = await response.arrayBuffer();
    const contentLength = response.headers.get('Content-Length');
    if (contentLength !== null && parseInt(contentLength, 10) !== arrayBuffer.byteLength) {
        throw new Error(`Incomplete MOD download: expected ${contentLength} bytes, got ${arrayBuffer.byteLength}`);
    }

    try {
        const mod = new Mod(arrayBuffer);
        mod.sourceInfo = {
            url,
            contentLength: contentLength !== null ? parseInt(contentLength, 10) : null,
            zipMethod: response.headers.get('X-Zip-Entry-Method'),
            zipExpectedSize: response.headers.get('X-Zip-Entry-Expected-Size'),
            zipActualSize: response.headers.get('X-Zip-Entry-Actual-Size'),
            zipSource: response.headers.get('X-Zip-Entry-Source'),
        };
        return mod;
    } catch (error) {
        error.modLoadInfo = {
            url,
            bytesReceived: arrayBuffer.byteLength,
            contentLength: contentLength !== null ? parseInt(contentLength, 10) : null,
            zipMethod: response.headers.get('X-Zip-Entry-Method'),
            zipExpectedSize: response.headers.get('X-Zip-Entry-Expected-Size'),
            zipActualSize: response.headers.get('X-Zip-Entry-Actual-Size'),
            zipSource: response.headers.get('X-Zip-Entry-Source'),
        };
        throw error;
    }
};
