(function() {
    const state = {
        currentUserKeys: null,
        currentUserPrivateKey: null,
        currentUserPrivateKeyFingerprint: null,
        publicKeyCache: new Map(),
        privateKeyCache: new Map()
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
        state.currentUserPrivateKey = null;
        state.currentUserPrivateKeyFingerprint = null;
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

    async function getCurrentUserPrivateKeyInfo(forceReload = false) {
        const keys = await loadCurrentUserKeys(forceReload);
        const row = getPreferredPrivateKeyRow(keys);
        if (!row) {
            return null;
        }

        if (state.currentUserPrivateKey && state.currentUserPrivateKeyFingerprint === row.fingerprint && !forceReload) {
            return {
                keyRow: row,
                privateKey: state.currentUserPrivateKey
            };
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

    async function getCurrentUserDecryptedPrivateKey(passphrase, forceReload = false) {
        const info = await getCurrentUserPrivateKeyInfo(forceReload);
        if (!info || !info.encryptedPrivateKey) {
            return null;
        }

        const cacheKey = String(info.keyRow.fingerprint || '').toUpperCase() + '|' + String(passphrase || '');
        if (state.currentUserPrivateKey && state.currentUserPrivateKeyFingerprint === cacheKey && !forceReload) {
            return state.currentUserPrivateKey;
        }

        const privateKey = await window.openpgp.readPrivateKey({ armoredKey: info.encryptedPrivateKey });
        const decryptedKey = await window.openpgp.decryptKey({
            privateKey: privateKey,
            passphrase: passphrase
        });

        state.currentUserPrivateKey = decryptedKey;
        state.currentUserPrivateKeyFingerprint = cacheKey;
        return decryptedKey;
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

    async function decryptMessage(armoredText, passphrase) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const keyInfo = await getCurrentUserPrivateKeyInfo(false);
        if (!keyInfo) {
            throw new Error('No stored private key is available.');
        }

        const privateKey = await getCurrentUserDecryptedPrivateKey(passphrase, false);
        const message = await window.openpgp.readMessage({ armoredMessage: String(armoredText || '') });
        const decrypted = await window.openpgp.decrypt({
            message: message,
            decryptionKeys: [privateKey],
            format: 'utf8'
        });

        return decrypted.data || '';
    }

    async function verifySignedMessage(armoredText, senderSearch) {
        if (!hasOpenPgp()) {
            throw new Error('OpenPGP.js is unavailable.');
        }

        const publicKeyArmor = await fetchPublicKeyArmor(senderSearch);
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
        fetchPublicKeyCandidates: fetchPublicKeyCandidates,
        isCleartextSigned: isCleartextSigned,
        isEncryptedMessage: isEncryptedMessage,
        signCleartextMessage: signCleartextMessage,
        encryptMessage: encryptMessage,
        decryptMessage: decryptMessage,
        verifySignedMessage: verifySignedMessage
    };
})();
