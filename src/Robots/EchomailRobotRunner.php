<?php

namespace BinktermPHP\Robots;

use BinktermPHP\Robots\Processors\IbbsLastCallProcessor;

/**
 * Runs all enabled echomail robot rules and dispatches messages to processors.
 */
class EchomailRobotRunner
{
    /** @var \PDO */
    private \PDO $db;

    /** @var callable|null */
    private $debugCallback = null;

    /**
     * Map of processor_type => fully-qualified class name.
     * Add new processors here to register them.
     */
    private const PROCESSORS = [
        'ibbslastcall_rot47' => IbbsLastCallProcessor::class,
    ];

    /** Batch size per robot run */
    private const BATCH_SIZE = 500;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Set a callback to receive debug output lines (one string per call).
     * When set, the runner and processors emit per-message decode details.
     *
     * @param callable|null $fn  fn(string $line): void
     */
    public function setDebugCallback(?callable $fn): void
    {
        $this->debugCallback = $fn;
    }

    /**
     * Run all enabled robots, or a specific one by ID.
     *
     * @param int|null $robotId  If null, runs all enabled robots
     * @return array             Summary: [ ['robot_id'=>..., 'name'=>..., 'examined'=>..., 'processed'=>..., 'error'=>...], ... ]
     */
    public function run(?int $robotId = null): array
    {
        $results = [];

        if ($robotId !== null) {
            $stmt = $this->db->prepare("SELECT * FROM echomail_robots WHERE id = ?");
            $stmt->execute([$robotId]);
        } else {
            $stmt = $this->db->query("SELECT * FROM echomail_robots WHERE enabled = TRUE ORDER BY id ASC");
        }

        $robots = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        foreach ($robots as $robot) {
            $results[] = $this->processRobot($robot);
        }

        return $results;
    }

    /**
     * Process a single robot rule: fetch unprocessed messages, dispatch to processor,
     * update progress counters and cursor.
     *
     * @param array $robot  Row from echomail_robots table
     * @return array        Summary for this robot
     */
    public function processRobot(array $robot): array
    {
        $robotId       = (int)$robot['id'];
        $processorType = $robot['processor_type'];
        $echoareaId    = (int)$robot['echoarea_id'];
        $lastId        = (int)($robot['last_processed_echomail_id'] ?? 0);
        $subjectPat    = $robot['subject_pattern'] ?? null;
        $config        = json_decode($robot['processor_config'] ?? '{}', true) ?: [];

        $summary = [
            'robot_id'  => $robotId,
            'name'      => $robot['name'],
            'examined'  => 0,
            'processed' => 0,
            'error'     => null,
        ];

        // Resolve processor class
        $processorClass = self::PROCESSORS[$processorType] ?? null;
        if ($processorClass === null) {
            $error = "Unknown processor type: {$processorType}";
            $this->updateRobotError($robotId, $error);
            $summary['error'] = $error;
            return $summary;
        }

        if ($this->debugCallback !== null) {
            ($this->debugCallback)(sprintf(
                "Robot #%d (%s) | processor: %s | echoarea_id: %d | cursor: %d | pattern: %s",
                $robotId,
                $robot['name'],
                $processorType,
                $echoareaId,
                $lastId,
                $subjectPat ?? '(any)'
            ));
        }

        try {
            /** @var MessageProcessorInterface $processor */
            $processor = new $processorClass($this->db);

            // Pass debug callback to processor if it supports it
            if ($this->debugCallback !== null && method_exists($processor, 'setDebugCallback')) {
                $processor->setDebugCallback($this->debugCallback);
            }

            // Build query — subject_pattern is a substring (ILIKE) match
            if (!empty($subjectPat)) {
                $stmt = $this->db->prepare("
                    SELECT *
                    FROM echomail
                    WHERE echoarea_id = ?
                      AND id > ?
                      AND subject ILIKE ?
                    ORDER BY id ASC
                    LIMIT " . self::BATCH_SIZE
                );
                $stmt->execute([$echoareaId, $lastId, '%' . $subjectPat . '%']);
            } else {
                $stmt = $this->db->prepare("
                    SELECT *
                    FROM echomail
                    WHERE echoarea_id = ?
                      AND id > ?
                    ORDER BY id ASC
                    LIMIT " . self::BATCH_SIZE
                );
                $stmt->execute([$echoareaId, $lastId]);
            }

            $messages = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            $newLastId  = $lastId;
            $examined   = 0;
            $processed  = 0;

            foreach ($messages as $message) {
                $examined++;
                $msgId = (int)$message['id'];

                if ($this->debugCallback !== null) {
                    ($this->debugCallback)(sprintf(
                        "  --- Msg #%d | from: %s | subj: %s",
                        $msgId,
                        $message['from_name'] ?? '?',
                        $message['subject'] ?? '?'
                    ));
                }

                $handled = $processor->processMessage($message, $config);
                if ($handled) {
                    $processed++;
                }

                if ($this->debugCallback !== null) {
                    ($this->debugCallback)('  → ' . ($handled ? 'processed' : 'skipped'));
                }

                if ($msgId > $newLastId) {
                    $newLastId = $msgId;
                }
            }

            // Update progress
            $updateStmt = $this->db->prepare("
                UPDATE echomail_robots SET
                    last_processed_echomail_id = ?,
                    last_run_at                = NOW(),
                    messages_examined          = messages_examined + ?,
                    messages_processed         = messages_processed + ?,
                    last_error                 = NULL,
                    updated_at                 = NOW()
                WHERE id = ?
            ");
            $updateStmt->execute([$newLastId, $examined, $processed, $robotId]);

            $summary['examined']  = $examined;
            $summary['processed'] = $processed;

        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $this->updateRobotError($robotId, $error);
            $summary['error'] = $error;
        }

        return $summary;
    }

    /**
     * Return a map of processor_type => ['class', 'display_name', 'description'].
     *
     * @return array
     */
    public function getRegisteredProcessors(): array
    {
        $result = [];
        foreach (self::PROCESSORS as $type => $class) {
            $result[$type] = [
                'type'         => $type,
                'display_name' => $class::getDisplayName(),
                'description'  => $class::getDescription(),
            ];
        }
        return $result;
    }

    /**
     * Record an error on a robot rule and update last_run_at.
     *
     * @param int    $robotId
     * @param string $error
     */
    private function updateRobotError(int $robotId, string $error): void
    {
        $stmt = $this->db->prepare("
            UPDATE echomail_robots SET
                last_error   = ?,
                last_run_at  = NOW(),
                updated_at   = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$error, $robotId]);
    }
}
