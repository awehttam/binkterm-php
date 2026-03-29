<?php

namespace BinktermPHP;

use BinktermPHP\Binkp\Config\BinkpConfig;
use PDO;

class DashboardStatsService
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function getStats(array $user): array
    {
        $userId = (int)($user['user_id'] ?? $user['id'] ?? 0);
        $isAdmin = !empty($user['is_admin']);
        $meta = new UserMeta();

        $lastNetmailCount = (int)($meta->getValue($userId, 'last_netmail_count') ?? 0);
        $lastEchomailCount = (int)($meta->getValue($userId, 'last_echomail_count') ?? 0);
        $lastChatMaxId = $meta->getValue($userId, 'last_chat_max_id');
        $lastFilesMaxId = $meta->getValue($userId, 'last_files_max_id');

        try {
            $binkpConfig = BinkpConfig::getInstance();
            $myAddresses = $binkpConfig->getMyAddresses();
            $myAddresses[] = $binkpConfig->getSystemAddress();
        } catch (\Exception $e) {
            $myAddresses = [];
        }

        if (!empty($myAddresses)) {
            $addressPlaceholders = implode(',', array_fill(0, count($myAddresses), '?'));
            $unreadStmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE mrs.read_at IS NULL
                  AND (
                    n.user_id = ?
                    OR ((LOWER(n.to_name) = LOWER(?) OR LOWER(n.to_name) = LOWER(?)) AND n.to_address IN ($addressPlaceholders))
                  )
            ");
            $params = [$userId, $userId, $user['username'], $user['real_name']];
            $params = array_merge($params, $myAddresses);
            $unreadStmt->execute($params);
        } else {
            $unreadStmt = $this->db->prepare("
                SELECT COUNT(*) as count
                FROM netmail n
                LEFT JOIN message_read_status mrs ON (mrs.message_id = n.id AND mrs.message_type = 'netmail' AND mrs.user_id = ?)
                WHERE n.user_id = ? AND mrs.read_at IS NULL
            ");
            $unreadStmt->execute([$userId, $userId]);
        }
        $unreadNetmail = (int)($unreadStmt->fetch()['count'] ?? 0);

        $sysopUnreadFilter = $isAdmin ? "" : " AND COALESCE(e.is_sysop_only, FALSE) = FALSE";
        $unreadEchomailStmt = $this->db->prepare("
            SELECT COUNT(*) as count
            FROM echomail em
            INNER JOIN echoareas e ON em.echoarea_id = e.id
            INNER JOIN user_echoarea_subscriptions ues ON e.id = ues.echoarea_id AND ues.user_id = ?
            LEFT JOIN message_read_status mrs ON (mrs.message_id = em.id AND mrs.message_type = 'echomail' AND mrs.user_id = ?)
            WHERE mrs.read_at IS NULL AND e.is_active = TRUE AND ues.is_active = TRUE{$sysopUnreadFilter}
        ");
        $unreadEchomailStmt->execute([$userId, $userId]);
        $unreadEchomail = (int)($unreadEchomailStmt->fetch()['count'] ?? 0);

        if ($lastChatMaxId === null) {
            $maxStmt = $this->db->query("SELECT COALESCE(MAX(id), 0) as max_id FROM chat_messages");
            $chatMaxId = (int)($maxStmt->fetch()['max_id'] ?? 0);
            $meta->setValue($userId, 'last_chat_max_id', (string)$chatMaxId);
            $chatBadge = 0;
        } else {
            $lastChatMaxId = (int)$lastChatMaxId;
            $chatStmt = $this->db->prepare("
                SELECT COUNT(*) as new_count, COALESCE(MAX(m.id), ?) as max_id
                FROM chat_messages m
                LEFT JOIN chat_rooms r ON m.room_id = r.id
                WHERE m.id > ?
                  AND m.from_user_id != ?
                  AND (
                      (m.room_id IS NOT NULL AND r.is_active = TRUE)
                      OR m.to_user_id = ?
                  )
            ");
            $chatStmt->execute([$lastChatMaxId, $lastChatMaxId, $userId, $userId]);
            $chatRow = $chatStmt->fetch();
            $chatBadge = (int)($chatRow['new_count'] ?? 0);
            $chatMaxId = (int)($chatRow['max_id'] ?? $lastChatMaxId);
        }

        $fileAreaConditions = "fa.is_active = TRUE AND (fa.is_private = FALSE OR fa.is_private IS NULL)";

        if ($lastFilesMaxId === null) {
            $filesMaxStmt = $this->db->query("
                SELECT COALESCE(MAX(f.id), 0) AS max_id
                FROM files f
                JOIN file_areas fa ON fa.id = f.file_area_id
                WHERE {$fileAreaConditions}
                  AND f.status = 'approved'
                  AND f.source_type <> 'iso_subdir'
            ");
            $filesMaxId = (int)($filesMaxStmt->fetch()['max_id'] ?? 0);
            $meta->setValue($userId, 'last_files_max_id', (string)$filesMaxId);
            $filesBadge = 0;
            $totalFiles = 0;
        } else {
            $lastFilesMaxId = (int)$lastFilesMaxId;
            $filesStmt = $this->db->prepare("
                SELECT COUNT(*) AS new_count, COALESCE(MAX(f.id), ?) AS max_id
                FROM files f
                JOIN file_areas fa ON fa.id = f.file_area_id
                WHERE {$fileAreaConditions}
                  AND f.status = 'approved'
                  AND f.source_type <> 'iso_subdir'
                  AND f.id > ?
            ");
            $filesStmt->execute([$lastFilesMaxId, $lastFilesMaxId]);
            $filesRow = $filesStmt->fetch();
            $filesBadge = (int)($filesRow['new_count'] ?? 0);
            $filesMaxId = (int)($filesRow['max_id'] ?? $lastFilesMaxId);

            $totalFilesStmt = $this->db->query("
                SELECT COUNT(*) AS count
                FROM files f
                JOIN file_areas fa ON fa.id = f.file_area_id
                WHERE {$fileAreaConditions}
                  AND f.status = 'approved'
                  AND f.source_type <> 'iso_subdir'
            ");
            $totalFiles = (int)($totalFilesStmt->fetch()['count'] ?? 0);
        }

        $netmailBadge = $unreadNetmail > $lastNetmailCount ? $unreadNetmail : 0;
        $echomailBadge = $unreadEchomail > $lastEchomailCount ? $unreadEchomail : 0;

        $creditBalance = 0;
        if (UserCredit::isEnabled()) {
            try {
                $creditBalance = UserCredit::getBalance($userId);
            } catch (\Exception $e) {
                $creditBalance = 0;
            }
        }

        $pendingFileApprovals = 0;
        $pendingFilesMaxId = 0;
        if ($isAdmin) {
            $lastPendingFilesMaxId = $meta->getValue($userId, 'last_pending_files_max_id');

            if ($lastPendingFilesMaxId === null) {
                // First visit — record current max so future uploads appear as new
                $maxPendingStmt = $this->db->query("SELECT COALESCE(MAX(id), 0) AS max_id FROM files WHERE status = 'pending'");
                $pendingFilesMaxId = (int)($maxPendingStmt->fetch()['max_id'] ?? 0);
                $meta->setValue($userId, 'last_pending_files_max_id', (string)$pendingFilesMaxId);
                $pendingFileApprovals = 0;
            } else {
                $lastPendingFilesMaxId = (int)$lastPendingFilesMaxId;
                $pendingStmt = $this->db->prepare(
                    "SELECT COUNT(*) AS new_count, COALESCE(MAX(id), ?) AS max_id FROM files WHERE status = 'pending' AND id > ?"
                );
                $pendingStmt->execute([$lastPendingFilesMaxId, $lastPendingFilesMaxId]);
                $pendingRow = $pendingStmt->fetch();
                $pendingFileApprovals = (int)($pendingRow['new_count'] ?? 0);
                $pendingFilesMaxId = (int)($pendingRow['max_id'] ?? $lastPendingFilesMaxId);
            }
        }

        return [
            'unread_netmail' => $netmailBadge,
            'new_echomail' => $echomailBadge,
            'chat_total' => $chatBadge,
            'new_files' => $filesBadge,
            'total_netmail' => $unreadNetmail,
            'total_echomail' => $unreadEchomail,
            'chat_max_id' => $chatMaxId,
            'files_max_id' => $filesMaxId,
            'total_files' => $totalFiles,
            'credit_balance' => $creditBalance,
            'pending_file_approvals' => $pendingFileApprovals,
            'pending_files_max_id' => $pendingFilesMaxId,
        ];
    }
}
