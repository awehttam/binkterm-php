<?php

/*
 * Copright Matthew Asham and BinktermPHP Contributors
 * 
 * Redistribution and use in source and binary forms, with or without modification, are permitted provided that the 
 * following conditions are met:
 * 
 * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
 * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.
 * Neither the name of the copyright holder nor the names of its contributors may be used to endorse or promote products derived from this software without specific prior written permission.
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE
 * 
 */


namespace BinktermPHP\FileArea;

use BinktermPHP\Config;
use BinktermPHP\Database;
use BinktermPHP\FileAreaManager;
use PDO;

/**
 * FileAreaRuleProcessor - Loads and executes file area rules.
 */
class FileAreaRuleProcessor
{
    private array $rules = [];
    private ScriptExecutor $executor;
    private MacroSubstitutor $macroSubstitutor;
    private FileActionHandler $actionHandler;
    private FileAreaManager $fileAreaManager;
    private PDO $db;
    private string $output = '';
    private bool $debugEnabled = false;
    private string $debugLogPath = '';

    public function __construct()
    {
        $this->db = Database::getInstance()->getPdo();
        $this->executor = new ScriptExecutor();
        $this->macroSubstitutor = new MacroSubstitutor();
        $this->actionHandler = new FileActionHandler();
        $this->fileAreaManager = new FileAreaManager();
        $this->rules = $this->loadRules();
        $this->debugEnabled = strtolower((string)Config::env('FILE_ACTION_DEBUG', 'false')) === 'true';
        $this->debugLogPath = Config::getLogPath('file_action_debug.log');
    }

    /**
     * Process a file against global and area-specific rules.
     *
     * @param string $filepath
     * @param string $areatag
     * @return array
     */
    public function processFile(string $filepath, string $areatag): array
    {
        $filename = basename($filepath);
        $areaKey = strtoupper($areatag);
        $areaDomain = $this->getDomainForFile($filepath, $areatag);
        $domainKey = strtoupper($areatag . '@' . $areaDomain);

        $globalRules = $this->rules['global_rules'] ?? [];
        $areaRules = $this->rules['area_rules'][$domainKey]
            ?? ($this->rules['area_rules'][$areaKey] ?? []);
        $allRules = array_merge($globalRules, $areaRules);

        if (empty($allRules)) {
            $this->logDebug("No rules configured for area {$areatag} and file {$filename}.");
            return [
                'matched' => 0,
                'executed' => [],
                'stopped' => false,
                'output' => $this->output
            ];
        }

        $matchedRules = $this->matchRules($filename, $allRules, $areaDomain);
        $executed = [];

        if (empty($matchedRules)) {
            $this->logDebug("No matching rules for area {$areatag} (domain {$areaDomain}) and file {$filename}.");
        }

        foreach ($matchedRules as $rule) {
            $executed[] = $this->executeRule($rule, $filepath, $areatag);
            if ($this->actionHandler->shouldStop()) {
                break;
            }
        }

        return [
            'matched' => count($matchedRules),
            'executed' => $executed,
            'stopped' => $this->actionHandler->shouldStop(),
            'output' => $this->output
        ];
    }

    /**
     * @return string
     */
    public function getOutput(): string
    {
        return $this->output;
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
     * @return array
     */
    private function loadRules(): array
    {
        $configPath = __DIR__ . '/../../config/filearea_rules.json';
        if (!file_exists($configPath)) {
            return [
                'global_rules' => [],
                'area_rules' => []
            ];
        }

        $content = file_get_contents($configPath);
        $data = json_decode($content, true);

        if (!is_array($data)) {
            error_log('FileAreaRuleProcessor: invalid JSON in filearea_rules.json');
            return [
                'global_rules' => [],
                'area_rules' => []
            ];
        }

        $data['global_rules'] = $data['global_rules'] ?? [];
        $data['area_rules'] = $data['area_rules'] ?? [];

        $normalizedAreaRules = [];
        foreach ($data['area_rules'] as $key => $rules) {
            $normalizedAreaRules[strtoupper($key)] = $rules;
        }
        $data['area_rules'] = $normalizedAreaRules;

        return $data;
    }

    /**
     * @param string $filename
     * @param array $rules
     * @return array
     */
    private function matchRules(string $filename, array $rules, string $areaDomain): array
    {
        $matched = [];
        $areaDomainLower = strtolower($areaDomain);

        foreach ($rules as $rule) {
            $enabled = $rule['enabled'] ?? true;
            if (!$enabled) {
                continue;
            }

            $ruleDomain = $rule['domain'] ?? null;
            if (is_string($ruleDomain) && $ruleDomain !== '') {
                if (strtolower($ruleDomain) !== $areaDomainLower) {
                    continue;
                }
            }

            $pattern = $rule['pattern'] ?? '';
            if ($pattern === '') {
                continue;
            }

            $result = @preg_match($pattern, $filename);
            if ($result === false) {
                error_log("FileAreaRuleProcessor: invalid regex pattern: {$pattern}");
                continue;
            }

            if ($result === 1) {
                $matched[] = $rule;
            }
        }

        return $matched;
    }

    /**
     * @param array $rule
     * @param string $filepath
     * @param string $areatag
     * @return array
     */
    private function executeRule(array $rule, string $filepath, string $areatag): array
    {
        $ruleName = $rule['name'] ?? 'Unnamed Rule';
        $scriptTemplate = $rule['script'] ?? '';
        $timeout = (int)($rule['timeout'] ?? 600);

        $context = $this->buildContext($filepath, $areatag);
        $command = $this->substituteMacros($scriptTemplate, $context);

        $this->logDebug("Executing rule '{$ruleName}' for {$filename} (area {$areatag}) with command: {$command}");
        $result = $this->executeScript($command, $timeout);
        $success = !$result['timed_out'] && $result['exit_code'] === 0;

        $action = $success ? ($rule['success_action'] ?? '') : ($rule['fail_action'] ?? '');
        $actionResult = true;
        if ($action !== '') {
            $actionResult = $this->handleAction($action, $filepath, $areatag);
        }

        $this->logDebug("Rule '{$ruleName}' result: exit={$result['exit_code']}, timed_out=" . ($result['timed_out'] ? 'yes' : 'no') . ", success=" . ($success ? 'yes' : 'no') . ", action='{$action}', action_ok=" . ($actionResult ? 'yes' : 'no'));
        if (!empty($result['stdout'])) {
            $this->logDebug("Rule '{$ruleName}' stdout: " . trim($result['stdout']));
        }
        if (!empty($result['stderr'])) {
            $this->logDebug("Rule '{$ruleName}' stderr: " . trim($result['stderr']));
        }

        $this->logRuleExecution(
            $ruleName,
            $areatag,
            $context['domain'] ?? '',
            basename($filepath),
            $result['exit_code'],
            $success,
            $action
        );

        if (!empty($result['stdout'])) {
            $this->output .= $result['stdout'] . "\n";
        }
        if (!empty($result['stderr'])) {
            $this->output .= $result['stderr'] . "\n";
        }

        return [
            'rule' => $ruleName,
            'exit_code' => $result['exit_code'],
            'success' => $success,
            'action' => $action
        ];
    }

    /**
     * @param string $template
     * @param array $context
     * @return string
     */
    private function substituteMacros(string $template, array $context): string
    {
        return $this->macroSubstitutor->substitute($template, $context);
    }

    /**
     * @param string $command
     * @param int $timeout
     * @return array
     */
    private function executeScript(string $command, int $timeout): array
    {
        return $this->executor->execute($command, $timeout);
    }

    /**
     * @param string $action
     * @param string $filepath
     * @param string $areatag
     * @return void
     */
    private function handleAction(string $action, string $filepath, string $areatag): bool
    {
        return $this->actionHandler->executeAction($action, $filepath, $areatag);
    }

    /**
     * @param string $ruleName
     * @param string $areatag
     * @param string $filename
     * @param int $exitCode
     * @param bool $success
     * @param string $action
     * @return void
     */
    private function logRuleExecution(
        string $ruleName,
        string $areatag,
        string $domain,
        string $filename,
        int $exitCode,
        bool $success,
        string $action
    ): void {
        $logPath = Config::env('FILEAREA_RULE_ACTION_LOG', Config::getLogPath('filearea_rules.log'));
        $logDir = dirname($logPath);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $result = $success ? 'success' : 'fail';
        $postAction = $action === '' ? 'none' : $action;
        $timestamp = date('Y-m-d H:i:s');
        $domainLabel = $domain !== '' ? $domain : 'unknown';
        $entry = "[{$timestamp}] AREATAG: {$areatag} | DOMAIN: {$domainLabel} | FILE: {$filename} | RULE: {$ruleName} | ACTION: script | EXIT: {$exitCode} | RESULT: {$result} | POST-ACTION: {$postAction}\n";
        file_put_contents($logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $filepath
     * @param string $areatag
     * @return array
     */
    private function buildContext(string $filepath, string $areatag): array
    {
        $basedir = realpath(__DIR__ . '/../../');
        $filename = basename($filepath);
        $filesize = file_exists($filepath) ? filesize($filepath) : 0;
        $domain = $this->getDomainForFile($filepath, $areatag);
        $uploader = '';
        $ticfile = '';

        $record = $this->fileAreaManager->getFileRecordByPath($filepath);
        if ($record) {
            if (!empty($record['domain'])) {
                $domain = $record['domain'];
            }
            if (!empty($record['uploaded_from_address'])) {
                $uploader = $record['uploaded_from_address'];
            } elseif (!empty($record['owner_id'])) {
                $uploader = $this->getUserName((int)$record['owner_id']);
            }
        }

        return [
            'basedir'  => $basedir ?: '',   // path component macro — must not be pre-quoted
            'filepath' => escapeshellarg($filepath),
            'filename' => escapeshellarg($filename),
            'filesize' => (string)$filesize,
            'domain'   => escapeshellarg($domain),
            'areatag'  => escapeshellarg($areatag),
            'uploader' => escapeshellarg($uploader),
            'ticfile'  => escapeshellarg($ticfile),
            'tempdir'  => escapeshellarg(sys_get_temp_dir()),
        ];
    }

    /**
     * @param string $filepath
     * @param string $areatag
     * @return string
     */
    private function getDomainForFile(string $filepath, string $areatag): string
    {
        $record = $this->fileAreaManager->getFileRecordByPath($filepath);
        if ($record && !empty($record['domain'])) {
            return $record['domain'];
        }

        return $this->fileAreaManager->getDomainForArea($areatag);
    }


    /**
     * @param int $userId
     * @return string
     */
    private function getUserName(int $userId): string
    {
        $stmt = $this->db->prepare("SELECT username, real_name FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$userId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$result) {
            return '';
        }

        return $result['username'] ?: ($result['real_name'] ?? '');
    }
}

