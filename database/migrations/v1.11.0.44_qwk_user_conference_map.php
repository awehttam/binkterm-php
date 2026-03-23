<?php
/**
 * Migration: v1.11.0.44_qwk_user_conference_map
 *
 * Adds a persistent per-user QWK conference number map so conference
 * numbering remains stable across downloads. Existing users are backfilled
 * from their latest saved QWK download map where possible, then any remaining
 * active subscriptions are assigned the next available number in the current
 * subscription order.
 */

return function($db) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS qwk_user_conference_map (
            user_id           INTEGER     NOT NULL REFERENCES users(id) ON DELETE CASCADE,
            echoarea_id       INTEGER     NOT NULL REFERENCES echoareas(id) ON DELETE CASCADE,
            conference_number INTEGER     NOT NULL CHECK (conference_number > 0),
            created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            PRIMARY KEY (user_id, echoarea_id),
            UNIQUE (user_id, conference_number)
        )
    ");

    $db->exec("
        CREATE INDEX IF NOT EXISTS idx_qwk_user_conference_map_user
            ON qwk_user_conference_map (user_id, conference_number)
    ");

    $users = $db->query("SELECT id FROM users ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);

    $latestLogStmt = $db->prepare("
        SELECT conference_map
        FROM qwk_download_log
        WHERE user_id = ?
        ORDER BY downloaded_at DESC
        LIMIT 1
    ");

    $areaLookupStmt = $db->prepare("
        SELECT id
        FROM echoareas
        WHERE LOWER(tag) = LOWER(?) AND LOWER(COALESCE(domain, '')) = LOWER(COALESCE(?, ''))
        LIMIT 1
    ");

    $activeSubsStmt = $db->prepare("
        SELECT e.id
        FROM echoareas e
        JOIN user_echoarea_subscriptions s ON e.id = s.echoarea_id
        WHERE s.user_id = ? AND s.is_active = TRUE AND e.is_active = TRUE
          AND (COALESCE(e.is_sysop_only, FALSE) = FALSE OR EXISTS (
                SELECT 1 FROM users u WHERE u.id = ? AND u.is_admin = TRUE
              ))
        ORDER BY
            CASE
                WHEN COALESCE(e.is_local, FALSE) = TRUE THEN 0
                WHEN LOWER(e.domain) = 'lovlynet' THEN 1
                ELSE 2
            END,
            e.tag
    ");

    $existingStmt = $db->prepare("
        SELECT echoarea_id, conference_number
        FROM qwk_user_conference_map
        WHERE user_id = ?
    ");

    $insertStmt = $db->prepare("
        INSERT INTO qwk_user_conference_map (user_id, echoarea_id, conference_number, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), NOW())
        ON CONFLICT (user_id, echoarea_id)
        DO UPDATE SET updated_at = NOW()
    ");

    foreach ($users as $userId) {
        $usedNumbers = [];
        $seenAreas   = [];

        $latestLogStmt->execute([$userId]);
        $conferenceMapJson = $latestLogStmt->fetchColumn();
        if ($conferenceMapJson) {
            $conferenceMap = json_decode($conferenceMapJson, true);
            if (is_array($conferenceMap)) {
                ksort($conferenceMap, SORT_NUMERIC);
                foreach ($conferenceMap as $number => $conf) {
                    $conferenceNumber = (int)$number;
                    if ($conferenceNumber <= 0 || !empty($conf['is_netmail'])) {
                        continue;
                    }

                    $tag = trim((string)($conf['tag'] ?? ''));
                    if ($tag === '') {
                        continue;
                    }

                    $domain = (string)($conf['domain'] ?? '');
                    $areaLookupStmt->execute([$tag, $domain]);
                    $echoareaId = $areaLookupStmt->fetchColumn();
                    if (!$echoareaId) {
                        continue;
                    }

                    $echoareaId = (int)$echoareaId;
                    if (isset($usedNumbers[$conferenceNumber]) || isset($seenAreas[$echoareaId])) {
                        continue;
                    }

                    $insertStmt->execute([$userId, $echoareaId, $conferenceNumber]);
                    $usedNumbers[$conferenceNumber] = true;
                    $seenAreas[$echoareaId] = true;
                }
            }
        }

        $existingStmt->execute([$userId]);
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $usedNumbers[(int)$row['conference_number']] = true;
            $seenAreas[(int)$row['echoarea_id']] = true;
        }

        $activeSubsStmt->execute([$userId, $userId]);
        $activeEchoareaIds = array_map('intval', $activeSubsStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($activeEchoareaIds as $echoareaId) {
            if (isset($seenAreas[$echoareaId])) {
                continue;
            }

            $conferenceNumber = 1;
            while (isset($usedNumbers[$conferenceNumber])) {
                $conferenceNumber++;
            }

            $insertStmt->execute([$userId, $echoareaId, $conferenceNumber]);
            $usedNumbers[$conferenceNumber] = true;
            $seenAreas[$echoareaId] = true;
        }
    }

    return true;
};
