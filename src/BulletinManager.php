<?php

namespace BinktermPHP;

use PDO;

/**
 * Data access and rendering helpers for sysop-managed login bulletins.
 */
class BulletinManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getActiveBulletins(?int $userId = null): array
    {
        $params = [];
        $readSelect = 'FALSE AS is_read';
        $readJoin = '';
        if ($userId !== null && $userId > 0) {
            $readSelect = 'CASE WHEN br.user_id IS NULL THEN FALSE ELSE TRUE END AS is_read';
            $readJoin = 'LEFT JOIN bulletin_reads br ON br.bulletin_id = b.id AND br.user_id = ?';
            $params[] = $userId;
        }

        $stmt = $this->db->prepare("
            SELECT b.*, {$readSelect}
            FROM bulletins b
            {$readJoin}
            WHERE b.is_active = TRUE
              AND (b.active_from IS NULL OR b.active_from <= NOW())
              AND (b.active_until IS NULL OR b.active_until > NOW())
            ORDER BY b.sort_order ASC, b.id ASC
        ");
        $stmt->execute($params);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getUnreadBulletins(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare("
            SELECT b.*, FALSE AS is_read
            FROM bulletins b
            LEFT JOIN bulletin_reads br ON br.bulletin_id = b.id AND br.user_id = ?
            WHERE b.is_active = TRUE
              AND (b.active_from IS NULL OR b.active_from <= NOW())
              AND (b.active_until IS NULL OR b.active_until > NOW())
              AND br.user_id IS NULL
            ORDER BY b.sort_order ASC, b.id ASC
        ");
        $stmt->execute([$userId]);

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAllBulletins(): array
    {
        $stmt = $this->db->query("
            SELECT b.*, u.username AS created_by_username
            FROM bulletins b
            LEFT JOIN users u ON u.id = b.created_by
            ORDER BY b.sort_order ASC, b.id ASC
        ");

        return array_map([$this, 'normalizeRow'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function getUnreadCount(int $userId): int
    {
        if ($userId <= 0) {
            return 0;
        }

        $stmt = $this->db->prepare("
            SELECT COUNT(*)
            FROM bulletins b
            LEFT JOIN bulletin_reads br ON br.bulletin_id = b.id AND br.user_id = ?
            WHERE b.is_active = TRUE
              AND (b.active_from IS NULL OR b.active_from <= NOW())
              AND (b.active_until IS NULL OR b.active_until > NOW())
              AND br.user_id IS NULL
        ");
        $stmt->execute([$userId]);

        return (int)$stmt->fetchColumn();
    }

    public function markRead(int $userId, int $bulletinId): void
    {
        if ($userId <= 0 || $bulletinId <= 0) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO bulletin_reads (user_id, bulletin_id)
            VALUES (?, ?)
            ON CONFLICT (user_id, bulletin_id) DO UPDATE SET seen_at = NOW()
        ");
        $stmt->execute([$userId, $bulletinId]);
    }

    /**
     * @param int[] $bulletinIds
     */
    public function markAllRead(int $userId, array $bulletinIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $bulletinIds), fn($id) => $id > 0)));
        if ($userId <= 0 || empty($ids)) {
            return;
        }

        $stmt = $this->db->prepare("
            INSERT INTO bulletin_reads (user_id, bulletin_id)
            VALUES (?, ?)
            ON CONFLICT (user_id, bulletin_id) DO UPDATE SET seen_at = NOW()
        ");
        foreach ($ids as $id) {
            $stmt->execute([$userId, $id]);
        }
    }

    public function create(array $data, ?int $createdBy = null): int
    {
        $clean = $this->sanitizeData($data);
        $stmt = $this->db->prepare("
            INSERT INTO bulletins (title, body, format, sort_order, is_active, active_from, active_until, created_by)
            VALUES (:title, :body, :format, :sort_order, :is_active, :active_from, :active_until, :created_by)
            RETURNING id
        ");
        $stmt->execute([
            ':title' => $clean['title'],
            ':body' => $clean['body'],
            ':format' => $clean['format'],
            ':sort_order' => $clean['sort_order'],
            ':is_active' => $clean['is_active'] ? 'true' : 'false',
            ':active_from' => $clean['active_from'],
            ':active_until' => $clean['active_until'],
            ':created_by' => $createdBy,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['id'] : 0;
    }

    public function update(int $id, array $data): void
    {
        $clean = $this->sanitizeData($data);
        $stmt = $this->db->prepare("
            UPDATE bulletins
            SET title = :title,
                body = :body,
                format = :format,
                sort_order = :sort_order,
                is_active = :is_active,
                active_from = :active_from,
                active_until = :active_until,
                updated_at = NOW()
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':title' => $clean['title'],
            ':body' => $clean['body'],
            ':format' => $clean['format'],
            ':sort_order' => $clean['sort_order'],
            ':is_active' => $clean['is_active'] ? 'true' : 'false',
            ':active_from' => $clean['active_from'],
            ':active_until' => $clean['active_until'],
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM bulletins WHERE id = ?");
        $stmt->execute([$id]);
    }

    /**
     * @param int[] $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $orderedIds), fn($id) => $id > 0)));
        if (empty($ids)) {
            return;
        }

        $stmt = $this->db->prepare("UPDATE bulletins SET sort_order = ?, updated_at = NOW() WHERE id = ?");
        foreach ($ids as $index => $id) {
            $stmt->execute([$index + 1, $id]);
        }
    }

    public function renderBodyHtml(array $bulletin): string
    {
        $body = (string)($bulletin['body'] ?? '');
        if (($bulletin['format'] ?? 'plain') === 'markdown') {
            return MarkdownRenderer::toHtml($body);
        }

        $escaped = htmlspecialchars($body, ENT_QUOTES, 'UTF-8');
        $linked = preg_replace(
            '/\b(https?:\/\/[^\s<]+)/',
            '<a href="$1" target="_blank" rel="noopener noreferrer">$1</a>',
            $escaped
        ) ?? $escaped;

        return '<pre class="mb-0 bulletin-plain-body">' . $linked . '</pre>';
    }

    /**
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int)$row['id'];
        $row['sort_order'] = (int)$row['sort_order'];
        $row['created_by'] = isset($row['created_by']) ? (int)$row['created_by'] : null;
        $row['is_active'] = filter_var($row['is_active'], FILTER_VALIDATE_BOOLEAN);
        $row['is_read'] = filter_var($row['is_read'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $row['body_html'] = $this->renderBodyHtml($row);
        return $row;
    }

    /**
     * @return array{title:string,body:string,format:string,sort_order:int,is_active:bool,active_from:?string,active_until:?string}
     */
    private function sanitizeData(array $data): array
    {
        $title = trim((string)($data['title'] ?? ''));
        $body = (string)($data['body'] ?? '');
        $format = (string)($data['format'] ?? 'plain');

        if ($title === '') {
            throw new \InvalidArgumentException('Bulletin title is required.');
        }
        if (trim($body) === '') {
            throw new \InvalidArgumentException('Bulletin body is required.');
        }
        if (!in_array($format, ['plain', 'markdown'], true)) {
            throw new \InvalidArgumentException('Invalid bulletin format.');
        }

        return [
            'title' => mb_substr($title, 0, 255),
            'body' => $body,
            'format' => $format,
            'sort_order' => (int)($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active']),
            'active_from' => $this->normalizeDateTime($data['active_from'] ?? null),
            'active_until' => $this->normalizeDateTime($data['active_until'] ?? null),
        ];
    }

    private function normalizeDateTime($value): ?string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '') {
            return null;
        }

        return (new \DateTimeImmutable($value))->format('Y-m-d H:i:sP');
    }
}
