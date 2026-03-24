/**
 * BinktermPHP Echomail MCP Server
 *
 * Provides read-only access to the echomail and echoareas tables via the
 * Model Context Protocol. Connects directly to PostgreSQL using the same
 * environment variables as BinktermPHP.
 *
 * Authentication: Bearer token or X-API-Key header. Configure one or more
 * allowed keys in MCP_API_KEYS (comma-separated).
 *
 * Transport: Streamable HTTP (POST /mcp) with optional SSE upgrade.
 */

import 'dotenv/config';
import express from 'express';
import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { StreamableHTTPServerTransport } from '@modelcontextprotocol/sdk/server/streamableHttp.js';
import pg from 'pg';
import { z } from 'zod';

const { Pool } = pg;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

// date_written comes from the FTN packet (local time converted to UTC by PHP).
// node-postgres returns TIMESTAMP WITHOUT TIME ZONE as a plain string with no
// timezone context, so we append 'Z' — the same approach used by the
// BinktermPHP web client in formatDate()/formatFullDate() — to produce a
// correct ISO 8601 UTC timestamp.  date_received is always set by the server
// via now() AT TIME ZONE 'UTC' and does not need adjustment here.
function fixDateWritten(row) {
    if (row.date_written && typeof row.date_written === 'string') {
        row.date_written = row.date_written.replace(' ', 'T') + 'Z';
    }
    return row;
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const PORT     = parseInt(process.env.MCP_PORT     ?? '3740', 10);
const API_KEYS = (process.env.MCP_API_KEYS ?? '')
    .split(',')
    .map(k => k.trim())
    .filter(Boolean);

if (API_KEYS.length === 0) {
    console.error('ERROR: MCP_API_KEYS is not set. Set at least one key in .env');
    process.exit(1);
}

// ---------------------------------------------------------------------------
// PostgreSQL pool — same env vars as BinktermPHP
// ---------------------------------------------------------------------------

const pool = new Pool({
    host:     process.env.DB_HOST     ?? 'localhost',
    port:     parseInt(process.env.DB_PORT ?? '5432', 10),
    database: process.env.DB_NAME     ?? 'binktest',
    user:     process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    ssl:      process.env.DB_SSLMODE ? { rejectUnauthorized: false } : false,
    max:      5,
});

pool.on('error', (err) => {
    console.error('[DB] Unexpected pool error:', err.message);
});

// ---------------------------------------------------------------------------
// Auth middleware
// ---------------------------------------------------------------------------

function authenticate(req, res, next) {
    const authHeader = req.headers['authorization'] ?? '';
    const apiKeyHeader = req.headers['x-api-key'] ?? '';

    let token = null;
    if (authHeader.toLowerCase().startsWith('bearer ')) {
        token = authHeader.slice(7).trim();
    } else if (apiKeyHeader) {
        token = apiKeyHeader.trim();
    }

    if (!token || !API_KEYS.includes(token)) {
        res.status(401).json({ error: 'Unauthorized' });
        return;
    }
    next();
}

// ---------------------------------------------------------------------------
// MCP server + tools
// ---------------------------------------------------------------------------

function createServer() {
const server = new McpServer({
    name:    'mcp-echomail',
    version: '1.0.0',
});

// --- list_echoareas ---------------------------------------------------------

server.tool(
    'list_echoareas',
    'List all active echo areas with their tags, descriptions, domains, and message counts.',
    {
        include_inactive: z.boolean().optional().describe('Include inactive areas (default: false)'),
        domain:           z.string().optional().describe('Filter by network domain (e.g. "fidonet")'),
    },
    async ({ include_inactive = false, domain }) => {
        const conditions = [];
        const params     = [];

        if (!include_inactive) {
            conditions.push('is_active = TRUE');
        }
        if (domain) {
            params.push(domain);
            conditions.push(`domain = $${params.length}`);
        }

        const where = conditions.length ? `WHERE ${conditions.join(' AND ')}` : '';
        const sql = `
            SELECT tag, domain, description, moderator, message_count,
                   is_active, is_local, is_sysop_only
            FROM echoareas
            ${where}
            ORDER BY tag ASC
        `;

        const result = await pool.query(sql, params);
        return {
            content: [{
                type: 'text',
                text: JSON.stringify(result.rows, null, 2),
            }],
        };
    }
);

// --- get_echoarea -----------------------------------------------------------

server.tool(
    'get_echoarea',
    'Get details about a specific echo area by tag.',
    {
        tag:    z.string().describe('Echo area tag (e.g. "GENERAL")'),
        domain: z.string().optional().describe('Network domain to disambiguate areas with the same tag'),
    },
    async ({ tag, domain }) => {
        const params = [tag.toUpperCase()];
        let sql = `
            SELECT tag, domain, description, moderator, uplink_address,
                   message_count, is_active, is_local, is_sysop_only,
                   is_default_subscription, created_at
            FROM echoareas
            WHERE tag = $1
        `;
        if (domain) {
            params.push(domain);
            sql += ` AND domain = $${params.length}`;
        }
        sql += ' LIMIT 1';

        const result = await pool.query(sql, params);
        if (result.rows.length === 0) {
            return { content: [{ type: 'text', text: `Echo area "${tag}" not found.` }] };
        }
        return {
            content: [{ type: 'text', text: JSON.stringify(result.rows[0], null, 2) }],
        };
    }
);

// --- get_messages -----------------------------------------------------------

server.tool(
    'get_messages',
    'Get recent messages from an echo area, with optional filters and pagination.',
    {
        tag:    z.string().describe('Echo area tag'),
        domain: z.string().optional().describe('Network domain'),
        limit:  z.number().int().min(1).max(100).optional().describe('Number of messages to return (default: 25, max: 100)'),
        offset: z.number().int().min(0).optional().describe('Pagination offset (default: 0)'),
        from_name: z.string().optional().describe('Filter by sender name (partial, case-insensitive)'),
        to_name:   z.string().optional().describe('Filter by recipient name (partial, case-insensitive)'),
        subject:   z.string().optional().describe('Filter by subject (partial, case-insensitive)'),
        since:     z.string().optional().describe('Only messages received after this ISO-8601 datetime'),
    },
    async ({ tag, domain, limit = 25, offset = 0, from_name, to_name, subject, since }) => {
        // Resolve echoarea id
        const areaParams = [tag.toUpperCase()];
        let areaSql = 'SELECT id FROM echoareas WHERE tag = $1 AND is_active = TRUE';
        if (domain) {
            areaParams.push(domain);
            areaSql += ` AND domain = $${areaParams.length}`;
        }
        const areaResult = await pool.query(areaSql, areaParams);
        if (areaResult.rows.length === 0) {
            return { content: [{ type: 'text', text: `Echo area "${tag}" not found or inactive.` }] };
        }
        const areaId = areaResult.rows[0].id;

        const conditions = [`em.echoarea_id = $1`];
        const params     = [areaId];

        if (from_name) {
            params.push(`%${from_name}%`);
            conditions.push(`em.from_name ILIKE $${params.length}`);
        }
        if (to_name) {
            params.push(`%${to_name}%`);
            conditions.push(`em.to_name ILIKE $${params.length}`);
        }
        if (subject) {
            params.push(`%${subject}%`);
            conditions.push(`em.subject ILIKE $${params.length}`);
        }
        if (since) {
            params.push(since);
            conditions.push(`em.date_received >= $${params.length}`);
        }

        params.push(limit);
        params.push(offset);

        const sql = `
            SELECT em.id, em.from_address, em.from_name, em.to_name,
                   em.subject, em.date_written, em.date_received,
                   em.message_id, em.origin_line,
                   LEFT(em.message_text, 500) AS message_preview
            FROM echomail em
            WHERE ${conditions.join(' AND ')}
            ORDER BY em.date_received DESC
            LIMIT $${params.length - 1}
            OFFSET $${params.length}
        `;

        const result = await pool.query(sql, params);
        return {
            content: [{
                type: 'text',
                text: JSON.stringify(result.rows.map(fixDateWritten), null, 2),
            }],
        };
    }
);

// --- get_message ------------------------------------------------------------

server.tool(
    'get_message',
    'Get the full text of a single echomail message by its ID.',
    {
        id: z.number().int().positive().describe('Echomail message ID'),
    },
    async ({ id }) => {
        const sql = `
            SELECT em.id, em.from_address, em.from_name, em.to_name,
                   em.subject, em.date_written, em.date_received,
                   em.message_id, em.reply_to_id, em.origin_line,
                   em.tearline_component, em.message_text, em.kludge_lines,
                   ea.tag AS echoarea_tag, ea.domain AS echoarea_domain
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE em.id = $1
        `;
        const result = await pool.query(sql, [id]);
        if (result.rows.length === 0) {
            return { content: [{ type: 'text', text: `Message ID ${id} not found.` }] };
        }
        return {
            content: [{ type: 'text', text: JSON.stringify(fixDateWritten(result.rows[0]), null, 2) }],
        };
    }
);

// --- search_echomail --------------------------------------------------------

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
        const conditions = [];
        const params     = [];

        // Full-text match across subject + body
        params.push(`%${query}%`);
        const qIdx = params.length;
        conditions.push(`(em.subject ILIKE $${qIdx} OR em.message_text ILIKE $${qIdx})`);

        if (tag) {
            params.push(tag.toUpperCase());
            conditions.push(`ea.tag = $${params.length}`);
        }
        if (domain) {
            params.push(domain);
            conditions.push(`ea.domain = $${params.length}`);
        }
        if (from_name) {
            params.push(`%${from_name}%`);
            conditions.push(`em.from_name ILIKE $${params.length}`);
        }
        if (since) {
            params.push(since);
            conditions.push(`em.date_received >= $${params.length}`);
        }

        conditions.push('ea.is_active = TRUE');

        params.push(limit);

        const sql = `
            SELECT em.id, ea.tag AS echoarea_tag, ea.domain AS echoarea_domain,
                   em.from_name, em.to_name, em.subject,
                   em.date_received, em.date_written,
                   LEFT(em.message_text, 300) AS message_preview
            FROM echomail em
            JOIN echoareas ea ON ea.id = em.echoarea_id
            WHERE ${conditions.join(' AND ')}
            ORDER BY em.date_received DESC
            LIMIT $${params.length}
        `;

        const result = await pool.query(sql, params);
        return {
            content: [{
                type: 'text',
                text: result.rows.length === 0
                    ? `No messages found matching "${query}".`
                    : JSON.stringify(result.rows.map(fixDateWritten), null, 2),
            }],
        };
    }
);

// --- get_thread -------------------------------------------------------------

server.tool(
    'get_thread',
    'Get a message and all its replies, forming a complete conversation thread.',
    {
        id: z.number().int().positive().describe('ID of any message in the thread (root or reply)'),
    },
    async ({ id }) => {
        // Find root of thread
        const rootSql = `
            WITH RECURSIVE thread AS (
                SELECT id, reply_to_id FROM echomail WHERE id = $1
                UNION ALL
                SELECT em.id, em.reply_to_id
                FROM echomail em
                JOIN thread t ON em.id = t.reply_to_id
            )
            SELECT id FROM thread WHERE reply_to_id IS NULL
            LIMIT 1
        `;
        const rootResult = await pool.query(rootSql, [id]);
        const rootId = rootResult.rows[0]?.id ?? id;

        // Fetch the thread downward from root
        const threadSql = `
            WITH RECURSIVE thread AS (
                SELECT em.id, em.reply_to_id, em.from_name, em.to_name,
                       em.subject, em.date_received, em.date_written,
                       em.message_text, em.origin_line, 0 AS depth
                FROM echomail em WHERE em.id = $1
                UNION ALL
                SELECT em.id, em.reply_to_id, em.from_name, em.to_name,
                       em.subject, em.date_received, em.date_written,
                       em.message_text, em.origin_line, t.depth + 1
                FROM echomail em
                JOIN thread t ON em.reply_to_id = t.id
            )
            SELECT * FROM thread ORDER BY depth ASC, date_received ASC
        `;
        const result = await pool.query(threadSql, [rootId]);
        return {
            content: [{
                type: 'text',
                text: JSON.stringify(result.rows.map(fixDateWritten), null, 2),
            }],
        };
    }
);

    return server;
}

// ---------------------------------------------------------------------------
// Express app + MCP transport
// ---------------------------------------------------------------------------

const app = express();
app.use(express.json());

// Health check (no auth required)
app.get('/health', (_req, res) => {
    res.json({ status: 'ok', server: 'mcp-echomail' });
});

// All MCP traffic goes through POST /mcp with auth
app.post('/mcp', authenticate, async (req, res) => {
    const transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: undefined, // stateless
    });
    const server = createServer();
    res.on('close', () => transport.close());
    await server.connect(transport);
    await transport.handleRequest(req, res, req.body);
});

// SSE upgrade for clients that prefer streaming (GET /mcp)
app.get('/mcp', authenticate, async (req, res) => {
    const transport = new StreamableHTTPServerTransport({
        sessionIdGenerator: undefined,
    });
    const server = createServer();
    res.on('close', () => transport.close());
    await server.connect(transport);
    await transport.handleRequest(req, res);
});

app.listen(PORT, () => {
    console.log(`[mcp-echomail] Listening on port ${PORT}`);
    console.log(`[mcp-echomail] ${API_KEYS.length} API key(s) configured`);
});
