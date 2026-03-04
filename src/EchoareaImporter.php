<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;
use PDO;

/**
 * Imports echo areas from CSV rows in the format:
 * ECHOTAG,DESCRIPTION,DOMAIN
 */
class EchoareaImporter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance()->getPdo();
    }

    public function importCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open uploaded CSV file.');
        }

        $parsed = $this->parseAndValidateCsv($handle);

        fclose($handle);

        if (!empty($parsed['errors'])) {
            return [
                'processed' => $parsed['processed'],
                'created' => 0,
                'updated' => 0,
                'skipped' => $parsed['skipped'],
                'errors' => $parsed['errors'],
            ];
        }

        return $this->applyImport($parsed['rows'], $parsed['processed'], $parsed['skipped']);
    }

    private function parseAndValidateCsv($handle): array
    {
        $summary = [
            'processed' => 0,
            'skipped' => 0,
            'errors' => [],
            'rows' => [],
        ];

        $config = BinkpConfig::getInstance();
        $lineNumber = 0;
        $seenKeys = [];

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;

            if ($lineNumber === 1 && $this->isHeaderRow($row)) {
                continue;
            }

            if ($this->isBlankRow($row)) {
                $summary['skipped']++;
                continue;
            }

            $summary['processed']++;

            try {
                $normalized = $this->validateRow($row, $config);
                $rowKey = $normalized['tag'] . '@' . ($normalized['domain'] ?? '');
                if (isset($seenKeys[$rowKey])) {
                    throw new \RuntimeException('Duplicate ECHOTAG/DOMAIN combination within the CSV file.');
                }
                $seenKeys[$rowKey] = true;
                $summary['rows'][] = $normalized;
            } catch (\Throwable $e) {
                $summary['errors'][] = 'Line ' . $lineNumber . ': ' . $e->getMessage();
            }
        }

        return $summary;
    }

    private function validateRow(array $row, BinkpConfig $config): array
    {
        $tag = strtoupper(trim((string)($row[0] ?? '')));
        $description = trim((string)($row[1] ?? ''));
        $domain = strtolower(trim((string)($row[2] ?? '')));

        if ($tag === '' || $description === '') {
            throw new \RuntimeException('ECHOTAG and DESCRIPTION are required.');
        }

        if (!preg_match('/^[A-Z0-9._-]+$/', $tag)) {
            throw new \RuntimeException('Invalid ECHOTAG. Use only letters, numbers, dots, underscores, and hyphens.');
        }

        if ($domain !== '' && !preg_match('/^[a-zA-Z0-9_-]+$/', $domain)) {
            throw new \RuntimeException('Invalid DOMAIN. Use only letters, numbers, underscores, and hyphens.');
        }

        $isLocal = ($domain === '');
        $storedDomain = $isLocal ? null : $domain;

        if (!$isLocal && $config->getUplinkByDomain($domain) === null) {
            throw new \RuntimeException("Unknown DOMAIN '{$domain}'. Add the network domain first in BinkP configuration.");
        }

        return [
            'tag' => $tag,
            'description' => $description,
            'domain' => $storedDomain,
            'is_local' => $isLocal,
        ];
    }

    private function applyImport(array $rows, int $processed, int $skipped): array
    {
        $summary = [
            'processed' => $processed,
            'created' => 0,
            'updated' => 0,
            'skipped' => $skipped,
            'errors' => [],
        ];

        $findBlankDomainStmt = $this->db->prepare(
            "SELECT id FROM echoareas WHERE UPPER(tag) = UPPER(?) AND (domain IS NULL OR domain = '') LIMIT 1"
        );
        $findDomainStmt = $this->db->prepare(
            "SELECT id FROM echoareas WHERE UPPER(tag) = UPPER(?) AND LOWER(domain) = LOWER(?) LIMIT 1"
        );
        $insertStmt = $this->db->prepare(
            "INSERT INTO echoareas (tag, description, moderator, uplink_address, color, is_active, is_local, is_sysop_only, domain, gemini_public)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $updateStmt = $this->db->prepare(
            "UPDATE echoareas
             SET description = ?, is_active = ?, is_local = ?, domain = ?
             WHERE id = ?"
        );

        try {
            $this->db->beginTransaction();

            foreach ($rows as $row) {
                $existingId = $this->findExistingEchoareaId($row['tag'], $row['domain'], $findBlankDomainStmt, $findDomainStmt);
                if ($existingId !== null) {
                    $updateStmt->execute([
                        $row['description'],
                        'true',
                        $row['is_local'] ? 'true' : 'false',
                        $row['domain'],
                        $existingId,
                    ]);
                    $summary['updated']++;
                    continue;
                }

                $insertStmt->execute([
                    $row['tag'],
                    $row['description'],
                    null,
                    null,
                    '#28a745',
                    'true',
                    $row['is_local'] ? 'true' : 'false',
                    'false',
                    $row['domain'],
                    'false',
                ]);
                $summary['created']++;
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }

            throw new \RuntimeException('Import failed and no changes were applied: ' . $e->getMessage(), 0, $e);
        }

        return $summary;
    }

    private function findExistingEchoareaId(string $tag, ?string $domain, \PDOStatement $findBlankDomainStmt, \PDOStatement $findDomainStmt): ?int
    {
        if ($domain === null || $domain === '') {
            $findBlankDomainStmt->execute([$tag]);
            $row = $findBlankDomainStmt->fetch();
        } else {
            $findDomainStmt->execute([$tag, $domain]);
            $row = $findDomainStmt->fetch();
        }

        return $row ? (int)$row['id'] : null;
    }

    private function isHeaderRow(array $row): bool
    {
        $normalized = array_map(static function ($value) {
            return strtoupper(trim((string)$value));
        }, array_slice($row, 0, 3));

        return $normalized === ['ECHOTAG', 'DESCRIPTION', 'DOMAIN'];
    }

    private function isBlankRow(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }
}
