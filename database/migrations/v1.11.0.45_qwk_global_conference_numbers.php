<?php
/**
 * Migration: v1.11.0.45_qwk_global_conference_numbers
 *
 * Adds a canonical BBS-wide QWK conference number to echo areas so QWK packet
 * conference IDs are stable system-wide instead of varying by user
 * subscription order.
 */

return function($db) {
    $db->exec("
        ALTER TABLE echoareas
        ADD COLUMN IF NOT EXISTS qwk_conference_number INTEGER
    ");

    $db->exec("
        CREATE UNIQUE INDEX IF NOT EXISTS idx_echoareas_qwk_conference_number
            ON echoareas (qwk_conference_number)
            WHERE qwk_conference_number IS NOT NULL
    ");

    $rows = $db->query("
        SELECT id
        FROM echoareas
        WHERE qwk_conference_number IS NULL
        ORDER BY
            CASE
                WHEN COALESCE(is_local, FALSE) = TRUE THEN 0
                WHEN LOWER(domain) = 'lovlynet' THEN 1
                ELSE 2
            END,
            LOWER(tag),
            id
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return true;
    }

    $nextNumber = (int)$db->query("
        SELECT COALESCE(MAX(qwk_conference_number), 0)
        FROM echoareas
    ")->fetchColumn();

    $updateStmt = $db->prepare("
        UPDATE echoareas
        SET qwk_conference_number = ?
        WHERE id = ?
    ");

    foreach ($rows as $row) {
        $nextNumber++;
        $updateStmt->execute([$nextNumber, (int)$row['id']]);
    }

    return true;
};
