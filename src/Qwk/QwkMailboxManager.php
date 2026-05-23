<?php

namespace BinktermPHP\Qwk;

use BinktermPHP\Database;
use BinktermPHP\SysK;
use PDO;

class QwkMailboxManager
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getAll(bool $includeSecrets = false): array
    {
        $stmt = $this->db->query("
            SELECT id, name, bbs_id, host, port, username, password, ftp_remote_path,
                   poll_schedule, enabled, last_polled_at, last_error, created_at, updated_at
            FROM qwk_mailboxes
            ORDER BY LOWER(name), id
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$row) {
            $row = $this->normalizeRow($row);
            if ($includeSecrets) {
                $row['password_plain'] = $this->decryptPassword((string)($row['password'] ?? ''));
            } else {
                unset($row['password']);
            }
        }
        unset($row);
        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeRow(array $row): array
    {
        $row['id'] = (int)($row['id'] ?? 0);
        $row['port'] = (int)($row['port'] ?? 21);
        $row['enabled'] = filter_var($row['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
        return $row;
    }

    public function decryptPassword(string $encrypted): string
    {
        return SysK::decrypt($encrypted);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function save(array $data, ?int $id = null): int
    {
        $name = trim((string)($data['name'] ?? ''));
        $bbsId = strtoupper(substr(preg_replace('/[^A-Z0-9]/i', '', (string)($data['bbs_id'] ?? '')), 0, 8));
        $host = trim((string)($data['host'] ?? ''));
        $port = (int)($data['port'] ?? 21);
        $username = trim((string)($data['username'] ?? ''));
        $password = (string)($data['password'] ?? '');
        $path = trim((string)($data['ftp_remote_path'] ?? '/'));
        $schedule = trim((string)($data['poll_schedule'] ?? ''));
        $enabled = !empty($data['enabled']);

        if ($name === '' || $bbsId === '' || $host === '' || $username === '') {
            throw new \InvalidArgumentException('Missing required mailbox fields');
        }

        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('Invalid port');
        }

        if ($id === null && $password === '') {
            throw new \InvalidArgumentException('Password is required for new QWK mailboxes');
        }

        if ($id !== null && $password === '') {
            $existing = $this->getById($id, true);
            if (!$existing) {
                throw new \InvalidArgumentException('QWK mailbox not found');
            }
            $password = (string)($existing['password_plain'] ?? '');
        }

        $encryptedPassword = SysK::encrypt($password);

        if ($id === null) {
            $stmt = $this->db->prepare("
                INSERT INTO qwk_mailboxes
                    (name, bbs_id, host, port, username, password, ftp_remote_path, poll_schedule, enabled, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                RETURNING id
            ");
            $stmt->execute([
                $name,
                $bbsId,
                $host,
                $port,
                $username,
                $encryptedPassword,
                $path !== '' ? $path : '/',
                $schedule !== '' ? $schedule : null,
                $enabled ? 'true' : 'false',
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int)$row['id'] : 0;
        }

        $stmt = $this->db->prepare("
            UPDATE qwk_mailboxes
            SET name = ?, bbs_id = ?, host = ?, port = ?, username = ?, password = ?,
                ftp_remote_path = ?, poll_schedule = ?, enabled = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([
            $name,
            $bbsId,
            $host,
            $port,
            $username,
            $encryptedPassword,
            $path !== '' ? $path : '/',
            $schedule !== '' ? $schedule : null,
            $enabled ? 'true' : 'false',
            $id,
        ]);
        if ($stmt->rowCount() === 0) {
            throw new \InvalidArgumentException('QWK mailbox not found');
        }

        return $id;
    }

    public function getById(int $id, bool $includeSecret = false): ?array
    {
        $stmt = $this->db->prepare("
            SELECT id, name, bbs_id, host, port, username, password, ftp_remote_path,
                   poll_schedule, enabled, last_polled_at, last_error, created_at, updated_at
            FROM qwk_mailboxes
            WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        $row = $this->normalizeRow($row);
        if ($includeSecret) {
            $row['password_plain'] = $this->decryptPassword((string)($row['password'] ?? ''));
        } else {
            unset($row['password']);
        }

        return $row;
    }

    public function delete(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM qwk_mailboxes WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->rowCount() > 0;
    }

    public function markPollResult(int $id, ?string $error = null): void
    {
        $stmt = $this->db->prepare("
            UPDATE qwk_mailboxes
            SET last_polled_at = NOW(),
                last_error = ?,
                updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$error, $id]);
    }
}
