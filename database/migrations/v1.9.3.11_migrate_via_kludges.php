<?php
/**
 * Migration: 1.9.3.11 - Migrate bottom kludges (Via, SEEN-BY, PATH) to bottom_kludges column
 *
 * Moves Via, SEEN-BY, and PATH kludges from kludge_lines to bottom_kludges for FTS-4009 compliance
 */

return function($db) {
    echo "Migrating bottom kludges (Via, SEEN-BY, PATH) to bottom_kludges column...\n";

    $processed = 0;

    // Migrate netmail bottom kludges
    $stmt = $db->query("SELECT id, kludge_lines, bottom_kludges FROM netmail WHERE kludge_lines IS NOT NULL AND kludge_lines != ''");
    $updateStmt = $db->prepare("UPDATE netmail SET kludge_lines = ?, bottom_kludges = ? WHERE id = ?");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $kludgeLines = $row['kludge_lines'];
        $existingBottomKludges = $row['bottom_kludges'];
        $lines = explode("\n", $kludgeLines);

        $topKludges = [];
        $newBottomKludges = [];

        // Start with existing bottom kludges to preserve them
        if (!empty($existingBottomKludges)) {
            $existingLines = explode("\n", $existingBottomKludges);
            foreach ($existingLines as $line) {
                if (!empty(trim($line))) {
                    $newBottomKludges[] = trim($line);
                }
            }
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // Check if this is a bottom kludge (Via, SEEN-BY, PATH per FTS-4009)
            // Via: ^AVia 1:153/757 @... or ^AVia: 1:153/757 @...
            // SEEN-BY: SEEN-BY: 1/1 2/2
            // PATH: ^APATH: 1/1 2/2
            if (preg_match('/^\x01Via[:\s]+\d+:\d+\/\d+/i', $line) ||
                preg_match('/^SEEN-BY:/i', $line) ||
                preg_match('/^\x01PATH:/i', $line)) {
                // Avoid duplicates
                if (!in_array($line, $newBottomKludges)) {
                    $newBottomKludges[] = $line;
                }
            } else {
                $topKludges[] = $line;
            }
        }

        $finalKludgeLines = empty($topKludges) ? null : implode("\n", $topKludges);
        $finalBottomKludges = empty($newBottomKludges) ? null : implode("\n", $newBottomKludges);

        // Only update if there are changes
        if ($finalKludgeLines !== $row['kludge_lines'] || $finalBottomKludges !== $existingBottomKludges) {
            $updateStmt->execute([$finalKludgeLines, $finalBottomKludges, $row['id']]);
            $processed++;
        }
    }

    echo "Migrated bottom kludges for $processed netmail messages\n";

    // Migrate echomail bottom kludges
    $processed = 0;
    $stmt = $db->query("SELECT id, kludge_lines, bottom_kludges FROM echomail WHERE kludge_lines IS NOT NULL AND kludge_lines != ''");
    $updateStmt = $db->prepare("UPDATE echomail SET kludge_lines = ?, bottom_kludges = ? WHERE id = ?");

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $kludgeLines = $row['kludge_lines'];
        $existingBottomKludges = $row['bottom_kludges'];
        $lines = explode("\n", $kludgeLines);

        $topKludges = [];
        $newBottomKludges = [];

        // Start with existing bottom kludges to preserve them
        if (!empty($existingBottomKludges)) {
            $existingLines = explode("\n", $existingBottomKludges);
            foreach ($existingLines as $line) {
                if (!empty(trim($line))) {
                    $newBottomKludges[] = trim($line);
                }
            }
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if (empty($trimmed)) {
                continue;
            }

            // Check if this is a bottom kludge (Via, SEEN-BY, PATH per FTS-4009)
            // Via: ^AVia 1:153/757 @... or ^AVia: 1:153/757 @...
            // SEEN-BY: SEEN-BY: 1/1 2/2
            // PATH: ^APATH: 1/1 2/2
            if (preg_match('/^\x01Via[:\s]+\d+:\d+\/\d+/i', $line) ||
                preg_match('/^SEEN-BY:/i', $line) ||
                preg_match('/^\x01PATH:/i', $line)) {
                // Avoid duplicates
                if (!in_array($line, $newBottomKludges)) {
                    $newBottomKludges[] = $line;
                }
            } else {
                $topKludges[] = $line;
            }
        }

        $finalKludgeLines = empty($topKludges) ? null : implode("\n", $topKludges);
        $finalBottomKludges = empty($newBottomKludges) ? null : implode("\n", $newBottomKludges);

        // Only update if there are changes
        if ($finalKludgeLines !== $row['kludge_lines'] || $finalBottomKludges !== $existingBottomKludges) {
            $updateStmt->execute([$finalKludgeLines, $finalBottomKludges, $row['id']]);
            $processed++;
        }
    }

    echo "Migrated bottom kludges for $processed echomail messages\n";

    return true;
};
