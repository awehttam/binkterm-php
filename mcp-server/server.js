/**
 * BinktermPHP MCP Server
 *
 * Provides read-only access to the echomail and echoareas tables via the
 * Model Context Protocol. Requires a valid registered license.
 *
 * Authentication: each user generates a personal bearer key stored in the
 * users_meta table under keyname 'mcp_serverkey'. The requesting user's
 * echoarea permissions are enforced on every query (sysop-only areas are
 * hidden for non-admin users; inactive areas are hidden for all users).
 *
 * Configuration is read from the main BinktermPHP .env file (one level up).
 * DB_PASS is used for the database password (not DB_PASSWORD).
 */

import crypto from 'crypto';
import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { spawn } from 'child_process';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR  = path.resolve(__dirname, '..');

// ---------------------------------------------------------------------------
// CLI args  --pid-file=<path>  --bind=<host>  --help
// ---------------------------------------------------------------------------

const USAGE = `
BinktermPHP MCP Server

Usage: node mcp-server/server.js [options]

Options:
  --bind=<host>       IP address or hostname to listen on (default: all interfaces)
                      Use 127.0.0.1 when running behind a reverse proxy.
  --pid-file=<path>   Write the server PID to this file on startup.
  --daemon            Fork to the background, redirect output to the log file,
                      and exit the parent process. Useful for boot scripts and
                      cron @reboot entries. Not needed when started via
                      restart_daemons.sh (which already handles detaching).
  --help              Show this help message and exit.

Configuration is read from the main BinktermPHP .env file (one level up).
The server requires a valid registered license in data/license.json.

Environment variables (from .env):
  MCP_SERVER_PORT     Port to listen on (default: 3740; MCP_PORT also accepted)
  MCP_BIND_HOST       Default bind host (overridden by --bind)
  DB_HOST             PostgreSQL host (default: localhost)
  DB_PORT             PostgreSQL port (default: 5432)
  DB_NAME             PostgreSQL database name
  DB_USER             PostgreSQL username
  DB_PASS             PostgreSQL password
  DB_SSLMODE          Set to any value to enable SSL for the DB connection
  LICENSE_FILE        Path to license.json (default: data/license.json)
`.trim();

if (process.argv.includes('--help') || process.argv.includes('-h')) {
    console.log(USAGE);
    process.exit(0);
}

let pidFilePath = null;
let bindHost    = null;
let daemonMode  = false;
for (const arg of process.argv.slice(2)) {
    let m;
    if ((m = arg.match(/^--pid-file=(.+)$/)))  pidFilePath = m[1];
    if ((m = arg.match(/^--bind=(.+)$/)))       bindHost    = m[1];
    if (arg === '--daemon')                      daemonMode  = true;
}

// ---------------------------------------------------------------------------
// Load main .env file
// ---------------------------------------------------------------------------

function loadDotEnv(filePath) {
    try {
        const lines = fs.readFileSync(filePath, 'utf8').split('\n');
        for (const raw of lines) {
            const line = raw.trim();
            if (!line || line.startsWith('#')) continue;
            const eqIdx = line.indexOf('=');
            if (eqIdx < 0) continue;
            const key = line.slice(0, eqIdx).trim();
            let val   = line.slice(eqIdx + 1).trim();
            // Strip surrounding quotes
            if ((val.startsWith('"') && val.endsWith('"')) || (val.startsWith("'") && val.endsWith("'"))) {
                val = val.slice(1, -1);
            }
            if (!(key in process.env)) {
                process.env[key] = val;
            }
        }
    } catch (_) {
        // If .env is missing, rely on environment variables already set
    }
}

loadDotEnv(path.join(ROOT_DIR, '.env'));

// ---------------------------------------------------------------------------
// Daemon mode — fork to background before anything else starts
// ---------------------------------------------------------------------------

if (daemonMode) {
    const logPath = path.join(ROOT_DIR, 'data', 'logs', 'mcp-server.log');
    fs.mkdirSync(path.dirname(logPath), { recursive: true });
    const logFd     = fs.openSync(logPath, 'a');
    const childArgs = process.argv.slice(1).filter(a => a !== '--daemon');
    const child     = spawn(process.execPath, childArgs, {
        detached: true,
        stdio:    ['ignore', logFd, logFd],
        cwd:      ROOT_DIR,
    });
    child.unref();
    fs.closeSync(logFd);
    console.log(`[mcp-server] Daemon started (PID ${child.pid})`);
    process.exit(0);
}

// ---------------------------------------------------------------------------
// Logging
// ---------------------------------------------------------------------------

const LOG_FILE = path.join(ROOT_DIR, 'data', 'logs', 'mcp-server.log');

function ensureLogDir() {
    const dir = path.dirname(LOG_FILE);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
}

ensureLogDir();

function log(level, ...args) {
    const ts  = new Date().toISOString();
    const msg = `[${ts}] [${level}] ${args.join(' ')}`;
    console.log(msg);
    try {
        fs.appendFileSync(LOG_FILE, msg + '\n');
    } catch (_) {}
}

const logger = {
    info:  (...a) => log('INFO',  ...a),
    warn:  (...a) => log('WARN',  ...a),
    error: (...a) => log('ERROR', ...a),
};

// ---------------------------------------------------------------------------
// License verification
// ---------------------------------------------------------------------------

/** Ed25519 public key (base64-encoded, 32 bytes) — must match src/License.php */
const PUBLIC_KEY_BASE64 = 'fopFI+s+0lx8Kyvs4THMz22sHm6ovbV72zJcQGuGr4k=';

function verifyLicense() {
    const licenseRelPath = process.env.LICENSE_FILE ?? 'data/license.json';
    const resolvedPath   = path.isAbsolute(licenseRelPath)
        ? licenseRelPath
        : path.join(ROOT_DIR, licenseRelPath);

    let data;
    try {
        data = JSON.parse(fs.readFileSync(resolvedPath, 'utf8'));
    } catch (_) {
        logger.error('License file not found or unreadable:', resolvedPath);
        return false;
    }

    if (!data || !data.payload || typeof data.signature !== 'string') {
        logger.error('License file malformed.');
        return false;
    }

    const payload     = data.payload;
    // JSON.stringify produces the same output as PHP json_encode with
    // JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE for this payload.
    const payloadJson = JSON.stringify(payload);

    let sig, keyObject;
    try {
        sig = Buffer.from(data.signature, 'base64');
        // Node.js crypto requires Ed25519 keys in SPKI DER format.
        // Prepend the 12-byte SPKI header for Ed25519 to the raw 32-byte key.
        const rawKey   = Buffer.from(PUBLIC_KEY_BASE64, 'base64');
        const spkiHeader = Buffer.from('302a300506032b6570032100', 'hex');
        const derKey   = Buffer.concat([spkiHeader, rawKey]);
        keyObject = crypto.createPublicKey({ key: derKey, format: 'der', type: 'spki' });
    } catch (_) {
        logger.error('License key or signature could not be decoded.');
        return false;
    }

    let ok = false;
    try {
        ok = crypto.verify(null, Buffer.from(payloadJson), keyObject, sig);
    } catch (e) {
        logger.error('License signature verification error:', e.message);
        return false;
    }

    if (!ok) {
        logger.error('License signature invalid.');
        return false;
    }

    if (!['registered', 'sponsor'].includes(payload.tier)) {
        logger.error(`License tier '${payload.tier}' is insufficient (need registered or sponsor).`);
        return false;
    }

    if (payload.expires_at) {
        if (new Date(payload.expires_at) < new Date()) {
            logger.error('License has expired.');
            return false;
        }
    }

    logger.info(`License OK — licensee: ${payload.licensee}, tier: ${payload.tier}`);
    return true;
}

if (!verifyLicense()) {
    logger.error('MCP server requires a valid registered license. Exiting.');
    process.exit(1);
}

// ---------------------------------------------------------------------------
// Deferred imports (after license check so startup failures are early)
// ---------------------------------------------------------------------------

import express from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import pg from 'pg';
import { z } from 'zod';

const { Pool } = pg;

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const PORT = parseInt(process.env.MCP_SERVER_PORT ?? process.env.MCP_PORT ?? '3740', 10);
const BIND = bindHost ?? process.env.MCP_BIND_HOST ?? undefined;  // undefined = listen on all interfaces

// ---------------------------------------------------------------------------
// PostgreSQL pool — reads DB_* from main .env; uses DB_PASS (not DB_PASSWORD)
// ---------------------------------------------------------------------------

const pool = new Pool({
    host:     process.env.DB_HOST ?? 'localhost',
    port:     parseInt(process.env.DB_PORT ?? '5432', 10),
    database: process.env.DB_NAME ?? 'binktest',
    user:     process.env.DB_USER,
    password: process.env.DB_PASS,   // main .env uses DB_PASS
    ssl:      process.env.DB_SSLMODE ? { rejectUnauthorized: false } : false,
    max:      5,
});

pool.on('error', (err) => {
    logger.error('[DB] Unexpected pool error:', err.message);
});

// ---------------------------------------------------------------------------
// Encoding-safe query helper
// ---------------------------------------------------------------------------

/**
 * Run a query that might touch text columns with invalid UTF-8 byte sequences.
 *
 * PostgreSQL validates UTF-8 encoding when performing string operations such as
 * ILIKE. If a stored message contains a corrupted byte sequence the query will
 * fail with "invalid byte sequence for encoding UTF8".  On that specific error
 * we retry the query on a dedicated client with `client_encoding = 'SQL_ASCII'`,
 * which instructs PostgreSQL to skip encoding validation and treat the bytes as
 * opaque data.  The dedicated client is destroyed after use so it is never
 * returned to the pool with altered session settings.
 *
 * @param {string}  sql
 * @param {Array}   [params]
 * @returns {Promise<import('pg').QueryResult>}
 */
async function queryWithEncodingFallback(sql, params = []) {
    try {
        return await pool.query(sql, params);
    } catch (e) {
        if (!e.message?.includes('invalid byte sequence')) {
            logger.error('DB query error:', e.message);
            throw e;
        }
        logger.warn('Encoding error — retrying query with SQL_ASCII client encoding');
        const client = await pool.connect();
        try {
            await client.query("SET client_encoding TO 'SQL_ASCII'");
            return await client.query(sql, params);
        } catch (e2) {
            logger.error('DB query error (SQL_ASCII fallback):', e2.message);
            throw e2;
        } finally {
            // Destroy this connection; do not return it to the pool with altered encoding.
            client.release(true);
        }
    }
}

// ---------------------------------------------------------------------------
// Auth middleware — resolves bearer key against users_meta table
// ---------------------------------------------------------------------------

/**
 * @typedef {{ userId: number, isAdmin: boolean }} UserCtx
 */

/**
 * Resolve a bearer token to a user context.
 * Returns null and sends 401/500 if the token is missing or unknown.
 *
 * @param {import('express').Request}  req
 * @param {import('express').Response} res
 * @returns {Promise<UserCtx|null>}
 */
async function resolveUser(req, res) {
    const authHeader   = req.headers['authorization'] ?? '';
    const apiKeyHeader = req.headers['x-api-key'] ?? '';

    let token = null;
    if (authHeader.toLowerCase().startsWith('bearer ')) {
        token = authHeader.slice(7).trim();
    } else if (apiKeyHeader) {
        token = apiKeyHeader.trim();
    }

    if (!token) {
        res.status(401).json({ error: 'Unauthorized' });
        return null;
    }

    let result;
    try {
        result = await pool.query(
            `SELECT um.user_id, u.is_admin
               FROM users_meta um
               JOIN users u ON u.id = um.user_id
              WHERE um.keyname = 'mcp_serverkey'
                AND um.valname = $1
              LIMIT 1`,
            [token]
        );
    } catch (e) {
        logger.error('Auth DB query error:', e.message);
        res.status(500).json({ error: 'Internal error' });
        return null;
    }

    if (result.rows.length === 0) {
        res.status(401).json({ error: 'Unauthorized' });
        return null;
    }

    const row = result.rows[0];
    return { userId: Number(row.user_id), isAdmin: !!row.is_admin };
}

// ---------------------------------------------------------------------------
// MCP server + tools
// ---------------------------------------------------------------------------

/**
 * Create a new MCP server instance scoped to the given user context.
 *
 * @param {UserCtx} userCtx
 * @returns {McpServer}
 */
function createServer(userCtx) {
    const server = new McpServer({
        name:    'mcp-server',
        version: '1.0.0',
    });

    // Sysop-only clause fragment — empty string for admin users
    const sysopClause       = userCtx.isAdmin ? '' : 'AND ea.is_sysop_only = FALSE';

    // --- list_echoareas -------------------------------------------------------

    server.tool(
        'list_echoareas',
        'List active echomail areas with their tags, descriptions, domains, and message counts.',
        {
            domain: z.string().optional().describe('Filter by network domain (e.g. "fidonet")'),
        },
        async ({ domain }) => {
            const conditions = [`ea.is_active = TRUE`];
            const params     = [];

            if (!userCtx.isAdmin) conditions.push('ea.is_sysop_only = FALSE');

            if (domain) {
                params.push(domain);
                conditions.push(`ea.domain = $${params.length}`);
            }

            const sql = `
                SELECT ea.tag, ea.domain, ea.description, ea.moderator,
                       ea.message_count, ea.is_active, ea.is_local, ea.is_sysop_only
                FROM echoareas ea
                WHERE ${conditions.join(' AND ')}
                ORDER BY ea.tag ASC
            `;
            const result = await queryWithEncodingFallback(sql, params);
            return { content: [{ type: 'text', text: JSON.stringify(result.rows, null, 2) }] };
        }
    );

    // --- get_echoarea ---------------------------------------------------------

    server.tool(
        'get_echoarea',
        'Get details about a specific echomail area by tag.',
        {
            tag:    z.string().describe('Echo area tag (e.g. "GENERAL")'),
            domain: z.string().optional().describe('Network domain to disambiguate areas with the same tag'),
        },
        async ({ tag, domain }) => {
            const params = [tag.toUpperCase()];
            let sql = `
                SELECT ea.tag, ea.domain, ea.description, ea.moderator, ea.uplink_address,
                       ea.message_count, ea.is_active, ea.is_local, ea.is_sysop_only,
                       ea.is_default_subscription, ea.created_at
                FROM echoareas ea
                WHERE ea.tag = $1
                  AND ea.is_active = TRUE
                  ${sysopClause}
            `;
            if (domain) {
                params.push(domain);
                sql += ` AND ea.domain = $${params.length}`;
            }
            sql += ' LIMIT 1';

            const result = await queryWithEncodingFallback(sql, params);
            if (result.rows.length === 0) {
                return { content: [{ type: 'text', text: `Echo area "${tag}" not found or access denied.` }] };
            }
            return { content: [{ type: 'text', text: JSON.stringify(result.rows[0], null, 2) }] };
        }
    );

    // --- get_messages ---------------------------------------------------------

    server.tool(
        'get_echomail_messages',
        'Get recent echomail messages from an echo area, with optional filters and pagination.',
        {
            tag:       z.string().describe('Echo area tag'),
            domain:    z.string().optional().describe('Network domain'),
            limit:     z.number().int().min(1).max(100).optional().describe('Number of messages to return (default: 25, max: 100)'),
            offset:    z.number().int().min(0).optional().describe('Pagination offset (default: 0)'),
            from_name: z.string().optional().describe('Filter by sender name (partial, case-insensitive)'),
            to_name:   z.string().optional().describe('Filter by recipient name (partial, case-insensitive)'),
            subject:   z.string().optional().describe('Filter by subject (partial, case-insensitive)'),
            since:     z.string().optional().describe('Only messages received after this ISO-8601 datetime'),
        },
        async ({ tag, domain, limit = 25, offset = 0, from_name, to_name, subject, since }) => {
            const areaParams = [tag.toUpperCase()];
            let areaSql = `
                SELECT ea.id FROM echoareas ea
                WHERE ea.tag = $1 AND ea.is_active = TRUE ${sysopClause}
            `;
            if (domain) { areaParams.push(domain); areaSql += ` AND ea.domain = $${areaParams.length}`; }

            const areaResult = await pool.query(areaSql, areaParams);
            if (areaResult.rows.length === 0) {
                return { content: [{ type: 'text', text: `Echo area "${tag}" not found or access denied.` }] };
            }
            const areaId = areaResult.rows[0].id;

            const conditions = ['em.echoarea_id = $1'];
            const params     = [areaId];

            if (from_name) { params.push(`%${from_name}%`); conditions.push(`encode(em.from_name::bytea, 'escape') ILIKE $${params.length}`); }
            if (to_name)   { params.push(`%${to_name}%`);   conditions.push(`encode(em.to_name::bytea,   'escape') ILIKE $${params.length}`); }
            if (subject)   { params.push(`%${subject}%`);   conditions.push(`encode(em.subject::bytea,   'escape') ILIKE $${params.length}`); }
            if (since)     { params.push(since);             conditions.push(`em.date_received >= $${params.length}`); }

            params.push(limit, offset);

            const sql = `
                SELECT em.id, em.from_address, em.from_name, em.to_name, em.subject,
                       to_char(em.date_written,  'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_written,
                       to_char(em.date_received, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_received,
                       em.message_id, em.origin_line,
                       LEFT(em.message_text, 500) AS message_preview
                FROM echomail em
                WHERE ${conditions.join(' AND ')}
                ORDER BY em.date_received DESC
                LIMIT $${params.length - 1}
                OFFSET $${params.length}
            `;
            const result = await queryWithEncodingFallback(sql, params);
            return { content: [{ type: 'text', text: JSON.stringify(result.rows, null, 2) }] };
        }
    );

    // --- get_message ----------------------------------------------------------

    server.tool(
        'get_echomail_message',
        'Get the full text of a single echomail message by its ID.',
        {
            id: z.number().int().positive().describe('Echomail message ID'),
        },
        async ({ id }) => {
            const sql = `
                SELECT em.id, em.from_address, em.from_name, em.to_name, em.subject,
                       to_char(em.date_written,  'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_written,
                       to_char(em.date_received, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_received,
                       em.message_id, em.reply_to_id, em.origin_line,
                       em.tearline_component, em.message_text, em.kludge_lines,
                       ea.tag AS echoarea_tag, ea.domain AS echoarea_domain
                FROM echomail em
                JOIN echoareas ea ON ea.id = em.echoarea_id
                WHERE em.id = $1
                  AND ea.is_active = TRUE
                  ${sysopClause}
            `;
            const result = await queryWithEncodingFallback(sql, [id]);
            if (result.rows.length === 0) {
                return { content: [{ type: 'text', text: `Message ID ${id} not found or access denied.` }] };
            }
            return { content: [{ type: 'text', text: JSON.stringify(result.rows[0], null, 2) }] };
        }
    );

    // --- search_echomail ------------------------------------------------------

    server.tool(
        'search_echomail',
        'Full-text search across all echomail messages. Searches subject, body, sender, and recipient.',
        {
            query:     z.string().min(2).describe('Search term (minimum 2 characters)'),
            tag:       z.string().optional().describe('Limit search to a specific echo area tag'),
            domain:    z.string().optional().describe('Limit search to a specific network domain'),
            from_name: z.string().optional().describe('Filter by sender name (partial, case-insensitive)'),
            since:     z.string().optional().describe('Only messages received after this ISO-8601 datetime'),
            limit:     z.number().int().min(1).max(50).optional().describe('Max results (default: 20, max: 50)'),
        },
        async ({ query, tag, domain, from_name, since, limit = 20 }) => {
            const conditions = ['ea.is_active = TRUE'];
            const params     = [];

            if (!userCtx.isAdmin) conditions.push('ea.is_sysop_only = FALSE');

            // Cast through bytea before ILIKE to avoid "invalid byte sequence" errors on
            // messages stored with corrupted encoding. encode(col::bytea, 'escape') produces
            // valid ASCII and matches ASCII search terms identically to plain ILIKE.
            params.push(`%${query}%`);
            conditions.push(
                `(encode(em.subject::bytea,      'escape') ILIKE $${params.length}` +
                ` OR encode(em.message_text::bytea, 'escape') ILIKE $${params.length})`
            );

            if (tag)       { params.push(tag.toUpperCase()); conditions.push(`ea.tag       = $${params.length}`); }
            if (domain)    { params.push(domain);            conditions.push(`ea.domain    = $${params.length}`); }
            if (from_name) { params.push(`%${from_name}%`); conditions.push(`encode(em.from_name::bytea, 'escape') ILIKE $${params.length}`); }
            if (since)     { params.push(since);             conditions.push(`em.date_received >= $${params.length}`); }

            params.push(limit);

            const sql = `
                SELECT em.id, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain,
                       em.from_name, em.to_name, em.subject,
                       to_char(em.date_written,  'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_written,
                       to_char(em.date_received, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_received,
                       LEFT(em.message_text, 300) AS message_preview
                FROM echomail em
                JOIN echoareas ea ON ea.id = em.echoarea_id
                WHERE ${conditions.join(' AND ')}
                ORDER BY em.date_received DESC
                LIMIT $${params.length}
            `;
            const result = await queryWithEncodingFallback(sql, params);
            return {
                content: [{
                    type: 'text',
                    text: result.rows.length === 0
                        ? `No messages found matching "${query}".`
                        : JSON.stringify(result.rows, null, 2),
                }],
            };
        }
    );

    // --- get_thread -----------------------------------------------------------

    server.tool(
        'get_echomail_thread',
        'Get an echomail message and all its replies, forming a complete conversation thread.',
        {
            id: z.number().int().positive().describe('ID of any message in the thread (root or reply)'),
        },
        async ({ id }) => {
            // Verify the seed message is accessible to this user
            const checkResult = await pool.query(
                `SELECT em.id FROM echomail em
                 JOIN echoareas ea ON ea.id = em.echoarea_id
                 WHERE em.id = $1 AND ea.is_active = TRUE ${sysopClause}`,
                [id]
            );
            if (checkResult.rows.length === 0) {
                return { content: [{ type: 'text', text: `Message ID ${id} not found or access denied.` }] };
            }

            // Walk up to thread root
            const rootResult = await pool.query(`
                WITH RECURSIVE thread AS (
                    SELECT id, reply_to_id FROM echomail WHERE id = $1
                    UNION ALL
                    SELECT em.id, em.reply_to_id
                    FROM echomail em JOIN thread t ON em.id = t.reply_to_id
                )
                SELECT id FROM thread WHERE reply_to_id IS NULL LIMIT 1
            `, [id]);
            const rootId = rootResult.rows[0]?.id ?? id;

            // Fetch the full thread downward, enforcing echoarea access at every level
            const result = await queryWithEncodingFallback(`
                WITH RECURSIVE thread AS (
                    SELECT em.id, em.reply_to_id, em.from_name, em.to_name, em.subject,
                           to_char(em.date_written,  'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_written,
                           to_char(em.date_received, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_received,
                           em.message_text, em.origin_line, 0 AS depth
                    FROM echomail em
                    JOIN echoareas ea ON ea.id = em.echoarea_id
                    WHERE em.id = $1 AND ea.is_active = TRUE ${sysopClause}
                    UNION ALL
                    SELECT em.id, em.reply_to_id, em.from_name, em.to_name, em.subject,
                           to_char(em.date_written,  'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_written,
                           to_char(em.date_received, 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS date_received,
                           em.message_text, em.origin_line, t.depth + 1
                    FROM echomail em
                    JOIN echoareas ea ON ea.id = em.echoarea_id
                    JOIN thread t ON em.reply_to_id = t.id
                    WHERE ea.is_active = TRUE ${sysopClause}
                )
                SELECT * FROM thread ORDER BY depth ASC, date_received ASC
            `, [rootId]);

            return { content: [{ type: 'text', text: JSON.stringify(result.rows, null, 2) }] };
        }
    );

    return server;
}

// ---------------------------------------------------------------------------
// Express app + MCP transport
// ---------------------------------------------------------------------------

const app = express();
app.use(express.json());

// Request logging
app.use((req, res, next) => {
    const start = Date.now();
    res.on('finish', () => {
        logger.info(`${req.method} ${req.path} ${res.statusCode} (${Date.now() - start}ms) [${req.ip}]`);
    });
    next();
});

// Health check (no auth required)
app.get('/health', (_req, res) => {
    res.json({ status: 'ok', server: 'mcp-server' });
});

app.post('/mcp', async (req, res) => {
    const userCtx = await resolveUser(req, res);
    if (!userCtx) return;

    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    const server    = createServer(userCtx);
    res.on('close', () => transport.close());
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
});

app.get('/mcp', async (req, res) => {
    const userCtx = await resolveUser(req, res);
    if (!userCtx) return;

    const transport = new StreamableHTTPServerTransport({ sessionIdGenerator: undefined });
    const server    = createServer(userCtx);
    res.on('close', () => transport.close());
    await server.connect(transport);
    await transport.handleRequest(req, res);
});

// Express error handler (catches synchronous throws from route handlers)
// eslint-disable-next-line no-unused-vars
app.use((err, req, res, next) => {
    logger.error(`Unhandled error on ${req.method} ${req.path}:`, err.message);
    res.status(500).json({ error: 'Internal server error' });
});

// ---------------------------------------------------------------------------
// Startup + graceful shutdown
// ---------------------------------------------------------------------------

const httpServer = BIND
    ? app.listen(PORT, BIND, () => { logger.info(`Listening on ${BIND}:${PORT}`); })
    : app.listen(PORT,       () => { logger.info(`Listening on port ${PORT}`); });

if (pidFilePath) {
    try {
        fs.mkdirSync(path.dirname(pidFilePath), { recursive: true });
        fs.writeFileSync(pidFilePath, String(process.pid));
        logger.info(`PID ${process.pid} written to ${pidFilePath}`);
    } catch (e) {
        logger.warn('Could not write PID file:', e.message);
    }
}

function shutdown() {
    logger.info('Shutting down...');
    httpServer.close(() => {
        pool.end(() => {
            if (pidFilePath) {
                try { fs.unlinkSync(pidFilePath); } catch (_) {}
            }
            process.exit(0);
        });
    });
}

process.on('SIGTERM', shutdown);
process.on('SIGINT',  shutdown);
