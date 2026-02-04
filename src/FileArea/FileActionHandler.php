<?php

namespace BinktermPHP\FileArea;

use BinktermPHP\Config;
use BinktermPHP\FileAreaManager;
use BinktermPHP\SysopNotificationService;

/**
 * FileActionHandler - Handles post-rule file actions.
 */
class FileActionHandler
{
    private FileAreaManager $fileAreaManager;
    private bool $shouldStop = false;
    private bool $debugEnabled = false;
    private string $debugLogPath = '';

    public function __construct()
    {
        $this->fileAreaManager = new FileAreaManager();
        $this->debugEnabled = strtolower((string)Config::env('FILE_ACTION_DEBUG', 'false')) === 'true';
        $this->debugLogPath = Config::getLogPath('file_action_debug.log');
    }

    /**
     * Execute one or more actions.
     *
     * @param string $action
     * @param string $filepath
     * @param string $areatag
     * @return bool
     */
    public function executeAction(string $action, string $filepath, string $areatag): bool
    {
        $action = trim($action);
        if ($action === '') {
            return true;
        }

        $actions = array_filter(array_map('trim', explode('+', $action)));
        $success = true;
        $this->logDebug("Action pipeline '{$action}' for file {$filepath} (area {$areatag})");

        foreach ($actions as $part) {
            $lower = strtolower($part);
            if ($lower === 'stop') {
                $this->shouldStop = true;
                continue;
            }

            if (str_starts_with($lower, 'move:')) {
                $targetArea = trim(substr($part, 5));
                $success = $this->moveToArea($filepath, $targetArea) && $success;
                continue;
            }

            switch ($lower) {
                case 'delete':
                    $success = $this->deleteFile($filepath) && $success;
                    break;
                case 'keep':
                    break;
                case 'notify':
                    $success = $this->notifySysop([
                        'areatag' => $areatag,
                        'filepath' => $filepath,
                        'action' => $action
                    ]) && $success;
                    break;
                case 'archive':
                    $success = $this->archiveFile($filepath, $areatag) && $success;
                    break;
                default:
                    $success = false;
                    break;
            }
        }

        $this->logDebug("Action pipeline result for file {$filepath}: " . ($success ? 'success' : 'failure'));
        return $success;
    }

    /**
     * @return bool
     */
    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    private function logDebug(string $message): void
    {
        if (!$this->debugEnabled) {
            return;
        }

        $logDir = dirname($this->debugLogPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = date('Y-m-d H:i:s');
        $entry = "[{$timestamp}] {$message}\n";
        file_put_contents($this->debugLogPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $filepath
     * @return bool
     */
    private function deleteFile(string $filepath): bool
    {
        return $this->fileAreaManager->deleteFileByPath($filepath);
    }

    /**
     * @param array $context
     * @return bool
     */
    private function notifySysop(array $context): bool
    {
        $filename = basename($context['filepath'] ?? '');
        $subject = 'File area rule action';
        $message = "A file area rule triggered a notify action.
";
        $message .= "Area: " . ($context['areatag'] ?? '') . "
";
        $message .= "File: " . $filename . "
";
        $message .= "Path: " . ($context['filepath'] ?? '') . "
";
        $message .= "Action: " . ($context['action'] ?? '') . "
";

        return SysopNotificationService::sendNoticeToSysop($subject, $message);
    }

    /**
     * @param string $filepath
     * @param string $targetArea
     * @return bool
     */
    private function moveToArea(string $filepath, string $targetArea): bool
    {
        return $this->fileAreaManager->moveFileToArea($filepath, $targetArea);
    }

    /**
     * @param string $filepath
     * @param string $areatag
     * @return bool
     */
    private function archiveFile(string $filepath, string $areatag): bool
    {
        return $this->fileAreaManager->archiveFileByPath($filepath, $areatag);
    }
}
