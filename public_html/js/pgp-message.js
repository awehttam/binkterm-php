(function() {
    const state = {
        currentUserKeys: null,
        publicKeyCache: new Map(),
        privateKeyCache: new Map(),
        decryptedPrivateKeyCache: new Map(),
        parsedPublicKeyCache: new Map()
    };

    function hasOpenPgp() {
        return !!(window.openpgp && typeof window.openpgp.readKey === 'function');
    }

    function fetchJson(url, options = {}) {
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: Object.assign({
                'X-Requested-With': 'XMLHttpRequest'
            }, options.headers || {})
        }, options)).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        });
    }

    function fetchText(url, options = {}) {
        return fetch(url, Object.assign({
            credentials: 'same-origin',
            headers: Object.assign({
                'X-Requested-With': 'XMLHttpRequest'
            }, options.headers || {})
        }, options)).then(function(response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.text();
        });
    }

    function normalizeKeysResponse(data) {
        return Array.isArray(data && data.keys) ? data.keys : [];
    }

    function normalizePublicKeySearchResponse(text) {
        const results = [];
        const lines = String(text || '').split(/\r?\n/);
        let current = null;

        for (const rawLine of lines) {
            const line = String(rawLine || '').trim();
            if (!line) {
                continue;
            }
            if (line.indexOf('pub:') === 0) {
                const parts = line.split(':');
                current = {
                    fingerprint: parts[1] || '',
                    key_algorithm: parts[2] || '',
                    key_created_at: parts[3] || '',
                    username: parts[4] || '',
                    user_id_string: ''
                };
                results.push(current);
                continue;
            }
            if (line.indexOf('uid:') === 0 && current) {
                current.user_id_string = line.substring(4).trim();
            }
        }

        return results.filter(function(entry) {
            return !!entry.fingerprint;
        });
    }

    async function loadCurrentUserKeys(forceReload = false) {
        if (state.currentUserKeys && !forceReload) {
            return state.currentUserKeys;
        }

        const data = await fetchJson('/api/user/pgp/keys');
        state.currentUserKeys = normalizeKeysResponse(data);
        state.privateKeyCache.clear();
        state.decryptedPrivateKeyCache.clear();
        state.parsedPublicKeyCache.clear();
        return state.currentUserKeys;
    }

    function getPreferredPrivateKeyRow(keys) {
        if (!Array.isArray(keys) || keys.length === 0) {
            return null;
        }
        return keys.find(function(key) {
            return !!key.has_private_key && !!key.is_primary;
        }) || keys.find(function(key) {
            return !!key.has_private_key;
        }) || null;
    }

    function getManagedPrivateKeyRows(keys) {
        if (!Array.isArray(keys) || keys.length === 0) {
            return [];
        }

        return keys.filter(function(key) {
            return !!key.has_private_key;
        });
    }

    async function getEncryptedPrivateKeyInfoForRow(row) {
        if (!row || !row.fingerprint) {
            return null;
        }

        const cacheKey = String(row.fingerprint || '').toUpperCase();
        let encrypted = state.privateKeyCache.get(cacheKey);
        if (!encrypted) {
            const data = await fetchJson('/api/user/pgp/private-key/' + encodeURIComponent(row.fingerprint));
            encrypted = (data && data.encrypted_private_key) ? String(data.encrypted_private_key) : '';
            if (!encrypted) {
                return null;
            }
            state.privateKeyCache.set(cacheKey, encrypted);
        }

        return {
            keyRow: row,
            encryptedPrivateKey: encrypted
        };
    }

    async function getCurrentUserPrivateKeyInfo(forceReload = false) {
        const keys = await loadCurrentUserKeys(forceReload);
        const row = getPreferredPrivateKeyRow(keys);
        if (!row) {
            return null;
        }

        return getEncryptedPrivateKeyInfoForRow(row);
    }

    async function getDecryptedPrivateKeyForRow(row, passphrase, forceReload = false) {
        const info = await getEncryptedPrivateKeyInfoForRow(row);
        if (!info || !info.encryptedPrivateKey) {
            return null;
        }

        const cacheKey = String(info.keyRow.fingerprint || '').toUpperCase() + '|' + String(passphrase || '');
        if (state.decryptedPrivateKeyCache.has(cacheKey) && !forceReload) {
            return state.decryptedPrivateKeyCache.get(cacheKey);
        }

        const privateKey = await window.openpgp.readPrivateKey({ armoredKey: info.encryptedPrivateKey });
        const decryptedKey = await window.openpgp.decryptKey({
            privateKey: privateKey,
            passphrase: passphrase
        });

        state.decryptedPrivateKeyCache.set(cacheKey, decryptedKey);
        return decryptedKey;
    }

    async function getCurrentUserDecryptedPrivateKey(passphrase, forceReload = false) {
        const info = await getCurrentUserPrivateKeyInfo(forceReload);
        if (!info || !info.keyRow) {
            return null;
        }

        return getDecryptedPrivateKeyForRow(info.keyRow, passphrase, forceReload);
    }

    function normalizeKeyId(value) {
        if (!value) {
            return '';
        }
        if (typeof value.toHex === 'function') {
            value = value.toHex();
        }
        return String(value || '').trim().toUpperCase().replace(/^0X/, '');
    }

    function collectKeyIdsFromKeyLike(keyLike, ids) {
        if (!keyLike || !ids) {
            return;
        }

        if (typeof keyLike.getKeyID === 'function') {
            const keyId = normalizeKeyId(keyLike.getKeyID());
            if (keyId) {
                ids.add(keyId);
            }
        }

        if (typeof keyLike.getKeyIDs === 'function') {
            const keyIds = keyLike.getKeyIDs();
            if (Array.isArray(keyIds)) {
                keyIds.forEach(function(keyId) {
                    keyId = normalizeKeyId(keyId);
                    if (keyId) {
                        ids.add(keyId);
                    }
                });
            }
        }

        if (keyLike.keyPacket && typeof keyLike.keyPacket.getKeyID === 'function') {
            const packetKeyId = normalizeKeyId(keyLike.keyPacket.getKeyID());
            if (packetKeyId) {
                ids.add(packetKeyId);
            }
        }
    }

    function collectKeyIdentifiers(key) {
        const ids = new Set();
        if (!key) {
            return ids;
        }

        if (typeof key.getEncryptionKeyIDs === 'function') {
            const encryptionKeyIds = key.getEncryptionKeyIDs();
            if (Array.isArray(encryptionKeyIds)) {
                encryptionKeyIds.forEach(function(keyId) {
                    keyId = normalizeKeyId(keyId);
                    if (keyId) {
                        ids.add(keyId);
                    }
                });
            }
        }

        collectKeyIdsFromKeyLike(key, ids);

        if (typeof key.getKeys === 'function') {
            const subkeys = key.getKeys();
            if (Array.isArray(subkeys)) {
                subkeys.forEach(function(subkey) {
                    collectKeyIdsFromKeyLike(subkey, ids);
                });
            }
        }

        if (typeof key.getFingerprint === 'function') {
            const fingerprint = normalizeKeyId(key.getFingerprint());
            if (fingerprint) {
                ids.add(fingerprint);
                if (fingerprint.length >= 16) {
                    ids.add(fingerprint.slice(-16));
                }
            }
        }

        return ids;
    }

    function getMessageEncryptionKeyIds(message) {
        const ids = new Set();
        if (!message || typeof message.getEncryptionKeyIDs !== 'function') {
            return ids;
        }

        const messageKeyIds = message.getEncryptionKeyIDs();
        if (!Array.isArray(messageKeyIds)) {
            return ids;
        }

        messageKeyIds.forEach(function(keyId) {
            keyId = normalizeKeyId(keyId);
            if (keyId) {
                ids.add(keyId);
            }
        });

        return ids;
    }

    async function getParsedPublicKeyForRow(row) {
        if (!row || !row.fingerprint || !row.armored_public_key) {
            return null;
        }

        const cacheKey = String(row.fingerprint || '').toUpperCase();
        if (state.parsedPublicKeyCache.has(cacheKey)) {
            return state.parsedPublicKeyCache.get(cacheKey);
        }

        const parsedKey = await window.openpgp.readKey({ armoredKey: String(row.armored_public_key) });
        state.parsedPublicKeyCache.set(cacheKey, parsedKey);
        return parsedKey;
    }

    async function findCandidatePrivateKeyRowsForMessage(armoredText, forceReload = false) {
        const message = await window.openpgp.readMessage({ armoredMessage: String(armoredText || '') });
        const allRows = getManagedPrivateKeyRows(await loadCurrentUserKeys(forceReload));
        const messageKeyIds = getMessageEncryptionKeyIds(message);

        if (allRows.length <= 1 || messageKeyIds.size === 0) {
            return {
                message: message,
                rows: allRows
            };
        }

        const matchedRows = [];
        const unmatchedRows = [];

        for (const row of allRows) {
            try {
                const publicKey = await getParsedPublicKeyForRow(row);
                const rowKeyIds = collectKeyIdentifiers(publicKey);
                const hasMatch = Array.from(messageKeyIds).some(function(messageKeyId) {
                    return rowKeyIds.has(messageKeyId);
                });

                if (hasMatch) {
                    matchedRows.push(row);
                } else {
                    unmatchedRows.push(row);
                }
            } catch (error) {
                unmatchedRows.push(row);
            }
        }

        return {
            message: message,
            rows: matchedRows.length > 0 ? matchedRows.concat(unmatchedRows) : allRows
        };
    }

    async function fetchPublicKeyArmor(search) {
        const normalizedSearch = String(search || '').trim();
        if (!normalizedSearch) {
            return null;
        }

        const cacheKey = normalizedSearch.toLowerCase();
        if (state.publicKeyCache.has(cacheKey)) {
            return state.publicKeyCache.get(cacheKey);
        }

        const response = await fetchText('/pks/lookup?op=get&search=' + encodeURIComponent(normalizedSearch));
        if (!response || response.indexOf('BEGIN PGP PUBLIC KEY BLOCK') === -1) {
            return null;
        }

        state.publicKeyCache.set(cacheKey, response);
        return response;
    }

    async function fetchVerificationPublicKeyArmor(search, senderAddress) {
        const normalizedSearch = String(search || '').trim();
        const normalizedAddress = String(senderAddress || '').trim();
        if (!normalizedSearch) {
            return null;
        }

        const cacheKey = 'verify-key:' + normalizedAddress.toLowerCase() + ':' + normalizedSearch.toLowerCase();
        if (state.publicKeyCache.has(cacheKey)) {
            return state.publicKeyCache.get(cacheKey);
        }

        const data = await fetchJson('/api/pgp/lookup?op=get&mode=verify&search='
            + encodeURIComponent(normalizedSearch)
            + '&address=' + encodeURIComponent(normalizedAddress));
        const armored = (data && data.key && data.key.armored_public_key)
            ? String(data.key.armored_public_key)
            : '';
        if (!armored || armored.indexOf('BEGIN PGP PUBLIC KEY BLOCK') === -1) {
            return null;
        }

        state.publicKeyCache.set(cacheKey, armored);
        return armored;
    }

    async function fetchPublicKeyCandidates(search) {
        const normalizedSearch = String(search || '').trim();
        if (!normalizedSearch) {
            return [];
        }

        const cacheKey = 'search:' + normalizedSearch.toLowerCase();
        if (state.publicKeyCache.has(cacheKey)) {
            return state.publicKeyCache.get(cacheKey);
        }

        const response = await fetchText('/pks/lookup?search=' + encodeURIComponent(normalizedSearch));
        const candidates = normalizePublicKeySearchResponse(response);
        state.publicKeyCache.set(cacheKey, candidates);
        return candidates;
    }

    async function fetchComposePublicKeyCandidates(search, destinationAddress) {
        const normalizedSearch = String(search || '').trim();
        const normalizedAddress = String(destinationAddress || '').trim();
        if (!normalizedSearch) {
            return [];
        }

        const cacheKey = 'compose-search:' + normalizedAddress.toLowerCase() + ':' + normalizedSearch.toLowerCase();
        if (state.publicKeyCache.has(cacheKey)) {
            return state.publicKeyCache.get(cacheKey);
        }

        const data = await fetchJson('/api/pgp/lookup?search='
            + encodeURIComponent(normalizedSearch)
            + '&address=' + encodeURIComponent(normalizedAddress));
        const candidates = Array.isArray(data && data.keys) ? data.keys : [];
        state.publicKeyCache.set(cacheKey, candidates);
        return candidates;
    }

    async function fetchComposePublicKeyArmor(search, destinationAddress) {
        const normalizedSearch = String(search || '').trim();
        const normalizedAddress = String(destinationAddress || '').trim();
        if (!normalizedSearch) {
            return null;
        }

        const cacheKey = 'compose-key:' + normalizedAddress.toLowerCase() + ':' + normalizedSearch.toLowerCase();
        if (state.publicKeyCache.has(cacheKey)) {
            return state.publicKeyCache.get(cacheKey);
        }

        const data = await fetchJson('/api/pgp/lookup?op=get&search='
            + encodeURIComponent(normalizedSearch)
            + '&address=' + encodeURIComponent(normalizedAddress));
        const armored = (data && data.key && data.key.armored_public_key)
            ? String(data.key.armored_public_key)
            : '';
        if (!armored || armored.indexOf('BEGIN PGP PUBLIC KEY BLOCK') === -1) {
            return null;
        }

        state.publicKeyCache.set(cacheKey, armored);
        return armored;
    }

    function isCleartextSigned(text) {
        return /-----BEGIN PGP SIGNED MESSAGE-----/i.test(String(text || ''));
    }

    function isEncryptedMessage(text) {
        return /-----BEGIN PGP MESSAGE-----/i.test(String(text || ''));
    }

    async function signCleartextMessage(text, passphrase, signerSearch) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const keyInfo = await getCurrentUserPrivateKeyInfo(false);
        if (!keyInfo) {
            throw new Error('No stored private key is available.');
        }

        const privateKey = await getCurrentUserDecryptedPrivateKey(passphrase, false);
        const cleartext = await window.openpgp.createCleartextMessage({ text: String(text || '') });
        return await window.openpgp.sign({
            message: cleartext,
            signingKeys: [privateKey],
            format: 'armored'
        });
    }

    async function encryptMessage(text, recipientSearch) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const publicKeyArmor = await fetchPublicKeyArmor(recipientSearch);
        if (!publicKeyArmor) {
            throw new Error('Recipient public key not found.');
        }

        const publicKey = await window.openpgp.readKey({ armoredKey: publicKeyArmor });
        const message = await window.openpgp.createMessage({ text: String(text || '') });
        return await window.openpgp.encrypt({
            message: message,
            encryptionKeys: [publicKey],
            format: 'armored'
        });
    }

    async function encryptMessageForDestination(text, recipientSearch, destinationAddress) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const publicKeyArmor = await fetchComposePublicKeyArmor(recipientSearch, destinationAddress);
        if (!publicKeyArmor) {
            throw new Error('Recipient public key not found.');
        }

        const publicKey = await window.openpgp.readKey({ armoredKey: publicKeyArmor });
        const message = await window.openpgp.createMessage({ text: String(text || '') });
        return await window.openpgp.encrypt({
            message: message,
            encryptionKeys: [publicKey],
            format: 'armored'
        });
    }

    async function decryptMessage(armoredText, passphrase) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const keys = getManagedPrivateKeyRows(await loadCurrentUserKeys(false));
        if (!keys.length) {
            throw new Error('No stored private key is available.');
        }

        const selection = await findCandidatePrivateKeyRowsForMessage(armoredText, false);
        let lastError = null;

        for (const row of selection.rows) {
            try {
                const privateKey = await getDecryptedPrivateKeyForRow(row, passphrase, false);
                if (!privateKey) {
                    continue;
                }

                const decrypted = await window.openpgp.decrypt({
                    message: selection.message,
                    decryptionKeys: [privateKey],
                    format: 'utf8'
                });

                return decrypted.data || '';
            } catch (error) {
                lastError = error;
            }
        }

        throw lastError || new Error('No stored private key is available.');
    }

    async function verifySignedMessage(armoredText, senderSearch, senderAddress) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const publicKeyArmor = await fetchVerificationPublicKeyArmor(senderSearch, senderAddress);
        if (!publicKeyArmor) {
            return {
                verified: false,
                reason: 'public_key_missing'
            };
        }

        const publicKey = await window.openpgp.readKey({ armoredKey: publicKeyArmor });
        const cleartext = await window.openpgp.readCleartextMessage({ cleartextMessage: String(armoredText || '') });
        const verification = await window.openpgp.verify({
            message: cleartext,
            verificationKeys: [publicKey]
        });

        try {
            await Promise.all((verification.signatures || []).map(function(signature) {
                return signature.verified;
            }));
            return {
                verified: true,
                text: cleartext.getText ? cleartext.getText() : String(armoredText || '')
            };
        } catch (error) {
            return {
                verified: false,
                reason: 'invalid_signature'
            };
        }
    }

    window.PgpMessageSupport = {
        hasOpenPgp: hasOpenPgp,
        loadCurrentUserKeys: loadCurrentUserKeys,
        getCurrentUserPrivateKeyInfo: getCurrentUserPrivateKeyInfo,
        getCurrentUserDecryptedPrivateKey: getCurrentUserDecryptedPrivateKey,
        fetchPublicKeyArmor: fetchPublicKeyArmor,
        fetchVerificationPublicKeyArmor: fetchVerificationPublicKeyArmor,
        fetchPublicKeyCandidates: fetchPublicKeyCandidates,
        fetchComposePublicKeyCandidates: fetchComposePublicKeyCandidates,
        fetchComposePublicKeyArmor: fetchComposePublicKeyArmor,
        isCleartextSigned: isCleartextSigned,
        isEncryptedMessage: isEncryptedMessage,
        signCleartextMessage: signCleartextMessage,
        encryptMessage: encryptMessage,
        encryptMessageForDestination: encryptMessageForDestination,
        decryptMessage: decryptMessage,
        verifySignedMessage: verifySignedMessage
    };
})();
