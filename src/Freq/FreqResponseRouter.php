<?php

namespace BinktermPHP\Freq;

use BinktermPHP\FileAreaManager;
use BinktermPHP\Binkp\Config\BinkpConfig;
use BinktermPHP\Binkp\Logger;

/**
 * Routes received binkp files that are FREQ responses to the correct user's
 * private file area.
 *
 * After any binkp session that receives files, call routeReceivedFiles() with
 * the remote node address and the list of received filenames.  The router
 * looks up all pending outbound FREQ requests for that node and routes each
 * received file to whichever user requested it by exact filename match.
 *
 * Files that do not match any pending request are left in data/inbound/
 * untouched (FILE_ATTACH attachments, infrastructure files, etc.).
 *
 * Magic names (ALLFILES, NODELIST, etc.) cannot be auto-routed because the
 * remote chooses the expanded filename at fulfillment time.
 */
class FreqResponseRouter
{
    private FreqRequestTracker $tracker;

    public function __construct(private \PDO $db, private ?Logger $logger = null)
    {
        $this->tracker = new FreqRequestTracker($db);
    }

    /**
     * Route any FREQ response files in the received file list.
     *
     * Looks up all pending outbound FREQ requests for the remote node and
     * builds a filename → request map.  Each received file is matched
     * case-insensitively against that map and routed to the user who
     * requested it.  This correctly handles multiple users having pending
     * requests to the same node with different filenames.
     *
     * Files that do not match any pending request are left in data/inbound/
     * untouched (e.g. FILE_ATTACH netmail attachments, infrastructure files).
     *
     * @param string   $remoteAddress FTN address of the remote node
     * @param string[] $filesReceived Filenames received during the session
     */
    public function routeReceivedFiles(string $remoteAddress, array $filesReceived): void
    {
        if (empty($filesReceived) || empty($remoteAddress)) {
            return;
        }

        $pendingRequests = $this->tracker->findPendingForNode($remoteAddress);
        if (empty($pendingRequests)) {
            return;
        }

        // Build a map of lowercase filename => pending request row.
        // Oldest request wins when two users requested the same filename
        // from the same node (findPendingForNode returns oldest first).
        $filenameToRequest = [];
        foreach ($pendingRequests as $pending) {
            foreach (json_decode($pending['requested_files'], true) ?? [] as $requestedFile) {
                $lower = strtolower($requestedFile);
                if (!isset($filenameToRequest[$lower])) {
                    $filenameToRequest[$lower] = $pending;
                }
            }
        }

        $inboundPath  = BinkpConfig::getInstance()->getInboundPath();
        $fileManager  = new FileAreaManager($this->db);
        $completedIds = [];

        foreach ($filesReceived as $filename) {
            $lower = strtolower($filename);

            if (!isset($filenameToRequest[$lower])) {
                continue;
            }

            $pending  = $filenameToRequest[$lower];
            $fullPath = $inboundPath . '/' . $filename;

            if (!file_exists($fullPath)) {
                $this->log('WARNING', "FREQ response file missing from inbound: {$filename}");
                continue;
            }

            try {
                $fileManager->storeFreqIncoming((int)$pending['user_id'], $fullPath, $remoteAddress);
                $completedIds[$pending['id']] = true;
                $this->log('INFO', "Routed '{$filename}' to user_id={$pending['user_id']} (request id={$pending['id']})");
            } catch (\Exception $e) {
                $this->log('WARNING', "Failed to store '{$filename}': " . $e->getMessage());
            }
        }

        foreach (array_keys($completedIds) as $id) {
            $this->tracker->markComplete((int)$id);
        }
    }

    /**
     * Determine whether a received filename looks like a FidoNet infrastructure
     * file that should remain in data/inbound/ for process_packets.
     *
     * @param string      $filename  Bare filename
     * @param string|null $fullPath  Full path to the file (needed for .zip peek)
     */
    public static function isInfrastructureFile(string $filename, ?string $fullPath = null): bool
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        // Unambiguous infrastructure extensions
        $definiteInfra = [
            'pkt', 'tic', 'req',
            'out',
            'flo', 'dlo', 'hlo', 'clo',
            'bsy', 'lck', 'in',
            'ndl', 'ndx',
        ];
        if (in_array($ext, $definiteInfra, true)) {
            return true;
        }

        // Day-of-week bundle extensions (plain, direct .d*, hold .h*, crash .c*)
        // e.g. .mo .tu .dmo .htu .csa etc.
        if (preg_match('/^[dhc]?(mo|tu|we|th|fr|sa|su)$/i', $ext)) {
            return true;
        }

        // Peek inside .zip to check for .pkt content
        if ($ext === 'zip' && $fullPath !== null && file_exists($fullPath)) {
            if (class_exists('ZipArchive')) {
                $zip = new \ZipArchive();
                if ($zip->open($fullPath) === true) {
                    $hasPkt = false;
                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = $zip->getNameIndex($i);
                        if ($entry !== false && strtolower(pathinfo($entry, PATHINFO_EXTENSION)) === 'pkt') {
                            $hasPkt = true;
                            break;
                        }
                    }
                    $zip->close();
                    if ($hasPkt) {
                        return true;
                    }
                }
            }
        }

        // Other compressed formats (.arc, .arj, .lzh, .gz) — cannot peek without
        // external tools; default to FREQ download (process_packets ignores them anyway)

        return false;
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger) {
            $this->logger->log($level, "[FreqResponseRouter] {$message}");
        }
    }
}
